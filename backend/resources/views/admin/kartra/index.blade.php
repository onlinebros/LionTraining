@extends('layouts.admin')

@section('title', 'Kartra Import')
@section('page-title', 'Kartra Content Import')

@section('breadcrumb')
    <li class="breadcrumb-item">Training</li>
    <li class="breadcrumb-item active">Kartra Import</li>
@endsection

@section('content')
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

{{-- Stats cards --}}
<div class="row mb-4">
    @foreach([
        ['label' => 'Total Items',  'key' => 'total',      'color' => 'primary'],
        ['label' => 'Discovered',   'key' => 'discovered',  'color' => 'secondary'],
        ['label' => 'Downloaded',   'key' => 'downloaded',  'color' => 'success'],
        ['label' => 'Mapped',       'key' => 'mapped',      'color' => 'info'],
        ['label' => 'With Video',   'key' => 'videos',      'color' => 'warning'],
        ['label' => 'Failed',       'key' => 'failed',      'color' => 'danger'],
    ] as $card)
    <div class="col-6 col-md-2 mb-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <h3 class="text-{{ $card['color'] }} mb-0">{{ $stats[$card['key']] }}</h3>
                <small class="text-muted">{{ $card['label'] }}</small>
            </div>
        </div>
    </div>
    @endforeach
</div>

<div class="row">
    <div class="col-md-8">
        {{-- Import content tree --}}
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Content Structure</h5>
                <a href="{{ route('admin.video-assets.index') }}" class="btn btn-sm btn-outline-primary">
                    Video Library →
                </a>
            </div>
            <div class="card-body p-0">
                @forelse($topLevel as $module)
                <div class="border-bottom p-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="badge badge-light-secondary me-2">{{ strtoupper($module->kartra_type) }}</span>
                            <strong>{{ $module->kartra_title }}</strong>
                            @if($module->kartra_order)
                                <small class="text-muted ms-1">#{{ $module->kartra_order }}</small>
                            @endif
                        </div>
                        <div class="d-flex gap-1">
                            @if($module->localCategory)
                                <span class="badge badge-light-success">→ {{ $module->localCategory->name }}</span>
                            @endif
                            <a href="{{ route('admin.kartra.show', $module) }}" class="btn btn-xs btn-outline-secondary">
                                Map
                            </a>
                        </div>
                    </div>

                    {{-- Children (lessons) --}}
                    @foreach($module->children as $lesson)
                    <div class="d-flex align-items-center justify-content-between ms-4 mt-2 py-2 border-top">
                        <div>
                            <span class="badge badge-light-info me-2">{{ strtoupper($lesson->kartra_type) }}</span>
                            {{ $lesson->kartra_title }}
                            @if($lesson->kartra_video_url)
                                <i data-feather="video" data-width="12" data-height="12" class="text-primary ms-1" title="Has video"></i>
                            @endif
                        </div>
                        <div class="d-flex gap-1 align-items-center">
                            @if($lesson->videoAsset)
                                @if($lesson->videoAsset->isOnVimeo())
                                    <span class="badge badge-light-success">Vimeo ✓</span>
                                @else
                                    <span class="badge badge-light-warning">{{ ucfirst($lesson->videoAsset->vimeo_status) }}</span>
                                @endif
                            @elseif($lesson->kartra_video_url)
                                <span class="badge badge-light-secondary">No download</span>
                            @endif
                            @if($lesson->localLesson)
                                <span class="badge badge-light-success">→ {{ Str::limit($lesson->localLesson->title, 25) }}</span>
                            @endif
                            <span class="badge badge-light-{{ $lesson->status === 'mapped' ? 'success' : ($lesson->status === 'failed' ? 'danger' : 'secondary') }}">
                                {{ $lesson->status }}
                            </span>
                            <a href="{{ route('admin.kartra.show', $lesson) }}" class="btn btn-xs btn-outline-secondary">Map</a>
                        </div>
                    </div>
                    @endforeach
                </div>
                @empty
                <div class="text-center text-muted py-5">
                    <p>No import data yet.</p>
                    <p class="mb-0">
                        Run <code>php artisan kartra:import</code> from the server,
                        or upload a JSON content file below.
                    </p>
                </div>
                @endforelse
            </div>
            @if($topLevel->hasPages())
                <div class="card-footer">{{ $topLevel->links() }}</div>
            @endif
        </div>
    </div>

    <div class="col-md-4">
        {{-- Import actions --}}
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">Import Actions</h6></div>
            <div class="card-body">
                <p class="text-muted small">
                    The Kartra portal scrape is best run from the CLI. Use these tools to import data
                    or trigger video downloads.
                </p>

                <div class="alert alert-info py-2 small">
                    <strong>CLI command:</strong><br>
                    <code>php artisan kartra:import</code><br>
                    <code>php artisan video:upload-vimeo</code>
                </div>

                {{-- Download pending videos --}}
                @if($stats['discovered'] > 0)
                <form method="POST" action="{{ route('admin.kartra.download-videos') }}" class="mb-3">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-warning w-100">
                        <i data-feather="download" data-width="14"></i>
                        Download Pending Videos ({{ $stats['discovered'] }})
                    </button>
                </form>
                @endif

                {{-- JSON Import --}}
                <div class="border-top pt-3">
                    <p class="text-muted small mb-2">
                        <strong>Import from JSON file</strong> — upload a manually-exported content map:
                    </p>
                    <form method="POST" action="{{ route('admin.kartra.import-json') }}"
                          enctype="multipart/form-data">
                        @csrf
                        <div class="mb-2">
                            <input type="file" name="json_file" class="form-control form-control-sm"
                                   accept=".json,.txt" required>
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                            Import JSON
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- JSON format reference --}}
        <div class="card">
            <div class="card-header"><h6 class="mb-0">JSON Format Reference</h6></div>
            <div class="card-body">
                <pre class="small text-muted mb-0" style="font-size:11px">[
  {
    "type": "module",
    "title": "Module 1",
    "order": 1,
    "children": [
      {
        "type": "lesson",
        "title": "Lesson 1",
        "video_url": "https://...",
        "description": "...",
        "order": 1
      }
    ]
  }
]</pre>
            </div>
        </div>
    </div>
</div>
@endsection
