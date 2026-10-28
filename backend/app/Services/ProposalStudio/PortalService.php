<?php

namespace App\Services\ProposalStudio;

use App\Mail\PortalActivity;
use App\Mail\PortalAnswered;
use App\Mail\PortalInvitation;
use App\Models\PortalEvent;
use App\Models\PortalInvite;
use App\Models\PortalSubmission;
use App\Models\Prospect;
use App\Models\ProspectMessage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The client portal: who may open it (owner decision D3: invited emails with
 * a sign-in link), what prospects send from it, and the answers they get.
 * Everything a prospect sends is also copied into the studio conversation,
 * so the admin and the assistant see it in context.
 */
class PortalService
{
    public function __construct(private PortalAssistant $assistant) {}

    public function invite(Prospect $prospect, string $email, ?string $name, User $by): PortalInvite
    {
        if ($prospect->publishedProposal === null) {
            throw new StudioRefused('Publish a proposal first. The portal shows the published version.');
        }

        $invite = PortalInvite::firstOrNew(['prospect_id' => $prospect->id, 'email' => Str::lower(trim($email))]);
        $invite->fill(['name' => $name ?: $invite->name, 'created_by' => $invite->created_by ?? $by->id]);
        $invite->revoked_at = null;
        $invite->save();

        $this->sendLink($invite);

        return $invite;
    }

    public function sendLink(PortalInvite $invite): void
    {
        $token = $invite->issueToken();
        Mail::to($invite->email)->send(new PortalInvitation($invite, $token));
    }

    public function revoke(PortalInvite $invite): void
    {
        $invite->forceFill(['revoked_at' => now(), 'token_hash' => null])->save();
    }

    /** A new link for someone who lost theirs. Says nothing either way. */
    public function requestLink(Prospect $prospect, string $email): void
    {
        $invite = PortalInvite::where('prospect_id', $prospect->id)->where('email', Str::lower(trim($email)))->whereNull('revoked_at')->first();
        if ($invite !== null && ($invite->sent_at === null || $invite->sent_at->lt(now()->subMinutes(2)))) {
            $this->sendLink($invite);
        }
    }

    public function signIn(Request $request, Prospect $prospect, string $token): ?PortalInvite
    {
        $invite = PortalInvite::findByToken($prospect, $token);
        if ($invite === null) {
            return null;
        }

        $request->session()->put(self::sessionKey($prospect), $invite->id);
        $invite->forceFill(['last_seen_at' => now()])->save();
        PortalEvent::record($prospect, $invite, 'signed_in');

        return $invite;
    }

    public function current(Request $request, Prospect $prospect): ?PortalInvite
    {
        $id = $request->session()->get(self::sessionKey($prospect));
        $invite = $id ? PortalInvite::where('prospect_id', $prospect->id)->find($id) : null;

        return $invite !== null && $invite->isActive() ? $invite : null;
    }

    public static function sessionKey(Prospect $prospect): string
    {
        return 'portal_access.'.$prospect->id;
    }

    /**
     * Details, a correction, or a request for a quote or survey.
     *
     * @param  array<string, string>  $fields
     * @param  array<int, UploadedFile>  $files
     */
    public function submit(Prospect $prospect, PortalInvite $invite, string $kind, ?string $body, array $fields = [], array $files = []): PortalSubmission
    {
        $this->guardRate($invite);

        $stored = [];
        foreach ($files as $file) {
            $stored[] = [
                'path' => $file->store("prospects/{$prospect->id}/portal", 'local'),
                'name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'size' => (int) $file->getSize(),
            ];
        }

        $submission = PortalSubmission::create([
            'prospect_id' => $prospect->id,
            'portal_invite_id' => $invite->id,
            'kind' => $kind,
            'body' => $body,
            'fields' => array_filter($fields, fn ($v) => filled($v)) ?: null,
            'files' => $stored ?: null,
        ]);

        $this->copyToConversation($prospect, $invite, $submission);
        PortalEvent::record($prospect, $invite, $kind, ['submission' => $submission->id]);
        $this->notifyAdmins($prospect, $submission);

        return $submission;
    }

    /** A question: answered by the assistant on the spot when it can, otherwise by the admin. */
    public function ask(Prospect $prospect, PortalInvite $invite, string $question): PortalSubmission
    {
        $this->guardRate($invite);

        $submission = PortalSubmission::create([
            'prospect_id' => $prospect->id,
            'portal_invite_id' => $invite->id,
            'kind' => 'question',
            'body' => $question,
        ]);
        $this->copyToConversation($prospect, $invite, $submission);
        PortalEvent::record($prospect, $invite, 'question', ['submission' => $submission->id]);

        $proposal = $prospect->publishedProposal;
        $answer = $proposal ? $this->assistant->answer($prospect, $proposal, $question) : null;

        if ($answer !== null) {
            $submission->forceFill(['answer' => $answer, 'answered_by' => 'assistant', 'answered_at' => now()])->save();
            $prospect->messages()->create([
                'role' => ProspectMessage::ROLE_ASSISTANT,
                'body' => "Answered in the portal:\n\n{$answer}",
                'meta' => ['mode' => 'portal_answer', 'submission' => $submission->id],
            ]);
        } else {
            $submission->forceFill(['answered_by' => 'pending'])->save();
            $this->notifyAdmins($prospect, $submission);
        }

        return $submission;
    }

    public function answer(PortalSubmission $submission, string $answer, User $by): void
    {
        $submission->forceFill([
            'answer' => $answer,
            'answered_by' => 'admin',
            'answered_by_user_id' => $by->id,
            'answered_at' => now(),
        ])->save();

        $submission->prospect->messages()->create([
            'role' => ProspectMessage::ROLE_USER,
            'user_id' => $by->id,
            'body' => "Answered the prospect's question in the portal:\n\n{$answer}",
            'meta' => ['mode' => 'portal_answer', 'submission' => $submission->id],
        ]);

        if ($submission->invite && $submission->invite->isActive()) {
            Mail::to($submission->invite->email)->send(new PortalAnswered($submission));
        }
    }

    private function copyToConversation(Prospect $prospect, PortalInvite $invite, PortalSubmission $submission): void
    {
        $lines = [$submission->kindLabel().'.'];
        if ($submission->body) {
            $lines[] = $submission->body;
        }
        foreach ($submission->fields ?? [] as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }
        foreach ($submission->files ?? [] as $file) {
            $lines[] = "Attached: {$file['name']}";
        }

        $prospect->messages()->create([
            'role' => ProspectMessage::ROLE_PROSPECT,
            'body' => implode("\n", $lines),
            'meta' => ['from' => $invite->name ?: $invite->email, 'submission' => $submission->id],
        ]);
    }

    private function notifyAdmins(Prospect $prospect, PortalSubmission $submission): void
    {
        $to = $prospect->creator?->email
            ? [$prospect->creator->email]
            : User::whereHas('role', fn ($q) => $q->where('name', Role::SUPER_ADMIN))->pluck('email')->all();
        if ($to) {
            Mail::to($to)->send(new PortalActivity($submission));
        }
    }

    private function guardRate(PortalInvite $invite): void
    {
        $recent = PortalSubmission::where('portal_invite_id', $invite->id)->where('created_at', '>=', now()->subHour())->count();
        if ($recent >= (int) config('proposal-studio.portal.hourly_limit')) {
            throw new StudioRefused('You have sent a lot in the last hour. Please try again a little later, or reply to our email.');
        }
    }
}
