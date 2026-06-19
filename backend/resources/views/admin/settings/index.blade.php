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
        border-color: #2d3748;
        transform: scale(1.15);
    }
    .color-swatch-label span { font-size: .7rem; color: #718096; }
    .logo-preview { max-height: 60px; border-radius: 6px; border: 1px solid #e9edf1; padding: 4px; background: #fff; }
    .logo-preview-dark { background: #2b2b3b; }
    .mode-card { border: 2px solid #e9edf1; border-radius: 10px; padding: 20px; cursor: pointer; transition: border-color .15s; }
    .mode-card.selected { border-color: var(--theme-default); }
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
                                   value="{{ $settings['site_name'] ?? 'Lion Training' }}"
                                   placeholder="Lion Training">
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
                        <p class="small text-muted mt-3 mb-0">Applies to both admin and member areas site-wide.</p>
                    </div>
                </div>

                {{-- Default Mode --}}
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">Default Mode</h5></div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Sets the site-wide default. Users can still toggle their personal preference.</p>
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
