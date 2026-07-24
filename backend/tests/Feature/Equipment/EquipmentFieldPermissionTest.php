<?php

namespace Tests\Feature\Equipment;

use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EquipmentFieldPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('equipment');
    }

    public function test_equipment_detail_hides_file_fields_without_file_read_permissions(): void
    {
        $manager = $this->userWithPermissions(['equipment.read']);
        $equipment = Equipment::query()->create([
            'equipment_no' => 'EQ-FILE',
            'name' => 'File Equipment',
            'manufacturer' => 'Acme',
            'model' => 'F1',
            'serial_no' => 'SER-FILE',
            'status' => 'active',
            'device_image' => 'private/device.jpg',
            'manual_files' => ['manual.pdf'],
            'instruction_files' => ['instruction.pdf'],
            'calibration_files' => ['calibration.pdf'],
            'other_files' => ['other.pdf'],
        ]);

        $response = $this->getJsonAs($manager, "/api/equipment/{$equipment->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'File Equipment')
            ->assertJsonPath('data.device_image', null)
            ->assertJsonPath('data.manual_files', null)
            ->assertJsonPath('data.instruction_files', null)
            ->assertJsonPath('data.calibration_files', null)
            ->assertJsonPath('data.other_files', null)
            ->assertJsonPath('meta.fields.device_image.read', false);

        $this->assertStringNotContainsString('private/device.jpg', $response->getContent());
        $this->assertStringNotContainsString('manual.pdf', $response->getContent());
    }

    public function test_equipment_delete_response_hides_file_fields_without_file_read_permissions(): void
    {
        $manager = $this->userWithPermissions(['equipment.delete']);
        $equipment = Equipment::query()->create([
            'equipment_no' => 'EQ-DELETE-FILE',
            'name' => 'Delete File Equipment',
            'status' => 'active',
            'device_image' => 'private/delete-device.jpg',
            'manual_files' => ['delete-manual.pdf'],
        ]);

        $response = $this->deleteJsonAs($manager, "/api/equipment/{$equipment->id}")
            ->assertOk()
            ->assertJsonPath('data.device_image', null)
            ->assertJsonPath('data.manual_files', null);

        $this->assertStringNotContainsString('private/delete-device.jpg', $response->getContent());
        $this->assertStringNotContainsString('delete-manual.pdf', $response->getContent());
    }

    public function test_equipment_file_download_requires_matching_field_read_permission(): void
    {
        Storage::disk('equipment')->put('manuals/manual.pdf', 'manual-bytes');
        $equipment = Equipment::query()->create([
            'equipment_no' => 'EQ-DOWNLOAD',
            'name' => 'Download Equipment',
            'status' => 'active',
            'manual_files' => ['equipment/manuals/manual.pdf'],
        ]);

        $this->getJsonAs(
            $this->userWithPermissions(['equipment.read']),
            "/api/equipment/{$equipment->id}/files/manual_files/0"
        )->assertForbidden();

        $response = $this->getJsonAs(
            $this->userWithPermissions(['equipment.read', 'equipment.field.manual_files.read']),
            "/api/equipment/{$equipment->id}/files/manual_files/0"
        )->assertOk()
            ->assertDownload('manual.pdf');

        $this->assertSame('manual-bytes', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_equipment_file_download_cannot_escape_the_equipment_directory(): void
    {
        Storage::disk('local')->put('backups/20260712-1/database.sql', 'top-secret-backup');

        $equipment = Equipment::query()->create([
            'equipment_no' => 'EQ-ESCAPE',
            'name' => 'Escape Equipment',
            'status' => 'active',
            'manual_files' => ['backups/20260712-1/database.sql'],
        ]);

        $response = $this->getJsonAs(
            $this->userWithPermissions(['equipment.read', 'equipment.field.manual_files.read']),
            "/api/equipment/{$equipment->id}/files/manual_files/0"
        )->assertNotFound();

        $this->assertStringNotContainsString('top-secret-backup', $response->getContent());
    }

    public function test_equipment_update_rejects_traversal_file_paths(): void
    {
        $equipment = Equipment::query()->create([
            'equipment_no' => 'EQ-TRAVERSAL',
            'name' => 'Traversal Equipment',
            'status' => 'active',
        ]);

        $this->putJsonAs(
            $this->userWithPermissions([
                'equipment.update',
                'equipment.field.manual_files.update',
            ]),
            "/api/equipment/{$equipment->id}",
            ['manual_files' => ['../../backups/database.sql']]
        )->assertStatus(422)->assertJsonValidationErrors('manual_files.0');
    }

    public function test_equipment_file_download_rejects_symlink_escape(): void
    {
        Storage::disk('local')->put('backups/20260712-2/database.sql', 'symlink-secret-backup');
        $linkPath = Storage::disk('equipment')->path('archive');
        $backupPath = dirname(Storage::disk('local')->path('backups/20260712-2/database.sql'));

        $this->assertTrue(symlink($backupPath, $linkPath));

        $equipment = Equipment::query()->create([
            'equipment_no' => 'EQ-SYMLINK',
            'name' => 'Symlink Equipment',
            'status' => 'active',
            'manual_files' => ['equipment/archive/database.sql'],
        ]);

        $response = $this->getJsonAs(
            $this->userWithPermissions(['equipment.read', 'equipment.field.manual_files.read']),
            "/api/equipment/{$equipment->id}/files/manual_files/0"
        )->assertNotFound();

        $this->assertStringNotContainsString('symlink-secret-backup', $response->getContent());
        unlink($linkPath);
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_equipment_field_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function getJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }

    private function deleteJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->deleteJson($uri);
    }

    private function putJsonAs(User $user, string $uri, array $data)
    {
        Sanctum::actingAs($user);

        return $this->putJson($uri, $data);
    }
}
