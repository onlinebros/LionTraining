@extends('layouts.admin')

@section('title', 'Video Library')
@section('page-title', 'Video Library')

@section('breadcrumb')
    <li class="breadcrumb-item">Training</li>
    <li class="breadcrumb-item active">Video Library</li>
@endsection

@section('content')
<div class="row mb-3">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex gap-2 align-items-center flex-wrap">
                {{-- Status filter --}}
                <a href="{{ route('admin.video-assets.index') }}"
                   class="btn btn-sm {{ !request('status') ? 'btn-primary' : 'btn-outline-secondary' }}">
                    All <span class="badge bg-secondary ms-1">{{ $assets->total() }}</span>
                </a>
                @foreach(['pending','uploading','uploaded','failed'] as $s)
                    <a href="{{ route('admin.video-assets.index', ['status' => $s]) }}"
                       class="btn btn-sm {{ request('status') === $s ? 'btn-primary' : 'btn-outline-secondary' }}">
                        {{ ucfirst($s) }}
                        @if(isset($statusCounts[$s]))
                            <span class="badge bg-secondary ms-1">{{ $statusCounts[$s] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.kartra.index') }}" class="btn btn-outline-info btn-sm">
                    <i data-feather="download" data-width="14" data-height="14"></i> Kartra Import
                </a>
            </div>
        </div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif
@if(session('info'))
    <div class="alert alert-info">{{ session('info') }}</div>
@endif

{{-- Vimeo token warning --}}
@if(!config('services.vimeo.access_token'))
    <div class="alert alert-warning">
        <strong>Vimeo not configured.</strong>
        Add <code>VIMEO_ACCESS_TOKEN</code> to your <code>.env</code> to enable Vimeo uploads.
        See <code>config/services.php</code> for details.
    </div>
@endif

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Video Assets ({{ $assets->total() }})</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Source</th>
                                <th>Size</th>
                                <th>Local File</th>
                                <th>Vimeo</th>
                                <th>Linked Block</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($assets as $asset)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $asset->title }}</div>
                                    @if($asset->description)
                                        <small class="text-muted">{{ Str::limit($asset->description, 60) }}</small>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge badge-light-info">{{ $asset->source }}</span>
                                    @if($asset->source_id)
                                        <small class="text-muted d-block">ID: {{ $asset->source_id }}</small>
                                    @endif
                                </td>
                                <td class="text-muted small">{{ $asset->formattedSize() }}</td>
                                <td>
                                    @if($asset->isDownloaded())
                                        <span class="badge badge-light-success">
                                            <i data-feather="check" data-width="12" data-height="12"></i> Downloaded
                                        </span>
                                        <small class="text-muted d-block">{{ $asset->local_filename }}</small>
                                    @else
                                        <span class="badge badge-light-warning">Not Downloaded</span>
                                    @endif
                                </td>
                                <td>
                                    @if($asset->vimeo_status === 'uploaded')
                                        <a href="{{ $asset->vimeo_url }}" target="_blank"
                                           class="badge badge-light-success text-decoration-none">
                                            <i data-feather="video" data-width="12" data-height="12"></i>
                                            On Vimeo
                                        </a>
                                    @elseif($asset->vimeo_status === 'uploading')
                                        <span class="badge badge-light-warning">Uploading…</span>
                                    @elseif($asset->vimeo_status === 'failed')
                                        <span class="badge badge-light-danger" title="{{ $asset->vimeo_upload_error }}">
                                            Failed
                                        </span>
                                    @else
                                        <span class="badge badge-light-secondary">Pending</span>
                                    @endif
                                </td>
                                <td class="text-muted small">
                                    @if($asset->contentBlock)
                                        {{ $asset->contentBlock->lesson?->title ?? '—' }}
                                    @else
                                        <em class="text-muted">Unassigned</em>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="d-flex gap-1 justify-content-end">
                                        <a href="{{ route('admin.video-assets.show', $asset) }}"
                                           class="btn btn-sm btn-warning">Manage</a>
                                        @if($asset->isDownloaded() && $asset->vimeo_status !== 'uploaded')
                                            <form method="POST" action="{{ route('admin.video-assets.upload-vimeo', $asset) }}">
                                                @csrf
                                                <button class="btn btn-sm btn-primary" type="submit">
                                                    Upload Vimeo
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No video assets yet.
                                    <a href="{{ route('admin.kartra.index') }}">Run the Kartra import</a> to get started.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($assets->hasPages())
                    <div class="p-3">{{ $assets->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
