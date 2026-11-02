<?php

namespace App\Services\ProposalStudio\Jobs;

use App\Models\MicrositeSection;
use App\Models\StudioJob;
use App\Services\ProposalStudio\Prompts;
use RuntimeException;

/**
 * The assistant's draft of one microsite section. It replaces the section's
 * text and stays a draft until an admin publishes it. Claims are kept as ids
 * of approved claims for the section's offering, as in proposals.
 */
class MicrositeSectionDraft implements Handler
{
    public function prompt(StudioJob $job): string
    {
        return Prompts::micrositeSection($this->section($job));
    }

    public function complete(StudioJob $job, string $text, array $meta): void
    {
        $section = $this->section($job);
        $decoded = Prompts::fenced($text, 'section');
        if ($decoded === null) {
            throw new RuntimeException('The section did not come back in the expected form.');
        }
        $body = is_string($decoded['body'] ?? null) ? trim($decoded['body']) : '';
        $content = MicrositeSection::cleanContent($section->layout, $decoded['content'] ?? []);
        $claimIds = self::cleanClaimIds($decoded['claim_ids'] ?? [], $section);
        if ($section->layout === 'plan') {
            // Steps already ticked off stay ticked off.
            $done = collect($section->c()['steps'] ?? [])->filter(fn ($s) => $s['done'] ?? false)->pluck('title')->all();
            $content['steps'] = array_map(fn ($s) => ['done' => $s['done'] || in_array($s['title'], $done, true)] + $s, $content['steps']);
        }
        if ($section->layout === 'cta') {
            $content['button_url'] = $section->c()['button_url'] ?? '';
        }
        $filled = match ($section->layout) {
            'text' => $body !== '',
            'proof' => $claimIds !== [] || ($content['lead'] ?? '') !== '',
            default => implode('', array_map(fn ($v) => is_array($v) ? json_encode($v) : (string) $v, array_filter($content))) !== '',
        };
        if (! $filled) {
            throw new RuntimeException('The section came back empty.');
        }

        $title = is_string($decoded['title'] ?? null) ? trim($decoded['title']) : '';
        $section->forceFill([
            'title' => mb_substr($title !== '' ? $title : $section->title, 0, 255),
            'body' => $section->layout === 'text' ? $body : null,
            'content' => $section->layout === 'text' ? null : $content,
            'claim_ids' => $claimIds,
            'ai_instruction' => null,
            'drafted_at' => now(),
        ])->save();

        $section->account->log('system', "The assistant drafted the \"{$section->title}\" section of the microsite.", null, $meta + ['section' => $section->id]);
    }

    public function fail(StudioJob $job, string $error): void
    {
        $section = $this->section($job);
        $section->account->log('system', "The assistant could not draft the \"{$section->title}\" section. Try again in a minute.", null, ['section' => $section->id, 'error' => mb_substr($error, 0, 500)]);
    }

    /**
     * Only ids of claims approved for the section's offering, in order, once.
     *
     * @return list<int>
     */
    public static function cleanClaimIds(mixed $ids, MicrositeSection $section): array
    {
        $offering = $section->offering();
        if ($offering === null || ! is_array($ids)) {
            return [];
        }
        $approved = $offering->approvedClaims()->pluck('id')->all();

        return array_values(array_unique(array_filter(
            array_map('intval', array_filter($ids, 'is_numeric')),
            fn ($id) => in_array($id, $approved, true),
        )));
    }

    private function section(StudioJob $job): MicrositeSection
    {
        return MicrositeSection::with('account.offering', 'opportunity.offering')->findOrFail($job->subject_id);
    }
}
