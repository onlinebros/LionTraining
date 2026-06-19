<div class="row">
    <div class="col-md-8 mb-3">
        <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control @error('title') is-invalid @enderror"
               value="{{ old('title', $lesson?->title) }}" required>
        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label fw-semibold">Slug</label>
        <input type="text" name="slug" class="form-control @error('slug') is-invalid @enderror"
               value="{{ old('slug', $lesson?->slug) }}" placeholder="auto-generated">
        <small class="text-muted">URL: /training/l/{slug}</small>
        @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="mb-3">
    <label class="form-label fw-semibold">Description</label>
    <textarea name="description" class="form-control" rows="3">{{ old('description', $lesson?->description) }}</textarea>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
        <select name="category_id" class="form-select @error('category_id') is-invalid @enderror" required>
            <option value="">— Select category —</option>
            @foreach($categories as $cat)
                <option value="{{ $cat->id }}"
                    {{ old('category_id', $lesson?->category_id ?? $selected) == $cat->id ? 'selected' : '' }}>
                    {{ $cat->name }}
                </option>
            @endforeach
        </select>
        @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label fw-semibold">Required Role</label>
        <select name="required_role_id" class="form-select">
            <option value="">— Inherit from category —</option>
            @foreach($roles as $role)
                <option value="{{ $role->id }}"
                    {{ old('required_role_id', $lesson?->required_role_id) == $role->id ? 'selected' : '' }}>
                    {{ $role->display_name }}
                </option>
            @endforeach
        </select>
        <small class="text-muted">Overrides the category's role requirement if set.</small>
    </div>
</div>

<hr class="my-3">
<h6 class="fw-semibold mb-1">Release Schedule</h6>
<p class="text-muted small mb-3">
    Override or add a time delay before this lesson becomes available. If blank, the category's release schedule applies.
    Leave both blank for immediate access.
</p>
<div class="row mb-3">
    <div class="col-md-5">
        <label class="form-label fw-semibold">Available After</label>
        <div class="input-group">
            <input type="number" name="release_delay" min="1" max="9999"
                   class="form-control"
                   value="{{ old('release_delay', $lesson?->release_delay) }}"
                   placeholder="e.g. 7">
            <select name="release_delay_unit" class="form-select" style="max-width:110px;">
                <option value="days"   {{ old('release_delay_unit', $lesson?->release_delay_unit ?? 'days') === 'days'   ? 'selected' : '' }}>Days</option>
                <option value="months" {{ old('release_delay_unit', $lesson?->release_delay_unit ?? 'days') === 'months' ? 'selected' : '' }}>Months</option>
            </select>
        </div>
        <small class="text-muted">Overrides the category schedule for this lesson. Leave blank to inherit.</small>
    </div>
    @if($lesson?->release_delay || $lesson?->category?->release_delay)
    <div class="col-md-7 d-flex align-items-end">
        <div class="alert alert-info py-2 px-3 mb-0 w-100 small">
            <i data-feather="clock" style="width:13px;height:13px;"></i>
            @if($lesson?->release_delay)
                This lesson releases after <strong>{{ $lesson->release_delay }} {{ $lesson->release_delay_unit ?? 'days' }}</strong> (lesson override).
            @elseif($lesson?->category?->release_delay)
                Inheriting category schedule: <strong>{{ $lesson->category->release_delay }} {{ $lesson->category->release_delay_unit ?? 'days' }}</strong>.
            @endif
        </div>
    </div>
    @endif
</div>

<div class="row">
    <div class="col-md-4 mb-3">
        <label class="form-label fw-semibold">Sort Order</label>
        <input type="number" name="sort_order" class="form-control"
               value="{{ old('sort_order', $lesson?->sort_order ?? 0) }}">
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label fw-semibold">Thumbnail</label>
        <input type="file" name="thumbnail" class="form-control" accept="image/*">
        @if($lesson?->thumbnail)
            <div class="mt-2">
                <img src="{{ Storage::url($lesson->thumbnail) }}" alt="thumbnail"
                     style="height:60px;border-radius:6px;object-fit:cover;">
            </div>
        @endif
    </div>
    <div class="col-md-4 mb-3 pt-4">
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="is_published" value="1" id="is_published"
                   {{ old('is_published', $lesson?->is_published) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_published">Published</label>
        </div>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_featured" value="1" id="is_featured"
                   {{ old('is_featured', $lesson?->is_featured) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_featured">Featured</label>
        </div>
    </div>
</div>
