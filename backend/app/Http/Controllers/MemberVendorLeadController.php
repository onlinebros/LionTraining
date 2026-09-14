<?php

namespace App\Http\Controllers;

use App\Models\CommissionLedger;
use App\Models\VendorLead;
use App\Services\Vendor\PromotionTracker;
use App\Services\Vendor\VendorReferralService;
use App\Support\Vendors;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A partner's own vendor-product pipeline: their share links, what each lead
 * they sourced has done since, ordering for themselves, and the promotion
 * standings.
 */
class MemberVendorLeadController extends Controller
{
    public function __construct(
        private readonly VendorReferralService $referrals,
        private readonly PromotionTracker $promotions,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $leads = VendorLead::where('member_id', $user->id)
            ->latest()
            ->paginate(20);

        $counts = VendorLead::where('member_id', $user->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        /*
         * Read from the ledger rather than recomputed from the leads. Commission
         * no longer always belongs to the partner whose link made the sale: a
         * recruit's own purchase pays their sponsor, on a lead the sponsor does
         * not own. A credit is written as soon as a sale is confirmed, so this
         * still reads correctly before any payout run.
         */
        $earned = (float) CommissionLedger::where('earner_id', $user->id)
            ->where('source_type', VendorLead::class)
            ->credits()
            ->where('status', '!=', 'voided')
            ->sum('amount');

        // Confirmed own purchases by partners this one sponsors. Name, product
        // and status only: the buyer's address and contact details are theirs.
        $teamPurchases = VendorLead::with(['buyer:id,name', 'commissionCredit'])
            ->where('earner_id', $user->id)
            ->where('attribution', VendorLead::ATTRIBUTION_SELF)
            ->where('buyer_user_id', '!=', $user->id)
            ->whereIn('status', [VendorLead::STATUS_CONVERTED, VendorLead::STATUS_REFUNDED])
            ->latest('converted_at')
            ->limit(25)
            ->get();

        $promotionKey = $this->promotions->currentKey();

        return view('member.vendor.leads', [
            'user'          => $user,
            'leads'         => $leads,
            'counts'        => $counts,
            'earned'        => $earned,
            'teamPurchases' => $teamPurchases,
            'promotion'     => $promotionKey !== null ? $this->promotions->standings($promotionKey) : null,
            'vendors'       => collect(Vendors::all())->filter(fn ($v, $slug) => Vendors::enabled($slug)),
        ]);
    }

    public function show(Request $request, VendorLead $lead)
    {
        // Scoped by ownership, not just by id — a partner must not be able to
        // read another partner's customer by guessing a primary key.
        if ($lead->member_id !== $request->user()->id) {
            throw new NotFoundHttpException();
        }

        return view('member.vendor.lead-show', [
            'lead'   => $lead->load('crmContact'),
            'vendor' => Vendors::find($lead->vendor),
        ]);
    }

    /** The back-office order form: a partner buying for themselves. */
    public function buy(Request $request, string $vendor, string $product)
    {
        [$vendorConfig, $productConfig] = $this->product($vendor, $product);

        $user         = $request->user();
        $promotionKey = $this->promotions->currentKey();

        return view('member.vendor.buy', [
            'user'       => $user,
            'sponsor'    => $user->sponsor()->first(),
            'vendorSlug' => $vendor,
            'vendor'     => $vendorConfig,
            'productKey' => $product,
            'product'    => $productConfig,
            'promotion'  => $promotionKey !== null ? $this->promotions->find($promotionKey) : null,
        ]);
    }

    /**
     * Place a partner's own order.
     *
     * Goes through the same capture and checkout as a customer's, so the vendor
     * receives an ordinary order. What differs is recorded on the lead: it is the
     * partner's own purchase, so it counts for them and pays their sponsor.
     */
    public function placeOwnOrder(Request $request, string $vendor, string $product)
    {
        $this->product($vendor, $product);

        $user = $request->user();

        $data = $request->validate([
            'first_name'    => ['required', 'string', 'max:100'],
            'last_name'     => ['nullable', 'string', 'max:100'],
            'phone'         => ['nullable', 'string', 'max:40'],
            'company'       => ['nullable', 'string', 'max:150'],
            'address_line1' => ['required', 'string', 'max:191'],
            'address_line2' => ['nullable', 'string', 'max:191'],
            'city'          => ['required', 'string', 'max:100'],
            'state'         => ['required', 'string', 'max:100'],
            'postal_code'   => ['required', 'string', 'max:20'],
            'quantity'      => ['required', 'integer', 'min:1', 'max:99'],
            'notes'         => ['nullable', 'string', 'max:2000'],
        ]);

        // The account email, never a typed one. It is what identifies this order
        // as the partner's own at every later step, the vendor's receipt included.
        $data['email']   = $user->email;
        $data['country'] = 'US';

        $lead = $this->referrals->capture($vendor, $product, $user, $data, [
            'buyer'      => $user,
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $mode = Vendors::find($vendor)['checkout']['mode'] ?? 'payment_link';

        return redirect()->route($mode === 'direct' ? 'vendor.order' : 'vendor.handoff', $lead->public_ref);
    }

    public function promotion(Request $request)
    {
        $key = $this->promotions->currentKey();

        return view('member.vendor.promotion', [
            'user'      => $request->user(),
            'standings' => $key !== null ? $this->promotions->standings($key) : null,
        ]);
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function product(string $vendor, string $product): array
    {
        $vendorConfig  = Vendors::find($vendor);
        $productConfig = Vendors::product($vendor, $product);

        if ($vendorConfig === null || $productConfig === null || ! Vendors::enabled($vendor)) {
            throw new NotFoundHttpException('Unknown vendor product.');
        }

        return [$vendorConfig, $productConfig];
    }
}
