<?php

namespace Tests\Feature\Partner;

use App\Models\PartnerCompany;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Database\Seeders\PartnerCompanySeeder;

/**
 * The first partner: iHub Global.
 *
 * Their claim page is a public, co-branded URL whose slug goes into iHub's own
 * mailings and whose logo is a file they gave us. These tests hold the parts
 * that would be embarrassing rather than merely broken: a dead logo on a page
 * carrying somebody else's brand, or a live claim page before the list is in.
 */
class IhubGlobalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => Role::FREE_MEMBER, 'display_name' => 'Free Member', 'is_admin' => false, 'level' => 1]);

        $this->seed(PartnerCompanySeeder::class);
    }

    private function ihub(): PartnerCompany
    {
        return PartnerCompany::where('slug', 'ihub')->firstOrFail();
    }

    public function test_the_seeder_creates_ihub_with_their_own_field_labels(): void
    {
        $ihub = $this->ihub();

        $this->assertSame('iHub Global', $ihub->name);
        // Their people know the credential by iHub's name for it.
        $this->assertSame('iHub User ID', $ihub->identifier_label);
    }

    public function test_the_logo_file_is_actually_there(): void
    {
        $ihub = $this->ihub();

        // A committed brand asset, not an upload — so the thing that breaks it
        // is somebody moving the file, which a path check catches and a URL
        // check does not.
        $this->assertFileExists(public_path($ihub->logo_dark_path));
        $this->assertNotNull($ihub->logoUrl(dark: true));
        $this->assertStringContainsString('partners/ihub/ihub-dark.png', $ihub->logoUrl(dark: true));
    }

    public function test_the_claim_page_is_off_until_somebody_turns_it_on(): void
    {
        // A live page with no spots behind it tells every iHub member their
        // details do not match. It goes live when the import is committed.
        $this->assertFalse($this->ihub()->is_active);
        $this->get(route('partner.claim', 'ihub'))->assertNotFound();
    }

    public function test_the_claim_page_is_co_branded_once_live(): void
    {
        $this->ihub()->update(['is_active' => true]);

        $this->get(route('partner.claim', 'ihub'))
            ->assertOk()
            ->assertSee('iHub Global')
            ->assertSee('iHub User ID')
            ->assertSee('partners/ihub/ihub-dark.png', false);
    }

    public function test_reseeding_does_not_flip_the_operational_switches(): void
    {
        $this->ihub()->update([
            'is_active'       => true,
            'webhook_enabled' => true,
            'webhook_url'     => 'https://api.ihub.example/quantum',
            'webhook_secret'  => 'a-secret-at-least-16-chars',
        ]);

        // A deploy runs the seeder again. Branding and wording should follow
        // the repo; switches somebody threw on purpose must not be thrown back.
        $this->seed(PartnerCompanySeeder::class);

        $ihub = $this->ihub();

        $this->assertTrue($ihub->is_active);
        $this->assertTrue($ihub->webhook_enabled);
        $this->assertSame('https://api.ihub.example/quantum', $ihub->webhook_url);
        $this->assertSame(1, PartnerCompany::where('slug', 'ihub')->count());
    }

    public function test_an_uploaded_logo_still_resolves_off_the_public_disk(): void
    {
        // The other kind of path these columns hold. Both have to work — see
        // PartnerCompany::logoUrl().
        Storage::fake('public');

        $company = PartnerCompany::create([
            'slug'      => 'uploaded',
            'name'      => 'Uploaded Co',
            'logo_path' => 'partner-logos/uploaded/logo.png',
        ]);

        $this->assertStringContainsString(
            'partner-logos/uploaded/logo.png',
            $company->logoUrl(dark: false),
        );
    }
}
