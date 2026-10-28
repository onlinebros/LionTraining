<?php

namespace Tests\Feature\ProposalStudio;

use App\Http\Middleware\RequireProposalStudioToken;
use App\Mail\PortalActivity;
use App\Mail\PortalAnswered;
use App\Mail\PortalInvitation;
use App\Models\PortalSubmission;
use App\Models\ProductPartnerAssignment;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\StudioJob;
use App\Services\ProposalStudio\Library;
use App\Services\ProposalStudio\PortalAssistant;
use App\Services\ProposalStudio\ProposalService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** Phases 2 to 4: proposals, the vendor's review, the client portal, and the relay to live. */
class ProposalPortalTest extends StudioTestCase
{
    private function draftedProposal(Prospect $prospect, array $claimIds = []): Proposal
    {
        $this->actingAs($this->super)->post("/admin/proposal-studio/prospects/{$prospect->id}/proposals")->assertRedirect();
        $ids = json_encode($claimIds);
        $this->runnerAnswering(<<<TXT
```proposal
{"headline": "Cleaner air for Bright Smile", "intro": "Thanks, Dana.", "situation": "A four-chair practice.", "plan": [{"site": "Main office", "product": "PRO In-Duct", "quantity": "1", "rationale": "One rooftop unit."}], "how_it_works": "It sits in the duct.", "benefits": [{"title": "Quiet", "text": "No noise."}], "claim_ids": {$ids}, "pricing": "", "next_steps": "Book a survey."}
```
TXT);
        $this->work();

        return $prospect->proposals()->firstOrFail();
    }

    private function publishedProposal(): array
    {
        config(['proposal-studio.vendor_review_first' => 0]);
        $prospect = $this->prospect();
        $this->runnerAnswering('Reply.');
        $this->work();
        $proposal = $this->draftedProposal($prospect);
        app(ProposalService::class)->publish($proposal, $this->super);

        return [$prospect->fresh(), $proposal->fresh()];
    }

    private function signedInPortal(Prospect $prospect): string
    {
        Mail::fake();
        $this->actingAs($this->super)->post("/admin/proposal-studio/prospects/{$prospect->id}/invites", ['email' => 'Dana@BrightSmile.test', 'name' => 'Dana'])->assertSessionHas('success');
        $token = null;
        Mail::assertSent(PortalInvitation::class, function (PortalInvitation $mail) use (&$token) {
            $token = $mail->token;

            return $mail->hasTo('dana@brightsmile.test');
        });
        auth()->logout();
        $this->get("/proposal/{$prospect->portal_ref}/enter/{$token}")->assertRedirect("/proposal/{$prospect->portal_ref}");

        return $token;
    }

    public function test_a_drafted_proposal_keeps_only_approved_claims_and_renders_their_exact_wording(): void
    {
        $doc = $this->document('Reduces airborne mold by 99.4%.* *Independent lab.');
        $approved = $this->approvedClaim($doc, 'Reduces airborne mold by 99.4%.*');
        $prospect = $this->prospect();
        $this->runnerAnswering('Reply.');
        $this->work();

        $proposal = $this->draftedProposal($prospect, [$approved->id, 999]);

        $this->assertSame([$approved->id], $proposal->content['claim_ids']);
        $this->assertSame('Cleaner air for Bright Smile', $proposal->content['headline']);
        $this->get("/admin/proposal-studio/prospects/{$prospect->id}/proposals/{$proposal->id}/preview")
            ->assertOk()->assertSee('Reduces airborne mold by 99.4%.*')->assertSee('Main office');
    }

    public function test_the_first_proposals_go_to_the_vendor_and_are_published_when_they_approve(): void
    {
        config(['proposal-studio.vendor_review_first' => 1]);
        $vendor = $this->user(Role::PRODUCT_PARTNER);
        ProductPartnerAssignment::create(['user_id' => $vendor->id, 'vendor' => 'plasmaguard', 'product_key' => ProductPartnerAssignment::ALL_PRODUCTS]);
        $prospect = $this->prospect();
        $this->runnerAnswering('Reply.');
        $this->work();
        $proposal = $this->draftedProposal($prospect);

        $this->actingAs($this->super)->post("/admin/proposal-studio/prospects/{$prospect->id}/proposals/{$proposal->id}/publish")->assertSessionHas('success');
        $this->assertSame('in_review', $proposal->fresh()->status);
        $this->assertNull($prospect->fresh()->publishedProposal);

        // The vendor asks for changes, then approves the next round.
        $this->actingAs($vendor)->get('/product-partner/proposals')->assertOk()->assertSee('Bright Smile Dental');
        $this->post("/product-partner/proposals/{$proposal->id}/review", ['decision' => 'changes'])->assertSessionHasErrors('note');
        $this->post("/product-partner/proposals/{$proposal->id}/review", ['decision' => 'changes', 'note' => 'Use our 2026 handout wording.'])->assertRedirect();
        $this->assertSame('draft', $proposal->fresh()->status);

        $this->actingAs($this->super)->post("/admin/proposal-studio/prospects/{$prospect->id}/proposals/{$proposal->id}/publish");
        $this->actingAs($vendor)->post("/product-partner/proposals/{$proposal->id}/review", ['decision' => 'approve'])->assertRedirect();

        $proposal->refresh();
        $this->assertSame('published', $proposal->status);
        $this->assertSame('approved', $proposal->vendor_review);
        $this->assertNotNull($prospect->fresh()->portal_ref);
        $this->assertSame('proposal', $prospect->fresh()->status);

        // The review period is over: the next one publishes straight away.
        $next = app(ProposalService::class)->newVersion($proposal, $this->super);
        $this->assertSame(2, $next->version);
        $this->assertFalse(app(ProposalService::class)->needsVendorReview($next));
        app(ProposalService::class)->publish($next, $this->super);
        $this->assertSame('superseded', $proposal->fresh()->status);

        // D6: once published, the vendor sees the prospect's details.
        $this->actingAs($vendor)->get("/product-partner/proposals/{$next->id}")->assertOk()->assertSee('dana@brightsmile.test');
    }

    public function test_a_vendor_sees_no_draft_and_no_other_vendor_s_proposals(): void
    {
        $vendor = $this->user(Role::PRODUCT_PARTNER);
        ProductPartnerAssignment::create(['user_id' => $vendor->id, 'vendor' => 'plasmaguard', 'product_key' => ProductPartnerAssignment::ALL_PRODUCTS]);
        $prospect = $this->prospect();
        $this->runnerAnswering('Reply.');
        $this->work();
        $proposal = $this->draftedProposal($prospect);

        $this->actingAs($vendor)->get("/product-partner/proposals/{$proposal->id}")->assertNotFound();

        $this->plasmaguard->update(['vendor_key' => null]);
        $proposal->forceFill(['status' => 'published'])->save();
        $this->actingAs($vendor)->get("/product-partner/proposals/{$proposal->id}")->assertNotFound();
    }

    public function test_only_invited_people_can_open_the_portal(): void
    {
        [$prospect] = $this->publishedProposal();
        $ref = $prospect->portal_ref;

        auth()->logout();
        $this->get("/proposal/{$ref}")->assertOk()->assertSee('Open your proposal')->assertDontSee('Cleaner air for Bright Smile');
        $this->get("/proposal/{$ref}/enter/not-a-token")->assertRedirect("/proposal/{$ref}");

        $token = $this->signedInPortal($prospect);
        $this->get("/proposal/{$ref}")->assertOk()->assertSee('Cleaner air for Bright Smile')->assertSee('Signed in as dana@brightsmile.test');
        $this->assertDatabaseHas('portal_events', ['prospect_id' => $prospect->id, 'type' => 'viewed']);

        // Removing the invite closes the portal at once.
        $this->actingAs($this->super)->delete("/admin/proposal-studio/prospects/{$prospect->id}/invites/{$prospect->invites()->first()->id}");
        auth()->logout();
        $this->get("/proposal/{$ref}")->assertSee('Open your proposal');
        $this->get("/proposal/{$ref}/enter/{$token}")->assertRedirect("/proposal/{$ref}")->assertSessionHas('error');

        // An expired link does not work either.
        $prospect->invites()->first()->forceFill(['revoked_at' => null])->save();
        $fresh = $prospect->invites()->first()->issueToken();
        $this->travel(15)->days();
        $this->get("/proposal/{$ref}/enter/{$fresh}")->assertSessionHas('error');
    }

    public function test_asking_for_a_new_link_says_the_same_thing_either_way(): void
    {
        [$prospect] = $this->publishedProposal();
        $this->signedInPortal($prospect);
        Mail::fake();
        $this->travel(5)->minutes();

        $this->post("/proposal/{$prospect->portal_ref}/link", ['email' => 'stranger@example.com'])->assertSessionHas('success');
        Mail::assertNothingSent();
        $this->post("/proposal/{$prospect->portal_ref}/link", ['email' => 'dana@brightsmile.test'])->assertSessionHas('success');
        Mail::assertSent(PortalInvitation::class);
    }

    public function test_details_and_files_from_the_portal_reach_the_conversation_and_the_admin(): void
    {
        Storage::fake('local');
        [$prospect] = $this->publishedProposal();
        $this->signedInPortal($prospect);
        Mail::fake();

        $this->post("/proposal/{$prospect->portal_ref}/send", [
            'kind' => 'details',
            'fields' => ['HVAC' => 'Two rooftop units', 'Timeline' => ''],
            'body' => 'We also have a lab in back.',
            'files' => [UploadedFile::fake()->image('rtu.jpg')],
        ])->assertRedirect();

        $submission = PortalSubmission::firstOrFail();
        $this->assertSame(['HVAC' => 'Two rooftop units'], $submission->fields);
        Storage::disk('local')->assertExists($submission->files[0]['path']);
        $copied = $prospect->messages()->where('role', 'prospect')->firstOrFail();
        $this->assertStringContainsString('HVAC: Two rooftop units', $copied->body);
        $this->assertStringContainsString('Attached: rtu.jpg', $copied->body);
        Mail::assertSent(PortalActivity::class, fn ($m) => $m->hasTo($this->super->email));

        $this->actingAs($this->super)->get("/admin/proposal-studio/prospects/{$prospect->id}/submissions/{$submission->id}/files/0")->assertOk();
    }

    public function test_without_an_api_key_questions_wait_for_the_admin_who_answers_by_email(): void
    {
        [$prospect] = $this->publishedProposal();
        $this->signedInPortal($prospect);
        Mail::fake();

        $this->post("/proposal/{$prospect->portal_ref}/ask", ['question' => 'How long does installation take?'])->assertRedirect();
        $question = PortalSubmission::where('kind', 'question')->firstOrFail();
        $this->assertSame('pending', $question->answered_by);
        $this->assertTrue($question->isWaitingForAnswer());
        Mail::assertSent(PortalActivity::class);

        $this->actingAs($this->super)->get("/admin/proposal-studio/prospects/{$prospect->id}")->assertSee('1 question to answer');
        $this->post("/admin/proposal-studio/prospects/{$prospect->id}/questions/{$question->id}/answer", ['answer' => 'About half a day.'])->assertSessionHas('success');

        $this->assertSame('admin', $question->fresh()->answered_by);
        Mail::assertSent(PortalAnswered::class, fn ($m) => $m->hasTo('dana@brightsmile.test'));
        $this->get("/admin/proposal-studio/offerings/{$this->plasmaguard->id}?tab=gaps")->assertSee('How long does installation take?');
    }

    public function test_the_portal_assistant_hands_off_unless_it_answers_in_the_expected_form(): void
    {
        $this->assertSame('Yes, it is quiet.', PortalAssistant::parse("```answer\n{\"handoff\": false, \"answer\": \"Yes, it is quiet.\"}\n```"));
        $this->assertNull(PortalAssistant::parse("```answer\n{\"handoff\": true, \"answer\": \"\"}\n```"));
        $this->assertNull(PortalAssistant::parse('Sure! It cures everything.'));
        $this->assertFalse(PortalAssistant::available());
    }

    public function test_only_shareable_documents_can_be_downloaded_from_the_portal(): void
    {
        Storage::fake('local');
        [$prospect] = $this->publishedProposal();
        $library = app(Library::class);
        $internal = $library->addUpload($this->plasmaguard, UploadedFile::fake()->createWithContent('margins.txt', 'secret'), ['title' => 'Margins', 'kind' => 'pricing', 'audience' => 'internal']);
        $shared = $library->addUpload($this->plasmaguard, UploadedFile::fake()->createWithContent('handout.txt', 'public'), ['title' => 'Handout', 'kind' => 'brochure', 'audience' => 'shareable']);
        $this->signedInPortal($prospect);

        $this->get("/proposal/{$prospect->portal_ref}/documents/{$shared->id}")->assertOk();
        $this->get("/proposal/{$prospect->portal_ref}/documents/{$internal->id}")->assertNotFound();
    }

    public function test_the_relay_hands_live_jobs_to_dev_only_with_the_token(): void
    {
        $prospect = $this->prospect();
        $this->postJson('/api/proposal-studio/relay/claim')->assertNotFound();

        $token = 'relay-token-0123456789';
        SiteSetting::set(RequireProposalStudioToken::SETTING, hash('sha256', $token));
        $this->withToken('wrong')->postJson('/api/proposal-studio/relay/claim')->assertNotFound();

        $job = $this->withToken($token)->postJson('/api/proposal-studio/relay/claim')->assertOk()->json('job');
        $this->assertSame('prospect_reply', $job['kind']);
        $this->assertStringContainsString('Four-chair dental practice', $job['prompt']);
        $this->assertStringContainsString('Quantum 3 Solution', $job['system']);
        $this->withToken($token)->postJson('/api/proposal-studio/relay/claim')->assertJsonPath('job', null);

        $this->withToken($token)->postJson("/api/proposal-studio/relay/{$job['id']}/complete", ['text' => 'What HVAC do they have?'])->assertOk()->assertJsonPath('status', 'done');
        $this->assertSame('What HVAC do they have?', $prospect->messages()->get()->last()->body);
        $this->withToken($token)->postJson("/api/proposal-studio/relay/{$job['id']}/complete", ['text' => 'again'])->assertStatus(409);
        $this->assertSame(StudioJob::DONE, StudioJob::find($job['id'])->status);
    }
}
