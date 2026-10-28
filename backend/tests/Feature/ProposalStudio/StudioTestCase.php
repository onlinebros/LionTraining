<?php

namespace Tests\Feature\ProposalStudio;

use App\Models\KnowledgeClaim;
use App\Models\Offering;
use App\Models\OfferingDocument;
use App\Models\Prospect;
use App\Models\Role;
use App\Models\User;
use App\Services\ProposalStudio\ClaudeRunner;
use App\Services\ProposalStudio\Library;
use App\Services\ProposalStudio\StudioService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class StudioTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $super;

    protected Offering $plasmaguard;

    protected function setUp(): void
    {
        parent::setUp();
        config(['proposal-studio.enabled' => true, 'proposal-studio.portal_ai.api_key' => null]);
        $this->seed(RoleSeeder::class);
        $this->super = $this->user(Role::SUPER_ADMIN);
        // Seeded by the migration.
        $this->plasmaguard = Offering::where('key', 'plasmaguard')->firstOrFail();
    }

    protected function user(string $role): User
    {
        $user = User::create(['name' => 'Test '.$role, 'email' => $role.'-'.uniqid().'@example.com', 'password' => 'password']);
        $user->forceFill(['role_id' => Role::findByName($role)->id, 'is_active' => true, 'billing_exempt' => true])->save();

        return $user->refresh();
    }

    protected function prospect(string $intake = 'Four-chair dental practice, about 2,400 sq ft, one rooftop unit.'): Prospect
    {
        return app(StudioService::class)->start($this->super, [
            'offering_id' => $this->plasmaguard->id,
            'company_name' => 'Bright Smile Dental',
            'industry' => 'Dental practice',
            'contact_name' => 'Dana Lee',
            'contact_email' => 'dana@brightsmile.test',
        ], $intake);
    }

    protected function document(string $text, array $attributes = []): OfferingDocument
    {
        return app(Library::class)->addText($this->plasmaguard, $text, $attributes + [
            'title' => 'Spec sheet', 'kind' => 'brochure', 'audience' => 'internal',
        ]);
    }

    protected function approvedClaim(OfferingDocument $document, string $quote): KnowledgeClaim
    {
        return app(Library::class)->addClaim($document, $quote, null, 'Test', $this->super);
    }

    /** The worker's CLI, answering every job with $texts in turn. */
    protected function runnerAnswering(string ...$texts): void
    {
        $runner = $this->createMock(ClaudeRunner::class);
        $runner->method('run')->willReturnOnConsecutiveCalls(...array_map(fn ($t) => ['text' => $t, 'meta' => ['model' => 'sonnet']], $texts));
        $this->app->instance(ClaudeRunner::class, $runner);
    }

    protected function work(int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->artisan('proposal-studio:work', ['--once' => true])->assertSuccessful();
        }
    }
}
