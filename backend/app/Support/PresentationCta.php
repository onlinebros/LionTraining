<?php

namespace App\Support;

use App\Models\Presentation;
use App\Models\User;

/**
 * What a presentation asks a guest to do.
 *
 * The same machinery recruits members and wins customers, and which one it is
 * doing changes per showing — so the call to action is configuration, not a
 * hard-wired link. Each type resolves to a destination that already exists in
 * the application rather than a new funnel.
 *
 * Whatever the destination, it carries the inviting member's referral code, so
 * every path a guest can take credits the same person.
 */
class PresentationCta
{
    public const JOIN = 'join';           // become a partner, through this sponsor

    public const SCHEDULE = 'schedule_call';  // book a call on an outside scheduler

    public const CUSTOM = 'custom';         // anywhere else

    public const NONE = 'none';           // no button at all

    public const TYPES = [
        self::JOIN => 'Join as a partner',
        self::SCHEDULE => 'Book a call (your scheduler link)',
        self::CUSTOM => 'Somewhere else (your own link)',
        self::NONE => 'No button',
    ];

    /*
     * How the destination is opened.
     *
     * A new window suits an ask somebody comes back from — they keep their
     * place in the video and the member is still there in the chat. The same
     * tab suits a flow done in steps, where the destination *is* the next
     * thing and there is nothing to come back to.
     */
    public const OPENS_NEW = 'new_window';

    public const OPENS_SAME = 'same_tab';

    public const OPENS = [
        self::OPENS_NEW => 'Open beside the video, in a new window',
        self::OPENS_SAME => 'Go to the page — this is the next step',
    ];

    /** Sensible wording per type, overridable per item and per placement.
     *  The small print is not here: it depends on how the button behaves. */
    public const DEFAULTS = [
        self::JOIN => [
            'headline' => 'Ready to take the next step?',
            'label' => 'See how to get started',
        ],
        self::SCHEDULE => [
            'headline' => 'Ready to talk it through with someone?',
            'label' => 'Book my call',
        ],
        self::CUSTOM => [
            'headline' => 'Interested?',
            'label' => 'Find out more',
        ],
    ];

    public function __construct(
        public readonly string $type,
        public readonly string $headline,
        public readonly string $label,
        public readonly ?string $note,
        public readonly ?string $url,
        public readonly string $opensIn = self::OPENS_NEW,
    ) {}

    public function isVisible(): bool
    {
        return $this->type !== self::NONE && filled($this->url);
    }

    public function opensInNewWindow(): bool
    {
        return $this->opensIn !== self::OPENS_SAME;
    }

    /**
     * The small print under the button, when nobody wrote any.
     *
     * Only says "opens in a new window" when that is actually what happens —
     * promising somebody they keep their place and then taking the page away
     * from them is worse than saying nothing at all.
     */
    public static function defaultNoteFor(string $opensIn): ?string
    {
        return $opensIn === self::OPENS_SAME
            ? null
            : 'Opens in a new window, so you keep your place here.';
    }

    /**
     * Resolve the button configured on one showing.
     */
    public static function for(Presentation $presentation, ?User $host): self
    {
        return self::make(
            type: $presentation->cta_type ?: config('presentations.default_cta', self::JOIN),
            headline: $presentation->cta_headline,
            label: $presentation->cta_label,
            note: $presentation->cta_note,
            url: $presentation->cta_url,
            host: $host,
            opensIn: $presentation->cta_opens_in ?: self::OPENS_NEW,
        );
    }

    /**
     * Build one from explicit values.
     *
     * Shared by the showing's own call to action and by every reusable item in
     * the library, so there is exactly one place that decides where a button
     * points and one place that decides its wording.
     */
    public static function make(
        ?string $type,
        ?string $headline,
        ?string $label,
        ?string $note,
        ?string $url,
        ?User $host,
        ?string $opensIn = null,
    ): self {
        $type = $type ?: config('presentations.default_cta', self::JOIN);
        $defaults = self::DEFAULTS[$type] ?? self::DEFAULTS[self::JOIN];
        $opensIn = $opensIn === self::OPENS_SAME ? self::OPENS_SAME : self::OPENS_NEW;

        return new self(
            type: $type,
            headline: $headline ?: $defaults['headline'],
            label: $label ?: $defaults['label'],
            note: $note ?: self::defaultNoteFor($opensIn),
            url: self::destination($type, $url, $host),
            opensIn: $opensIn,
        );
    }

    /**
     * Where the button goes.
     *
     * `join` is the partner sign-up at /join/{code}. `schedule_call` has no
     * in-house booking to fall back on, so it needs an explicit scheduler URL;
     * without one the button is hidden rather than pointed somewhere wrong.
     */
    private static function destination(string $type, ?string $url, ?User $host): ?string
    {
        return match ($type) {
            self::JOIN => $host?->referral_code ? route('join', $host->referral_code) : null,
            self::SCHEDULE => $url ?: null,
            self::CUSTOM => $url ?: null,
            default => null,
        };
    }
}
