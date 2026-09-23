<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductPartnerAssignment;
use App\Models\ProductPartnerPayment;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOpportunity;
use App\Services\ProductPartner\PartnerStatement;
use App\Support\Opportunity;
use App\Support\ProductPartner;
use App\Support\Vendors;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Our side of the vendor relationship: who at the vendor can see what, and
 * whether we agree they have paid us.
 */
class ProductPartnerController extends Controller
{
    public function __construct(private readonly PartnerStatement $statement) {}

    /** Every product partner account, and what each can see. */
    public function index()
    {
        $role = Role::findByName(Role::PRODUCT_PARTNER);

        return view('admin.product-partners.index', [
            'partners' => User::query()
                ->when($role, fn ($q) => $q->where('role_id', $role->id), fn ($q) => $q->whereRaw('1 = 0'))
                ->with('productPartnerAssignments')
                ->orderBy('name')
                ->paginate(25),

            // Accounts holding a grant but no longer on the role. The grant is
            // inert — ProductPartner::grants() checks the role — but it is a
            // loose end worth seeing rather than leaving in the table.
            'strays' => ProductPartnerAssignment::with('user')
                ->when($role, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('role_id', '!=', $role->id)))
                ->get(),
        ]);
    }

    /**
     * Link a user to a vendor's products.
     *
     * The role is not set here. Making somebody a product partner changes which
     * whole section of the application they land in, and doing that as a side
     * effect of ticking a product is the kind of surprise that ends with a
     * member locked out of their own back office. The screen says so and links
     * to the role field instead.
     */
    public function store(Request $request, User $user)
    {
        $data = $request->validate([
            'vendor'      => ['required', 'string', Rule::in(array_keys(Vendors::all()))],
            'product_key' => ['required', 'string', 'max:64'],
        ]);

        // '*' or a product this vendor actually sells. A typo would otherwise
        // create a grant that silently matches no orders.
        $valid = array_merge(
            [ProductPartnerAssignment::ALL_PRODUCTS],
            array_keys(ProductPartner::productOptions($data['vendor'])),
        );

        if (! in_array($data['product_key'], $valid, true)) {
            return back()->withErrors(['product_key' => 'That product is not in this vendor\'s registry.']);
        }

        ProductPartnerAssignment::firstOrCreate(
            [
                'user_id'     => $user->id,
                'vendor'      => $data['vendor'],
                'product_key' => $data['product_key'],
            ],
            ['granted_by_user_id' => $request->user()->id],
        );

        return back()->with('success', Vendors::name($data['vendor']).' access granted to '.$user->name.'.');
    }

    public function destroy(User $user, ProductPartnerAssignment $assignment)
    {
        // Bound separately, so the pair has to be checked: an id from another
        // user's row would otherwise revoke the wrong grant.
        if ($assignment->user_id !== $user->id) {
            abort(404);
        }

        $label = $assignment->label();
        $assignment->delete();

        return back()->with('success', $label.' removed from '.$user->name.'.');
    }

    // ── Letting a partner sell, not just watch ────────────────────────────────

    /**
     * Give a product partner the member back office as well.
     *
     * One button rather than "add the business line, and remember to tick
     * primary". Getting that checkbox wrong is not a cosmetic mistake: a
     * partner whose primary line is still the default is asked for a card for
     * a $49.99 training program nobody sold them, and the person it happens to
     * is an outside company's employee.
     *
     * The line is chosen from the vendors they already hold, so "can see
     * PlasmaGuard's numbers" and "can sell PlasmaGuard" stay paired.
     */
    public function grantMemberAccess(Request $request, User $user)
    {
        if (! $user->isProductPartner()) {
            return back()->withErrors(['error' => $user->name.' is not a product partner.']);
        }

        $opportunity = ProductPartner::sellingOpportunityFor($user);

        if ($opportunity === null) {
            return back()->withErrors([
                'error' => 'None of this account\'s vendors has a business line pointed at it in '
                    .'config/opportunities.php, so there is nothing to put them on. Link their products first.',
            ]);
        }

        // Primary, always. See the note above — a non-primary line leaves the
        // default in place and the card gate armed.
        $user->associateOpportunity(
            $opportunity,
            UserOpportunity::SOURCE_ADMIN,
            primary: true,
            by: $request->user(),
        );

        return back()->with('success',
            $user->name.' can now sell '.Opportunity::get($opportunity)->name()
            .' and use the member area. No card will be asked for.');
    }

    /**
     * Take the member area away again, leaving the portal.
     *
     * Clears the business lines and the primary with them, which is what puts
     * canUseMemberArea() back to false. Deliberately not the generic "remove
     * opportunity" action, which refuses to remove a primary — here removing
     * the primary is the entire point.
     */
    public function revokeMemberAccess(User $user)
    {
        if (! $user->isProductPartner()) {
            return back()->withErrors(['error' => $user->name.' is not a product partner.']);
        }

        $user->opportunityAssociations()->delete();
        $user->forceFill(['primary_opportunity' => null])->save();

        return back()->with('success', $user->name.' is back to the partner portal only.');
    }

    // ── Looking through a partner's eyes ──────────────────────────────────────

    /**
     * Open the portal scoped exactly as this partner sees it.
     *
     * An admin can always open the portal, but their own view is wider than
     * any real partner's — they hold every vendor. This is what answers "what
     * does PlasmaGuard actually see", including the awkward states: one product
     * of two, or nothing linked at all.
     *
     * Read-only. It changes what is on the screen and never who is acting:
     * the session still belongs to the admin, every write in the portal is
     * refused while it is on, and the banner says whose view it is.
     */
    public function viewAs(Request $request, User $user)
    {
        if (! $user->isProductPartner()) {
            return back()->withErrors([
                'error' => $user->name.' is not a product partner, so there is no view to borrow.',
            ]);
        }

        session([ProductPartner::VIEW_AS => $user->id]);

        return redirect()->route('product-partner.dashboard');
    }

    public function stopViewingAs(Request $request)
    {
        session()->forget(ProductPartner::VIEW_AS);

        return redirect()->route('admin.product-partners.index')
            ->with('success', 'Back to your own view.');
    }

    // ── Payments the vendor says they have made ───────────────────────────────

    public function payments(Request $request)
    {
        $vendor = $request->query('vendor', array_key_first(Vendors::all()));

        return view('admin.product-partners.payments', [
            'vendor'   => $vendor,
            'vendors'  => Vendors::all(),
            'payments' => ProductPartnerPayment::forVendor($vendor)
                ->with(['recordedBy', 'confirmedBy'])
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
                ->orderByDesc('paid_on')
                ->paginate(50),
            'totals'   => $this->statement->forVendor($request->user(), $vendor),
        ]);
    }

    /**
     * Agree that a payment arrived.
     *
     * Settling the invoice it names happens in the same transaction — see
     * PartnerStatement::confirmPayment().
     */
    public function confirmPayment(Request $request, ProductPartnerPayment $payment)
    {
        if (! $payment->isPending()) {
            return back()->withErrors(['error' => 'That payment has already been decided.']);
        }

        $data = $request->validate(['decision_note' => ['nullable', 'string', 'max:2000']]);

        $settled = $this->statement->confirmPayment($payment, $request->user(), $data['decision_note'] ?? null);

        return back()->with('success', $settled > 0
            ? "Payment confirmed, and {$settled} order(s) settled on invoice {$payment->invoice_reference}."
            : 'Payment confirmed.');
    }

    /** Disagree, with a reason. The vendor sees the reason. */
    public function rejectPayment(Request $request, ProductPartnerPayment $payment)
    {
        if (! $payment->isPending()) {
            return back()->withErrors(['error' => 'That payment has already been decided.']);
        }

        $data = $request->validate(['decision_note' => ['required', 'string', 'max:2000']]);

        $payment->reject($request->user(), $data['decision_note']);

        return back()->with('success', 'Payment rejected.');
    }
}
