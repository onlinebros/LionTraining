<?php

namespace App\Services\ProposalStudio\Jobs;

use App\Models\Proposal;
use App\Models\ProspectMessage;
use App\Models\StudioJob;
use App\Services\ProposalStudio\Prompts;
use RuntimeException;

/**
 * The assistant's draft of a proposal page. It fills an empty draft version;
 * claims are kept as ids of approved claims, so the page always renders
 * their exact wording from the library, never from the model's text.
 */
class ProposalDraft implements Handler
{
    public function prompt(StudioJob $job): string
    {
        return Prompts::proposalDraft($this->proposal($job));
    }

    public function complete(StudioJob $job, string $text, array $meta): void
    {
        $proposal = $this->proposal($job);
        $decoded = Prompts::fenced($text, 'proposal');
        if ($decoded === null) {
            throw new RuntimeException('The draft did not come back in the expected form.');
        }

        $proposal->forceFill(['content' => self::clean($decoded, $proposal)])->save();
        $proposal->prospect->messages()->create([
            'role' => ProspectMessage::ROLE_ASSISTANT,
            'body' => "I have drafted version {$proposal->version} of the proposal page. It is in the Proposal panel: read it, edit anything, then publish it when it is right.",
            'meta' => $meta + ['mode' => 'proposal'],
        ]);
    }

    public function fail(StudioJob $job, string $error): void
    {
        $proposal = $this->proposal($job);
        $proposal->prospect->messages()->create([
            'role' => ProspectMessage::ROLE_ERROR,
            'body' => 'The assistant could not draft the proposal this time. Please try again in a minute.',
            'meta' => ['error' => mb_substr($error, 0, 1000)],
        ]);
        // An empty version is no use to anyone.
        if ($proposal->content === null) {
            $proposal->delete();
        }
    }

    /**
     * Only the expected fields, as strings, and only claim ids that are
     * approved for this offering.
     *
     * @param  array<mixed>  $raw
     * @return array<string, mixed>
     */
    public static function clean(array $raw, Proposal $proposal): array
    {
        $str = fn ($v) => is_string($v) ? trim($v) : '';
        $approved = $proposal->prospect->offering->approvedClaims()->pluck('id')->all();

        return [
            'headline' => $str($raw['headline'] ?? ''),
            'intro' => $str($raw['intro'] ?? ''),
            'situation' => $str($raw['situation'] ?? ''),
            'plan' => array_values(array_map(fn ($row) => [
                'site' => $str($row['site'] ?? ''),
                'product' => $str($row['product'] ?? ''),
                'quantity' => is_scalar($row['quantity'] ?? null) ? trim((string) $row['quantity']) : '',
                'rationale' => $str($row['rationale'] ?? ''),
            ], array_filter(is_array($raw['plan'] ?? null) ? $raw['plan'] : [], 'is_array'))),
            'how_it_works' => $str($raw['how_it_works'] ?? ''),
            'benefits' => array_values(array_map(fn ($row) => [
                'title' => $str($row['title'] ?? ''),
                'text' => $str($row['text'] ?? ''),
            ], array_filter(is_array($raw['benefits'] ?? null) ? $raw['benefits'] : [], 'is_array'))),
            'claim_ids' => array_values(array_unique(array_filter(
                array_map('intval', is_array($raw['claim_ids'] ?? null) ? $raw['claim_ids'] : []),
                fn ($id) => in_array($id, $approved, true),
            ))),
            'pricing' => $str($raw['pricing'] ?? ''),
            'next_steps' => $str($raw['next_steps'] ?? ''),
        ];
    }

    private function proposal(StudioJob $job): Proposal
    {
        return Proposal::with('prospect.offering')->findOrFail($job->subject_id);
    }
}
