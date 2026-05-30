<?php

namespace App\Services\TestOrders;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderNumberService
{
    private const SALT = 'XPD_LIMS_2026';

    public function generate(?Carbon $date = null): string
    {
        $date ??= Carbon::now();
        $dateKey = $date->toDateString();

        $sequence = DB::transaction(function () use ($dateKey): int {
            $row = DB::table('test_order_sequences')
                ->where('date_key', $dateKey)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('test_order_sequences')->insert([
                    'date_key' => $dateKey,
                    'last_no' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return 1;
            }

            $next = ((int) $row->last_no) + 1;
            DB::table('test_order_sequences')
                ->where('date_key', $dateKey)
                ->update([
                    'last_no' => $next,
                    'updated_at' => now(),
                ]);

            return $next;
        });

        $month = $date->format('m');
        $day = $date->format('d');
        $base = $date->format('y')
            .$month[0]
            .$day[0]
            .str_pad((string) $sequence, 3, '0', STR_PAD_LEFT)
            .$month[1]
            .$day[1];

        return $base.$this->checkDigits($base);
    }

    public function checkDigits(string $base): string
    {
        $hash = md5($base.self::SALT);
        $digits = '';

        for ($i = strlen($hash) - 1; $i >= 0; $i--) {
            if (ctype_digit($hash[$i])) {
                $digits .= $hash[$i];

                if (strlen($digits) === 2) {
                    break;
                }
            }
        }

        return str_pad(strrev($digits), 2, '0', STR_PAD_LEFT);
    }
}
