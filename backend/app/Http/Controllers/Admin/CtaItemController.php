<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CtaItem;
use App\Support\PresentationCta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The library of things a guest can be asked to do.
 *
 * Written once and placed wherever they are wanted, so the wording of "book a
 * call" is the same on every video that asks for one — and changing it changes
 * it everywhere rather than in the seven places somebody remembers.
 */
class CtaItemController extends Controller
{
    public function index()
    {
        return view('admin.cta-items.index', [
            'items' => CtaItem::withCount('cues')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('admin.cta-items.form', [
            'item'  => new CtaItem([
                'kind'      => PresentationCta::JOIN,
                'opens_in'  => PresentationCta::OPENS_NEW,
                'is_active' => true,
            ]),
            'types' => $this->selectableTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $item = CtaItem::create($this->validated($request) + ['created_by' => $request->user()->id]);

        return redirect()->route('admin.cta-items.index')
            ->with('success', "\"{$item->name}\" is ready to use.");
    }

    public function edit(CtaItem $ctaItem)
    {
        return view('admin.cta-items.form', [
            'item'  => $ctaItem,
            'types' => $this->selectableTypes(),
        ]);
    }

    public function update(Request $request, CtaItem $ctaItem): RedirectResponse
    {
        $ctaItem->update($this->validated($request));

        return redirect()->route('admin.cta-items.index')->with('success', 'Saved.');
    }

    /**
     * Retire one.
     *
     * Deleted rather than deactivated only when nothing uses it. A cue pointing
     * at a missing item stops being offered, which is the safe failure — but
     * silently emptying a video of its buttons is not something to do by
     * accident, so it has to be deliberate.
     */
    public function destroy(CtaItem $ctaItem): RedirectResponse
    {
        if ($ctaItem->cues()->exists()) {
            return back()->with('error',
                "\"{$ctaItem->name}\" is placed on a video. Remove it there first, or switch it off.");
        }

        $ctaItem->delete();

        return redirect()->route('admin.cta-items.index')->with('success', 'Deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'     => 'required|string|max:120',
            'kind'     => ['required', Rule::in(array_keys($this->selectableTypes()))],
            'headline' => 'nullable|string|max:160',
            'label'    => 'nullable|string|max:60',
            'note'     => 'nullable|string|max:300',
            // A plain link and a booking both need somewhere to go; only the
            // sign-up page is built in.
            'url'      => 'nullable|url|max:500|required_if:kind,custom,schedule_call',
            'opens_in' => ['required', Rule::in(array_keys(PresentationCta::OPENS))],
        ], [
            'url.required_if' => 'Give the link this button should open.',
        ]) + ['is_active' => $request->boolean('is_active')];
    }

    /** Everything except "no button", which is the absence of an item. */
    private function selectableTypes(): array
    {
        return collect(PresentationCta::TYPES)
            ->except(PresentationCta::NONE)
            ->all();
    }
}
