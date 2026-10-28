<?php

namespace Tests\Feature\ProposalStudio;

use App\Mail\MicrositeActivity;
use App\Mail\MicrositeInvitation;
use App\Mail\MicrositeReply;
use App\Models\BusinessAccount;
use App\Models\MicrositeMember;
use App\Models\MicrositeSection;
use App\Models\PortalSubmission;
use App\Models\Proposal;
use App\Models\Role;
use App\Services\ProposalStudio\MicrositeService;
use App\Services\ProposalStudio\Prompts;
use App\Services\ProposalStudio\ProposalService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** B2B accounts, their microsites, and who may open them (memory-bank/b2b-accounts.md). */
class AccountMicrositeTest extends StudioTestCase
{
    private function account(): BusinessAccount
    {
        $prospect = $this->prospect();

        return $prospect->account()->firstOrFail();
    }

    private function publishedSection(BusinessAccount $account, string $kind = 'welcome', string $body = 'Welcome, Bright Smile.', array $extra = []): MicrositeSection
    {
        $section = app(MicrositeService::class)->addSection($account, $kind, $account->opportunities()->first());
        $section->forceFill(['body' => $body] + $extra)->save();
        app(MicrositeService::class)->publish($section, $this->super);

        return $section->fresh();
    }

    /** Invite someone and sign them in through their emailed link. */
    private function signIn(BusinessAccount $account, string $email, string $role = 'viewer', bool $pricing = false): MicrositeMember
    {
        Mail::fake();
        $this->actingAs($this->super)->post("/admin/proposal-studio/accounts/{$account->id}/members", [
            'email' => $email, 'name' => 'Dana', 'role' => $role, 'can_see_pricing' => $pricing ? '1' : null,
        ])->assertSessionHas('success');
        $token = $this->tokenSentTo($email);
        auth()->logout();
        $ref = $account->fresh()->site_ref;
        $this->get("/client/{$ref}/enter/{$token}")->assertRedirect("/client/{$ref}");

        return MicrositeMember::where('email', strtolower($email))->firstOrFail();
    }

    private function tokenSentTo(string $email): string
    {
        $token = null;
        Mail::assertSent(MicrositeInvitation::class, function (MicrositeInvitation $mail) use ($email, &$token) {
            if ($mail->hasTo(strtolower($email))) {
                $token = $mail->token;

                return true;
            }

            return false;
        });

        return $token;
    }

    public function test_a_new_prospect_gets_an_account_and_a_new_opportunity_joins_the_existing_one(): void
    {
        $account = $this->account();
        $this->assertSame('Bright Smile Dental', $account->name);
        $this->assertSame('engaged', $account->stage);
        $this->assertSame('dana@brightsmile.test', $account->contacts()->first()->email);
        $this->assertTrue($account->contacts()->first()->is_primary);

        $this->actingAs($this->super)->post("/admin/proposal-studio/accounts/{$account->id}/opportunities", [
            'offering_id' => $this->plasmaguard->id,
            'intake' => 'They have a second clinic opening in March.',
        ])->assertRedirect();

        $this->assertSame(1, BusinessAccount::count());
        $this->assertSame(2, $account->opportunities()->count());
        $this->actingAs($this->super)->get("/admin/proposal-studio/accounts/{$account->id}")->assertOk()->assertSee('Opportunity started');
    }

    public function test_an_account_can_be_created_by_hand_and_its_pages_render(): void
    {
        $this->actingAs($this->super)->get('/admin/proposal-studio/accounts/new')->assertOk();
        $this->post('/admin/proposal-studio/accounts', [
            'name' => 'Harbor Hotels', 'industry' => 'Hotel group', 'size' => '250–999', 'owner_user_id' => $this->super->id,
            'contact' => ['name' => 'Rae Ortiz', 'title' => 'VP Operations', 'email' => 'rae@harbor.test', 'role' => 'decision_maker'],
        ])->assertRedirect();
        $account = BusinessAccount::where('name', 'Harbor Hotels')->firstOrFail();
        $this->assertSame('target', $account->stage);
        $this->assertSame('decision_maker', $account->contacts()->first()->role);

        $this->post("/admin/proposal-studio/accounts/{$account->id}/activities", ['type' => 'task', 'body' => 'Send the walkthrough deck', 'due_on' => now()->subDay()->toDateString()])
            ->assertSessionHas('success');
        $this->get('/admin/proposal-studio/accounts')->assertOk()->assertSee('Send the walkthrough deck');
        $this->get("/admin/proposal-studio/accounts/{$account->id}")->assertOk()->assertSee('VP Operations')->assertSee('Build their microsite');

        // With no opportunity, the outline still starts, and the builder says why it is thin.
        $this->post("/admin/proposal-studio/accounts/{$account->id}/site/starter");
        $this->get("/admin/proposal-studio/accounts/{$account->id}/site")->assertOk()->assertSee('Welcome')->assertSee('Draft with AI');
        $this->get("/admin/proposal-studio/accounts/{$account->id}/site/preview")->assertOk()->assertSee('Harbor Hotels');
    }

    public function test_winning_an_opportunity_makes_the_account_a_customer(): void
    {
        $account = $this->account();
        $prospect = $account->opportunities()->first();

        $this->actingAs($this->super)->put("/admin/proposal-studio/prospects/{$prospect->id}", [
            'offering_id' => $this->plasmaguard->id, 'company_name' => 'Bright Smile Dental',
            'status' => 'won', 'outcome_note' => 'The owner liked that it is silent.',
        ])->assertSessionHas('success');

        $this->assertSame('customer', $account->fresh()->stage);
        $this->assertTrue($account->activities()->where('body', 'like', '%Customer%')->exists());
    }

    public function test_accounts_are_for_super_admins_only(): void
    {
        $account = $this->account();
        $admin = $this->user(Role::SUPPORT_ADMIN);

        $this->actingAs($admin)->get('/admin/proposal-studio/accounts')->assertForbidden();
        $this->actingAs($admin)->get("/admin/proposal-studio/accounts/{$account->id}/site")->assertForbidden();
        $this->actingAs($this->super)->get('/admin/proposal-studio/accounts')->assertOk()->assertSee('Bright Smile Dental');
    }

    public function test_the_assistant_drafts_a_section_keeping_only_approved_claims_for_its_offering(): void
    {
        $doc = $this->document('Reduces airborne mold by 99.4%.* *Independent lab.');
        $approved = $this->approvedClaim($doc, 'Reduces airborne mold by 99.4%.*');
        $account = $this->account();
        $this->runnerAnswering('Reply.');
        $this->work();

        $this->actingAs($this->super)->post("/admin/proposal-studio/accounts/{$account->id}/site/starter")->assertSessionHas('success');
        $evidence = $account->sections()->where('kind', 'evidence')->firstOrFail();
        $this->post("/admin/proposal-studio/accounts/{$account->id}/sections/{$evidence->id}/draft")->assertSessionHas('success');

        $this->runnerAnswering(<<<TXT
```section
{"title": "What the testing shows", "body": "Here is what the independent testing found.", "claim_ids": [{$approved->id}, 999]}
```
TXT);
        $this->work();

        $evidence->refresh();
        $this->assertSame('Here is what the independent testing found.', $evidence->body);
        $this->assertSame([$approved->id], $evidence->claim_ids);
        $this->assertSame('draft', $evidence->status);
        $this->get("/admin/proposal-studio/accounts/{$account->id}/site/preview")->assertOk()
            ->assertSee('Reduces airborne mold by 99.4%.*')->assertSee('Draft: not on the site yet');
    }

    public function test_a_section_about_the_product_needs_an_opportunity(): void
    {
        $account = $this->account();
        $section = app(MicrositeService::class)->addSection($account, 'evidence', null);

        $this->actingAs($this->super)->post("/admin/proposal-studio/accounts/{$account->id}/sections/{$section->id}/draft")
            ->assertSessionHas('error');
    }

    public function test_the_section_prompt_carries_what_we_learned_without_contact_details(): void
    {
        $other = $this->prospect();
        $other->update(['status' => 'lost', 'outcome_note' => 'Facilities worried about filter changes.', 'industry' => 'Gym']);
        PortalSubmission::create(['prospect_id' => $other->id, 'kind' => 'question', 'body' => 'Does it make noise?']);

        $account = $this->account();
        $section = app(MicrositeService::class)->addSection($account, 'faq', $account->opportunities()->first());
        $prompt = Prompts::micrositeSection($section);

        $this->assertStringContainsString('# WHAT WE HAVE LEARNED', $prompt);
        $this->assertStringContainsString('Lost (Gym): Facilities worried about filter changes.', $prompt);
        $this->assertStringContainsString('Does it make noise?', $prompt);
        $this->assertStringContainsString('# THE COMPANY', $prompt);
        $this->assertStringContainsString('Dana Lee (Other)', $prompt);
        // The roles of their people, not how to reach them.
        $this->assertStringNotContainsString('dana@brightsmile.test', Str::between($prompt, '# THE COMPANY', '# PROSPECT'));
    }

    public function test_nobody_can_be_invited_before_a_section_is_published(): void
    {
        Mail::fake();
        $account = $this->account();

        $this->actingAs($this->super)->post("/admin/proposal-studio/accounts/{$account->id}/members", ['email' => 'dana@brightsmile.test', 'role' => 'viewer'])
            ->assertSessionHas('error');
        Mail::assertNothingSent();
    }

    public function test_invited_people_see_only_published_sections_and_what_is_still_coming(): void
    {
        $account = $this->account();
        $this->publishedSection($account);
        $draft = app(MicrositeService::class)->addSection($account, 'solution', $account->opportunities()->first());
        $draft->forceFill(['title' => 'Our plan for your clinics', 'body' => 'SECRET DRAFT TEXT'])->save();
        $ref = $account->fresh()->site_ref;

        // Not signed in: the sign-in page, nothing more.
        $this->get("/client/{$ref}")->assertOk()->assertSee('Email me a new link')->assertDontSee('Welcome, Bright Smile.');

        $this->signIn($account, 'Dana@BrightSmile.test');
        $this->get("/client/{$ref}")->assertOk()
            ->assertSee('Welcome, Bright Smile.')
            ->assertSee('Still being prepared')->assertSee('Our plan for your clinics')
            ->assertDontSee('SECRET DRAFT TEXT');
        $this->assertTrue($account->activities()->where('type', 'client')->where('body', 'like', '%signed in%')->exists());

        // Hiding progress hides the titles too.
        $account->update(['site_show_progress' => false]);
        $this->get("/client/{$ref}")->assertDontSee('Our plan for your clinics');
    }

    public function test_a_link_works_only_for_its_own_site_and_not_once_removed(): void
    {
        $account = $this->account();
        $this->publishedSection($account);
        $otherAccount = $this->account();
        $this->publishedSection($otherAccount, 'welcome', 'Welcome, other company.');
        $otherRef = $otherAccount->fresh()->ensureSiteRef();

        Mail::fake();
        $member = app(MicrositeService::class)->invite($account, 'dana@brightsmile.test', null, 'viewer', false, $this->super);
        $token = $this->tokenSentTo('dana@brightsmile.test');

        $this->get("/client/{$otherRef}/enter/{$token}")->assertRedirect("/client/{$otherRef}");
        $this->get("/client/{$otherRef}")->assertDontSee('Welcome, other company.');

        $ref = $account->fresh()->site_ref;
        $this->get("/client/{$ref}/enter/{$token}");
        $this->get("/client/{$ref}")->assertSee('Welcome, Bright Smile.');

        app(MicrositeService::class)->revoke($member, $this->super);
        $this->get("/client/{$ref}")->assertDontSee('Welcome, Bright Smile.');
        $this->get("/client/{$ref}/enter/{$token}");
        $this->get("/client/{$ref}")->assertDontSee('Welcome, Bright Smile.');
    }

    public function test_pricing_is_shown_only_to_people_allowed_to_see_it(): void
    {
        config(['proposal-studio.vendor_review_first' => 0]);
        $account = $this->account();
        $prospect = $account->opportunities()->first();
        $proposal = Proposal::create(['prospect_id' => $prospect->id, 'version' => 1, 'status' => 'draft', 'content' => [
            'headline' => 'Cleaner air for Bright Smile', 'pricing' => 'About $4,200 installed.',
        ]]);
        app(ProposalService::class)->publish($proposal, $this->super);
        $this->publishedSection($account, 'proposal', '');
        $this->publishedSection($account, 'custom', 'Volume terms for the group.', ['title' => 'Group terms', 'pricing_only' => true]);
        $ref = $account->fresh()->site_ref;

        $this->signIn($account, 'facilities@brightsmile.test');
        $this->get("/client/{$ref}")->assertOk()->assertSee('Cleaner air for Bright Smile')
            ->assertDontSee('About $4,200 installed.')->assertDontSee('Volume terms for the group.');

        $this->signIn($account, 'finance@brightsmile.test', 'viewer', true);
        $this->get("/client/{$ref}")->assertSee('About $4,200 installed.')->assertSee('Volume terms for the group.');
    }

    public function test_a_member_can_ask_for_a_colleague_and_nothing_is_sent_until_an_admin_approves(): void
    {
        $account = $this->account();
        $this->publishedSection($account);
        $ref = $account->fresh()->site_ref;
        $this->signIn($account, 'dana@brightsmile.test');
        Mail::fake();

        $this->post("/client/{$ref}/colleagues", ['email' => 'Sam@BrightSmile.test', 'name' => 'Sam', 'note' => 'Runs our facilities'])
            ->assertSessionHas('success');
        Mail::assertNotSent(MicrositeInvitation::class);
        Mail::assertSent(MicrositeActivity::class, fn ($m) => $m->hasTo($this->super->email));
        $request = MicrositeMember::where('email', 'sam@brightsmile.test')->firstOrFail();
        $this->assertTrue($request->isPendingRequest());

        // A pending request cannot sign in, and cannot be asked for twice.
        $this->post("/client/{$ref}/colleagues", ['email' => 'sam@brightsmile.test'])->assertSessionHas('error');

        $this->actingAs($this->super)->post("/admin/proposal-studio/accounts/{$account->id}/members/{$request->id}/approve", ['role' => 'contributor'])
            ->assertSessionHas('success');
        $this->assertNotEmpty($this->tokenSentTo('sam@brightsmile.test'));
        $this->assertSame('contributor', $request->fresh()->role);
        $this->assertFalse($request->fresh()->can_see_pricing);
    }

    public function test_only_contributors_comment_and_our_reply_is_emailed(): void
    {
        $account = $this->account();
        $section = $this->publishedSection($account);
        $ref = $account->fresh()->site_ref;

        $this->signIn($account, 'viewer@brightsmile.test');
        $this->post("/client/{$ref}/sections/{$section->id}/comments", ['body' => 'Please mention our new clinic.'])->assertSessionHas('error');

        $this->signIn($account, 'dana@brightsmile.test', 'contributor');
        Mail::fake();
        $this->post("/client/{$ref}/sections/{$section->id}/comments", ['body' => 'Please mention our new clinic.'])->assertSessionHas('success');
        Mail::assertSent(MicrositeActivity::class);
        $comment = $section->comments()->firstOrFail();

        $this->actingAs($this->super)->post("/admin/proposal-studio/accounts/{$account->id}/comments/{$comment->id}/reply", ['reply' => 'Added it.'])
            ->assertSessionHas('success');
        Mail::assertSent(MicrositeReply::class, fn ($m) => $m->hasTo('dana@brightsmile.test'));
    }

    public function test_the_client_logo_must_be_a_raster_image_and_the_colour_a_hex_value(): void
    {
        Storage::fake('local');
        $account = $this->account();
        $url = "/admin/proposal-studio/accounts/{$account->id}/site/settings";

        $this->actingAs($this->super)->post($url, ['logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')])->assertSessionHasErrors('logo');
        $this->post($url, ['brand_color' => 'red; } body { display:none'])->assertSessionHasErrors('brand_color');

        $this->post($url, ['logo' => UploadedFile::fake()->image('logo.png', 200, 80), 'brand_color' => '#1A5F7A'])->assertSessionHas('success');
        $account->refresh();
        $this->assertSame('#1a5f7a', $account->brand_color);
        $this->assertSame('#ffffff', $account->brandInk());
        Storage::disk('local')->assertExists($account->logo_path);
    }
}
