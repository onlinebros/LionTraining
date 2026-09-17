<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The companies whose lists we take, and the branding on their claim pages.
 */
class PartnerCompanyController extends Controller
{
    public function index()
    {
        $companies = PartnerCompany::withCount('imports')->orderBy('name')->get();

        return view('admin.partners.companies', [
            'companies' => $companies,
            // One query for every company's split rather than one per card.
            'counts'    => $companies->mapWithKeys(
                fn (PartnerCompany $c) => [$c->id => $c->spotCounts()],
            ),
        ]);
    }

    public function create()
    {
        return view('admin.partners.company-form', ['company' => new PartnerCompany()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $company = PartnerCompany::create($data);
        $this->storeLogos($request, $company);

        return redirect()->route('admin.partners.companies.index')
            ->with('success', "{$company->name} added. Its claim page is {$company->claimUrl()}");
    }

    public function edit(PartnerCompany $company)
    {
        return view('admin.partners.company-form', compact('company'));
    }

    public function update(Request $request, PartnerCompany $company)
    {
        $company->update($this->validated($request, $company));
        $this->storeLogos($request, $company);

        return redirect()->route('admin.partners.companies.index')
            ->with('success', "{$company->name} updated.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PartnerCompany $company = null): array
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'slug'             => [
                'nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                'unique:partner_companies,slug' . ($company ? ",{$company->id}" : ''),
            ],
            'headline'         => 'nullable|string|max:255',
            'intro'            => 'nullable|string|max:2000',
            'support_email'    => 'nullable|email|max:255',
            'identifier_label' => 'nullable|string|max:64',
            'activation_label' => 'nullable|string|max:64',
            'is_active'        => 'nullable|boolean',
            'logo'             => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:2048',
            'logo_dark'        => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:2048',

            // https only. The payload can carry a member's contact details and
            // is authenticated by a shared secret; neither survives a plaintext
            // hop, and "they only support http" is a conversation to have with
            // the partner, not a setting to allow.
            'webhook_url'      => 'nullable|url|starts_with:https://|max:255',
            'webhook_secret'   => 'nullable|string|min:16|max:255',
            'webhook_signature_style' => 'nullable|in:' . implode(',', array_keys(\App\Models\PartnerCompany::SIGNATURE_STYLES)),
            'webhook_enabled'  => 'nullable|boolean',
            'webhook_include_contact' => 'nullable|boolean',
        ], [
            'slug.regex' => 'The link must be lowercase letters, numbers and hyphens — it becomes part of the URL.',
            'webhook_url.starts_with' => 'The webhook URL must be https — the payload is signed and may carry contact details.',
            'webhook_secret.min' => 'Use at least 16 characters. This secret is what proves a delivery came from us.',
        ]);

        // The slug is in the URL the partner prints, so it is set once and then
        // left alone: changing it silently breaks every link already sent out.
        if ($company !== null) {
            unset($data['slug']);
        } else {
            $data['slug'] = $data['slug'] ?: Str::slug($data['name']);
        }

        $data['is_active']               = $request->boolean('is_active');
        $data['webhook_enabled']         = $request->boolean('webhook_enabled');
        $data['webhook_include_contact'] = $request->boolean('webhook_include_contact');

        // A blank secret field means "leave the one you have". The form cannot
        // show the current secret back — it is encrypted and there is no reason
        // to put it on a screen — so treating blank as "clear it" would silently
        // break the integration every time somebody edited the headline.
        if (blank($data['webhook_secret'] ?? null)) {
            unset($data['webhook_secret']);
        }

        foreach (['identifier_label', 'activation_label'] as $label) {
            if (blank($data[$label] ?? null)) {
                unset($data[$label]);
            }
        }

        unset($data['logo'], $data['logo_dark']);

        return $data;
    }

    private function storeLogos(Request $request, PartnerCompany $company): void
    {
        foreach (['logo' => 'logo_path', 'logo_dark' => 'logo_dark_path'] as $input => $column) {
            if (! $request->hasFile($input)) {
                continue;
            }

            $old = $company->{$column};
            $company->{$column} = $request->file($input)->store("partner-logos/{$company->slug}", 'public');
            $company->save();

            if ($old !== null) {
                Storage::disk('public')->delete($old);
            }
        }
    }
}
