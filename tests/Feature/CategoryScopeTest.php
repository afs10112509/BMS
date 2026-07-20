<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\User;
use App\Services\CategoryAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryScopeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchA = Branch::query()->create([
            'name' => 'Cabang A',
            'type' => 'konter',
            'status' => 'active',
        ]);

        $this->branchB = Branch::query()->create([
            'name' => 'Cabang B',
            'type' => 'konter',
            'status' => 'active',
        ]);
    }

    public function test_owner_can_create_global_and_local_categories(): void
    {
        $owner = User::factory()->owner()->create();
        Sanctum::actingAs($owner);

        $this->postJson('/api/categories', [
            'name' => 'Omzet Global',
            'type' => 'income',
            'branch_id' => null,
        ])->assertCreated();

        $this->postJson('/api/categories', [
            'name' => 'Biaya Lokal A',
            'type' => 'expense',
            'branch_id' => $this->branchA->id,
        ])->assertCreated();

        $this->assertDatabaseHas('categories', [
            'name' => 'Omzet Global',
            'branch_id' => null,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('categories', [
            'name' => 'Biaya Lokal A',
            'branch_id' => $this->branchA->id,
        ]);
    }

    public function test_admin_can_only_create_local_for_own_branch(): void
    {
        $admin = User::factory()->admin($this->branchA->id)->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/categories', [
            'name' => 'Lokal Admin',
            'type' => 'expense',
        ])->assertCreated()
            ->assertJsonPath('data.branch_id', $this->branchA->id);

        $this->postJson('/api/categories', [
            'name' => 'Coba Global',
            'type' => 'expense',
            'branch_id' => null,
        ])->assertForbidden();

        $this->postJson('/api/categories', [
            'name' => 'Coba Cabang Lain',
            'type' => 'expense',
            'branch_id' => $this->branchB->id,
        ])->assertForbidden();
    }

    public function test_admin_cannot_update_global_category(): void
    {
        $global = Category::query()->create([
            'branch_id' => null,
            'name' => 'Global Seed',
            'type' => 'income',
            'is_active' => true,
        ]);

        $admin = User::factory()->admin($this->branchA->id)->create();
        Sanctum::actingAs($admin);

        $this->putJson("/api/categories/{$global->id}", [
            'name' => 'Hacked',
        ])->assertForbidden();
    }

    public function test_unique_rules_for_global_and_local(): void
    {
        Category::query()->create([
            'branch_id' => null,
            'name' => 'Sama',
            'type' => 'expense',
            'is_active' => true,
        ]);

        Category::query()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Sama',
            'type' => 'expense',
            'is_active' => true,
        ]);

        $owner = User::factory()->owner()->create();
        Sanctum::actingAs($owner);

        $this->postJson('/api/categories', [
            'name' => 'Sama',
            'type' => 'expense',
            'branch_id' => null,
        ])->assertStatus(422);

        $this->postJson('/api/categories', [
            'name' => 'Sama',
            'type' => 'expense',
            'branch_id' => $this->branchA->id,
        ])->assertStatus(422);

        $this->postJson('/api/categories', [
            'name' => 'Sama',
            'type' => 'expense',
            'branch_id' => $this->branchB->id,
        ])->assertCreated();
    }

    public function test_category_availability_scopes_by_branch(): void
    {
        $global = Category::query()->create([
            'branch_id' => null,
            'name' => 'Global',
            'type' => 'income',
            'is_active' => true,
        ]);
        $localA = Category::query()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Lokal A',
            'type' => 'expense',
            'is_active' => true,
        ]);
        $localB = Category::query()->create([
            'branch_id' => $this->branchB->id,
            'name' => 'Lokal B',
            'type' => 'expense',
            'is_active' => true,
        ]);
        $inactive = Category::query()->create([
            'branch_id' => null,
            'name' => 'Mati',
            'type' => 'expense',
            'is_active' => false,
        ]);

        $svc = app(CategoryAvailability::class);

        $this->assertTrue($svc->isAllowed($this->branchA->id, $global->id));
        $this->assertTrue($svc->isAllowed($this->branchA->id, $localA->id));
        $this->assertFalse($svc->isAllowed($this->branchA->id, $localB->id));
        $this->assertFalse($svc->isAllowed($this->branchA->id, $inactive->id));
    }
}
