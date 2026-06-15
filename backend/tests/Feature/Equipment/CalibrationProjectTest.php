<?php

namespace Tests\Feature\Equipment;

use App\Models\CalibrationProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CalibrationProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_manager_can_manage_calibration_projects_and_preview_labels(): void
    {
        Carbon::setTestNow('2026-06-15 12:17:08');
        $manager = $this->userWithPermissions([
            'calibration_projects.read',
            'calibration_projects.create',
            'calibration_projects.update',
            'calibration_projects.delete',
            'calibration_project_labels.print',
        ]);

        $projectId = $this->postJsonAs($manager, '/api/calibration-projects', [
            'project_no' => 'CP-001',
            'project_name' => '积分球定标',
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        $this->getJsonAs($manager, '/api/calibration-projects')
            ->assertOk()
            ->assertJsonPath('data.0.created_at', '2026-06-15 12:17:08')
            ->assertJsonPath('data.0.updated_at', '2026-06-15 12:17:08');

        $this->postJsonAs($manager, "/api/calibration-projects/{$projectId}", [
            'project_no' => 'CP-001',
            'project_name' => '积分球定标修订',
        ], 'PUT')->assertOk()->assertJsonPath('data.project_name', '积分球定标修订');

        $this->getJsonAs($manager, '/api/calibration-projects')
            ->assertOk()
            ->assertJsonPath('data.0.project_no', 'CP-001');

        $this->postJsonAs($manager, '/api/calibration-project-labels/preview', [
            'project_ids' => [$projectId],
            'label_width_mm' => 40,
            'label_height_mm' => 60,
        ])->assertOk()
            ->assertJsonPath('data.0.project_no', 'CP-001')
            ->assertJsonPath('data.0.qr_text', 'CP-001');

        $this->deleteJsonAs($manager, "/api/calibration-projects/{$projectId}")->assertOk();
        $this->assertDatabaseHas('calibration_projects', ['id' => $projectId, 'status' => 'disabled']);
    }

    public function test_read_permission_is_required(): void
    {
        $viewer = $this->userWithPermissions([]);
        CalibrationProject::query()->create(['project_no' => 'CP-002', 'project_name' => '色温定标']);

        $this->getJsonAs($viewer, '/api/calibration-projects')->assertForbidden();
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'calibration_project_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function postJsonAs(User $user, string $uri, array $data = [], string $method = 'POST')
    {
        Sanctum::actingAs($user);

        return $this->json($method, $uri, $data);
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
}
