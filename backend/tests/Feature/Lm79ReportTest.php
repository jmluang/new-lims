<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Lm79Report;
use App\Models\PdfDocument;
use App\Models\Sample;
use App\Models\TestOrder;
use App\Models\User;
use App\Services\Pdf\PdfDocumentDraftService;
use App\Services\Pdf\PdfRendererClient;
use App\Services\Pdf\PdfRendererHttpException;
use App\Services\Reports\Lm79Fields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class Lm79ReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('inspection_media');
        Storage::fake('pdf');
        $role = Role::create(['name' => 'report_editor', 'guard_name' => 'web', 'status' => 'active']);
        $role->givePermissionTo(collect(['lm79_reports.read', 'lm79_reports.create', 'lm79_reports.update', 'lm79_reports.delete', 'lm79_reports.print', 'pdf.workflow.create'])->map(fn ($p) => Permission::findOrCreate($p, 'web')));
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function sample(string $number = 'S-001'): Sample
    {
        $order = TestOrder::first() ?? TestOrder::create(['order_no' => 'ORDER-001', 'contract_no' => 'CONTRACT-001', 'order_date' => '2026-10-04', 'urgency' => 'normal', 'client_company' => 'A&B Lighting', 'client_address' => 'Client Road', 'manufacturer_company' => 'Factory', 'sample_status' => 'received']);

        return Sample::create(['test_order_id' => $order->id, 'sample_no' => $number, 'sample_name' => 'Test lamp', 'model' => 'L-30', 'power' => '30W', 'quantity' => 1, 'status' => 'pending', 'current_holder' => '样品室', 'received_date' => '2026-10-03']);
    }

    private function payload(Sample $sample, string $number = 'REPORT-001'): array
    {
        return ['sample_id' => $sample->id, 'report_number' => $number, 'values' => array_replace(Lm79Fields::defaults(), ['lab_name' => 'A&B Laboratory', 'product_name' => 'Test lamp', 'model' => 'L-30', 'power' => '30', 'total_flux' => '300']), 'standards' => ['ANSI/IES LM-79-19'], 'equipment' => [], 'retained_media_ids' => []];
    }

    public function test_sample_options_resolve_commission_snapshots_and_keep_rated_power_separate(): void
    {
        $sample = $this->sample();
        $this->getJson('/api/lm79-reports/sample-options?sample_id='.$sample->id)->assertOk()
            ->assertJsonPath('data.0.data.values.applicant', 'A&B Lighting')
            ->assertJsonPath('data.0.data.values.rated_power', '30')
            ->assertJsonPath('data.0.data.values.power', '')
            ->assertJsonPath('data.0.data.values.test_date', '2026-10-03');
    }

    public function test_draft_preserves_manual_values_and_its_sample_snapshot(): void
    {
        $sample = $this->sample();
        $payload = $this->payload($sample);
        $id = $this->postJson('/api/lm79-reports', $payload)->assertCreated()->json('data.id');
        $sample->update(['sample_no' => 'RENAMED']);
        $payload['values']['total_flux'] = '450';
        $this->putJson('/api/lm79-reports/'.$id, $payload)->assertOk()->assertJsonPath('data.data.values.total_flux', '450')->assertJsonPath('data.sample_snapshot.sample_no', 'S-001');
        $payload['sample_id'] = $this->sample('S-002')->id;
        $this->putJson('/api/lm79-reports/'.$id, $payload)->assertConflict();
    }

    public function test_report_number_uniqueness_uses_the_existing_normalization_rule(): void
    {
        $sample = $this->sample();
        $this->postJson('/api/lm79-reports', $this->payload($sample))->assertCreated();
        $this->postJson('/api/lm79-reports', $this->payload($sample, 'report-001'))->assertUnprocessable();
    }

    public function test_report_numbers_follow_the_reference_format_and_survive_updates_and_deletion(): void
    {
        $this->travelTo(now('Asia/Shanghai')->setDate(2026, 10, 4)->setTime(23, 59));
        $sample = $this->sample();
        $payload = $this->payload($sample, '');
        $first = $this->postJson('/api/lm79-reports', $payload)->assertCreated()
            ->assertJsonPath('data.report_number', 'XPD20261004-001')->json('data.id');
        unset($payload['report_number']);
        $this->postJson('/api/lm79-reports', $payload)->assertCreated()->assertJsonPath('data.report_number', 'XPD20261004-002');
        $this->putJson('/api/lm79-reports/'.$first, $payload)->assertOk()->assertJsonPath('data.report_number', 'XPD20261004-001');
        $this->deleteJson('/api/lm79-reports/'.$first)->assertNoContent();
        $this->postJson('/api/lm79-reports', $payload)->assertCreated()->assertJsonPath('data.report_number', 'XPD20261004-003');
        $this->travel(2)->minutes();
        $this->postJson('/api/lm79-reports', $payload)->assertCreated()->assertJsonPath('data.report_number', 'XPD20261005-001');
    }

    public function test_generated_report_numbers_skip_existing_report_and_pdf_numbers(): void
    {
        $this->travelTo(now('Asia/Shanghai')->setDate(2026, 10, 4)->setTime(12, 0));
        $sample = $this->sample();
        $this->postJson('/api/lm79-reports', $this->payload($sample, 'xpd20261004-001'))->assertCreated();
        PdfDocument::create(['document_uuid' => (string) Str::uuid(), 'document_public_id' => 'REPORT-002', 'organization_scope' => 'default', 'authoritative_report_number' => 'XPD20261004-002', 'normalized_report_number' => 'XPD20261004-002', 'status' => 'draft', 'created_by_id' => auth()->id()]);
        $this->postJson('/api/lm79-reports', $this->payload($sample, ''))->assertCreated()->assertJsonPath('data.report_number', 'XPD20261004-003');
    }

    public function test_numbers_are_allocated_before_saving_and_editing_does_not_allocate_again(): void
    {
        $this->travelTo(now('Asia/Shanghai')->setDate(2026, 10, 4)->setTime(12, 0));
        $number = $this->postJson('/api/lm79-reports/report-number')->assertOk()
            ->assertJsonPath('data.report_number', 'XPD20261004-001')->json('data.report_number');
        $this->postJson('/api/lm79-reports/report-number')->assertOk()->assertJsonPath('data.report_number', 'XPD20261004-002');
        $payload = $this->payload($this->sample(), $number);
        $id = $this->postJson('/api/lm79-reports', $payload)->assertCreated()->assertJsonPath('data.report_number', $number)->json('data.id');
        $this->getJson('/api/lm79-reports/'.$id)->assertOk()->assertJsonPath('data.report_number', $number);
        $this->putJson('/api/lm79-reports/'.$id, $payload)->assertOk()->assertJsonPath('data.report_number', $number);
        $this->assertDatabaseHas('lm79_report_sequences', ['date_key' => '2026-10-04', 'last_no' => 2]);
        $this->postJson('/api/lm79-reports/report-number')->assertOk()->assertJsonPath('data.report_number', 'XPD20261004-003');
    }

    public function test_number_allocation_requires_report_creation_permission(): void
    {
        Role::findByName('report_editor', 'web')->revokePermissionTo('lm79_reports.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->postJson('/api/lm79-reports/report-number')->assertForbidden();
        $this->assertDatabaseCount('lm79_report_sequences', 0);
    }

    public function test_list_searches_effective_report_details_and_returns_compact_summaries(): void
    {
        $payload = $this->payload($this->sample());
        $payload['values']['product_name'] = 'Precision spotlight';
        $payload['values']['model'] = 'MODEL-UPDATED';
        $payload['values']['applicant'] = 'Report Customer';
        $payload['values']['spectrum_data'] = "380 0.1\n780 1";
        $id = $this->postJson('/api/lm79-reports', $payload)->assertCreated()->json('data.id');
        foreach (['Precision', 'MODEL-UPDATED', 'Report Customer', 'S-001', 'ORDER-001'] as $search) {
            $this->getJson('/api/lm79-reports?search='.urlencode($search))->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id)
                ->assertJsonPath('data.0.product_name', 'Precision spotlight')
                ->assertJsonPath('data.0.applicant', 'Report Customer')
                ->assertJsonPath('data.0.spectrum_point_count', 2)->assertJsonMissingPath('data.0.data');
        }
        $this->getJson('/api/lm79-reports?status=draft')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/lm79-reports?status=submitted')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/lm79-reports?status=unknown')->assertUnprocessable();
    }

    public function test_deleted_sample_keeps_its_report_evidence_editable(): void
    {
        $sample = $this->sample();
        $payload = $this->payload($sample);
        $id = $this->postJson('/api/lm79-reports', $payload)->assertCreated()->json('data.id');
        $sample->delete();
        $payload['sample_id'] = null;
        $payload['values']['total_flux'] = '425';
        $this->putJson('/api/lm79-reports/'.$id, $payload)->assertOk()->assertJsonPath('data.sample_id', null)->assertJsonPath('data.sample_snapshot.sample_no', 'S-001')->assertJsonPath('data.data.values.total_flux', '425');
    }

    public function test_spectral_measurements_are_stored_in_rows_and_replaced_atomically(): void
    {
        $payload = $this->payload($this->sample());
        $payload['values']['spectrum_data'] = "450.5 1\n380 0.1\n950 0.2";
        $id = $this->postJson('/api/lm79-reports', $payload)->assertCreated()->json('data.id');
        $report = Lm79Report::findOrFail($id);
        $this->assertArrayNotHasKey('spectrum_data', $report->data['values']);
        $this->assertSame([380.0, 450.5, 950.0], $report->spectrumPoints->pluck('wavelength')->all());
        $this->getJson('/api/lm79-reports/'.$id)->assertJsonPath('data.data.values.spectrum_data', "380 0.1\n450.5 1\n950 0.2");
        $payload['values']['spectrum_data'] = "380 0.5\n780 0.8";
        $this->putJson('/api/lm79-reports/'.$id, $payload)->assertOk();
        $this->assertDatabaseCount('lm79_spectrum_points', 2);
        $payload['values']['spectrum_data'] = '380 broken';
        $this->putJson('/api/lm79-reports/'.$id, $payload)->assertUnprocessable();
        $this->assertDatabaseCount('lm79_spectrum_points', 2);
        $this->deleteJson('/api/lm79-reports/'.$id)->assertNoContent();
        $this->assertDatabaseCount('lm79_spectrum_points', 0);
    }

    public function test_pdf_payload_preserves_text_without_html_escaping_and_user_results(): void
    {
        $this->mock(PdfRendererClient::class, function ($mock) {
            $mock->shouldReceive('renderLm79Report')->once()->withArgs(function ($payload) {
                $this->assertSame('A&B Laboratory', $payload['labName']);
                $this->assertSame('300', collect($payload['sections'])->firstWhere('title', '光度参数')['rows'][0][1]);
                $this->assertStringNotContainsString('L12345', json_encode($payload['sections'][0]['rows'][0]));

                return true;
            })->andReturn('%PDF-1.4 example');
        });
        $id = $this->postJson('/api/lm79-reports', $this->payload($this->sample()))->assertCreated()->json('data.id');
        $this->get('/api/lm79-reports/'.$id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_attachments_are_private_and_cannot_be_grafted_between_reports(): void
    {
        $sample = $this->sample();
        $payload = $this->payload($sample);
        $payload['photos'] = [UploadedFile::fake()->image('sample.jpg')];
        $id = $this->post('/api/lm79-reports', $payload, ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $media = Lm79Report::findOrFail($id)->getFirstMedia('photos');
        $this->get('/api/lm79-reports/'.$id.'/media/'.$media->id)->assertOk();
        $other = $this->postJson('/api/lm79-reports', $this->payload($sample, 'REPORT-002'))->assertCreated()->json('data.id');
        $this->get('/api/lm79-reports/'.$other.'/media/'.$media->id)->assertNotFound();
        $payload = $this->payload($sample, 'REPORT-002');
        $payload['retained_media_ids'] = [$media->id];
        $this->putJson('/api/lm79-reports/'.$other, $payload)->assertUnprocessable();
    }

    public function test_invalid_rendering_content_returns_a_validation_error_and_does_not_freeze(): void
    {
        $this->mock(PdfRendererClient::class, fn ($mock) => $mock->shouldReceive('renderLm79Report')->once()->andThrow(new PdfRendererHttpException(422, '{"error":"Invalid PDF"}')));
        $id = $this->postJson('/api/lm79-reports', $this->payload($this->sample()))->assertCreated()->json('data.id');
        $this->getJson('/api/lm79-reports/'.$id.'/pdf')->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $this->assertNull(Lm79Report::findOrFail($id)->pdf_document_id);
    }

    public function test_rendered_attachment_budget_accounts_for_base64_request_overhead(): void
    {
        $payload = $this->payload($this->sample());
        $payload['pdf_gonio'] = UploadedFile::fake()->create('report.pdf', 16385, 'application/pdf');
        $this->post('/api/lm79-reports', $payload, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->assertDatabaseCount('lm79_reports', 0);
    }

    public function test_signing_submission_freezes_the_draft_and_reuses_its_source(): void
    {
        $this->mock(PdfRendererClient::class, function ($mock) {
            $mock->shouldReceive('renderLm79Report')->once()->andReturn('%PDF-1.4 report');
            $mock->shouldReceive('inspectSignaturePdf')->once()->andReturn(['encrypted' => false, 'signatureCount' => 0, 'docMdpPermission' => null, 'pageCount' => 3]);
        });
        $sample = $this->sample();
        $payload = $this->payload($sample);
        $id = $this->postJson('/api/lm79-reports', $payload)->assertCreated()->json('data.id');
        $first = $this->postJson('/api/lm79-reports/'.$id.'/signing-source')->assertOk()->json('data');
        $this->getJson('/api/lm79-reports?status=submitted')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.locked', true)->assertJsonPath('data.0.document_uuid', $first['document_uuid']);
        $this->postJson('/api/lm79-reports/'.$id.'/signing-source')->assertOk()->assertJsonPath('data.source_uuid', $first['source_uuid']);
        $this->assertSame(1, PdfDocument::count());
        $this->putJson('/api/lm79-reports/'.$id, $payload)->assertConflict();
        $this->deleteJson('/api/lm79-reports/'.$id)->assertConflict();
        $document = PdfDocument::firstOrFail();
        try {
            app(PdfDocumentDraftService::class)->rename($document, 'REPORT-RENAMED', auth()->user());
            $this->fail('A frozen generated report must retain its PDF identity.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('PDF_DOCUMENT_LINKED_LM79_REPORT', $e->getMessage());
        }
    }

    public function test_missing_permissions_deny_report_access(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/lm79-reports')->assertForbidden();
        $this->postJson('/api/lm79-reports', [])->assertForbidden();
    }

    public function test_equipment_lookup_and_save_resolve_the_existing_ledger_and_retain_its_snapshot(): void
    {
        $equipment = Equipment::create(['equipment_no' => 'EQ-REPORT-001', 'name' => 'Photometer', 'manufacturer' => 'Instrument Factory', 'model' => 'PM-30', 'serial_no' => 'SERIAL-001', 'status' => 'active', 'next_calibration_date' => '2027-10-04']);
        $this->getJson('/api/lm79-reports/equipment-lookup?code=EQ-REPORT-001')->assertOk()
            ->assertJsonPath('data.equipment_id', $equipment->id)->assertJsonPath('data.equipment_no', 'EQ-REPORT-001');
        $payload = $this->payload($this->sample());
        $payload['equipment'] = [['equipment_id' => $equipment->id, 'name' => 'Forged Name', 'model' => 'Wrong model', 'cal_cert' => 'CAL-001', 'cal_org' => 'Calibration Lab']];
        $response = $this->postJson('/api/lm79-reports', $payload)->assertCreated()
            ->assertJsonPath('data.data.equipment.0.name', 'Photometer')->assertJsonPath('data.data.equipment.0.model', 'PM-30')
            ->assertJsonPath('data.data.equipment.0.cal_due', '2027-10-04');
        $id = $response->json('data.id');
        $payload['equipment'] = $response->json('data.data.equipment');
        $equipment->update(['name' => 'Renamed Photometer', 'model' => 'PM-40']);
        $payload['equipment'][0]['name'] = 'Modified snapshot';
        $payload['equipment'][0]['cal_cert'] = 'CAL-UPDATED';
        $this->putJson('/api/lm79-reports/'.$id, $payload)->assertOk()->assertJsonPath('data.data.equipment.0.name', 'Photometer')->assertJsonPath('data.data.equipment.0.model', 'PM-30')->assertJsonPath('data.data.equipment.0.cal_cert', 'CAL-UPDATED');
        $equipment->delete();
        $this->putJson('/api/lm79-reports/'.$id, $payload)->assertOk()->assertJsonPath('data.data.equipment.0.serial', 'SERIAL-001');
    }

    public function test_missing_equipment_lookup_returns_an_actionable_message_without_model_details(): void
    {
        $response = $this->getJson('/api/lm79-reports/equipment-lookup?code=EQ-MISSING-001')
            ->assertNotFound()
            ->assertJsonPath('message', '未找到设备「EQ-MISSING-001」。请核对设备编号，或在设备管理中确认设备已登记。');
        $this->assertStringNotContainsString('App\\Models', $response->getContent());
        $response->assertJsonMissingPath('exception');
    }

    public function test_new_equipment_requires_a_ledger_link_and_cannot_duplicate_a_device(): void
    {
        $payload = $this->payload($this->sample());
        $payload['equipment'] = [['name' => 'Unlinked device']];
        $this->postJson('/api/lm79-reports', $payload)->assertUnprocessable();
        $equipment = Equipment::create(['equipment_no' => 'EQ-REPORT-002', 'name' => 'Spectrometer', 'status' => 'active']);
        $payload['equipment'] = [['equipment_id' => $equipment->id], ['equipment_id' => $equipment->id]];
        $this->postJson('/api/lm79-reports', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('lm79_reports', 0);
    }

    public function test_equipment_snapshot_cannot_be_copied_from_another_report(): void
    {
        $equipment = Equipment::create(['equipment_no' => 'EQ-REPORT-003', 'name' => 'Power meter', 'status' => 'active']);
        $sample = $this->sample();
        $first = $this->payload($sample);
        $first['equipment'] = [['equipment_id' => $equipment->id]];
        $response = $this->postJson('/api/lm79-reports', $first)->assertCreated();
        $second = $this->payload($sample, 'REPORT-002');
        $id = $this->postJson('/api/lm79-reports', $second)->assertCreated()->json('data.id');
        $second['equipment'] = $response->json('data.data.equipment');
        $this->putJson('/api/lm79-reports/'.$id, $second)->assertUnprocessable();
    }
}
