<?php

namespace App\Services\Samples;

use App\Models\Sample;
use App\Models\TestOrder;

class SampleNumberService
{
    public function nextDeliverySequence(TestOrder $testOrder): int
    {
        return ((int) Sample::query()
            ->where('test_order_id', $testOrder->id)
            ->max('delivery_sequence')) + 1;
    }

    public function sampleNo(TestOrder $testOrder, int $deliverySequence, int $sampleIndex, int $deliveryReceivedCount): string
    {
        return "{$testOrder->order_no}-{$deliverySequence}-{$sampleIndex}/{$deliveryReceivedCount}";
    }
}
