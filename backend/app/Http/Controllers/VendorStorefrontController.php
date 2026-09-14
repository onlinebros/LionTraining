<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VendorLead;
use App\Services\Vendor\VendorOrderService;
use App\Services\Vendor\VendorStripeClient;
use App\Services\Vendor\VendorReferralService;
use App\Support\Vendors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The member-coded, customer-facing product page.
 *
 * Public and unauthenticated: the visitor is the partner's prospect, not a user
 * of this application. The partner's identity comes from the URL, is resolved
 * server-side, and is snapshotted onto the lead — it is never taken from a form
 * field, which a customer could edit to redirect someone else's commission.
 */
class VendorStorefrontController extends Controller
{
    public function __construct(
        private readonly VendorReferralService $referrals,
        private readonly VendorOrderService $orders,
        private readonly VendorStripeClient $stripe,
    ) {}

    public function show(string $code, string $vendor, string $product)
    {
        [$member, $vendorConfig, $productConfig] = $this->resolve($code, $vendor, $product);

        return view('public.vendor.product', [
            'member'        => $member,
            'vendorSlug'    => $vendor,
            'vendor'        => $vendorConfig,
            'productKey'    => $product,
            'product'       => $productConfig,
        ]);
    }

    public function store(Request $request, string $code, string $vendor, string $product)
    {
        [$member, , $productConfig] = $this->resolve($code, $vendor, $product);

        // Honeypot. Bots fill every field they find; a real browser never sees
        // this one. Answered as success so a scraper learns nothing from the
        // difference between accepted and rejected.
        if (filled($request->input('website_url'))) {
            Log::info('Vendor enquiry honeypot triggered', ['ip' => $request->ip()]);

            return redirect()->route('vendor.product', [$code, $vendor, $product])
                ->with('status', 'Thanks — we have your details.');
        }

        $qualifierRules = [];

        foreach ($productConfig['qualifiers'] ?? [] as $key => $definition) {
            $qualifierRules["qualifiers.{$key}"] = ['nullable', Rule::in($definition['options'] ?? [])];
        }

        /*
         * Stage one asks only for what identifies the buyer and what they want.
         * No address, no company, no shipping questions.
         *
         * The point is the drop-off. A single long form loses people at the
         * address, and everything they typed before it is lost with them — which
         * on a $6,000 product means losing a lead the partner could have called.
         * Splitting it means the lead exists the moment we know who they are,
         * and the address becomes stage two rather than a gate.
         */
        $data = $request->validate([
            'first_name'    => ['required', 'string', 'max:100'],
            'last_name'     => ['nullable', 'string', 'max:100'],
            'email'         => ['required', 'email:rfc', 'max:191'],
            'phone'         => ['nullable', 'string', 'max:40'],
            'quantity'      => ['nullable', 'integer', 'min:1', 'max:99'],
            'furnace_count' => ['nullable', 'integer', 'min:1', 'max:'.(int) ($productConfig['sizing']['max_systems'] ?? 20)],
            'notes'         => ['nullable', 'string', 'max:2000'],
        ] + $qualifierRules);

        // The sizing answer is kept alongside the other qualifiers — it is the
        // reasoning behind the quantity, and the partner will want it on the call.
        if (filled($data['furnace_count'] ?? null)) {
            $data['qualifiers'] = ($data['qualifiers'] ?? []) + ['furnace_count' => (int) $data['furnace_count']];
        }

        $lead = $this->referrals->capture($vendor, $product, $member, $data, [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'utm'        => $request->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']) ?: null,
        ]);

        // Payment-link vendors still hand off to their hosted page; direct
        // vendors continue to stage two on our own checkout.
        $mode = Vendors::find($vendor)['checkout']['mode'] ?? 'payment_link';

        return redirect()->route($mode === 'direct' ? 'vendor.order' : 'vendor.handoff', $lead->public_ref);
    }

    /**
     * Stage two: where it ships, what it costs, and paying for it.
     *
     * Split into two renders of the same URL rather than two URLs — before an
     * address there is nothing to quote, and after one there is nothing left to
     * ask. A customer who leaves and comes back lands wherever they got to.
     */
    public function order(string $reference)
    {
        $lead = $this->lead($reference);

        if ($lead->isConverted()) {
            return redirect()->route('vendor.complete', $lead->public_ref);
        }

        $vendorConfig  = Vendors::find($lead->vendor);
        $productConfig = Vendors::product($lead->vendor, $lead->product_key);

        $quote = null;
        $error = null;

        if (filled($lead->postal_code)) {
            try {
                $quote = $this->orders->quote($lead);
            } catch (\RuntimeException $e) {
                // A product with no price, or no way to rate the parcel. The
                // customer sees a "we will be in touch" rather than a stack
                // trace, and the lead is already saved either way.
                Log::warning('Vendor quote failed at checkout', [
                    'lead'  => $lead->public_ref,
                    'error' => $e->getMessage(),
                ]);
                $error = $e->getMessage();
            }
        }

        return view('public.vendor.order', [
            'lead'    => $lead,
            'vendor'  => $vendorConfig,
            'product' => $productConfig,
            'quote'   => $quote,
            'error'   => $error,
        ]);
    }

    /** Save the shipping address, which is what makes a quote possible. */
    public function saveAddress(Request $request, string $reference)
    {
        $lead = $this->lead($reference);

        if ($lead->isConverted()) {
            return redirect()->route('vendor.complete', $lead->public_ref);
        }

        $data = $request->validate([
            'company'       => ['nullable', 'string', 'max:150'],
            'address_line1' => ['required', 'string', 'max:191'],
            'address_line2' => ['nullable', 'string', 'max:191'],
            'city'          => ['required', 'string', 'max:100'],
            'state'         => ['required', 'string', 'max:100'],
            'postal_code'   => ['required', 'string', 'max:20'],
            'country'       => ['nullable', 'string', 'size:2'],
            'quantity'      => ['nullable', 'integer', 'min:1', 'max:99'],
            'notes'         => ['nullable', 'string', 'max:2000'],
        ]);

        $data['country'] = $data['country'] ?? 'US';

        $lead->fill(array_filter($data, static fn ($v) => $v !== null))->save();

        return redirect()->route('vendor.order', $lead->public_ref);
    }

    /**
     * Create the PaymentIntent the browser will confirm.
     *
     * Called on submit rather than on page load. The Payment Element is mounted
     * in deferred mode, so the card fields render from an amount alone and no
     * intent exists until someone actually intends to pay — otherwise every
     * idle page view would litter the vendor's dashboard with abandoned intents.
     *
     * The amount is recomputed here from the stored lead, never taken from the
     * request. The browser is not a trusted source of prices.
     */
    public function pay(string $reference)
    {
        $lead = $this->lead($reference);

        if ($lead->isConverted()) {
            return response()->json(['error' => 'This order has already been paid.'], 409);
        }

        try {
            $result = $this->orders->place($lead);
        } catch (\RuntimeException $e) {
            Log::warning('Vendor order could not be placed', [
                'lead'  => $lead->public_ref,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'We could not complete this order automatically. '
                    .'Your details are saved and we will be in touch.',
            ], 422);
        }

        return response()->json([
            'client_secret' => $result['client_secret'],
            'return_url'    => route('vendor.complete', $lead->public_ref),
        ]);
    }

    /**
     * Where the customer lands after paying.
     *
     * Like the old thank-you page, this does NOT mark anything converted — it is
     * reached by a redirect the customer controls. The webhook is what confirms
     * the sale; this only reports what we already know.
     */
    public function complete(Request $request, string $reference)
    {
        $lead = $this->lead($reference);

        /*
         * Read the intent's real status rather than trusting the redirect.
         * Stripe appends `redirect_status` to the return URL and a customer can
         * edit it, so it is a hint about what to render and never a reason to
         * mark anything sold. The webhook does that.
         */
        $status = null;

        if (filled($lead->provider_payment_intent_id)) {
            try {
                $intent = $this->stripe->for($lead->vendor)
                    ->paymentIntents->retrieve($lead->provider_payment_intent_id);
                $status = $intent->status;
            } catch (\Throwable $e) {
                Log::info('Could not read payment status for completion page', [
                    'lead'  => $lead->public_ref,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return view('public.vendor.complete', [
            'lead'   => $lead,
            'vendor' => Vendors::find($lead->vendor),
            'status' => $status,
        ]);
    }

    /**
     * The bridge between our page and the vendor's checkout.
     *
     * A visible step rather than a bare redirect. The customer has just typed
     * their details into a Quantum Life page; dropping them without warning onto
     * a payment page branded for a company they may not recognise is how a
     * checkout gets abandoned. It also gives the handoff somewhere to fail
     * gracefully when a vendor has no checkout URL configured yet.
     */
    public function handoff(string $reference)
    {
        $lead = $this->lead($reference);

        try {
            $url = $this->referrals->checkoutUrl($lead);
        } catch (\RuntimeException $e) {
            // No checkout configured — the enquiry is captured and the partner
            // follows it up by hand. Still a completed lead, not an error page.
            Log::warning('Vendor handoff without a checkout URL', [
                'lead'  => $lead->public_ref,
                'error' => $e->getMessage(),
            ]);

            return view('public.vendor.handoff', [
                'lead'       => $lead,
                'vendor'     => Vendors::find($lead->vendor),
                'checkoutUrl' => null,
            ]);
        }

        // Recorded before the customer leaves, so the exact URL we sent them to
        // is auditable even if they never arrive.
        $this->referrals->markHandedOff($lead, $url);

        return view('public.vendor.handoff', [
            'lead'        => $lead,
            'vendor'      => Vendors::find($lead->vendor),
            'checkoutUrl' => $url,
        ]);
    }

    /**
     * Where the vendor's "after payment" redirect lands, if they configure one.
     *
     * Deliberately does NOT mark the lead converted. This page is reached by a
     * browser redirect the customer controls; treating it as proof of payment
     * would let anyone with the URL manufacture a commission. Conversion comes
     * from a signed webhook or an admin, and nothing else.
     */
    public function thanks(string $reference)
    {
        $lead = $this->lead($reference);

        return view('public.vendor.thanks', [
            'lead'   => $lead,
            'vendor' => Vendors::find($lead->vendor),
        ]);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * @return array{0: User, 1: array<string,mixed>, 2: array<string,mixed>}
     */
    private function resolve(string $code, string $vendor, string $product): array
    {
        $vendorConfig  = Vendors::find($vendor);
        $productConfig = Vendors::product($vendor, $product);

        if ($vendorConfig === null || $productConfig === null || ! Vendors::enabled($vendor)) {
            throw new NotFoundHttpException('Unknown vendor product.');
        }

        $member = User::where('referral_code', $code)->first();

        // A deactivated partner's link stops working rather than silently
        // capturing leads nobody is going to work.
        if ($member === null || $member->is_active === false) {
            throw new NotFoundHttpException('Unknown referral code.');
        }

        return [$member, $vendorConfig, $productConfig];
    }

    private function lead(string $reference): VendorLead
    {
        $lead = VendorLead::where('public_ref', $reference)->first();

        if ($lead === null) {
            throw new NotFoundHttpException('Unknown reference.');
        }

        return $lead;
    }
}
