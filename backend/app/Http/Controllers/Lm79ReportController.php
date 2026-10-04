<?php

namespace App\Http\Controllers;

use App\Models\Lm79Report;
use App\Models\PdfDocument;
use App\Models\PdfSourceUpload;
use App\Models\Sample;
use App\Services\Audit\AuditLogger;
use App\Services\Inspection\InspectionMediaLibrary;
use App\Services\Inspection\InspectionSubjectLookup;
use App\Services\Pdf\PdfRendererClient;
use App\Services\Pdf\PdfRendererHttpException;
use App\Services\Pdf\PdfSourceService;
use App\Services\Pdf\ReportNumberNormalizer;
use App\Services\Reports\Lm79Calculations;
use App\Services\Reports\Lm79Equipment;
use App\Services\Reports\Lm79Fields;
use App\Services\Reports\Lm79Payload;
use App\Services\Reports\Lm79ReportNumber;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Lm79ReportController extends Controller
{
    private const RESOURCE = 'lm79_reports';

    private const COLLECTIONS = ['photos', 'pdf_gonio', 'pdf_sphere', 'ies', 'gos', 'haas'];

    public function index(Request $request)
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE);
        $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'in:draft,submitted'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', Rule::in([15, 30, 50, 100])]]);
        $rows = Lm79Report::query()
            ->select(['id', 'report_number', 'sample_snapshot', 'created_by', 'pdf_document_id', 'updated_at',
                'data->values->product_name as list_product_name', 'data->values->model as list_model', 'data->values->applicant as list_applicant'])
            ->with(['creator:id,name', 'document:id,document_uuid'])
            ->withCount(['media', 'spectrumPoints'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(function ($query) use ($search) {
                    foreach (['report_number', 'sample_snapshot->sample_no', 'sample_snapshot->order_no', 'data->values->product_name', 'data->values->model', 'data->values->applicant'] as $field) {
                        $query->orWhere($field, 'like', $search);
                    }
                });
            })
            ->when($request->input('status') === 'draft', fn ($query) => $query->whereNull('pdf_document_id'))
            ->when($request->input('status') === 'submitted', fn ($query) => $query->whereNotNull('pdf_document_id'))
            ->orderByDesc('updated_at')->orderByDesc('id')->paginate($request->integer('per_page', 15));

        return response()->json(['data' => $rows->map(fn ($r) => [
            'id' => $r->id, 'report_number' => $r->report_number, 'sample_no' => $r->sample_snapshot['sample_no'], 'order_no' => $r->sample_snapshot['order_no'] ?? '',
            'product_name' => $r->list_product_name ?? $r->sample_snapshot['sample_name'], 'model' => $r->list_model ?? $r->sample_snapshot['model'], 'applicant' => $r->list_applicant ?? '',
            'created_by_name' => $r->creator?->name, 'attachment_count' => $r->media_count, 'spectrum_point_count' => $r->spectrum_points_count,
            'locked' => $r->pdf_document_id !== null, 'document_uuid' => $r->document?->document_uuid, 'updated_at' => $r->updated_at?->toIso8601String(),
        ]), 'meta' => ['total' => $rows->total(), 'current_page' => $rows->currentPage(), 'per_page' => $rows->perPage()]]);
    }

    public function options(Request $request)
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE);

        return response()->json(['data' => ['groups' => Lm79Fields::GROUPS, 'defaults' => Lm79Fields::defaults()]]);
    }

    public function reportNumber(Request $request, Lm79ReportNumber $numbers)
    {
        $this->authorizePermission($request, self::RESOURCE.'.create', self::RESOURCE);

        return response()->json(['data' => ['report_number' => $numbers->generate()]]);
    }

    public function equipmentLookup(Request $request, InspectionSubjectLookup $subjects, Lm79Equipment $equipment)
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE);
        $payload = $request->validate(['code' => ['required', 'string', 'max:255']]);

        $code = trim($payload['code']);
        try {
            $device = $subjects->equipmentByNo($code);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => "未找到设备「{$code}」。请核对设备编号，或在设备管理中确认设备已登记。"], 404);
        }

        return response()->json(['data' => $equipment->option($device)]);
    }

    public function samples(Request $request)
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE);
        $samples = Sample::query()->with(['testOrder.standards', 'orderSample'])->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('sample_no', 'like', '%'.$request->string('search').'%')->orWhere('sample_name', 'like', '%'.$request->string('search').'%')->orWhere('model', 'like', '%'.$request->string('search').'%')))->when($request->filled('sample_id'), fn ($q) => $q->whereKey($request->integer('sample_id')))->latest()->limit(30)->get();

        return response()->json(['data' => $samples->map(fn ($s) => ['id' => $s->id, 'snapshot' => $this->snapshot($s), 'data' => $this->sampleData($s)])]);
    }

    private function snapshot(Sample $sample): array
    {
        return ['sample_no' => $sample->sample_no, 'sample_name' => $sample->sample_name, 'model' => $sample->model, 'order_no' => $sample->testOrder?->order_no];
    }

    private function sampleData(Sample $s): array
    {
        $o = $s->testOrder;
        $v = array_replace(Lm79Fields::defaults(), ['product_name' => $s->sample_name, 'model' => $s->model ?? '', 'rated_voltage' => $s->input_voltage ?? '',
            'rated_power' => preg_replace('/\s*[wW]\s*$/', '', $s->power ?? ''),
            'applicant' => $o?->client_company ?? '', 'applicant_address' => $o?->client_address ?? '',
            'manufacturer' => $o?->manufacturer_company ?? '', 'manufacturer_address' => $o?->manufacturer_address ?? '',
            'test_date' => $s->received_date?->toDateString() ?? '']);

        return ['values' => $v, 'standards' => $o?->standards->map(fn ($r) => trim($r->standard_code.' '.$r->standard_name))->all() ?? [], 'equipment' => []];
    }

    public function show(Request $request, Lm79Report $report)
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE, $report);

        return response()->json(['data' => $this->serialize($report)]);
    }

    public function store(Request $request)
    {
        $this->authorizePermission($request, self::RESOURCE.'.create', self::RESOURCE);

        return $this->save($request);
    }

    public function update(Request $request, Lm79Report $report)
    {
        $this->authorizePermission($request, self::RESOURCE.'.update', self::RESOURCE, $report);

        return $this->save($request, $report);
    }

    private function save(Request $request, ?Lm79Report $report = null)
    {
        $this->decode($request);
        $payload = $request->validate([
            'sample_id' => [$report ? 'nullable' : 'required', 'integer', 'exists:samples,id'],
            'report_number' => ['nullable', 'string', 'max:128'], ...Lm79Fields::rules(),
            'standards' => ['present', 'array', 'max:30'], 'standards.*' => ['required', 'string', 'max:1000'],
            'equipment' => ['present', 'array', 'max:30'],
            'equipment.*' => ['array:name,model,serial,cal_cert,cal_org,cal_due,equipment_id,equipment_no,manufacturer,next_calibration_date,snapshot_id'],
            'equipment.*.name' => ['nullable', 'string', 'max:255'],
            ...collect(['model', 'serial', 'cal_cert', 'cal_org', 'cal_due'])->mapWithKeys(fn ($f) => ['equipment.*.'.$f => ['nullable', 'string', 'max:255']])->all(),
            'equipment.*.equipment_id' => ['nullable', 'integer'],
            'equipment.*.snapshot_id' => ['nullable', 'string', 'max:64'],
            ...collect(['equipment_no', 'manufacturer', 'next_calibration_date'])->mapWithKeys(fn ($f) => ['equipment.*.'.$f => ['nullable', 'string', 'max:255']])->all(),
            'retained_media_ids' => ['present', 'array'], 'retained_media_ids.*' => ['integer'],
            'photos' => ['nullable', 'array', 'max:10'], 'photos.*' => ['file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'pdf_gonio' => ['nullable', 'file', 'mimes:pdf', 'max:20480'], 'pdf_sphere' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'ies' => ['nullable', 'file', 'extensions:ies,txt', 'max:10240'],
            'gos' => ['nullable', 'file', 'extensions:gos', 'max:20480'], 'haas' => ['nullable', 'file', 'extensions:haas', 'max:20480'],
        ]);
        $values = array_replace(Lm79Fields::defaults(), array_map(fn ($s) => $s ?? '', $payload['values']));
        foreach (Lm79Fields::GROUPS as $group) {
            foreach ($group['fields'] as $f) {
                if ($f['numeric'] && $values[$f['name']] !== '' && (! is_numeric($values[$f['name']]) || ! is_finite((float) $values[$f['name']]))) {
                    throw ValidationException::withMessages(['values.'.$f['name'] => ['请输入有限的数字。']]);
                }
            }
        }
        $number = trim($payload['report_number'] ?? '');
        $normalized = $number !== '' ? app(ReportNumberNormalizer::class)->normalize($number) : null;
        $spectrum = app(Lm79Payload::class)->spectrum($values['spectrum_data'] ?? '');
        unset($values['spectrum_data']);
        if ($normalized !== null) {
            validator(['number' => $normalized], ['number' => ['required', 'max:128', Rule::unique('lm79_reports', 'normalized_report_number')->ignore($report?->id)]])->validate();
        }
        $sample = isset($payload['sample_id']) ? Sample::query()->with(['testOrder.standards', 'orderSample'])->findOrFail($payload['sample_id']) : null;
        $written = [];
        $removed = [];
        try {
            $saved = DB::transaction(function () use ($request, $payload, $report, $values, $number, $normalized, $sample, $spectrum, &$written, &$removed) {
                $row = $report ? Lm79Report::query()->lockForUpdate()->findOrFail($report->id) : new Lm79Report;
                $this->editable($row);
                if ($row->exists && (int) $row->sample_id !== (int) $sample?->id) {
                    throw new ConflictHttpException('报告关联的实际样品不能更换。');
                }
                $before = $row->exists ? $this->serialize($row) : null;
                $media = $row->exists ? $row->media()->get() : collect();
                if (array_diff($payload['retained_media_ids'], $media->pluck('id')->all()) !== []) {
                    throw ValidationException::withMessages(['retained_media_ids' => ['附件不属于此报告或已被移除。']]);
                }
                $retained = $media->whereIn('id', $payload['retained_media_ids']);
                $removed = $media->whereNotIn('id', $payload['retained_media_ids'])->all();
                foreach (self::COLLECTIONS as $collection) {
                    $new = $request->file($collection);
                    $count = $retained->where('collection_name', $collection)->count() + ($new ? (is_array($new) ? count($new) : 1) : 0);
                    if ($count > ($collection === 'photos' ? 10 : 1)) {
                        throw ValidationException::withMessages([$collection => ['请先移除旧附件再上传，照片最多 10 张，其他类别限一个文件。']]);
                    }
                }
                $uploadSize = $retained->sum('size');
                $renderSize = $retained->whereIn('collection_name', Lm79Report::RENDER_COLLECTIONS)->sum('size');
                foreach (self::COLLECTIONS as $c) {
                    foreach ((array) ($request->file($c) ? (is_array($request->file($c)) ? $request->file($c) : [$request->file($c)]) : []) as $f) {
                        $uploadSize += $f->getSize();
                        if (in_array($c, Lm79Report::RENDER_COLLECTIONS, true)) {
                            $renderSize += $f->getSize();
                        }
                    }
                }
                if ($uploadSize > 20 * 1024 * 1024) {
                    throw ValidationException::withMessages(['attachments' => ['报告附件总大小最多 20 MB。']]);
                }
                if ($renderSize > Lm79Report::RENDER_MEDIA_LIMIT) {
                    throw ValidationException::withMessages(['attachments' => ['照片与 PDF 附录合计最多 16 MB。']]);
                }
                $equipment = app(Lm79Equipment::class)->resolve($payload['equipment'], $row->exists ? ($row->data['equipment'] ?? []) : []);
                $assignedNumber = $number !== '' ? $number : ($row->exists ? $row->report_number : app(Lm79ReportNumber::class)->generate());
                $row->fill(['sample_id' => $sample?->id, 'sample_snapshot' => $row->exists ? $row->sample_snapshot : $this->snapshot($sample), 'report_number' => $assignedNumber, 'normalized_report_number' => $normalized ?? app(ReportNumberNormalizer::class)->normalize($assignedNumber), 'data' => ['values' => $values, 'standards' => $payload['standards'], 'equipment' => $equipment]]);
                if (! $row->exists) {
                    $row->created_by = $request->user()->id;
                }
                $row->save();
                $row->spectrumPoints()->delete();
                foreach (array_chunk($spectrum, 500, true) as $chunk) {
                    DB::table('lm79_spectrum_points')->insert(array_map(fn ($point, $position) => ['report_id' => $row->id, 'position' => $position, 'wavelength' => $point[0], 'relative_power' => $point[1]], $chunk, array_keys($chunk)));
                }
                foreach (self::COLLECTIONS as $c) {
                    $uploads = $request->file($c);
                    foreach ($uploads ? (is_array($uploads) ? $uploads : [$uploads]) : [] as $file) {
                        $written[] = $row->addMedia($file)->withCustomProperties(['original_file_name' => $file->getClientOriginalName(), 'sha256' => hash_file('sha256', $file->getRealPath())])->toMediaCollection($c);
                    }
                }
                app(AuditLogger::class)->record(actor: $request->user(), action: self::RESOURCE.($before ? '.update' : '.create'), module: self::RESOURCE, subject: $row, before: $before ?? [], after: $this->serialize($row->fresh()));

                return $row;
            });
        } catch (\Throwable $e) {
            app(InspectionMediaLibrary::class)->discardFiles($written);
            throw $e;
        }
        foreach ($removed as $media) {
            $media->delete();
        }

        return response()->json(['data' => $this->serialize($saved->fresh())], $report ? 200 : 201);
    }

    private function decode(Request $request): void
    {
        foreach (['values', 'standards', 'equipment', 'retained_media_ids'] as $key) {
            if (is_string($request->input($key))) {
                try {
                    $request->merge([$key => json_decode($request->input($key), true, 512, JSON_THROW_ON_ERROR)]);
                } catch (\JsonException) {
                    throw ValidationException::withMessages([$key => ['数据格式无效。']]);
                }
            }
        }
    }

    private function editable(Lm79Report $report): void
    {
        if ($report->pdf_document_id !== null) {
            throw new ConflictHttpException('报告已提交签署，原始内容已冻结。');
        }
    }

    public function calculate(Request $request, Lm79Report $report)
    {
        $this->authorizePermission($request, self::RESOURCE.'.update', self::RESOURCE, $report);
        $this->editable($report);
        try {
            $ies = $report->getFirstMedia('ies');
            $calculated = (new Lm79Calculations)->calculate($this->serialize($report)['data']['values'], $ies ? file_get_contents($ies->getPath()) : null);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['ies' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $calculated]);
    }

    public function pdf(Request $request, Lm79Report $report, PdfRendererClient $renderer, Lm79Payload $payload)
    {
        $this->authorizePermission($request, self::RESOURCE.'.print', self::RESOURCE, $report);
        if ($report->pdf_document_id !== null) {
            throw new ConflictHttpException('请在签署文档中查看冻结后的 PDF。');
        }
        $bytes = $this->render($renderer, $payload->build($report));

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename=lm79-report.pdf']);
    }

    public function signingSource(Request $request, Lm79Report $report, PdfRendererClient $renderer, Lm79Payload $payload, PdfSourceService $sources)
    {
        $this->authorizePermission($request, self::RESOURCE.'.print', self::RESOURCE, $report);
        $this->authorizePermission($request, 'pdf.workflow.create', 'pdf.workflow');
        $createdSource = null;
        try {
            $result = DB::transaction(function () use ($request, $report, $renderer, $payload, $sources, &$createdSource) {
                $row = Lm79Report::query()->lockForUpdate()->findOrFail($report->id);
                if ($row->pdf_source_uuid !== null) {
                    $source = PdfSourceUpload::query()->where('source_uuid', $row->pdf_source_uuid)->firstOrFail();
                    abort_unless((int) $source->created_by_id === $request->user()->id, 403);

                    return ['source_uuid' => $source->source_uuid, 'document_uuid' => PdfDocument::findOrFail($row->pdf_document_id)->document_uuid, 'report_number' => $row->report_number];
                }
                $bytes = $this->render($renderer, $payload->build($row));
                if (strlen($bytes) > 20 * 1024 * 1024) {
                    throw ValidationException::withMessages(['pdf' => ['最终 PDF 超过签署流程的 20 MB 限制。']]);
                }
                $temp = tempnam(sys_get_temp_dir(), 'lm79-report-');
                try {
                    file_put_contents($temp, $bytes);
                    $createdSource = $sources->inspect(new UploadedFile($temp, 'lm79-report.pdf', 'application/pdf', null, true), $request->user());
                    $document = $sources->confirm($createdSource, $row->report_number, $request->user());
                } finally {
                    if (is_file($temp)) {
                        unlink($temp);
                    }
                }
                $row->update(['pdf_source_uuid' => $createdSource->source_uuid, 'pdf_document_id' => $document->id]);
                app(AuditLogger::class)->record(actor: $request->user(), action: self::RESOURCE.'.submit', module: self::RESOURCE, subject: $row, after: ['document_uuid' => $document->document_uuid, 'source_uuid' => $createdSource->source_uuid]);

                return ['source_uuid' => $createdSource->source_uuid, 'document_uuid' => $document->document_uuid, 'report_number' => $row->report_number];
            });
        } catch (\Throwable $e) {
            if ($createdSource) {
                Storage::disk('pdf')->delete($createdSource->stored_path);
            }
            throw $e;
        }

        return response()->json(['data' => $result]);
    }

    public function media(Request $request, Lm79Report $report, Media $media)
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE, $report);
        $library = app(InspectionMediaLibrary::class);

        return $library->downloadResponse($library->ownedMedia($report, $media));
    }

    private function render(PdfRendererClient $renderer, array $payload): string
    {
        try {
            return $renderer->renderLm79Report($payload);
        } catch (PdfRendererHttpException $e) {
            if ($e->statusCode === 422) {
                throw ValidationException::withMessages(['pdf' => ['报告内容或附录无法生成，请检查字段长度、图片格式以及附录是否为有效的未加密、未签名 PDF。']]);
            }
            throw new HttpException($e->statusCode === 503 ? 503 : 502, 'PDF 服务暂时无法生成报告，请稍后重试。', $e);
        } catch (\RuntimeException $e) {
            Log::warning('LM-79 PDF rendering failed.', ['error' => $e->getMessage()]);
            throw new HttpException(502, 'PDF 服务未就绪，请联系管理员检查服务配置。', $e);
        }
    }

    public function destroy(Request $request, Lm79Report $report)
    {
        $this->authorizePermission($request, self::RESOURCE.'.delete', self::RESOURCE, $report);
        DB::transaction(function () use ($request, $report) {
            $row = Lm79Report::query()->lockForUpdate()->findOrFail($report->id);
            $this->editable($row);
            app(AuditLogger::class)->record(actor: $request->user(), action: self::RESOURCE.'.delete', module: self::RESOURCE, subject: $row, before: $this->serialize($row));
            $row->delete();
        });

        return response()->noContent();
    }

    private function serialize(Lm79Report $report, bool $details = true): array
    {
        $data = $details ? $report->data : ['values' => [], 'standards' => [], 'equipment' => []];
        if ($details) {
            $data['values']['spectrum_data'] = $report->spectrumText();
            $data['equipment'] = app(Lm79Equipment::class)->withKeys($data['equipment']);
        }

        return ['id' => $report->id, 'sample_id' => $report->sample_id, 'sample_snapshot' => $report->sample_snapshot,
            'report_number' => $report->report_number, 'data' => $data, 'locked' => $report->pdf_document_id !== null,
            'document_uuid' => $report->pdf_document_id ? PdfDocument::find($report->pdf_document_id)?->document_uuid : null,
            'media' => $details ? $report->media()->get()->map(fn ($m) => app(InspectionMediaLibrary::class)->serialize($m))->all() : [],
            'updated_at' => $report->updated_at?->toIso8601String()];
    }
}
