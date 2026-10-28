<?php

namespace App\Services\ProposalStudio;

use App\Models\KnowledgeClaim;
use App\Models\KnowledgePassage;
use App\Models\Offering;
use App\Models\OfferingDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the assistant is shown of an offering's knowledge library.
 *
 * Documents go into a prompt whole while they fit. Past that, the passages
 * that best match the question are chosen by a plain keyword score (BM25):
 * a library is a few hundred passages, which PHP scores in milliseconds, and
 * it works the same on every database.
 */
class Knowledge
{
    // About 300 words: enough to keep a claim with its footnote.
    public const PASSAGE_CHARS = 1800;

    /** Cut a document into passages on paragraph boundaries. */
    public function index(OfferingDocument $document): void
    {
        DB::transaction(function () use ($document) {
            $document->passages()->delete();
            $position = 0;
            foreach (self::split((string) $document->extracted_text) as $text) {
                KnowledgePassage::create([
                    'offering_id' => $document->offering_id,
                    'offering_document_id' => $document->id,
                    'position' => $position++,
                    'text' => $text,
                ]);
            }
        });
    }

    /** @return list<string> */
    public static function split(string $text): array
    {
        $passages = [];
        $current = '';
        foreach (preg_split("/\n\s*\n/", trim($text)) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            // A paragraph longer than a passage is cut at sentence ends.
            $pieces = mb_strlen($paragraph) > self::PASSAGE_CHARS
                ? (preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph])
                : [$paragraph];
            foreach ($pieces as $piece) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($piece) > self::PASSAGE_CHARS) {
                    $passages[] = $current;
                    $current = '';
                }
                $current = $current === '' ? $piece : $current."\n\n".$piece;
                while (mb_strlen($current) > self::PASSAGE_CHARS * 1.5) {
                    $passages[] = mb_substr($current, 0, self::PASSAGE_CHARS);
                    $current = mb_substr($current, self::PASSAGE_CHARS);
                }
            }
        }
        if (trim($current) !== '') {
            $passages[] = $current;
        }

        return $passages;
    }

    /**
     * The documents section of a prompt.
     *
     * @param  bool  $shareableOnly  for anything a prospect will read
     */
    public function documentsBlock(Offering $offering, string $query, bool $shareableOnly = false, ?int $budget = null): string
    {
        $budget ??= (int) config('proposal-studio.context_chars');
        $documents = $offering->documents()
            ->where('use_for_ai', true)->where('text_chars', '>', 0)
            ->when($shareableOnly, fn ($q) => $q->where('audience', 'shareable'))
            ->get();

        if ($documents->isEmpty()) {
            return "# OFFERING DOCUMENTS\nNone yet. You know nothing about the product beyond what is written above, so make no product claims; say what you would need to know.";
        }

        if ($documents->sum('text_chars') <= $budget) {
            return "# OFFERING DOCUMENTS\n\n".$documents->map(fn (OfferingDocument $doc) => self::header($doc)."\n<<<\n{$doc->extracted_text}\n>>>")->implode("\n\n");
        }

        // Too much to send whole: the passages that best match, grouped back
        // under their documents in reading order.
        $chosen = [];
        $used = 0;
        foreach ($this->search($offering, $query, $documents->pluck('id')->all()) as $passage) {
            if ($used + mb_strlen($passage->text) > $budget) {
                continue;
            }
            $used += mb_strlen($passage->text);
            $chosen[] = $passage;
        }

        $byDocument = collect($chosen)->groupBy('offering_document_id');
        $out = ['# OFFERING DOCUMENTS', 'The library is too large to send whole. These are the passages that best match the conversation; other passages exist. If something you need may be in a passage you have not seen, say so.'];
        foreach ($documents as $doc) {
            $passages = $byDocument->get($doc->id);
            if ($passages === null) {
                continue;
            }
            $out[] = self::header($doc)."\n".$passages->sortBy('position')->map(fn ($p) => "<<< passage {$p->position}\n{$p->text}\n>>>")->implode("\n");
        }

        return implode("\n\n", $out);
    }

    /**
     * Passages ranked by BM25 against the query, best first.
     *
     * @param  list<int>  $documentIds
     * @return Collection<int, KnowledgePassage>
     */
    public function search(Offering $offering, string $query, array $documentIds, int $limit = 200): Collection
    {
        $passages = KnowledgePassage::where('offering_id', $offering->id)->whereIn('offering_document_id', $documentIds)->get();
        if ($passages->isEmpty()) {
            return $passages;
        }

        $terms = array_unique(self::terms($query));
        $docs = $passages->mapWithKeys(fn ($p) => [$p->id => array_count_values(self::terms($p->text))]);
        $lengths = $docs->map(fn ($tf) => array_sum($tf));
        $avg = max(1, $lengths->avg());
        $n = $passages->count();
        $idf = [];
        foreach ($terms as $term) {
            $df = $docs->filter(fn ($d) => isset($d[$term]))->count();
            $idf[$term] = log(1 + ($n - $df + 0.5) / ($df + 0.5));
        }

        $scores = [];
        foreach ($docs as $id => $tf) {
            $score = 0.0;
            foreach ($terms as $term) {
                if (! isset($tf[$term])) {
                    continue;
                }
                $score += $idf[$term] * ($tf[$term] * 2.2) / ($tf[$term] + 1.2 * (0.25 + 0.75 * $lengths[$id] / $avg));
            }
            $scores[$id] = $score;
        }
        arsort($scores);

        $byId = $passages->keyBy('id');

        return collect(array_keys(array_slice($scores, 0, $limit, true)))->map(fn ($id) => $byId[$id])->values();
    }

    /** The approved claims, which are all a prospect may be told about performance. */
    public function claimsBlock(Offering $offering): string
    {
        $claims = $offering->approvedClaims()->with('document')->get();
        if ($claims->isEmpty()) {
            return "# APPROVED CLAIMS\nNone approved yet. Make no performance, safety, health, test or certification claim in anything a prospect will read.";
        }

        return "# APPROVED CLAIMS\nThe only performance, safety, health, test or certification statements that may reach a prospect. Quote them exactly and cite them as [Claim N].\n\n"
            .$claims->map(fn (KnowledgeClaim $c) => "[Claim {$c->id}]".($c->topic ? " ({$c->topic})" : '')." \"{$c->quote}\"".($c->footnote ? " Footnote: {$c->footnote}" : '').' Source: [Doc '.$c->offering_document_id.'] '.($c->document->title ?? ''))->implode("\n");
    }

    private static function header(OfferingDocument $doc): string
    {
        $meta = array_filter([
            $doc->kindLabel(),
            $doc->audience === 'shareable' ? 'may be shown to prospects' : 'internal only',
            $doc->read_on ? 'read on '.$doc->read_on->toDateString() : null,
            $doc->source_url,
        ]);

        return "## [Doc {$doc->id}] {$doc->title} (".implode('; ', $meta).')';
    }

    /** @return list<string> */
    private static function terms(string $text): array
    {
        static $stop = ['the', 'and', 'for', 'are', 'with', 'that', 'this', 'from', 'you', 'your', 'our', 'can', 'have', 'has', 'was', 'were', 'will', 'what', 'how', 'not', 'but', 'they', 'their', 'its', 'all', 'any', 'into', 'than', 'then', 'there', 'which', 'who', 'about', 'more', 'also', 'each', 'does'];
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $m);

        return array_values(array_filter($m[0], fn ($t) => ! in_array($t, $stop, true)));
    }
}
