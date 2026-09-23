<?php

namespace Tests\Feature;

use App\Models\ErrorLog;
use App\Models\Role;
use App\Models\User;
use App\Support\ErrorReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * What a member sees when something breaks.
 *
 * The rule these hold: an error is never a dead end. Whatever went wrong, the
 * page it produces has to offer a way back into the application, because the
 * alternative — a bare stack of text with no links on it — leaves somebody with
 * no option but to close the tab, and they do not come back to a tab they
 * closed.
 *
 * The 500 page carries a second promise: that the failure has already reached
 * us. That one is only kept when it is true, so both branches are pinned.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ErrorReference::forget();

        // The custom pages are what production renders. Laravel shows the
        // developer trace instead while debug is on, so these ask for the
        // production behaviour explicitly rather than depending on the test
        // environment happening to have it off.
        config(['app.debug' => false]);
    }

    private function member(): User
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $user = User::create([
            'name'     => 'Lost Member',
            'email'    => 'lost@example.com',
            'password' => 'password',
        ]);

        $user->forceFill([
            'role_id'        => Role::findByName(Role::PAID_MEMBER)->id,
            'is_active'      => true,
            'billing_exempt' => true,
        ])->save();

        return $user->refresh();
    }

    /** A route that fails the way an unhandled bug fails. */
    private function routeThatThrows(): void
    {
        Route::middleware('web')->get('/__throws', function () {
            throw new RuntimeException('A deliberate failure, from a test.');
        });
    }

    // ── 404 ───────────────────────────────────────────────────────────────────

    public function test_a_missing_page_offers_a_guest_the_way_back_in(): void
    {
        $response = $this->get('/this-route-does-not-exist');

        $response->assertStatus(404)
            ->assertSee('That page is not here')
            ->assertSee('Back to the home page')
            ->assertSee('Log in');
    }

    public function test_a_missing_page_offers_a_member_their_own_screens(): void
    {
        $response = $this->actingAs($this->member())->get('/this-route-does-not-exist');

        $response->assertStatus(404)
            ->assertSee('Go to my dashboard')
            ->assertSee('My Team')
            ->assertSee('Contact support');
    }

    public function test_a_missing_page_is_not_reported_as_a_fault(): void
    {
        // A mistyped URL is not an incident, and a board full of them is a board
        // nobody reads.
        $this->get('/this-route-does-not-exist')->assertStatus(404);

        $this->assertSame(0, ErrorLog::count());
    }

    // ── 500 ───────────────────────────────────────────────────────────────────

    public function test_a_failure_says_it_has_already_been_reported(): void
    {
        $this->routeThatThrows();

        $response = $this->get('/__throws');

        $response->assertStatus(500)
            ->assertSee('Something went wrong on our end')
            ->assertSee('The error has already been reported to our technical staff', false)
            ->assertSee('working to resolve it');
    }

    public function test_the_failure_it_reports_is_really_on_the_board(): void
    {
        $this->routeThatThrows();

        $this->get('/__throws')->assertStatus(500);

        $logged = ErrorLog::sole();

        $this->assertSame('A deliberate failure, from a test.', $logged->message);
        $this->assertSame('new', $logged->status);
        $this->assertStringContainsString('/__throws', $logged->url);
    }

    public function test_the_failure_page_quotes_the_reference_support_can_look_up(): void
    {
        $this->routeThatThrows();

        $response = $this->get('/__throws');

        // The number on the page is the row an admin opens, not a decoration.
        $response->assertSee('#' . ErrorLog::sole()->id, false);
    }

    public function test_a_failure_page_still_offers_a_way_back_in(): void
    {
        $this->routeThatThrows();

        $this->get('/__throws')
            ->assertStatus(500)
            ->assertSee('Back to the home page');
    }

    public function test_it_does_not_claim_a_report_it_could_not_make(): void
    {
        // What a database outage looks like from here: the page renders, but
        // nothing was written, so there is nothing to promise.
        ErrorReference::forget();

        $view = $this->view('errors.500');

        $view->assertSee('could not record the details automatically');
        $view->assertDontSee('has already been reported');
    }

    // ── The rest ──────────────────────────────────────────────────────────────

    public function test_an_expired_session_explains_itself_in_words(): void
    {
        $this->view('errors.419')
            ->assertSee('Your session expired')
            ->assertSee('Log in');
    }

    public function test_a_forbidden_page_explains_itself_in_words(): void
    {
        $this->view('errors.403')
            ->assertSee('not open to your account');
    }
}
