<?php

namespace Tests\Feature\Standards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StandardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_standard_library_supports_crud_catalog_tree_and_items(): void
    {
        $admin = $this->userWithPermissions([
            'standards.read',
            'standards.create',
            'standards.update',
            'standards.delete',
            'standard_catalogs.read',
            'standard_catalogs.create',
            'standard_catalogs.update',
            'standard_catalogs.delete',
            'standard_items.read',
            'standard_items.create',
            'standard_items.update',
            'standard_items.delete',
        ]);

        $standardId = $this->postJsonAs($admin, '/api/standards', [
            'std_no' => 'GB/T 7000.1-2023',
            'chinese_name' => '灯具 第1部分：一般要求与试验',
            'publish_date' => '2023-01-01',
            'implement_date' => '2023-07-01',
            'status' => 'active',
            'category' => 'lighting',
            'language' => 'zh',
        ])->assertCreated()
            ->assertJsonPath('data.std_no', 'GB/T 7000.1-2023')
            ->json('data.id');

        $catalogId = $this->postJsonAs($admin, "/api/standards/{$standardId}/catalogs", [
            'code' => '4',
            'name' => '试验要求',
            'content' => '接地电阻、绝缘电阻、耐压测试',
            'sort_order' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.code', '4')
            ->json('data.id');

        $this->postJsonAs($admin, "/api/standards/{$standardId}/catalogs", [
            'parent_id' => $catalogId,
            'code' => '4.1',
            'name' => '接地电阻',
            'content' => '按标准条款执行',
            'sort_order' => 1,
        ])->assertCreated();

        $this->postJsonAs($admin, "/api/standards/{$standardId}/catalogs", [
            'name' => '缺少编号的目录',
            'content' => '必须返回校验错误',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->postJsonAs($admin, "/api/standards/{$standardId}/items", [
            'item_no' => 'I-001',
            'item_name' => '接地电阻',
            'requirement' => '符合标准要求',
            'unit' => 'Ω',
            'method' => 'GB/T 7000.1-2023',
        ])->assertCreated()
            ->assertJsonPath('data.item_no', 'I-001');

        $this->putJsonAs($admin, "/api/standards/{$standardId}", [
            'chinese_name' => '灯具 第1部分：一般要求与试验（更新）',
            'status' => 'pending',
        ])->assertOk()
            ->assertJsonPath('data.chinese_name', '灯具 第1部分：一般要求与试验（更新）')
            ->assertJsonPath('data.status', 'pending');

        $this->getJsonAs($admin, "/api/standards/{$standardId}")
            ->assertOk()
            ->assertJsonPath('data.std_no', 'GB/T 7000.1-2023')
            ->assertJsonCount(2, 'data.catalogs')
            ->assertJsonCount(1, 'data.items');

        $this->deleteJsonAs($admin, "/api/standards/{$standardId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $this->assertDatabaseHas('standards', ['id' => $standardId, 'status' => 'disabled']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'standards.create', 'subject_id' => (string) $standardId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'standards.update', 'subject_id' => (string) $standardId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'standards.delete', 'subject_id' => (string) $standardId]);
    }

    public function test_standard_list_filters_and_export_use_permissions(): void
    {
        $admin = $this->userWithPermissions([
            'standards.read',
            'standards.create',
            'standards.export',
        ]);

        $this->postJsonAs($admin, '/api/standards', [
            'std_no' => 'GB/T 7000.1-2023',
            'chinese_name' => '灯具 第1部分：一般要求与试验',
            'status' => 'active',
            'category' => 'lighting',
            'language' => 'zh',
        ])->assertCreated();

        $this->postJsonAs($admin, '/api/standards', [
            'std_no' => 'GB/T 1234-2026',
            'chinese_name' => '其他标准',
            'status' => 'pending',
            'category' => 'other',
            'language' => 'en',
        ])->assertCreated();

        $this->getJsonAs($admin, '/api/standards?search=7000&status=active&category=lighting&language=zh')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.std_no', 'GB/T 7000.1-2023');

        $this->getJsonAs($admin, '/api/standards/export?category=lighting')
            ->assertOk()
            ->assertJsonPath('headers.0', 'std_no')
            ->assertJsonPath('data.0.std_no', 'GB/T 7000.1-2023');
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_standards_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function postJsonAs(User $user, string $uri, array $data = [])
    {
        Sanctum::actingAs($user);

        return $this->postJson($uri, $data);
    }

    private function putJsonAs(User $user, string $uri, array $data = [])
    {
        Sanctum::actingAs($user);

        return $this->putJson($uri, $data);
    }

    private function deleteJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->deleteJson($uri);
    }

    private function getJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }
}
