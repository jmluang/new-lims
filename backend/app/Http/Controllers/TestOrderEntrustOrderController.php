<?php

namespace App\Http\Controllers;

use App\Models\TestOrder;
use App\Services\Pdf\PdfRendererClient;
use App\Services\TestOrders\BuildEntrustOrderPdfPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TestOrderEntrustOrderController extends Controller
{
    public function show(
        Request $request,
        TestOrder $testOrder,
        BuildEntrustOrderPdfPayload $payloadBuilder,
        PdfRendererClient $pdfRendererClient,
    ) {
        $this->authorizePermission($request, 'test_orders.print', 'test_orders', $testOrder);

        try {
            $pdf = $pdfRendererClient->renderEntrustOrder($payloadBuilder->build($testOrder));
        } catch (RuntimeException $exception) {
            Log::error('Unable to generate entrust order PDF.', [
                'test_order_id' => $testOrder->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Unable to generate entrust order PDF.'], 502);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename='.$testOrder->order_no.'.pdf',
        ]);
    }
}
