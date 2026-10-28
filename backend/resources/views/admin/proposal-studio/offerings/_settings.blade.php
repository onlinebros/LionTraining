{{-- How the assistant presents the offering. --}}
<div class="row g-3">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-bold">How the assistant presents it</h6></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.proposal-studio.offerings.update', $offering) }}">
                    @csrf @method('PUT')
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="of-name">Name</label>
                        <input id="of-name" name="name" class="form-control" maxlength="255" required value="{{ old('name', $offering->name) }}">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="of-vendor">Vendor</label>
                            <input id="of-vendor" name="vendor_name" class="form-control" maxlength="255" required value="{{ old('vendor_name', $offering->vendor_name) }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="of-url">Vendor website</label>
                            <input id="of-url" type="url" name="vendor_url" class="form-control @error('vendor_url') is-invalid @enderror" maxlength="255" value="{{ old('vendor_url', $offering->vendor_url) }}">
                            @error('vendor_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="of-vendor-key">Vendor in the Product Partner portal</label>
                        <select id="of-vendor-key" name="vendor_key" class="form-select">
                            <option value="">None: no vendor review or visibility</option>
                            @foreach((array) config('vendors.vendors') as $key => $vendorConfig)
                                <option value="{{ $key }}" @selected(old('vendor_key', $offering->vendor_key) === $key)>{{ $vendorConfig['name'] ?? $key }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">That vendor's people review the first {{ config('proposal-studio.vendor_review_first') }} proposals, and see published ones.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="of-opp">Business line</label>
                        <select id="of-opp" name="opportunity_key" class="form-select">
                            <option value="">None</option>
                            @foreach((array) config('opportunities.opportunities') as $key => $opp)
                                <option value="{{ $key }}" @selected(old('opportunity_key', $offering->opportunity_key) === $key)>{{ $opp['name'] ?? $key }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="of-summary">Summary</label>
                        <textarea id="of-summary" name="summary" rows="3" class="form-control" maxlength="5000">{{ old('summary', $offering->summary) }}</textarea>
                        <div class="form-text">One or two sentences: what it is and who it is for.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="of-guidance">Guidance for the assistant</label>
                        <textarea id="of-guidance" name="ai_guidance" rows="5" class="form-control" maxlength="20000" placeholder="How we position it, which industries to lead with, tone, what to recommend first…">{{ old('ai_guidance', $offering->ai_guidance) }}</textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="of-prohibited">Claims never to make</label>
                        <textarea id="of-prohibited" name="prohibited_claims" rows="4" class="form-control" maxlength="20000" placeholder="One per line, e.g. that it prevents or cures any disease">{{ old('prohibited_claims', $offering->prohibited_claims) }}</textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="of-checklist">Discovery checklist</label>
                        <textarea id="of-checklist" name="discovery_checklist" rows="8" class="form-control" maxlength="10000">{{ old('discovery_checklist', $offering->discovery_checklist) }}</textarea>
                        <div class="form-text">What we need to learn about a prospect, one item per line. The assistant asks from this list.</div>
                    </div>
                    <div class="form-check mb-3">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="of-active" @checked(old('is_active', $offering->is_active))>
                        <label class="form-check-label small" for="of-active">Active: new prospects can use it</label>
                    </div>
                    <button class="btn btn-primary btn-sm">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>
