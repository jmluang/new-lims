<?php

namespace App\Services\Reports;

use App\Models\Lm79Report;
use App\Models\PdfDocument;
use Illuminate\Support\Facades\DB;

final class Lm79ReportNumber
{
    public function generate(): string
    {
        $date = now('Asia/Shanghai');
        $dateKey = $date->toDateString();
        $prefix = 'XPD'.$date->format('Ymd').'-';

        return DB::transaction(function () use ($dateKey, $prefix): string {
            // Insert acquires the daily row lock even when concurrent requests create it first.
            DB::table('lm79_report_sequences')->insertOrIgnore(['date_key' => $dateKey, 'last_no' => 0]);
            $sequence = DB::table('lm79_report_sequences')->where('date_key', $dateKey)->lockForUpdate()->first();
            $next = (int) $sequence->last_no;
            do {
                $number = $prefix.str_pad((string) ++$next, 3, '0', STR_PAD_LEFT);
            } while (Lm79Report::where('normalized_report_number', $number)->exists() || PdfDocument::where('normalized_report_number', $number)->exists());
            DB::table('lm79_report_sequences')->where('date_key', $dateKey)->update(['last_no' => $next]);

            return $number;
        }, 3);
    }
}
