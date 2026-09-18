<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company whose membership list is being brought into Quantum 3.
 *
 * @property string $slug
 * @property string $name
 */
class PartnerCompany extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'logo_path',
        'logo_dark_path',
        'headline',
        'intro',
        'support_email',
        'identifier_label',
        'activation_label',
        'is_active',
        'webhook_url',
        'webhook_secret',
        'webhook_signature_style',
        'webhook_enabled',
        'webhook_include_contact',
    ];

    protected $hidden = [
        'webhook_secret',
    ];

    // How a partner's endpoint expects the signature. We do not get to pick:
    // their receiver already exists, and matching it is worth more than
    // consistency across partners.
    //
    //   q3_timestamped   Q3-Signature: t=<unix>,v1=<hmac of "t.body">
    //                    Stripe's convention. The timestamp is signed, so an
    //                    intercepted delivery cannot be replayed at leisure.
    //   partner_sha256   X-Partner-Signature: sha256=<hmac of the body>
    //                    GitHub's convention. Simpler, and what iHub reads.
    public const SIGNATURE_Q3      = 'q3_timestamped';
    public const SIGNATURE_SHA256  = 'partner_sha256';

    public const SIGNATURE_STYLES = [
        self::SIGNATURE_Q3     => 'Q3-Signature: t=…,v1=… (timestamped, Stripe-style)',
        self::SIGNATURE_SHA256 => 'X-Partner-Signature: sha256=… (GitHub-style)',
    ];

    protected function casts(): array
    {
        return [
            'is_active'               => 'boolean',
            'webhook_enabled'         => 'boolean',
            'webhook_include_contact' => 'boolean',
            // Encrypted at rest: it is what lets the partner prove a delivery
            // came from us, so a leaked one lets anybody forge "this spot was
            // claimed" into their system.
            'webhook_secret'          => 'encrypted',
            'webhook_last_success_at' => 'datetime',
        ];
    }

    public function imports(): HasMany
    {
        return $this->hasMany(PartnerImport::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(PartnerWebhookDelivery::class)->latest();
    }

    /** Is there anywhere to send a claim event? */
    public function webhookConfigured(): bool
    {
        return $this->webhook_enabled
            && filled($this->webhook_url)
            && filled($this->webhook_secret);
    }

    /** Every spot ever created for this company, claimed or not. */
    public function spots(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** The public claim page this company's people are sent to. */
    public function claimUrl(): string
    {
        return route('partner.claim', $this->slug);
    }

    /**
     * The logo to show on the claim page, in whichever theme it renders.
     *
     * Two kinds of path end up in these columns and both have to work:
     *
     *   assets/…   a brand file committed to the repo, served straight out of
     *              public/ and cache-busted by its mtime. This is how a partner
     *              we have an actual relationship with gets their mark in —
     *              handed over as a file, reviewed, committed.
     *   anything   uploaded through the admin form onto the public disk.
     *   else
     *
     * Returns null when the company has no logo, and the view falls back to
     * setting their name in type — which is a legitimate look, not a broken one.
     */
    public function logoUrl(bool $dark = true): ?string
    {
        $path = $dark
            ? ($this->logo_dark_path ?: $this->logo_path)
            : ($this->logo_path ?: $this->logo_dark_path);

        if (blank($path)) {
            return null;
        }

        return str_starts_with($path, 'assets/')
            ? \App\Support\Asset::v($path)
            : \Illuminate\Support\Facades\Storage::url($path);
    }

    /**
     * Claimed and unclaimed counts.
     *
     * Cached, because this is on the public claim page and the page is about to
     * be linked in a mailing to everybody on the partner's list. Counting
     * iHub's 1.3 million positions takes 2.4 seconds — measured, not guessed —
     * and a public page that spends 2.4 seconds of database time per visitor
     * falls over the moment that mailing goes out.
     *
     * Five minutes stale is fine for what it is: a line of copy saying roughly
     * how many positions are still waiting. Nothing depends on it being exact,
     * and the number moves slowly. Pass $fresh where it has to be exact — the
     * admin board, which is one person looking rather than a mailing list.
     *
     * @return array{total:int, claimed:int, unclaimed:int}
     */
    public function spotCounts(bool $fresh = false): array
    {
        $key = "partner_company_{$this->id}_spot_counts";

        if ($fresh) {
            \Cache::forget($key);
        }

        return \Cache::remember($key, now()->addMinutes(5), function () {
            $row = $this->spots()
                ->selectRaw('count(*) AS total')
                ->selectRaw('count(*) FILTER (WHERE account_status = ?) AS unclaimed', [User::ACCOUNT_HOLDING])
                ->first();

            $total     = (int) ($row->total ?? 0);
            $unclaimed = (int) ($row->unclaimed ?? 0);

            return [
                'total'     => $total,
                'claimed'   => $total - $unclaimed,
                'unclaimed' => $unclaimed,
            ];
        });
    }
}
