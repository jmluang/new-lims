<?php

namespace App\Http\Controllers\Pdf;

use App\Models\HomepageFunctionStamp;
use Illuminate\Database\Eloquent\Model;

/**
 * 首页功能章 — several may be applied at once; `sort_order` seeds the default
 * left-to-right order the signing desk offers.
 */
class HomepageFunctionStampController extends PdfAssetController
{
    protected function resource(): string
    {
        return 'pdf_function_stamps';
    }

    protected function modelClass(): string
    {
        return HomepageFunctionStamp::class;
    }

    protected function fileColumn(): string
    {
        return 'image_path';
    }

    protected function directory(): string
    {
        return 'function-stamps';
    }

    protected function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function auditValues(Model $model): array
    {
        return $model->only(['id', 'name', 'sort_order', 'is_default', 'is_active']);
    }

    protected function baseQuery(mixed $query): mixed
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
