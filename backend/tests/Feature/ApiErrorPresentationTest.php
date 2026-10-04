<?php

namespace Tests\Feature;

use App\Models\Equipment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ApiErrorPresentationTest extends TestCase
{
    public function test_debug_api_errors_do_not_expose_exception_details(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/error-presentation-test', fn () => throw new RuntimeException('SQLSTATE[HY000] /var/www/private/config.php'));
        $this->getJson('/api/error-presentation-test')->assertStatus(500)
            ->assertExactJson(['message' => '本次操作出现异常，请先确认操作结果；持续异常请联系管理员。']);
    }

    public function test_missing_models_use_generic_record_guidance(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/error-presentation-test', fn () => throw (new ModelNotFoundException)->setModel(Equipment::class));
        $this->getJson('/api/error-presentation-test')->assertNotFound()
            ->assertExactJson(['message' => '未找到所需记录，请刷新后重试。']);
    }

    public function test_field_validation_messages_are_preserved(): void
    {
        Route::get('/api/error-presentation-test', fn () => request()->validate(['report_number' => 'required']));
        $this->getJson('/api/error-presentation-test')->assertUnprocessable()->assertJsonValidationErrors('report_number');
    }

    public function test_pdf_business_error_envelopes_are_preserved(): void
    {
        Route::get('/api/pdf/error-presentation-test', fn () => throw new HttpException(503, 'PDF_SOURCE_ENCRYPTED'));
        $this->getJson('/api/pdf/error-presentation-test')->assertStatus(503)
            ->assertExactJson(['error' => ['code' => 'PDF_SOURCE_ENCRYPTED', 'message' => 'PDF_SOURCE_ENCRYPTED']]);
    }
}
