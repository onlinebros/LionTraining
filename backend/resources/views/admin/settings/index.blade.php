@extends('layouts.admin')

@section('title', 'Site Settings')
@section('page-title', 'Site Settings')

@section('breadcrumb')
    <li class="breadcrumb-item active">Site Settings</li>
@endsection

@push('styles')
<style>
    .color-swatch-label { display: inline-block; cursor: pointer; text-align: center; }
    .color-swatch-label input[type=radio] { display: none; }
    .color-dot {
        width: 38px; height: 38px; border-radius: 50%;
        display: block; margin: 0 auto 4px;
        border: 3px solid transparent;
        transition: transform .15s, border-color .15s;
    }
    .color-swatch-label input:checked + .color-dot {
        border-color: var(--q3-gold);
        transform: scale(1.15);
    }
    .color-swatch-label span { font-size: .7rem; color: var(--q3-text-muted); }
    .logo-preview { max-height: 60px; border-radius: var(--q3-radius-sm); border: 1px solid var(--q3-border);
        padding: 4px; background: var(--q3-surface-2); }
    .logo-preview-dark { background: var(--q3-black); }
    .mode-card { border: 1px solid var(--q3-border); border-radius: var(--q3-radius); padding: 20px;
        cursor: pointer; transition: border-color .15s; }
    .mode-card.selected { border-color: var(--q3-gold); background: var(--q3-gold-tint); }
    .mode-card input[type=radio] { display: none; }
</style>
@endpush

@section('content')
<div class="container-fluid">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data">
        @csrf

        <div class="row">

            {{-- ── Branding ─────────────────────────────── --}}
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">Branding</h5></div>
                    <div class="card-body">

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Site Name</label>
                            <input type="text" name="site_name" class="form-control"
                                   value="{{ $settings['site_name'] ?? 'Quantum Life' }}"
                                   placeholder="Quantum Life">
                        </div>

                        {{-- Logo Light --}}
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Logo (Light Mode)</label>
                            @if(!empty($settings['logo_light']))
                                <div class="mb-2 d-flex align-items-center gap-3">
                                    <img src="{{ Storage::url($settings['logo_light']) }}" class="logo-preview" alt="Light logo">
                                    <form method="POST" action="{{ route('admin.settings.logo.remove') }}" class="d-inline">
                                        @csrf @method('DELETE')
                                        <input type="hidden" name="field" value="logo_light">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                </div>
                            @else
                                <p class="small text-muted mb-2">Using default logo.</p>
                            @endif
                            <input type="file" name="logo_light" class="form-control" accept="image/*">
                            <div class="form-text">PNG/SVG, max 512KB. Shown on light backgrounds.</div>
                        </div>

                        {{-- Logo Dark --}}
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Logo (Dark Mode)</label>
                            @if(!empty($settings['logo_dark']))
                                <div class="mb-2 d-flex align-items-center gap-3">
                                    <img src="{{ Storage::url($settings['logo_dark']) }}" class="logo-preview logo-preview-dark" alt="Dark logo">
                                    <form method="POST" action="{{ route('admin.settings.logo.remove') }}" class="d-inline">
                                        @csrf @method('DELETE')
                                        <input type="hidden" name="field" value="logo_dark">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                </div>
                            @else
                                <p class="small text-muted mb-2">Falls back to light logo if not set.</p>
                            @endif
                            <input type="file" name="logo_dark" class="form-control" accept="image/*">
                            <div class="form-text">Used in dark mode. PNG/SVG, max 512KB.</div>
                        </div>

                        {{-- Logo Icon --}}
                        <div class="mb-2">
                            <label class="form-label fw-semibold">Icon / Favicon Logo</label>
                            @if(!empty($settings['logo_icon']))
                                <div class="mb-2 d-flex align-items-center gap-3">
                                    <img src="{{ Storage::url($settings['logo_icon']) }}" style="height:40px;" class="logo-preview" alt="Icon logo">
                                    <form method="POST" action="{{ route('admin.settings.logo.remove') }}" class="d-inline">
                                        @csrf @method('DELETE')
                                        <input type="hidden" name="field" value="logo_icon">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                </div>
                            @else
                                <p class="small text-muted mb-2">Square icon for collapsed sidebar & favicon.</p>
                            @endif
                            <input type="file" name="logo_icon" class="form-control" accept="image/*">
                            <div class="form-text">Square icon, max 256KB.</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Appearance ───────────────────────────── --}}
            <div class="col-xl-6">

                {{-- Color Scheme --}}
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">Color Scheme</h5></div>
                    <div class="card-body">
                        @php
                        $colors = [
                            'color-1' => ['#7366ff', 'Purple'],
                            'color-2' => ['#0da3b7', 'Teal'],
                            'color-3' => ['#1c9462', 'Green'],
                            'color-4' => ['#f27209', 'Orange'],
                            'color-5' => ['#e42e2c', 'Red'],
                            'color-6' => ['#2563eb', 'Blue'],
                        ];
                        $current = $settings['color_scheme'] ?? 'color-1';
                        @endphp
                        <div class="d-flex gap-3 flex-wrap">
                            @foreach($colors as $key => [$hex, $label])
                            <label class="color-swatch-label">
                                <input type="radio" name="color_scheme" value="{{ $key }}"
                                       {{ $current === $key ? 'checked' : '' }}>
                                <span class="color-dot" style="background:{{ $hex }};"></span>
                                <span>{{ $label }}</span>
                            </label>
                            @endforeach
                        </div>
                        {{-- The Q3 black-and-gold theme owns the accent colour, so these
                             swatches no longer repaint the UI. The setting is left in place
                             (and still saved) rather than dropped, so nothing that reads
                             color_scheme breaks. --}}
                        <p class="small text-muted mt-3 mb-0">
                            <strong class="q3-gold">Overridden by the Q3 theme.</strong>
                            The brand accent is fixed to Q3 gold across the admin and member
                            areas. This preference is still stored but no longer changes the UI.
                        </p>
                    </div>
                </div>

                {{-- Default Mode --}}
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">Default Mode</h5></div>
                    <div class="card-body">
                        {{-- Same story as the colour scheme: the back office is dark by
                             design, so the layouts pin data-theme rather than reading this. --}}
                        <p class="text-muted small mb-3">
                            <strong class="q3-gold">Overridden by the Q3 theme.</strong>
                            The back office renders dark by design. This preference is still
                            stored but no longer changes the UI.
                        </p>
                        <div class="row g-3">
                            @foreach(['light' => ['☀️', 'Light Mode', 'Clean and bright'], 'dark' => ['🌙', 'Dark Mode', 'Easy on the eyes']] as $val => [$icon, $label, $desc])
                            <div class="col-6">
                                <label class="mode-card {{ ($settings['default_mode'] ?? 'light') === $val ? 'selected' : '' }} w-100"
                                       onclick="this.previousElementSibling?.click(); document.querySelectorAll('.mode-card').forEach(c=>c.classList.remove('selected')); this.classList.add('selected')">
                                    <input type="radio" name="default_mode" value="{{ $val }}"
                                           {{ ($settings['default_mode'] ?? 'light') === $val ? 'checked' : '' }}>
                                    <div class="text-center">
                                        <div style="font-size:1.8rem;">{{ $icon }}</div>
                                        <div class="fw-semibold mt-1">{{ $label }}</div>
                                        <small class="text-muted">{{ $desc }}</small>
                                    </div>
                                </label>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- Training library release --}}
        @php $trainingVisibility = \App\Support\TrainingAccess::visibility(); @endphp
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h5 class="mb-0">Training Library</h5>
                        @if($trainingVisibility === 'members')
                            <span class="badge bg-success">Live for members</span>
                        @else
                            <span class="badge bg-danger">Hidden</span>
                        @endif
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            While the library is hidden, every training page returns “not found” for anyone but an
                            administrator, no video or worksheet is served, and members see no Training link.
                            Administrators can browse the whole library as it will ship. Releasing it opens it to
                            members under the usual subscription and monthly release rules.
                        </p>

                        <div class="row g-3">
                            @foreach([
                                'admin'   => ['Hidden — admin preview only', 'Nothing is reachable by members. Use this while the library is being loaded and checked.'],
                                'members' => ['Live for members', 'Members with an active Training Program subscription see the modules that have released to them.'],
                            ] as $val => $copy)
                            <div class="col-md-6">
                                <input type="radio" name="training_visibility" id="tv_{{ $val }}" value="{{ $val }}"
                                       {{ $trainingVisibility === $val ? 'checked' : '' }} style="display:none;">
                                <label class="mode-card {{ $trainingVisibility === $val ? 'selected' : '' }} w-100"
                                       for="tv_{{ $val }}"
                                       onclick="document.querySelectorAll('[name=training_visibility]').forEach(r=>r.closest('.col-md-6').querySelector('.mode-card').classList.remove('selected')); this.classList.add('selected');">
                                    <div class="fw-bold mb-1">{{ $copy[0] }}</div>
                                    <div class="text-muted small">{{ $copy[1] }}</div>
                                </label>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <button type="submit" class="btn btn-primary px-5">
                    <i data-feather="save" style="width:15px;height:15px;" class="me-2"></i>
                    Save All Settings
                </button>
            </div>
        </div>

    </form>
</div>
@endsection

@push('scripts')
<script>
// Make mode cards behave as radio selects
document.querySelectorAll('.mode-card').forEach(card => {
    card.addEventListener('click', function () {
        document.querySelectorAll('.mode-card').forEach(c => c.classList.remove('selected'));
        this.classList.add('selected');
        this.querySelector('input[type=radio]').checked = true;
    });
});
</script>
@endpush
