<?php

namespace Tests\Feature\Pdf;

use App\Models\AuditLog;
use App\Models\DigitalSignature;
use App\Models\HomepageFunctionStamp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PdfAssetManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('pdf');
    }

    public function test_seal_can_be_created_replaced_downloaded_and_deleted(): void
    {
        Sanctum::actingAs($this->userWithPermissions([
            'pdf_digital_signatures.read',
            'pdf_digital_signatures.create',
            'pdf_digital_signatures.update',
            'pdf_digital_signatures.delete',
        ]));

        $id = $this->post('/api/pdf/digital-signatures', [
            'name' => '检测专用章',
            'signature_reason' => '报告签发',
            'is_default' => true,
            'image' => UploadedFile::fake()->image('seal.png'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $originalPath = DigitalSignature::query()->findOrFail($id)->appearance_image_path;
        Storage::disk('pdf')->assertExists($originalPath);

        $this->get("/api/pdf/digital-signatures/{$id}/file")->assertOk();

        $this->post("/api/pdf/digital-signatures/{$id}", [
            'name' => '检测专用章(新)',
            'image' => UploadedFile::fake()->image('seal-v2.png'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.name', '检测专用章(新)');

        $newPath = DigitalSignature::query()->findOrFail($id)->appearance_image_path;
        $this->assertNotSame($originalPath, $newPath);
        Storage::disk('pdf')->assertExists($newPath);
        Storage::disk('pdf')->assertMissing($originalPath);

        // Deleting removes the row and its stored image for good.
        $this->deleteJson("/api/pdf/digital-signatures/{$id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertFalse(DigitalSignature::query()->whereKey($id)->exists());
        Storage::disk('pdf')->assertMissing($newPath);
    }

    public function test_deleting_a_seal_is_recorded_in_the_audit_log(): void
    {
        $user = $this->userWithPermissions(['pdf_digital_signatures.create', 'pdf_digital_signatures.delete']);
        Sanctum::actingAs($user);

        $id = $this->post('/api/pdf/digital-signatures', [
            'name' => '待删除章',
            'image' => UploadedFile::fake()->image('seal.png'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->deleteJson("/api/pdf/digital-signatures/{$id}")->assertOk();

        // The configuration is gone, so the audit entry is the only remaining
        // explanation for documents signed with it.
        $audit = AuditLog::query()->where('action', 'pdf_digital_signatures.delete')->sole();
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame('待删除章', $audit->before_values['name']);
    }

    public function test_only_one_seal_stays_default(): void
    {
        Sanctum::actingAs($this->userWithPermissions([
            'pdf_digital_signatures.create',
            'pdf_digital_signatures.read',
        ]));

        $first = $this->post('/api/pdf/digital-signatures', [
            'name' => '章一',
            'is_default' => true,
            'image' => UploadedFile::fake()->image('a.png'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->post('/api/pdf/digital-signatures', [
            'name' => '章二',
            'is_default' => true,
            'image' => UploadedFile::fake()->image('b.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertFalse(DigitalSignature::query()->findOrFail($first)->is_default);
        $this->assertSame(1, DigitalSignature::query()->where('is_default', true)->count());
    }

    public function test_function_stamps_are_listed_in_sort_order(): void
    {
        HomepageFunctionStamp::query()->create(['name' => 'CNAS', 'image_path' => 'function-stamps/b.png', 'sort_order' => 2]);
        HomepageFunctionStamp::query()->create(['name' => 'CMA', 'image_path' => 'function-stamps/a.png', 'sort_order' => 1]);

        Sanctum::actingAs($this->userWithPermissions(['pdf_function_stamps.read']));

        $this->getJson('/api/pdf/function-stamps')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'CMA')
            ->assertJsonPath('data.1.name', 'CNAS');
    }

    public function test_managing_seals_requires_permission(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['pdf_digital_signatures.read']));

        $this->post('/api/pdf/digital-signatures', [
            'name' => '章',
            'image' => UploadedFile::fake()->image('a.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_pdf_assets_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
