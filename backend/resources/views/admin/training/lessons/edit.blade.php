@extends('layouts.admin')

@section('title', 'Edit Lesson')
@section('page-title', 'Edit Lesson')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.training.lessons.index') }}">Lessons</a></li>
    <li class="breadcrumb-item active">{{ $lesson->title }}</li>
@endsection

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css">
<style>
/* ── Add-block panel ─────────────────────────────────────────────────── */
#add-block-panel .add-block-inner {
    background: #ffffff;
    border-bottom: 1px solid #dee2e6;
    padding: 20px;
}
#add-block-panel .add-block-inner .form-label,
#add-block-panel .add-block-inner label,
#add-block-panel .add-block-inner h6,
#add-block-panel .add-block-inner small {
    color: #374151 !important;
}
#add-block-panel .add-block-inner .form-control,
#add-block-panel .add-block-inner .form-select {
    background-color: #f9fafb;
    color: #111827;
    border-color: #d1d5db;
}
#add-block-panel .add-block-inner .form-control::placeholder { color: #9ca3af; }
#add-block-panel .add-block-inner .nav-link { color: #374151; }
#add-block-panel .add-block-inner .nav-link.active { color: #fff; }

/* ── Block cards ─────────────────────────────────────────────────────── */
.block-card {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    margin-bottom: 12px;
    background: #ffffff;
}
.block-card .block-header {
    padding: 10px 14px;
    background: #f3f4f6;
    border-bottom: 1px solid #dee2e6;
    border-radius: 8px 8px 0 0;
    display: flex; align-items: center; gap: 8px; cursor: pointer;
    color: #111827;
}
.block-card .block-header .fw-semibold { color: #111827; }
.block-card .block-header small { color: #6b7280 !important; }
.block-card .block-body { padding: 16px; display: none; }
.block-card .block-body .form-label,
.block-card .block-body label { color: #374151 !important; }
.block-card .block-body .form-control,
.block-card .block-body .form-select {
    background-color: #f9fafb;
    color: #111827;
    border-color: #d1d5db;
}
.block-card.open .block-body { display: block; }

/* ── Type badges ─────────────────────────────────────────────────────── */
.block-type-badge { font-size: .7rem; padding: 2px 8px; border-radius: 20px; font-weight: 600; text-transform: uppercase; }
.type-video    { background: #fef3c7; color: #92400e; }
.type-text     { background: #dbeafe; color: #1e40af; }
.type-download { background: #d1fae5; color: #065f46; }

.drag-handle { cursor: grab; color: #9ca3af; }
</style>
@endpush

@section('content')

{{-- ── Lesson metadata ─────────────────────────────────────────────────── --}}
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Lesson Settings</h5>
        <div class="d-flex gap-2">
            @if($lesson->is_published)
                <a href="{{ route('member.training.lesson', $lesson->slug) }}"
                   target="_blank" class="btn btn-outline-success btn-sm">
                    <i data-feather="external-link" style="width:13px;height:13px;"></i> Preview
                </a>
            @endif
            <form method="POST" action="{{ route('admin.training.lessons.destroy', $lesson) }}"
                  onsubmit="return confirm('Delete this lesson and all its content?')">
                @csrf @method('DELETE')
                <button class="btn btn-outline-danger btn-sm">Delete Lesson</button>
            </form>
        </div>
    </div>
    <div class="card-body">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger">{{ $errors->first() }}</div>
        @endif
        <form method="POST" action="{{ route('admin.training.lessons.update', $lesson) }}" enctype="multipart/form-data">
            @csrf @method('PUT')
            @include('admin.training.lessons._form', ['lesson' => $lesson, 'selected' => $lesson->category_id])
            <button type="submit" class="btn btn-primary">Save Lesson Settings</button>
        </form>
    </div>
</div>

{{-- ── Content blocks ──────────────────────────────────────────────────── --}}
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Content Blocks
            <span class="badge badge-light-secondary ms-1">{{ $lesson->allContentBlocks->count() }}</span>
        </h5>
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse"
                data-bs-target="#add-block-panel">
            <i data-feather="plus" style="width:13px;height:13px;"></i> Add Block
        </button>
    </div>

    {{-- Add block panel --}}
    <div class="collapse" id="add-block-panel">
        <div class="add-block-inner">
            <h6 class="fw-bold mb-3">Add New Block</h6>

            {{-- Type tabs --}}
            <ul class="nav nav-pills mb-3" id="addBlockTabs">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-video">
                        <i data-feather="video" style="width:13px;height:13px;"></i> Video
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-text">
                        <i data-feather="align-left" style="width:13px;height:13px;"></i> Rich Text
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-download">
                        <i data-feather="download" style="width:13px;height:13px;"></i> Download
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                {{-- VIDEO --}}
                <div class="tab-pane fade show active" id="tab-video">
                    <form method="POST" action="{{ route('admin.training.content-blocks.store') }}" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="lesson_id" value="{{ $lesson->id }}">
                        <input type="hidden" name="type" value="video">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Block Title (optional)</label>
                                <input type="text" name="title" class="form-control" placeholder="e.g. Introduction">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Sort Order</label>
                                <input type="number" name="sort_order" class="form-control"
                                       value="{{ ($lesson->allContentBlocks->max('sort_order') ?? 0) + 10 }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Video URL <small class="text-muted">(YouTube, Vimeo, or direct .mp4)</small></label>
                                <input type="text" name="video_url" class="form-control"
                                       placeholder="https://www.youtube.com/watch?v=...">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Provider</label>
                                <select name="video_provider" class="form-select">
                                    <option value="youtube">YouTube</option>
                                    <option value="vimeo">Vimeo</option>
                                    <option value="file">Direct file</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-warning btn-sm">Add Video Block</button>
                            </div>
                        </div>
                    </form>
                </div>

                {{-- RICH TEXT --}}
                <div class="tab-pane fade" id="tab-text">
                    <form method="POST" action="{{ route('admin.training.content-blocks.store') }}">
                        @csrf
                        <input type="hidden" name="lesson_id" value="{{ $lesson->id }}">
                        <input type="hidden" name="type" value="text">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Block Title (optional)</label>
                                <input type="text" name="title" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Sort Order</label>
                                <input type="number" name="sort_order" class="form-control"
                                       value="{{ ($lesson->allContentBlocks->max('sort_order') ?? 0) + 10 }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Content</label>
                                <textarea name="body" class="summernote-new" rows="6"></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-info btn-sm">Add Text Block</button>
                            </div>
                        </div>
                    </form>
                </div>

                {{-- DOWNLOAD --}}
                <div class="tab-pane fade" id="tab-download">
                    <form method="POST" action="{{ route('admin.training.content-blocks.store') }}" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="lesson_id" value="{{ $lesson->id }}">
                        <input type="hidden" name="type" value="download">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Block Title / File Label <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control" placeholder="e.g. Training PDF" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Sort Order</label>
                                <input type="number" name="sort_order" class="form-control"
                                       value="{{ ($lesson->allContentBlocks->max('sort_order') ?? 0) + 10 }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label">File <span class="text-danger">*</span></label>
                                <input type="file" name="upload_file" class="form-control" required>
                                <small class="text-muted">Max 100 MB. Any file type.</small>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-success btn-sm">Add Download Block</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Existing blocks --}}
    <div class="card-body">
        @if($lesson->allContentBlocks->isEmpty())
            <p class="text-muted text-center py-3">No content blocks yet. Click "Add Block" above to get started.</p>
        @else
            <div id="blocks-container">
                @foreach($lesson->allContentBlocks as $block)
                <div class="block-card" id="block-{{ $block->id }}" data-id="{{ $block->id }}">
                    <div class="block-header" onclick="toggleBlock({{ $block->id }})">
                        <span class="drag-handle me-1">⠿</span>
                        <span class="block-type-badge type-{{ $block->type }}">{{ $block->type }}</span>
                        <span class="fw-semibold flex-grow-1 ms-2">
                            {{ $block->title ?: ($block->type === 'video' ? ($block->video_url ?: 'Video') : ($block->type === 'download' ? $block->file_name : 'Text block')) }}
                        </span>
                        @if(!$block->is_active)
                            <span class="badge badge-light-danger me-2">Hidden</span>
                        @endif
                        <small class="text-muted me-2">Order: {{ $block->sort_order }}</small>
                        <form method="POST" action="{{ route('admin.training.content-blocks.destroy', $block) }}"
                              onsubmit="return confirm('Remove this block?'); event.stopPropagation();">
                            @csrf @method('DELETE')
                            <button class="btn btn-outline-danger btn-xs py-0 px-2"
                                    onclick="event.stopPropagation()">
                                <i data-feather="trash-2" style="width:11px;height:11px;"></i>
                            </button>
                        </form>
                    </div>
                    <div class="block-body">
                        <form method="POST" action="{{ route('admin.training.content-blocks.update', $block) }}"
                              enctype="multipart/form-data">
                            @csrf @method('PUT')

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Block Title</label>
                                    <input type="text" name="title" class="form-control" value="{{ $block->title }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Sort Order</label>
                                    <input type="number" name="sort_order" class="form-control" value="{{ $block->sort_order }}">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                               {{ $block->is_active ? 'checked' : '' }}>
                                        <label class="form-check-label">Active</label>
                                    </div>
                                </div>
                            </div>

                            @if($block->type === 'video')
                                <div class="row g-3 mb-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Video URL</label>
                                        <input type="text" name="video_url" class="form-control" value="{{ $block->video_url }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Provider</label>
                                        <select name="video_provider" class="form-select">
                                            <option value="youtube" {{ $block->video_provider === 'youtube' ? 'selected' : '' }}>YouTube</option>
                                            <option value="vimeo"   {{ $block->video_provider === 'vimeo'   ? 'selected' : '' }}>Vimeo</option>
                                            <option value="file"    {{ $block->video_provider === 'file'    ? 'selected' : '' }}>Direct file</option>
                                        </select>
                                    </div>
                                </div>
                                @if($block->video_url)
                                    <div class="mb-3">
                                        @if($block->isEmbeddable())
                                            <div class="ratio ratio-16x9" style="max-width:480px;">
                                                <iframe src="{{ $block->embedUrl() }}" allowfullscreen></iframe>
                                            </div>
                                        @else
                                            <video src="{{ $block->video_url }}" controls style="max-width:480px;width:100%;"></video>
                                        @endif
                                    </div>
                                @endif

                            @elseif($block->type === 'text')
                                <div class="mb-3">
                                    <label class="form-label">Content</label>
                                    <textarea name="body" class="summernote-edit-{{ $block->id }}">{!! $block->body !!}</textarea>
                                </div>

                            @elseif($block->type === 'download')
                                @if($block->file_path)
                                    <div class="alert alert-light d-flex align-items-center gap-2 mb-3 py-2">
                                        <i data-feather="file" style="width:16px;height:16px;"></i>
                                        <span class="fw-semibold">{{ $block->file_name }}</span>
                                        <span class="text-muted small">({{ $block->formattedFileSize() }})</span>
                                        <a href="{{ route('member.training.download', $block) }}"
                                           class="btn btn-outline-secondary btn-xs ms-auto py-0 px-2" target="_blank">
                                            <i data-feather="download" style="width:11px;height:11px;"></i> Download
                                        </a>
                                    </div>
                                @endif
                                <div class="mb-3">
                                    <label class="form-label">Replace file</label>
                                    <input type="file" name="upload_file" class="form-control">
                                </div>
                            @endif

                            <button type="submit" class="btn btn-sm btn-primary">Save Block</button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>

            <div class="mt-3">
                <button class="btn btn-outline-secondary btn-sm" id="save-order-btn" onclick="saveOrder()">
                    <i data-feather="save" style="width:13px;height:13px;"></i> Save Block Order
                </button>
                <small class="text-muted ms-2">Drag blocks above to reorder, then click Save Order.</small>
            </div>
        @endif
    </div>
</div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
// Summernote for new-block text editor
document.querySelectorAll('.summernote-new').forEach(el => {
    $(el).summernote({ height: 200, toolbar: [
        ['style',  ['bold','italic','underline','clear']],
        ['para',   ['ul','ol','paragraph']],
        ['insert', ['link']],
        ['view',   ['fullscreen','codeview']],
    ]});
});

// Summernote for each existing text block
@foreach($lesson->allContentBlocks->where('type','text') as $block)
$('.summernote-edit-{{ $block->id }}').summernote({ height: 200, toolbar: [
    ['style',  ['bold','italic','underline','clear']],
    ['para',   ['ul','ol','paragraph']],
    ['insert', ['link']],
    ['view',   ['fullscreen','codeview']],
]});
@endforeach

// Toggle block open/close
function toggleBlock(id) {
    const card = document.getElementById('block-' + id);
    card.classList.toggle('open');
}

// Sortable drag-and-drop
const container = document.getElementById('blocks-container');
if (container) {
    Sortable.create(container, { handle: '.drag-handle', animation: 150 });
}

// Save order
function saveOrder() {
    const ids = [...document.querySelectorAll('#blocks-container .block-card')].map(el => el.dataset.id);
    fetch('{{ route('admin.training.content-blocks.reorder') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ order: ids }),
    }).then(r => r.json()).then(() => {
        const btn = document.getElementById('save-order-btn');
        btn.textContent = 'Saved!';
        setTimeout(() => { btn.innerHTML = '<i data-feather="save" style="width:13px;height:13px;"></i> Save Block Order'; feather.replace(); }, 1500);
    });
}
</script>
@endpush
