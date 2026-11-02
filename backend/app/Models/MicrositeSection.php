<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One section of a business account's microsite. The assistant drafts its
 * text from the brief; an admin edits it and publishes it, section by
 * section, so the client sees the site grow. Claims are kept as ids and shown
 * word for word from the library, as in proposals.
 *
 * @property int $id
 * @property int $business_account_id
 * @property int $position
 * @property string $kind
 * @property string $title
 * @property ?string $brief
 * @property string $layout
 * @property ?string $ai_instruction
 * @property ?string $body
 * @property ?array<string, mixed> $content
 * @property ?list<int> $claim_ids
 * @property ?int $prospect_id
 * @property bool $pricing_only
 * @property string $status
 * @property ?Carbon $published_at
 * @property ?Carbon $drafted_at
 */
class MicrositeSection extends Model
{
    /**
     * Kind => [label, default title, default brief, whether the assistant
     * writes it, default layout]. The kind says what a section is for; the
     * layout says how it looks.
     */
    public const KINDS = [
        'welcome' => ['Welcome', 'Welcome', 'Open the site: welcome the company by name, say in one line what we can do for them, and three short highlights of what they will find here.', true, 'hero'],
        'challenges' => ['Your challenges', 'What we have heard from you', 'Their situation and what they want to solve, in their terms, from everything we know about them. One card per challenge.', true, 'cards'],
        'solution' => ['Our approach', 'How we would approach it', 'How the offering fits their buildings and their goals. One card per benefit to them.', true, 'cards'],
        'how_it_works' => ['How it works', 'How it would work for you', 'The path from today to having it running in their buildings, step by step.', true, 'timeline'],
        'evidence' => ['Evidence', 'What the testing shows', 'Pick the approved claims that matter most to this company.', true, 'proof'],
        'proposal' => ['Proposal', 'Your proposal', 'Shows the published proposal of the chosen opportunity.', false, 'proposal'],
        'documents' => ['Documents', 'Documents for you', 'The offering\'s shareable documents.', false, 'documents'],
        'faq' => ['Questions', 'Questions you may have', 'The questions a company like this is likely to ask, answered from the documents and approved claims.', true, 'faq'],
        'next_steps' => ['Next steps', 'Our plan together', 'A shared plan from here to a decision: what we do, what they do, and roughly when.', true, 'plan'],
        'closing' => ['Closing', 'Ready when you are', 'A short, confident close and one clear next step.', true, 'cta'],
        'custom' => ['Custom', 'More', '', true, 'text'],
    ];

    /** Layout => [label, what it looks like]. */
    public const LAYOUTS = [
        'hero' => ['Hero', 'A full-screen opening with their name'],
        'cards' => ['Cards', 'Points side by side, each with an icon'],
        'timeline' => ['Timeline', 'Numbered steps down a line'],
        'proof' => ['Proof', 'Approved claims, large and word for word'],
        'faq' => ['Questions', 'Questions that open to their answers'],
        'plan' => ['Action plan', 'Shared steps both sides tick off'],
        'cta' => ['Call to action', 'A closing band with one button'],
        'text' => ['Text', 'Free text'],
    ];

    /** Icons the assistant and admins may put on cards. */
    public const ICONS = [
        'shield', 'wind', 'leaf', 'building', 'school', 'users', 'heart', 'spark',
        'clock', 'chart', 'check', 'bolt', 'dollar', 'tool', 'calendar', 'target',
    ];

    /** Who does a step of the action plan. */
    public const OWNERS = ['us' => 'Q3', 'you' => 'You', 'both' => 'Together'];

    protected $fillable = [
        'business_account_id', 'position', 'kind', 'layout', 'title', 'brief', 'ai_instruction',
        'body', 'content', 'claim_ids', 'prospect_id', 'pricing_only', 'status',
    ];

    protected function casts(): array
    {
        return [
            'claim_ids' => 'array',
            'content' => 'array',
            'pricing_only' => 'boolean',
            'published_at' => 'datetime',
            'drafted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BusinessAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    /** @return BelongsTo<Prospect, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    /** @return HasMany<MicrositeComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(MicrositeComment::class)->orderBy('id');
    }

    /** @return MorphMany<StudioJob, $this> */
    public function jobs(): MorphMany
    {
        return $this->morphMany(StudioJob::class, 'subject');
    }

    public function isAssistantBusy(): bool
    {
        return $this->jobs()->whereIn('status', [StudioJob::PENDING, StudioJob::CLAIMED])->get()
            ->contains(fn (StudioJob $job) => $job->isOpen());
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isWritten(): bool
    {
        return (bool) (self::KINDS[$this->kind][3] ?? true);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind][0] ?? ucfirst($this->kind);
    }

    /** The offering this section draws on: its opportunity's, or else the one the account presents. */
    public function offering(): ?Offering
    {
        return $this->opportunity?->offering ?? $this->account?->offering;
    }

    public function layoutLabel(): string
    {
        return self::LAYOUTS[$this->layout][0] ?? ucfirst($this->layout);
    }

    /** Whether it has anything to show yet. */
    public function hasContent(): bool
    {
        if (! $this->isWritten()) {
            return true;
        }

        return $this->layout === 'text'
            ? filled($this->body)
            : ! empty(array_filter($this->content ?? [])) || ! empty($this->claim_ids) || filled($this->body);
    }

    /** Its text as plain lines, for prompts and the builder's outline. */
    public function summary(): string
    {
        $c = $this->c();
        $lines = array_filter([
            $c['eyebrow'] ?? null, $c['headline'] ?? null, $c['lead'] ?? null, $c['text'] ?? null,
            ! empty($c['highlights']) ? 'Highlights: '.implode(' · ', $c['highlights']) : null,
        ]);
        foreach ($c['items'] ?? [] as $i) {
            $lines[] = isset($i['q']) ? "Q: {$i['q']}\nA: {$i['a']}" : "- {$i['title']}: {$i['text']}";
        }
        foreach ($c['steps'] ?? [] as $n => $st) {
            $lines[] = ($n + 1).'. '.$st['title'].(($st['when'] ?? '') !== '' ? " ({$st['when']})" : '').(isset($st['owner']) ? ' ['.(self::OWNERS[$st['owner']] ?? '').($st['done'] ? ', done' : '').']' : '').(($st['text'] ?? '') !== '' ? " {$st['text']}" : '');
        }
        if (filled($this->body)) {
            $lines[] = (string) $this->body;
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    public function c(): array
    {
        return $this->content ?? [];
    }

    /**
     * Keep only what the layout uses: known fields, plain text of a sensible
     * length, a bounded number of items, icons and owners from the lists.
     * Used for the assistant's answer and the admin's edits alike.
     *
     * @return array<string, mixed>
     */
    public static function cleanContent(string $layout, mixed $content): array
    {
        $content = is_array($content) ? $content : [];
        $text = fn ($v, int $max) => is_scalar($v) ? mb_substr(trim(strip_tags((string) $v)), 0, $max) : '';
        $items = fn (string $key, int $max, \Closure $row) => array_values(array_filter(array_map(
            $row,
            array_slice(array_values(array_filter(is_array($content[$key] ?? null) ? $content[$key] : [], 'is_array')), 0, $max),
        ), fn ($r) => ($r['title'] ?? '').($r['text'] ?? '').($r['q'] ?? '').($r['a'] ?? '') !== ''));

        $out = match ($layout) {
            'hero' => [
                'eyebrow' => $text($content['eyebrow'] ?? '', 80),
                'headline' => $text($content['headline'] ?? '', 160),
                'lead' => $text($content['lead'] ?? '', 600),
                'highlights' => array_values(array_filter(array_map(
                    fn ($h) => $text(is_array($h) ? ($h['label'] ?? '') : $h, 60),
                    array_slice(is_array($content['highlights'] ?? null) ? $content['highlights'] : [], 0, 4),
                ))),
            ],
            'cards' => [
                'lead' => $text($content['lead'] ?? '', 600),
                'items' => $items('items', 6, fn ($i) => [
                    'icon' => in_array($i['icon'] ?? null, self::ICONS, true) ? $i['icon'] : 'spark',
                    'title' => $text($i['title'] ?? '', 90),
                    'text' => $text($i['text'] ?? '', 400),
                ]),
            ],
            'timeline' => [
                'lead' => $text($content['lead'] ?? '', 600),
                'steps' => $items('steps', 8, fn ($i) => [
                    'title' => $text($i['title'] ?? '', 90),
                    'text' => $text($i['text'] ?? '', 400),
                    'when' => $text($i['when'] ?? '', 40),
                ]),
            ],
            'proof' => ['lead' => $text($content['lead'] ?? '', 600)],
            'faq' => [
                'lead' => $text($content['lead'] ?? '', 600),
                'items' => $items('items', 10, fn ($i) => [
                    'q' => $text($i['q'] ?? '', 200),
                    'a' => $text($i['a'] ?? '', 1200),
                ]),
            ],
            'plan' => [
                'lead' => $text($content['lead'] ?? '', 600),
                'steps' => $items('steps', 10, fn ($i) => [
                    'title' => $text($i['title'] ?? '', 120),
                    'owner' => array_key_exists($i['owner'] ?? null, self::OWNERS) ? $i['owner'] : 'both',
                    'when' => $text($i['when'] ?? '', 40),
                    'done' => (bool) ($i['done'] ?? false),
                ]),
            ],
            'cta' => [
                'headline' => $text($content['headline'] ?? '', 160),
                'text' => $text($content['text'] ?? '', 600),
                'button_label' => $text($content['button_label'] ?? '', 40),
                'button_url' => self::safeUrl($content['button_url'] ?? null),
            ],
            default => [],
        };

        return $out;
    }

    /**
     * A claim's quote as HTML, word for word, with its numbers wrapped so
     * the page can count them up. The text is unchanged: without script it
     * reads exactly as the library has it.
     */
    public static function markNumbers(string $quote): string
    {
        $parts = preg_split('/(\d[\d,]*(?:\.\d+)?)/', $quote, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$quote];

        return implode('', array_map(fn ($part, $i) => $i % 2 ? '<span class="n">'.e($part).'</span>' : e($part), $parts, array_keys($parts)));
    }

    /** An https or mailto link, or empty. */
    public static function safeUrl(mixed $url): string
    {
        $url = is_string($url) ? trim($url) : '';

        return preg_match('~^(https://[^\s<>"]+|mailto:[^\s<>"@]+@[^\s<>"]+)$~i', $url) ? mb_substr($url, 0, 500) : '';
    }

    /**
     * The approved claims it cites, in its order. A claim since rejected
     * drops out rather than being shown.
     *
     * @return Collection<int, KnowledgeClaim>
     */
    public function claims(): Collection
    {
        $ids = array_map('intval', $this->claim_ids ?? []);
        if (! $ids) {
            return collect();
        }
        $claims = KnowledgeClaim::with('document')->whereIn('id', $ids)->where('status', 'approved')->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $claims->get($id))->filter()->values();
    }
}
