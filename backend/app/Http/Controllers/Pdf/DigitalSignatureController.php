<?php

namespace App\Http\Controllers\Pdf;

use App\Models\DigitalSignature;
use Illuminate\Database\Eloquent\Model;

/**
 * 首页盖章 — the seal drawn on page one alongside the PKCS#7 signature field.
 */
class DigitalSignatureController extends PdfAssetController
{
    protected function resource(): string
    {
        return 'pdf_digital_signatures';
    }

    protected function modelClass(): string
    {
        return DigitalSignature::class;
    }

    protected function fileColumn(): string
    {
        return 'appearance_image_path';
    }

    protected function directory(): string
    {
        return 'digital-signatures';
    }

    protected function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'signature_contact' => ['nullable', 'string', 'max:255'],
            'signature_location' => ['nullable', 'string', 'max:255'],
            'signature_reason' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function auditValues(Model $model): array
    {
        return $model->only([
            'id', 'name', 'signature_contact', 'signature_location',
            'signature_reason', 'is_default', 'is_active',
        ]);
    }
}
