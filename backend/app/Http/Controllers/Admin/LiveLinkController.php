<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LiveLink;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin → Live Links: point q3.life/live-zoom and friends at today's call.
 *
 * Super admin only. These addresses sit on the company's own domain, so
 * whoever edits them decides where a link carrying the Q3 name sends people.
 */
class LiveLinkController extends Controller
{
    public function index()
    {
        return view('admin.live-links.index', [
            'links'  => LiveLink::with('editor')->orderBy('id')->get(),
            'prefix' => config('live_links.prefix'),
            'base'   => config('live_links.public_base_url'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'slug'  => ['required', 'string', 'max:60', 'regex:/^' . LiveLink::SLUG_PATTERN . '$/', 'unique:live_links,slug'],
            'label' => ['required', 'string', 'max:100'],
            'destination_url' => $this->urlRules(),
        ], [
            'slug.regex' => 'The address must start with "live-" and use only lowercase letters, numbers and hyphens (e.g. live-q-and-a).',
        ]);

        $link = LiveLink::create($data + ['is_active' => true, 'updated_by' => $request->user()->id]);

        return back()->with('success', "Added {$link->publicUrl()}.");
    }

    public function update(Request $request, LiveLink $liveLink)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'destination_url' => $this->urlRules(),
            'is_active' => ['nullable', 'boolean'],
        ]);

        $liveLink->update([
            'label'           => $data['label'],
            'destination_url' => $data['destination_url'] ?? null,
            'is_active'       => $request->boolean('is_active'),
            'updated_by'      => $request->user()->id,
        ]);

        $state = $liveLink->isLive()
            ? 'now goes to ' . $liveLink->destination_url
            : 'now shows the "nothing live right now" page';

        return back()->with('success', "{$liveLink->publicUrl()} {$state}.");
    }

    public function destroy(LiveLink $liveLink)
    {
        $url = $liveLink->publicUrl();
        $liveLink->delete();

        return back()->with('success', "Removed {$url}. Anyone who still has it will get a not-found page.");
    }

    /** https only: these links carry the company's domain, so no plain http or javascript: tricks. */
    private function urlRules(): array
    {
        return ['nullable', 'string', 'max:2000', 'url:https'];
    }
}
