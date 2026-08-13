<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared CRUD for the file-backed PDF signing assets (seals, perforation
 * stamps, function stamps, certificate templates).
 *
 * Their differences are the model, the column holding the stored path and the
 * validation rules; everything else — upload, replace, authenticated download,
 * audit trail — is identical, so it lives here once.
 */
abstract class PdfAssetController extends Controller
{
    protected const DISK = 'pdf';

    /** Permission/audit module name, e.g. `pdf_digital_signatures`. */
    abstract protected function resource(): string;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** Column holding the stored file path. */
    abstract protected function fileColumn(): string;

    /** Directory on the `pdf` disk, e.g. `digital-signatures`. */
    abstract protected function directory(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function rules(bool $creating): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function auditValues(Model $model): array;

    /**
     * Request field holding the upload.
     */
    protected function uploadField(): string
    {
        return 'image';
    }

    /**
     * @return array<int, string>
     */
    protected function uploadRules(bool $creating): array
    {
        return [
            $creating ? 'required' : 'nullable',
            'file',
            'mimes:png,jpg,jpeg',
            'max:8192',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, $this->resource().'.read', $this->resource());

        $query = $this->modelClass()::query();

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json([
            'data' => $this->baseQuery($query)->get()->map(fn (Model $model): array => $this->serialize($model))->values(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, $this->resource().'.create', $this->resource());

        $validated = $request->validate($this->rules(creating: true) + [
            $this->uploadField() => $this->uploadRules(creating: true),
        ]);

        unset($validated[$this->uploadField()]);
        $validated[$this->fileColumn()] = $this->storeUpload($request->file($this->uploadField()));

        $model = $this->modelClass()::query()->create($this->prepare($validated, $request, creating: true));

        $auditLogger->record(
            actor: $request->user(),
            action: $this->resource().'.create',
            module: $this->resource(),
            subject: $model,
            after: $this->auditValues($model),
        );

        return response()->json(['data' => $this->serialize($model)], 201);
    }

    public function update(Request $request, int $id, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, $this->resource().'.update', $this->resource());

        $model = $this->modelClass()::query()->findOrFail($id);
        $before = $this->auditValues($model);

        $validated = $request->validate($this->rules(creating: false) + [
            $this->uploadField() => $this->uploadRules(creating: false),
        ]);

        unset($validated[$this->uploadField()]);

        $previousPath = $model->{$this->fileColumn()};
        $replacement = $request->file($this->uploadField());

        if ($replacement instanceof UploadedFile) {
            $validated[$this->fileColumn()] = $this->storeUpload($replacement);
        }

        $model->update($this->prepare($validated, $request, creating: false));

        // Only drop the old file once the row points at the new one, so a failed
        // update can never leave a row referencing a deleted file.
        if ($replacement instanceof UploadedFile && filled($previousPath)) {
            Storage::disk(self::DISK)->delete($previousPath);
        }

        $auditLogger->record(
            actor: $request->user(),
            action: $this->resource().'.update',
            module: $this->resource(),
            subject: $model,
            before: $before,
            after: $this->auditValues($model->fresh()),
        );

        return response()->json(['data' => $this->serialize($model->fresh())]);
    }

    /**
     * Permanently removes the configuration and its stored file.
     *
     * Signed documents keep working: the seal is already baked into their bytes
     * and `pdf_files.metadata` keeps the id, while the audit entry below records
     * the deleted configuration's values so an old signing can still be
     * explained. Retiring without deleting stays available through the
     * `is_active` flag on the edit form.
     */
    public function destroy(Request $request, int $id, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, $this->resource().'.delete', $this->resource());

        $model = $this->modelClass()::query()->findOrFail($id);
        $before = $this->auditValues($model);
        $path = $model->{$this->fileColumn()};

        $model->delete();

        if (filled($path)) {
            Storage::disk(self::DISK)->delete($path);
        }

        $auditLogger->record(
            actor: $request->user(),
            action: $this->resource().'.delete',
            module: $this->resource(),
            subject: null,
            before: $before,
        );

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    /**
     * Streams the stored file. Seals live on a private disk, so the SPA loads
     * them through this authenticated endpoint rather than a public URL.
     */
    public function file(Request $request, int $id): StreamedResponse
    {
        $this->authorizePermission($request, $this->resource().'.read', $this->resource());

        $model = $this->modelClass()::query()->findOrFail($id);
        $path = $model->{$this->fileColumn()};
        $disk = Storage::disk(self::DISK);

        abort_unless(filled($path) && $disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function baseQuery(mixed $query): mixed
    {
        return $query->orderByDesc('is_default')->orderBy('id');
    }

    /**
     * Hook for subclasses that derive columns from the request.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function prepare(array $validated, Request $request, bool $creating): array
    {
        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(Model $model): array
    {
        return $model->toArray();
    }

    protected function storeUpload(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');

        return $file->storeAs(
            $this->directory(),
            Str::uuid()->toString().'.'.$extension,
            ['disk' => self::DISK],
        );
    }
}
