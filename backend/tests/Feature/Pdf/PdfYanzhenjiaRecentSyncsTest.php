<?php

namespace Tests\Feature\Pdf;

use App\Models\PdfFile;
use App\Models\PdfYanzhenjiaSync;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PdfYanzhenjiaRecentSyncsTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_syncs_show_the_latest_twenty_files_without_credentials(): void
    {
        Permission::findOrCreate('pdf_yanzhenjia_settings.read', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('pdf_yanzhenjia_settings.read');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        for ($index = 20; $index >= 0; $index--) {
            $file = PdfFile::query()->create([
                'file_id' => 'SYNC-'.$index,
                'file_name' => 'report-'.$index.'.pdf',
                'cover_report_number' => 'REPORT-'.$index,
                'sha256_hash' => hash('sha256', 'report-'.$index),
                'file_size' => 100,
                'created_by' => 'Operator',
            ]);
            $sync = PdfYanzhenjiaSync::query()->create([
                'pdf_file_id' => $file->id,
                'api_version' => 'v1',
                'target_appid' => str_repeat('a', 32),
                'request_payload' => [
                    'report_number' => $file->cover_report_number,
                    'sha256' => $file->sha256_hash,
                    'secret' => 'must-not-leak',
                ],
                'status' => $index === 0 ? 'succeeded' : 'failed',
                'last_error' => 'private diagnostic',
            ]);
            DB::table('pdf_yanzhenjia_syncs')->where('id', $sync->id)
                ->update(['updated_at' => now()->subMinutes($index)]);
        }

        $response = $this->getJson('/api/pdf/yanzhenjia-settings/recent-syncs')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.file_name', 'report-0.pdf')
            ->assertJsonPath('data.0.file_id', 'SYNC-0')
            ->assertJsonPath('data.0.report_number', 'REPORT-0')
            ->assertJsonPath('data.0.sha256', hash('sha256', 'report-0'))
            ->assertJsonPath('data.0.status', 'succeeded')
            ->assertJsonPath('data.1.status', 'failed');

        $this->assertNotEmpty($response->json('data.0.updated_at'));
        $this->assertNull(collect($response->json('data'))->firstWhere('file_id', 'SYNC-20'));
        $this->assertArrayNotHasKey('request_payload', $response->json('data.0'));
        $this->assertArrayNotHasKey('target_appid', $response->json('data.0'));
        $this->assertArrayNotHasKey('last_error', $response->json('data.0'));
    }

    public function test_recent_syncs_require_settings_read_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/pdf/yanzhenjia-settings/recent-syncs')
            ->assertForbidden()
            ->assertJsonPath('permission', 'pdf_yanzhenjia_settings.read');
    }
}
