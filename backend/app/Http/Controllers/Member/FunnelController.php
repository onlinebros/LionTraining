<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\PresentationFunnel;
use App\Services\Presentations\FunnelReport;
use Illuminate\Http\Request;

/**
 * A member's view of the flows they can share.
 *
 * Their own link, and their own prospects inside it. Every query goes through
 * `visibleTo()`, so there is no path here that returns somebody else's people.
 */
class FunnelController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $funnels = PresentationFunnel::shareableBy($user)
            ->whereNotNull('entry_presentation_id')
            ->withCount('steps')
            ->orderBy('title')
            ->get();

        return view('member.funnels.index', [
            'funnels' => $funnels,
            'user'    => $user,
            // How many of this member's own prospects are in each one, which is
            // the only number that means anything to them.
            'mine'    => \App\Models\FunnelParticipant::query()
                ->visibleTo($user)
                ->whereIn('funnel_id', $funnels->pluck('id'))
                ->selectRaw('funnel_id, count(*) as total')
                ->groupBy('funnel_id')
                ->pluck('total', 'funnel_id'),
        ]);
    }

    public function show(Request $request, PresentationFunnel $funnel, FunnelReport $report)
    {
        $user = $request->user();

        abort_unless($funnel->isShareableBy($user), 403);

        return view('member.funnels.show', [
            'funnel'   => $funnel->load('steps'),
            'shareUrl' => $funnel->shareUrlFor($user),
            'people'   => $report->people($funnel, $user),
            'choices'  => $report->choices($funnel, $user),
            'totals'   => $report->totals($funnel, $user),
        ]);
    }
}
