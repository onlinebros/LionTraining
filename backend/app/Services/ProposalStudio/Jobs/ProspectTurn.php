<?php

namespace App\Services\ProposalStudio\Jobs;

use App\Models\Prospect;
use App\Models\ProspectMessage;
use App\Models\StudioJob;
use App\Services\ProposalStudio\Prompts;

/**
 * A reply in a prospect's discovery conversation, or (draft) a plan and
 * three emails. Each answer carries the updated profile in a fenced block.
 */
class ProspectTurn implements Handler
{
    public function __construct(private bool $draft) {}

    public function prompt(StudioJob $job): string
    {
        return Prompts::prospectTurn($this->prospect($job), $this->draft);
    }

    public function complete(StudioJob $job, string $text, array $meta): void
    {
        $prospect = $this->prospect($job);
        [$text, $profile] = self::splitProfile($text);

        if ($this->draft) {
            $prospect->forceFill(['draft' => self::fromFirstHeading($text), 'draft_at' => now()]);
            $body = 'I have drafted the plan and the emails. They are in the Drafts panel. Tell me what to change, or keep adding detail and ask me to redraft.';
        } else {
            $body = $text;
        }

        $prospect->messages()->create([
            'role' => ProspectMessage::ROLE_ASSISTANT,
            'body' => $body,
            'meta' => $meta + ['mode' => $this->draft ? 'draft' : 'reply', 'profile_updated' => $profile !== null],
        ]);
        if ($profile !== null) {
            $prospect->forceFill(['profile' => $profile]);
        }
        $prospect->save();
    }

    public function fail(StudioJob $job, string $error): void
    {
        $this->prospect($job)->messages()->create([
            'role' => ProspectMessage::ROLE_ERROR,
            'body' => 'The assistant could not answer this time. Please try again in a minute.',
            'meta' => ['error' => mb_substr($error, 0, 1000)],
        ]);
    }

    private function prospect(StudioJob $job): Prospect
    {
        return Prospect::findOrFail($job->subject_id);
    }

    /**
     * The answer without its profile block, and the profile it carried (null
     * when there was none or it did not parse: the old one is kept).
     *
     * @return array{0: string, 1: ?array<string, mixed>}
     */
    public static function splitProfile(string $text): array
    {
        if (! preg_match('/```profile\s*\n(.*?)\n?```/s', $text, $m, PREG_OFFSET_CAPTURE)) {
            return [trim($text), null];
        }

        $without = trim(substr($text, 0, $m[0][1]).substr($text, $m[0][1] + strlen($m[0][0])));
        $decoded = json_decode(trim($m[1][0]), true);
        if (! is_array($decoded)) {
            return [$without, null];
        }

        $list = fn ($v) => is_array($v) ? array_values(array_filter($v, fn ($x) => is_string($x) && trim($x) !== '')) : [];
        $profile = [
            'summary' => is_string($decoded['summary'] ?? null) ? $decoded['summary'] : '',
            'facts' => array_values(array_filter(
                is_array($decoded['facts'] ?? null) ? $decoded['facts'] : [],
                fn ($f) => is_array($f) && filled($f['label'] ?? null) && filled($f['value'] ?? null),
            )),
            'sites' => array_values(array_filter(is_array($decoded['sites'] ?? null) ? $decoded['sites'] : [], 'is_array')),
            'open_questions' => $list($decoded['open_questions'] ?? null),
            'vendor_questions' => $list($decoded['vendor_questions'] ?? null),
        ];

        return [$without, $profile];
    }

    // The draft starts at its first heading; anything before it is preamble.
    public static function fromFirstHeading(string $text): string
    {
        $start = strpos($text, '## ');

        return trim($start === false ? $text : substr($text, $start));
    }
}
