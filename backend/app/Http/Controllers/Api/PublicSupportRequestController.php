<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Services\TurnstileVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Contact form on the public company website → support ticket.
 *
 * The requester is anonymous. The ticket is deliberately NOT linked to a user
 * with the same email: anyone can type any address, and linking would put a
 * stranger's message (and our replies) into that member's own ticket list.
 * Staff see an unverified "matches member X" hint in the back office instead.
 */
class PublicSupportRequestController extends Controller
{
    public function store(Request $request, TurnstileVerifier $turnstile): JsonResponse
    {
        // Honeypot. A filled trap gets the same 201 a person gets, before any
        // validation, so a bot learns nothing from the response.
        if (filled($request->input('hp_field'))) {
            return response()->json(['ok' => true, 'reference' => null], 201);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'topic' => ['required', 'string', Rule::in(SupportTicket::WEBSITE_TOPICS)],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
            // http(s) only: staff open this link from the back office, so a
            // javascript: URL would be script run in an admin session.
            'page_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            // Cloudflare Turnstile's cf-turnstile-response, renamed by the site.
            'turnstile_token' => ['required', 'string', 'max:2048'],
        ]);

        // Fail closed: only a verified token creates a ticket.
        $verdict = $turnstile->verify($data['turnstile_token'], $request->ip());

        if ($verdict === TurnstileVerifier::UNAVAILABLE) {
            return response()->json([
                'ok' => false,
                'message' => "We couldn't verify your request right now. Please try again in a few minutes.",
            ], 503);
        }

        if ($verdict !== TurnstileVerifier::PASSED) {
            throw ValidationException::withMessages([
                'turnstile_token' => ['Please complete the verification check and try again.'],
            ]);
        }

        $ticket = DB::transaction(function () use ($data) {
            $ticket = SupportTicket::create([
                'user_id' => null,
                'channel' => SupportTicket::CHANNEL_WEBSITE,
                'requester_name' => $this->singleLine($data['name'], 120),
                'requester_email' => $this->singleLine($data['email'], 255),
                'subject' => $this->singleLine($data['subject'], 200),
                'category' => $data['topic'],
                'priority' => 'normal',
                'status' => 'open',
                'source_url' => isset($data['page_url']) ? mb_substr(trim($data['page_url']), 0, 2048) : null,
            ]);

            SupportTicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => null,
                'body' => mb_substr(trim(str_replace(["\r\n", "\r"], "\n", $data['message'])), 0, 5000),
                'is_internal' => false,
            ]);

            return $ticket;
        });

        return response()->json(['ok' => true, 'reference' => $ticket->ticket_number], 201);
    }

    /** Trim, collapse whitespace (incl. newlines) to single spaces, and cap. */
    private function singleLine(string $value, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 0, $max);
    }
}
