<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use setasign\Fpdi\Tcpdf\Fpdi;
use Smalot\PdfParser\Parser;

/**
 * 处理光度数据后签名 — ported verbatim from zs-lims's PdfProcessingService but
 * DISABLED by default.
 *
 * It rebuilds the PDF page by page through FPDI and paints a white rectangle
 * over the "Photometric & Radiometric Parameters" block before the document is
 * signed. Because the coordinates are estimated rather than derived from the
 * glyph boxes, it can white out the wrong strip on a report whose layout drifts
 * — which is why `pdf_service.signing.photometric_removal_enabled` ships false.
 *
 * Enabling it requires three composer packages that this project does not
 * install by default:
 *
 *     composer require setasign/fpdi-tcpdf tecnickcom/tcpdf smalot/pdfparser
 *
 * @see docs/plans/2026-08-13-pdf-tamper-proof-migration-plan.md
 */
class PhotometricContentRemover
{
    /**
     * Text that marks the block to remove.
     *
     * @var list<string>
     */
    private const TARGET_TEXTS = [
        'Photometric & Radiometric Parameters',
    ];

    private const FPDI_CLASS = Fpdi::class;

    private const PARSER_CLASS = Parser::class;

    public function enabled(): bool
    {
        return (bool) config('pdf_service.signing.photometric_removal_enabled');
    }

    /**
     * Whether the optional PDF libraries this pipeline needs are installed.
     */
    public function available(): bool
    {
        return class_exists(self::FPDI_CLASS) && class_exists(self::PARSER_CLASS);
    }

    /**
     * Rewrites $sourcePath with the photometric block masked out.
     *
     * @return string path to the rewritten PDF inside $workingDir
     */
    public function remove(string $sourcePath, string $workingDir): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('处理光度数据后签名功能未启用');
        }

        if (! $this->available()) {
            throw new RuntimeException(
                '处理光度数据后签名依赖未安装，请先执行: composer require setasign/fpdi-tcpdf tecnickcom/tcpdf smalot/pdfparser'
            );
        }

        if (! is_file($sourcePath)) {
            throw new RuntimeException('源文件不存在: '.$sourcePath);
        }

        $outputPath = $workingDir.'/content_removed.pdf';
        $originalSize = filesize($sourcePath);

        Log::info('开始删除光度数据内容（使用文本解析和位置计算）', [
            'source' => $sourcePath,
            'output' => $outputPath,
            'size' => $originalSize,
        ]);

        $textPositions = $this->extractTargetTextPositions($sourcePath);

        $fpdiClass = self::FPDI_CLASS;
        $pdf = new $fpdiClass;
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        // Compression off keeps the rebuilt bytes closer to the source layout.
        $pdf->SetCompression(false);
        $pdf->SetTitle('Processed PDF');
        $pdf->SetAuthor('LIMS System');
        $pdf->SetCreator('PDF Processing Service');

        try {
            $pageCount = $pdf->setSourceFile($sourcePath);
        } catch (\Throwable $exception) {
            throw new RuntimeException('无法读取PDF文件: '.$exception->getMessage(), previous: $exception);
        }

        for ($page = 1; $page <= $pageCount; $page++) {
            $templateId = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($templateId);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            $this->overlayTargetTextAreas($pdf, $size, $textPositions, $page);
        }

        $pdf->Output($outputPath, 'F');

        if (! is_file($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('生成的PDF文件为空');
        }

        $outputSize = filesize($outputPath);

        if ($originalSize > 0 && $outputSize < ($originalSize * 0.1)) {
            Log::warning('生成的PDF文件大小异常', [
                'original_size' => $originalSize,
                'output_size' => $outputSize,
            ]);
        }

        Log::info('PDF处理完成（已删除光度数据）', [
            'output_path' => $outputPath,
            'original_size' => $originalSize,
            'output_size' => $outputSize,
            'removed_targets' => count($textPositions),
        ]);

        return $outputPath;
    }

    /**
     * @return list<array{page: int, text: string, found_in_content: bool, estimated_position: array<string, mixed>}>
     */
    private function extractTargetTextPositions(string $sourcePath): array
    {
        try {
            $parserClass = self::PARSER_CLASS;
            $document = (new $parserClass)->parseFile($sourcePath);

            $foundPositions = [];

            foreach ($document->getPages() as $pageIndex => $page) {
                $pageNumber = $pageIndex + 1;

                try {
                    $text = $page->getText();
                } catch (\Throwable $exception) {
                    Log::warning('页面文本解析失败', ['page' => $pageNumber, 'error' => $exception->getMessage()]);

                    continue;
                }

                foreach (self::TARGET_TEXTS as $targetText) {
                    if (stripos($text, $targetText) === false) {
                        continue;
                    }

                    Log::info('发现目标文本', ['page' => $pageNumber, 'target' => $targetText]);

                    $foundPositions[] = [
                        'page' => $pageNumber,
                        'text' => $targetText,
                        'found_in_content' => true,
                        'estimated_position' => $this->estimatedPosition(),
                    ];
                }
            }

            return $foundPositions === [] ? $this->defaultPhotometricPositions() : $foundPositions;
        } catch (\Throwable $exception) {
            Log::error('PDF文本解析失败', ['error' => $exception->getMessage(), 'file' => $sourcePath]);

            return $this->defaultPhotometricPositions();
        }
    }

    /**
     * The parser reports text without glyph boxes, so the block position is a
     * fixed estimate tuned against the English report template.
     *
     * @return array<string, mixed>
     */
    private function estimatedPosition(): array
    {
        return [
            'x' => 0,
            'y' => 214,
            'height' => 15,
            'relative_position' => 0.85,
            'confidence' => 'default',
        ];
    }

    /**
     * @return list<array{page: int, text: string, found_in_content: bool, estimated_position: array<string, mixed>}>
     */
    private function defaultPhotometricPositions(): array
    {
        Log::info('使用默认光度数据位置');

        return [[
            'page' => 1,
            'text' => 'Photometric & Radiometric Parameters (default)',
            'found_in_content' => false,
            'estimated_position' => [
                'x' => 14,
                'y' => 214,
                'height' => 20,
                'relative_position' => 0.85,
                'confidence' => 'default',
            ],
        ]];
    }

    /**
     * @param  array<string, mixed>  $size
     * @param  list<array<string, mixed>>  $textPositions
     */
    private function overlayTargetTextAreas(mixed $pdf, array $size, array $textPositions, int $currentPage): void
    {
        try {
            $pageWidth = $size['width'];
            $pageHeight = $size['height'];

            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetDrawColor(255, 255, 255);

            $overlaidCount = 0;

            foreach ($textPositions as $position) {
                if ($position['page'] !== $currentPage) {
                    continue;
                }

                $estimated = $position['estimated_position'];
                $rectWidth = 120;
                $rectHeight = $estimated['height'];
                $rectX = max(10, min($estimated['x'], $pageWidth - $rectWidth - 10));
                $rectY = max(10, min($estimated['y'], $pageHeight - $rectHeight - 20)) + 18;

                if ($rectX >= 0 && $rectY >= 0
                    && $rectWidth > 0 && $rectHeight > 0
                    && ($rectX + $rectWidth) <= $pageWidth
                    && ($rectY + $rectHeight) <= $pageHeight
                ) {
                    $pdf->Rect($rectX, $rectY, $rectWidth, $rectHeight, 'F');
                    $overlaidCount++;
                }
            }

            // Nothing matched on the cover: fall back to the band the block
            // normally occupies rather than shipping the data through.
            if ($overlaidCount === 0 && $currentPage === 1) {
                Log::info('未找到具体文本位置，使用默认覆盖区域');

                $defaultX = $pageWidth * 0.1;
                $defaultY = $pageHeight * 0.8;
                $defaultWidth = $pageWidth * 0.8;
                $defaultHeight = $pageHeight * 0.15;

                if (($defaultX + $defaultWidth) <= $pageWidth && ($defaultY + $defaultHeight) <= $pageHeight) {
                    $pdf->Rect($defaultX, $defaultY, $defaultWidth, $defaultHeight, 'F');
                }
            }
        } catch (\Throwable $exception) {
            // Masking is best-effort; a failure must not abort the whole job.
            Log::error('文本区域覆盖失败', ['page' => $currentPage, 'error' => $exception->getMessage()]);
        }
    }
}
