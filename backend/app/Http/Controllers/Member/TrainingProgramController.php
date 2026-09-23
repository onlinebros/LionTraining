<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Support\Opportunity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Training Program section: what it is, and how to get it.
 *
 * Three states, one page:
 *
 *   closed           Not on sale yet. The page says so and takes an expression
 *                    of interest. NOBODY is asked for a card anywhere in the
 *                    application while this is the state.
 *   open, not held   On sale. The page describes it and hands off to card
 *                    capture, which is where the enrollment options live.
 *   held             They already have it. The page points at Billing.
 *
 * The switch is `enrollment_open` on the line in config/opportunities.php, and
 * flipping it is the whole of "opening" the program.
 *
 * Lives outside the `subscribed` gate on purpose, like Billing: this is a page
 * about buying access, so it can never be behind having bought it.
 */
class TrainingProgramController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $line = Opportunity::membership();

        return view('member.training-program.show', [
            'user'      => $user,
            'line'      => $line,
            'open'      => $line->enrollmentOpen(),
            /*
            | Having BOUGHT it, not being on the line that sells it.
            |
            | Every account that predates the business lines sits on the
            | membership line by default, and almost none of them have paid for
            | anything. Asking hasOpportunity() here would tell all of them they
            | already have the training program, and hide the section from
            | exactly the people it exists for.
            */
            'held'      => $user->hasActiveMembership(),
            'interested' => $user->training_interest_at !== null,
            'amount'    => (int) config('stripe.subscription.amount'),
            'interval'  => (string) config('stripe.subscription.interval'),
            'threshold' => (float) config('stripe.subscription.commission_threshold'),
        ]);
    }

    /**
     * "Tell me when it opens."
     *
     * Idempotent and reversible: asking twice keeps the first timestamp, so the
     * list stays ordered by when somebody actually asked, and a partner can
     * take their name off again.
     */
    public function interest(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($request->boolean('remove')) {
            $user->forceFill(['training_interest_at' => null])->save();

            return back()->with('status', 'Taken off the list. You can add yourself again any time.');
        }

        if ($user->training_interest_at === null) {
            $user->forceFill(['training_interest_at' => now()])->save();
        }

        return back()->with('status', "You're on the list. We'll email you when the training program opens.");
    }
}
