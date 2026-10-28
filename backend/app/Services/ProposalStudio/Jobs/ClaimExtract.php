<?php

namespace App\Services\ProposalStudio\Jobs;

use App\Models\KnowledgeClaim;
use App\Models\OfferingDocument;
use App\Models\StudioJob;
use App\Services\ProposalStudio\Prompts;
use RuntimeException;

/**
 * Claims found in one document, saved as drafts for an admin to approve.
 * A quote that is not in the document word for word is dropped: the model
 * proposes, the document decides.
 */
class ClaimExtract implements Handler
{
    public function prompt(StudioJob $job): string
    {
        return Prompts::claimExtract($this->document($job));
    }

    public function complete(StudioJob $job, string $text, array $meta): void
    {
        $document = $this->document($job);
        $claims = Prompts::fenced($text, 'claims');
        if ($claims === null) {
            throw new RuntimeException('The claims did not come back in the expected form.');
        }

        $source = (string) $document->extracted_text;
        $existing = $document->claims()->pluck('quote')->map(fn ($q) => mb_strtolower(trim($q)))->all();
        $added = $dropped = 0;

        foreach (array_slice($claims, 0, 40) as $claim) {
            $quote = is_array($claim) && is_string($claim['quote'] ?? null) ? trim($claim['quote']) : '';
            if ($quote === '' || in_array(mb_strtolower($quote), $existing, true)) {
                continue;
            }
            if (! KnowledgeClaim::isQuotedFrom($quote, $source)) {
                $dropped++;

                continue;
            }
            $footnote = is_string($claim['footnote'] ?? null) ? trim($claim['footnote']) : '';
            KnowledgeClaim::create([
                'offering_id' => $document->offering_id,
                'offering_document_id' => $document->id,
                'topic' => is_string($claim['topic'] ?? null) ? mb_substr(trim($claim['topic']), 0, 120) : null,
                'quote' => $quote,
                // A footnote that is not in the document is not kept either.
                'footnote' => $footnote !== '' && KnowledgeClaim::isQuotedFrom($footnote, $source) ? $footnote : null,
                'status' => 'draft',
            ]);
            $existing[] = mb_strtolower($quote);
            $added++;
        }

        $job->forceFill(['meta' => ($job->meta ?? []) + $meta + ['added' => $added, 'dropped' => $dropped]]);
    }

    public function fail(StudioJob $job, string $error): void {}

    private function document(StudioJob $job): OfferingDocument
    {
        return OfferingDocument::with('offering')->findOrFail($job->subject_id);
    }
}
