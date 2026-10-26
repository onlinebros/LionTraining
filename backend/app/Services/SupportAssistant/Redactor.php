<?php

namespace App\Services\SupportAssistant;

use App\Models\SupportTicket;
use App\Services\RequestAssistant\ChangeRequestService;

/**
 * Takes the member's identity out of ticket text before it leaves the live
 * site for dev's Claude CLI (owner, 2026-10-22): their name becomes
 * "[member]", and email addresses, phone and other long numbers are masked.
 * URLs keep scheme, host and path only. The assistant writes {name} where the
 * member's name goes, and live fills it in.
 */
class Redactor
{
    /** @var list<string> */
    private array $names;

    public function __construct(SupportTicket $ticket)
    {
        $full = array_filter(array_unique([
            trim((string) $ticket->requester_name),
            trim((string) $ticket->user?->name),
        ]));

        // The full names first, then each part long enough to be a name and
        // not an initial ("Michael Stern" → "Michael Stern", "Michael", "Stern").
        $parts = [];
        foreach ($full as $name) {
            foreach (preg_split('/\s+/u', $name) ?: [] as $part) {
                if (mb_strlen($part) >= 3) {
                    $parts[] = $part;
                }
            }
        }
        usort($full, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $this->names = array_values(array_unique([...$full, ...$parts]));
    }

    public function text(?string $text): string
    {
        $text = (string) $text;

        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text) ?? $text;
        $text = preg_replace_callback('~https?://\S+~i', fn ($m) => ChangeRequestService::cleanUrl(rtrim($m[0], '.,;:)')) ?? '[link]', $text) ?? $text;
        // Phone, card and account numbers: nine or more digits in a run that
        // may include spaces and dashes. Dates (eight digits) are left alone.
        $text = preg_replace_callback('/\+?\d[\d \t().\-]{6,}\d/', fn ($m) => preg_match_all('/\d/', $m[0]) >= 9 ? '[number]' : $m[0], $text) ?? $text;

        // Case-sensitive: names are capitalised, and "will" or "mark" in a
        // sentence should survive a member called Will Mark.
        foreach ($this->names as $name) {
            $text = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', '[member]', $text) ?? $text;
        }

        return $text;
    }

    /** The member's first name, to put where the draft says {name}. */
    public static function firstName(SupportTicket $ticket): string
    {
        $name = trim((string) ($ticket->requester_name ?: $ticket->user?->name));

        return $name === '' ? 'there' : (preg_split('/\s+/u', $name)[0] ?? 'there');
    }
}
