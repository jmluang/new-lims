<?php

namespace App\Services\Pdf;

use App\Jobs\SyncPdfToYanzhenjia;
use App\Models\PdfFile;
use App\Models\PdfYanzhenjiaSync;
use Illuminate\Support\Facades\DB;

final class PdfYanzhenjiaSyncDispatcher
{
    public function enqueue(PdfFile $file): void
    {
        if (! config('services.yanzhenjia.enabled')) {
            return;
        }

        PdfYanzhenjiaSync::query()->firstOrCreate(['pdf_file_id' => $file->id]);
    }

    public function dispatchPending(int $limit = 100): int
    {
        if (! config('services.yanzhenjia.enabled')) {
            return 0;
        }

        return DB::transaction(function () use ($limit): int {
            $syncs = PdfYanzhenjiaSync::query()
                ->where(function ($query): void {
                    $query->where('status', 'pending')
                        ->orWhere(function ($query): void {
                            $query->whereIn('status', ['queued', 'running', 'failed'])
                                ->where('updated_at', '<', now()->subMinutes(30));
                        });
                })
                ->where('attempts', '<', 10)
                ->orderBy('id')
                ->limit(max(1, min($limit, 100)))
                ->lockForUpdate()
                ->get();

            foreach ($syncs as $sync) {
                $sync->update(['status' => 'queued']);
                SyncPdfToYanzhenjia::dispatch($sync->pdf_file_id)
                    ->onConnection('database')
                    ->afterCommit();
            }

            return $syncs->count();
        });
    }
}
