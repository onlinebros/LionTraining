<?php

namespace Tests\Feature\Partner;

use App\Models\PartnerCompany;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders the admin's partner company screens.
 *
 * The board reached production calling spotCounts(fresh: true) after the
 * parameter had been removed, so every load was a 500 for whoever opened it.
 * Nothing rendered these pages, so nothing said so. These do.
 */
class PartnerCompanyPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private PartnerCompany $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->admin = User::create([
            'name'     => 'Partner Admin',
            'email'    => 'partner-admin@example.com',
            'password' => 'password',
        ]);

        $this->admin->forceFill([
            'role_id'        => Role::findByName(Role::SUPER_ADMIN)->id,
            'is_active'      => true,
            'billing_exempt' => true,
        ])->save();

        $this->company = PartnerCompany::create([
            'slug'            => 'acme',
            'name'            => 'Acme Group',
            'total_spots'     => 1_300_000,
            'unclaimed_spots' => 1_299_400,
        ]);
    }

    public function test_the_company_board_renders_with_its_counts(): void
    {
        $this->actingAs($this->admin->refresh())
            ->get('/admin/partners/companies')
            ->assertOk()
            ->assertSee('Acme Group')
            // Claimed is total minus unclaimed, formatted as the cards show it.
            ->assertSee('1,299,400')
            ->assertSee('600');
    }

    public function test_the_company_form_pages_render(): void
    {
        $this->actingAs($this->admin->refresh())
            ->get('/admin/partners/companies/create')
            ->assertOk();

        $this->actingAs($this->admin->refresh())
            ->get("/admin/partners/companies/{$this->company->slug}/edit")
            ->assertOk()
            ->assertSee('Acme Group');
    }
}
