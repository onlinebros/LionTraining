<?php

namespace App\Http\Controllers;

use App\Models\FunnelParticipant;
use App\Models\PresentationFunnel;
use App\Models\User;
use App\Services\Presentations\Conversation;
use App\Services\Presentations\FunnelJourney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The way into a funnel.
 *
 * One job: work out who this person is and whose they are, then hand them to
 * the ordinary presentation room for whichever video they should be on. Every
 * step after this is a normal showing, so there is nothing funnel-shaped in the
 * watching experience — only in how somebody gets to it and where they go next.
 */
class FunnelWatchController extends Controller
{
    public function __construct(
        private readonly FunnelJourney $journey,
        private readonly Conversation $conversation,
    ) {
    }

    /**
     * Land on a flow.
     *
     * A returning participant is sent straight back to where they got to. A new
     * one gets the registration form — and, exactly as for a single showing, is
     * refused outright without an inviting member's code. A prospect five
     * videos deep who belongs to nobody is worse than one who never started,
     * because by then somebody will be certain they own them.
     */
    public function enter(Request $request, PresentationFunnel $funnel, ?string $code = null)
    {
        abort_unless($funnel->is_active, 404);

        $host        = $this->resolveHost($code);
        $participant = $this->currentParticipant($request, $funnel);

        if ($participant) {
            return $this->sendToCurrentStep($participant);
        }

        if (! $funnel->isReady()) {
            abort(404, 'This flow has no first video yet.');
        }

        if (! $host) {
            return response()->view('presentations.invite-required', [
                'presentation' => $funnel->entry,
                'unknownCode'  => filled($code),
            ], 404);
        }

        return view('funnels.register', [
            'funnel' => $funnel,
            'host'   => $host,
            'code'   => $code,
        ]);
    }

    public function register(Request $request, PresentationFunnel $funnel): RedirectResponse
    {
        abort_unless($funnel->is_active && $funnel->isReady(), 404);

        $data = $request->validate([
            'name'  => 'required|string|max:120',
            'email' => 'required|email:rfc|max:190',
            'phone' => 'nullable|string|max:40',
            'code'  => 'required|string|max:40',
        ], [
            'name.required'  => 'Please tell us your name.',
            'email.required' => 'Please give an email address so we can send you what you ask for.',
            'code.required'  => 'This link is missing its invitation code.',
        ]);

        $host = $this->resolveHost($data['code']);

        // Checked again because the form posts the code back as a field, and a
        // field can be edited.
        if (! $host) {
            return back()->withInput()->with(
                'error',
                'That invitation link is not valid. Ask the person who invited you for a new one.'
            );
        }

        $participant = $this->journey->enter($funnel, $data, $host, $request);

        Cookie::queue($this->cookieName($funnel), $participant->token, 60 * 24 * 60, httpOnly: true);

        // The room reads its own cookie, so issue that too rather than making
        // the guest's first arrival a special case in the watch controller.
        if ($attendee = $participant->threadAttendee) {
            Cookie::queue('pres_'.$attendee->presentation_id, $attendee->token, 60 * 24 * 60, httpOnly: true);
        }

        return $this->sendToCurrentStep($participant);
    }

    private function sendToCurrentStep(FunnelParticipant $participant): RedirectResponse
    {
        $step = $participant->currentPresentation ?? $participant->funnel->entry;

        abort_unless($step !== null, 404);

        return redirect()->route('presentations.watch', array_filter([
            'presentation' => $step->slug,
            'code'         => $participant->host?->referral_code,
        ]));
    }

    private function cookieName(PresentationFunnel $funnel): string
    {
        return 'flow_'.$funnel->id;
    }

    private function currentParticipant(Request $request, PresentationFunnel $funnel): ?FunnelParticipant
    {
        $token = $request->cookie($this->cookieName($funnel));

        if (! $token) {
            return null;
        }

        return $funnel->participants()->where('token', $token)->first();
    }

    /**
     * The member whose link brought this guest here.
     *
     * Any real user's code counts — admins and the top position recruit too. An
     * unknown code resolves to nobody, and the caller turns that away rather
     * than quietly creating a prospect nobody owns.
     */
    private function resolveHost(?string $code): ?User
    {
        return filled($code)
            ? User::where('referral_code', $code)->first()
            : null;
    }
}
