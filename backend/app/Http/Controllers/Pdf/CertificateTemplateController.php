<?php

namespace App\Http\Controllers\Pdf;

use App\Models\CertificateTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * 证书模板 (声明页) — a PDF merged in front of the report before signing.
 *
 * The merge runs in the browser, so this endpoint also serves the template
 * bytes to the signing desk, not just to the settings screen.
 */
class CertificateTemplateController extends PdfAssetController
{
    protected function resource(): string
    {
        return 'pdf_certificate_templates';
    }

    protected function modelClass(): string
    {
        return CertificateTemplate::class;
    }

    protected function fileColumn(): string
    {
        return 'file_path';
    }

    protected function directory(): string
    {
        return 'certificate-templates';
    }

    protected function uploadField(): string
    {
        return 'template';
    }

    protected function uploadRules(bool $creating): array
    {
        return [
            $creating ? 'required' : 'nullable',
            'file',
            'mimes:pdf',
            'max:20480',
        ];
    }

    protected function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'language' => ['sometimes', 'string', 'max:16'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepare(array $validated, Request $request, bool $creating): array
    {
        $upload = $request->file($this->uploadField());

        if ($upload !== null) {
            $validated['file_name'] = $upload->getClientOriginalName();
            $validated['file_size'] = $upload->getSize();
            $validated['mime_type'] = $upload->getClientMimeType();
        }

        if ($creating) {
            $validated['created_by'] = $request->user()?->name;
        }

        return $validated;
    }

    protected function auditValues(Model $model): array
    {
        return $model->only(['id', 'name', 'language', 'file_name', 'file_size', 'is_default', 'is_active']);
    }

    protected function baseQuery(mixed $query): mixed
    {
        return $query->orderBy('language')->orderByDesc('is_default')->orderBy('id');
    }
}
