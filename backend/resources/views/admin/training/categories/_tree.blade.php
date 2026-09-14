@foreach($items as $cat)
<div class="d-flex align-items-center gap-2 py-2 border-bottom"
     style="padding-left: {{ $depth * 28 + 8 }}px;">

    @if($depth > 0)
        <i data-feather="corner-down-right" style="width:14px;height:14px;color:var(--q3-text-dim);flex-shrink:0;"></i>
    @else
        <i data-feather="folder" style="width:16px;height:16px;color:var(--theme-default);flex-shrink:0;"></i>
    @endif

    <div class="flex-grow-1">
        <span class="fw-semibold">{{ $cat->name }}</span>
        <span class="text-muted small ms-2">/c/{{ $cat->slug }}</span>
        @if($cat->requiredRole)
            <span class="badge badge-light-warning ms-1">{{ $cat->requiredRole->display_name }}</span>
        @endif
        @if(!$cat->is_active)
            <span class="badge badge-light-danger ms-1">Inactive</span>
        @endif
    </div>

    <div class="d-flex gap-1 flex-shrink-0">
        <a href="{{ route('admin.training.lessons.index', ['category' => $cat->id]) }}"
           class="btn btn-secondary btn-sm">
            <i data-feather="list" data-width="14" data-height="14"></i>
            {{ $cat->lessons->count() }} lessons
        </a>
        <a href="{{ route('admin.training.lessons.create', ['category_id' => $cat->id]) }}"
           class="btn btn-primary btn-sm" title="Add lesson">
            <i data-feather="plus" data-width="14" data-height="14"></i> Lesson
        </a>
        <a href="{{ route('admin.training.categories.edit', $cat) }}"
           class="btn btn-warning btn-sm" title="Edit category">
            <i data-feather="edit-2" data-width="14" data-height="14"></i> Edit
        </a>
        <form method="POST" action="{{ route('admin.training.categories.destroy', $cat) }}"
              onsubmit="return confirm('Delete this category and all its lessons?')">
            @csrf @method('DELETE')
            <button class="btn btn-danger btn-sm" title="Delete">
                <i data-feather="trash-2" data-width="14" data-height="14"></i> Delete
            </button>
        </form>
    </div>
</div>

@if($cat->children->isNotEmpty())
    @include('admin.training.categories._tree', ['items' => $cat->children, 'depth' => $depth + 1])
@endif
@endforeach
