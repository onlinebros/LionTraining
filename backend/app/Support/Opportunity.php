<?php

namespace App\Support;

/**
 * One business line somebody can join us for — see config/opportunities.php
 * for what they are and why this is config rather than a table.
 *
 * A read-only view over one entry in that registry. It is a value object, not
 * a model: opportunities are defined by us, not created by users, and the only
 * thing stored per member is which keys they hold.
 *
 * An unknown key resolves to the default rather than throwing. The keys arrive
 * from query strings on a public marketing site, and a mistyped link must send
 * somebody to the ordinary sign-up, never to an error page.
 */
final class Opportunity
{
    /** The query parameter a front door carries: ?o=plasmaguard */
    public const PARAM = 'o';

    /** Session key, so the choice survives the trip through the sign-up form. */
    public const SESSION_KEY = 'signup_opportunity';

    private function __construct(
        public readonly string $key,
        private readonly array $config,
    ) {}

    // ── Registry ──────────────────────────────────────────────────────────────

    /** @return array<string,self> keyed by opportunity key, in config order */
    public static function all(): array
    {
        static $cache = null;

        if ($cache === null || app()->runningUnitTests()) {
            $cache = [];
            foreach ((array) config('opportunities.opportunities', []) as $key => $definition) {
                $cache[$key] = new self($key, (array) $definition);
            }
        }

        return $cache;
    }

    /** The opportunity with this key, or null. Use get() when a fallback is wanted. */
    public static function find(?string $key): ?self
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }

    /**
     * The opportunity with this key, falling back to the default.
     *
     * This is the accessor almost everything should use: a member row with a
     * null opportunity (every account older than this feature) and a member row
     * naming an opportunity that has since been removed from the registry both
     * have to keep working, and both mean "the ordinary one".
     */
    public static function get(?string $key): self
    {
        return self::find($key) ?? self::default();
    }

    public static function default(): self
    {
        $key = self::defaultKey();

        return self::find($key)
            // A default naming a missing opportunity is a deployment mistake,
            // not a user's, so fail loudly here rather than quietly downgrading
            // everyone's access.
            ?? throw new \RuntimeException("config/opportunities.php default '{$key}' is not defined.");
    }

    public static function defaultKey(): string
    {
        return (string) config('opportunities.default', 'q3-training');
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Every gateable section of the member area: key => label.
     *
     * @return array<string,string>
     */
    public static function featureCatalogue(): array
    {
        return (array) config('opportunities.features', []);
    }

    /**
     * Normalise a key that arrived from outside — a query string, a form, an
     * import. Returns null for anything not in the registry, so callers can
     * tell "they did not say" from "they said something we do not sell".
     */
    public static function sanitise(?string $key): ?string
    {
        $key = is_string($key) ? strtolower(trim($key)) : null;

        return $key !== null && self::exists($key) ? $key : null;
    }

    // ── One opportunity ───────────────────────────────────────────────────────

    public function name(): string
    {
        return (string) ($this->config['name'] ?? $this->key);
    }

    public function shortName(): string
    {
        return (string) ($this->config['short_name'] ?? $this->name());
    }

    public function tagline(): string
    {
        return (string) ($this->config['tagline'] ?? '');
    }

    /** The public site this opportunity is sold from, e.g. "q3.life". */
    public function site(): ?string
    {
        return $this->config['site'] ?? null;
    }

    /**
     * A partner's own copy of this line's marketing site: {site_url}/{code}.
     *
     * Per line, not global, because a B2B partner sharing the company site
     * would be sending their prospects to a membership they were never offered
     * — and the mistake looks like a working link.
     */
    public function partnerSiteUrl(string $referralCode): string
    {
        $base = $this->config['site_url'] ?? config('registration.site_url', '');

        return rtrim((string) $base, '/').'/'.$referralCode;
    }

    public function isDefault(): bool
    {
        return $this->key === self::defaultKey();
    }

    /**
     * Whether this line is being sold at all yet.
     *
     * A line whose enrollment is closed asks nobody for anything: no card
     * capture, no subscription gate, no upgrade prompt. Its section in the back
     * office says it is opening soon and takes an expression of interest.
     *
     * A line that was never going to charge (the product side) is trivially
     * open — there is nothing to open. This only means something where a
     * membership is required.
     */
    public function enrollmentOpen(): bool
    {
        if (! $this->requiresMembershipWhenOpen()) {
            return true;
        }

        return (bool) ($this->config['membership']['enrollment_open'] ?? true);
    }

    /**
     * Whether joining this opportunity means buying the membership — the
     * commercial fact, ignoring whether it is on sale yet.
     *
     * Almost nothing should ask this. requiresMembership() is the one that
     * decides behaviour; this exists so that "closed" and "free" stay
     * distinguishable, because they need very different copy.
     */
    public function requiresMembershipWhenOpen(): bool
    {
        return (bool) ($this->config['membership']['required'] ?? true);
    }

    /**
     * Whether a member on this line must carry the membership right now.
     *
     * False is what lets a partner into the back office with no card and no
     * subscription row at all — see RequireActiveSubscription. It is false for
     * the product line, which never charges, and false for any line whose
     * enrollment has not opened yet, which is not charging *today*.
     */
    public function requiresMembership(): bool
    {
        return $this->requiresMembershipWhenOpen() && $this->enrollmentOpen();
    }

    /** 'required' — card at sign-up. 'deferred' — only if they later buy. */
    public function cardPolicy(): string
    {
        return (string) ($this->config['membership']['card'] ?? 'required');
    }

    public function requiresCardUpFront(): bool
    {
        return $this->requiresMembership() && $this->cardPolicy() === 'required';
    }

    /**
     * The line a member joins to buy the training program.
     *
     * Named rather than assumed, because "the paid line" and "the default line"
     * happen to be the same key today and will not always be.
     */
    public static function membership(): self
    {
        return self::get(self::defaultKey());
    }

    /** @return list<string> */
    public function features(): array
    {
        return array_values((array) ($this->config['features'] ?? []));
    }

    public function allows(string $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /** The vendor/product this opportunity is built around, if it is a product line. */
    public function vendorKey(): ?string
    {
        return $this->config['vendor'] ?? null;
    }

    public function productKey(): ?string
    {
        return $this->config['product'] ?? null;
    }

    public function toArray(): array
    {
        return [
            'key'        => $this->key,
            'name'       => $this->name(),
            'short_name' => $this->shortName(),
            'tagline'    => $this->tagline(),
            'site'       => $this->site(),
            'membership' => $this->requiresMembership(),
            'features'   => $this->features(),
        ];
    }
}
