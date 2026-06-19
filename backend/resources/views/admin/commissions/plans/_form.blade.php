@php
    $oldType   = old('type', $plan?->type ?? 'flat');
    $config    = $plan?->config ?? [];
@endphp

<div class="mb-3">
    <label class="form-label">Plan Name <span class="text-danger">*</span></label>
    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
           value="{{ old('name', $plan?->name) }}" required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label class="form-label">Description</label>
    <textarea name="description" class="form-control" rows="2">{{ old('description', $plan?->description) }}</textarea>
</div>

<div class="mb-3">
    <label class="form-label">Commission Type <span class="text-danger">*</span></label>
    <select name="type" id="commission_type" class="form-select @error('type') is-invalid @enderror" required>
        <option value="flat"       {{ $oldType === 'flat'       ? 'selected' : '' }}>Flat Amount</option>
        <option value="percentage" {{ $oldType === 'percentage' ? 'selected' : '' }}>Percentage of Sale</option>
        <option value="tiered"     {{ $oldType === 'tiered'     ? 'selected' : '' }}>Tiered</option>
    </select>
    @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

{{-- Flat config --}}
<div id="config_flat" class="mb-3 {{ $oldType !== 'flat' ? 'd-none' : '' }}">
    <label class="form-label">Fixed Amount ($)</label>
    <input type="number" name="config_amount" step="0.01" min="0" class="form-control"
           value="{{ old('config_amount', $config['amount'] ?? '') }}">
</div>

{{-- Percentage config --}}
<div id="config_percentage" class="mb-3 {{ $oldType !== 'percentage' ? 'd-none' : '' }}">
    <label class="form-label">Rate (decimal, e.g. 0.10 = 10%)</label>
    <input type="number" name="config_rate" step="0.001" min="0" max="1" class="form-control"
           value="{{ old('config_rate', $config['rate'] ?? '') }}">
</div>

{{-- Tiered config --}}
<div id="config_tiered" class="mb-3 {{ $oldType !== 'tiered' ? 'd-none' : '' }}">
    <label class="form-label">Tiers (JSON array)</label>
    <textarea name="config_tiers" class="form-control font-monospace" rows="5"
              placeholder='[{"min":0,"max":999.99,"rate":0.05},{"min":1000,"max":null,"rate":0.10}]'>{{ old('config_tiers', isset($config['tiers']) ? json_encode($config['tiers'], JSON_PRETTY_PRINT) : '') }}</textarea>
    <div class="form-text">Each tier: <code>{"min": 0, "max": 999.99, "rate": 0.05}</code>. Set <code>max</code> to <code>null</code> for the top tier.</div>
</div>

<div class="mb-3">
    <label class="form-label">Clawback Window (days)</label>
    <input type="number" name="clawback_window_days" min="1" max="3650" class="form-control"
           style="max-width:200px"
           value="{{ old('clawback_window_days', $plan?->clawback_window_days) }}"
           placeholder="Leave blank for manual-only">
    <div class="form-text">How many days after earning a commission it remains automatically clawback-eligible. Leave blank for manual admin override only.</div>
</div>

<div class="mb-3">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
               {{ old('is_active', $plan?->is_active ?? true) ? 'checked' : '' }}>
        <label class="form-check-label" for="is_active">Active</label>
    </div>
</div>

@push('scripts')
<script>
document.getElementById('commission_type').addEventListener('change', function () {
    ['flat', 'percentage', 'tiered'].forEach(t => {
        document.getElementById('config_' + t).classList.toggle('d-none', t !== this.value);
    });
});
</script>
@endpush
