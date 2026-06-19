@extends('layouts.admin')

@section('title', 'Kartra Item: ' . $kartraImport->kartra_title)
@section('page-title', 'Map Import Item')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.kartra.index') }}">Kartra Import</a></li>
    <li class="breadcrumb-item active">{{ Str::limit($kartraImport->kartra_title, 40) }}</li>
@endsection

@section('content')
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="row">
    <div class="col-md-7">
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Kartra Content Info</h5></div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr><th class="text-muted small">Type</th><td>{{ $kartraImport->kartra_type }}</td></tr>
                    <tr><th class="text-muted small">Title</th><td>{{ $kartraImport->kartra_title }}</td></tr>
                    <tr><th class="text-muted small">Status</th>
                        <td><span class="badge badge-light-{{ $kartraImport->status === 'mapped' ? 'success' : 'secondary' }}">
                            {{ $kartraImport->status }}</span></td></tr>
                    @if($kartraImport->kartra_description)
                    <tr><th class="text-muted small">Description</th><td>{{ $kartraImport->kartra_description }}</td></tr>
                    @endif
                    @if($kartraImport->kartra_url)
                    <tr><th class="text-muted small">Kartra URL</th>
                        <td><a href="{{ $kartraImport->kartra_url }}" target="_blank" class="small">
                            {{ Str::limit($kartraImport->kartra_url, 60) }}</a></td></tr>
                    @endif
                    @if($kartraImport->kartra_video_url)
                    <tr><th class="text-muted small">Video URL</th>
                        <td class="small" style="word-break:break-all">{{ $kartraImport->kartra_video_url }}</td></tr>
                    @endif
                    @if($kartraImport->parent)
                    <tr><th class="text-muted small">Parent</th>
                        <td><a href="{{ route('admin.kartra.show', $kartraImport->parent) }}">
                            {{ $kartraImport->parent->kartra_title }}</a></td></tr>
                    @endif
                </table>

                @if($kartraImport->videoAsset)
                <div class="alert alert-info py-2 small mt-2">
                    <strong>Video Asset:</strong>
                    <a href="{{ route('admin.video-assets.show', $kartraImport->videoAsset) }}">
                        {{ $kartraImport->videoAsset->title }}
                    </a>
                    — {{ ucfirst($kartraImport->videoAsset->vimeo_status) }}
                    @if($kartraImport->videoAsset->isOnVimeo())
                        / <a href="{{ $kartraImport->videoAsset->vimeo_url }}" target="_blank">View on Vimeo</a>
                    @endif
                </div>
                @endif
            </div>
        </div>

        {{-- Children --}}
        @if($kartraImport->children->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">Children ({{ $kartraImport->children->count() }})</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Title</th><th>Type</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach($kartraImport->children as $child)
                        <tr>
                            <td>{{ $child->kartra_title }}</td>
                            <td><span class="badge badge-light-secondary">{{ $child->kartra_type }}</span></td>
                            <td><span class="badge badge-light-{{ $child->status === 'mapped' ? 'success' : 'secondary' }}">
                                {{ $child->status }}</span></td>
                            <td><a href="{{ route('admin.kartra.show', $child) }}" class="btn btn-xs btn-outline-secondary">Map</a></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>

    <div class="col-md-5">
        {{-- Mapping form --}}
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">Map to Local Training Content</h6></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.kartra.map', $kartraImport) }}">
                    @csrf @method('PATCH')

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Training Category</label>
                        <select name="local_category_id" class="form-select form-select-sm">
                            <option value="">— None —</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}"
                                    {{ $kartraImport->local_category_id == $cat->id ? 'selected' : '' }}>
                                    {{ $cat->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Training Lesson</label>
                        <select name="local_lesson_id" class="form-select form-select-sm">
                            <option value="">— None —</option>
                            @foreach($lessons as $lesson)
                                <option value="{{ $lesson->id }}"
                                    {{ $kartraImport->local_lesson_id == $lesson->id ? 'selected' : '' }}>
                                    [{{ $lesson->category?->name ?? '?' }}] {{ $lesson->title }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            @foreach(['discovered','downloaded','mapped','skipped'] as $s)
                                <option value="{{ $s }}" {{ $kartraImport->status === $s ? 'selected' : '' }}>
                                    {{ ucfirst($s) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm w-100">Save Mapping</button>
                </form>
            </div>
        </div>

        <div class="d-flex gap-2">
            <a href="{{ route('admin.kartra.index') }}" class="btn btn-sm btn-outline-secondary w-50">← Back</a>
            <form method="POST" action="{{ route('admin.kartra.destroy', $kartraImport) }}"
                  onsubmit="return confirm('Delete this import record?')" class="w-50">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger w-100">Delete Record</button>
            </form>
        </div>
    </div>
</div>
@endsection
