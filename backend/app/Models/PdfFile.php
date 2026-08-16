<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The signed-PDF ledger. Verification is a lookup by digest against these rows,
 * so a row must be written for every file that leaves the signing desk.
 */
#[Fillable([
    'file_id',
    'file_name',
    'file_path',
    'sha256_hash',
    'md5_hash',
    'cover_report_number',
    'signature',
    'public_key',
    'file_size',
    'signed_at',
    'created_by',
    'created_by_id',
    'metadata',
    'document_id',
    'revision_uuid',
    'parent_pdf_file_id',
    'revision_number',
    'revision_role',
    'revision_created_at',
    'revision_manifest',
    'revision_manifest_hash',
    'integrity_state',
    'disposition',
    'first_published_at',
])]
class PdfFile extends Model
{
    /** 未发现签名字段 */
    public const STATUS_NO_SIGNATURE = 0;

    /** 签名存在，校验通过，文件完整 */
    public const STATUS_VALID = 1;

    /** 签名存在，但校验失败，可能被修改 */
    public const STATUS_INVALID = 2;

    /** 签名格式错误或解析失败 */
    public const STATUS_ERROR = 3;

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'metadata' => 'array',
            'revision_manifest' => 'array',
            'revision_created_at' => 'datetime',
            'first_published_at' => 'datetime',
            'revision_number' => 'integer',
            'file_size' => 'integer',
        ];
    }

    /**
     * File name the signed report is delivered under.
     *
     * Shared by the signing response and the temporary download link so the
     * operator gets the same file name whichever of the two the browser used.
     */
    public function signedDownloadName(): string
    {
        $base = pathinfo((string) $this->file_name, PATHINFO_FILENAME) ?: 'document';

        return $base.'-正本.pdf';
    }

    protected static function booted(): void
    {
        static::creating(function (PdfFile $pdfFile): void {
            if (blank($pdfFile->file_id)) {
                $pdfFile->file_id = (string) Str::uuid();
            }
        });
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PdfDocument::class);
    }

    public static function statusDescription(int $status): string
    {
        return match ($status) {
            self::STATUS_NO_SIGNATURE => '未发现签名字段',
            self::STATUS_VALID => '签名存在，校验通过，文件完整',
            self::STATUS_INVALID => '签名存在，但校验失败，可能被修改',
            self::STATUS_ERROR => '签名格式错误或解析失败',
            default => '未知状态',
        };
    }
}
