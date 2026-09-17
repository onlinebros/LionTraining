<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverPartnerWebhookJob;
use App\Models\PartnerCompany;
use App\Models\PartnerWebhookDelivery;
use App\Services\Partner\PartnerWebhookDispatcher;
use Illuminate\Http\Request;

/**
 * What we told each partner company, and what they said back.
 *
 * Exists for one conversation: "we never got told about that claim." Without a
 * screen showing the payload, the attempts and the response, that is two
 * parties each certain the other is wrong.
 */
class PartnerWebhookController extends Controller
{
    public function __construct(private PartnerWebhookDispatcher $dispatcher) {}

    public function index(Request $request)
    {
        $companyId = $request->integer('company') ?: null;

        $deliveries = PartnerWebhookDelivery::query()
            ->with('company:id,name,slug')
            ->when($companyId, fn ($q) => $q->where('partner_company_id', $companyId))
            ->when($request->get('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(40)
            ->withQueryString();

        return view('admin.partners.webhooks', [
            'deliveries' => $deliveries,
            'companies'  => PartnerCompany::orderBy('name')->get(),
            'companyId'  => $companyId,
            'status'     => $request->get('status'),
            'failing'    => PartnerWebhookDelivery::where('status', PartnerWebhookDelivery::STATUS_FAILED)->count(),
        ]);
    }

    /**
     * The integration guide, to hand to the partner's engineers.
     *
     * Served from the repo rather than written into an email, so what a partner
     * is working from is the version in the codebase — and so the test that
     * checks it against the real payload is checking the document they actually
     * received.
     */
    public function guide()
    {
        return response()->download(
            base_path('resources/templates/partner-webhook-guide.md'),
            'quantum3-spot-claim-webhook.md',
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }

    public function show(PartnerWebhookDelivery $delivery)
    {
        $delivery->load('company', 'spot:id,name,email,external_user_id');

        return view('admin.partners.webhook-show', [
            'delivery' => $delivery,
            // Exactly the bytes that were signed and sent. An integration that
            // is not working is usually a disagreement about this.
            'body'     => $this->dispatcher->encode($delivery),
        ]);
    }

    /**
     * Send it again.
     *
     * The same row, so the same `event_id` — the partner's idempotency key. A
     * replay is "here it is again", never a second event, and a partner who
     * already processed it should be able to discard it on that basis.
     */
    public function replay(PartnerWebhookDelivery $delivery)
    {
        $delivery->update([
            'status' => PartnerWebhookDelivery::STATUS_PENDING,
            'error'  => null,
        ]);

        DeliverPartnerWebhookJob::dispatch($delivery->id);

        return back()->with('success', "Queued {$delivery->event_id} for redelivery.");
    }

    /** Fire a test event at a company's endpoint. */
    public function ping(PartnerCompany $company)
    {
        if (! $company->webhookConfigured()) {
            return back()->withErrors(['webhook' =>
                "{$company->name} has no enabled webhook URL and secret to send to."]);
        }

        $delivery = $this->dispatcher->ping($company);

        return redirect()->route('admin.partners.webhooks.show', $delivery)
            ->with('success', 'Test event queued. Refresh in a moment for the response.');
    }
}
