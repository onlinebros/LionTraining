<?php

namespace App\Http\Controllers;

use App\Models\LiveLink;
use Illuminate\Support\Facades\DB;

/**
 * q3.life/live-zoom → today's Zoom room.
 *
 * Never cacheable: the whole point is that staff change the destination and
 * the same printed link follows it at once, so neither a browser nor
 * Cloudflare may hold on to a redirect.
 */
class LiveLinkRedirectController extends Controller
{
    public function __invoke(string $slug)
    {
        $link = LiveLink::where('slug', strtolower($slug))->first();

        abort_if(! $link, 404);

        if (! $link->isLive()) {
            return response()
                ->view('public.live-links.waiting', ['link' => $link])
                ->header('Cache-Control', 'no-store, max-age=0');
        }

        // A plain UPDATE, so a busy minute of clicks doesn't race on the count.
        DB::table('live_links')->where('id', $link->id)->update([
            'clicks'          => DB::raw('clicks + 1'),
            'last_clicked_at' => now(),
        ]);

        return redirect()->away($link->destination_url, 302)
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
