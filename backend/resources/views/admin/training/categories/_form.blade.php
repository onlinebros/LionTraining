<div class="mb-3">
    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
           value="{{ old('name', $category?->name) }}" required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label class="form-label fw-semibold">Slug</label>
    <input type="text" name="slug" class="form-control @error('slug') is-invalid @enderror"
           value="{{ old('slug', $category?->slug) }}" placeholder="auto-generated if blank">
    <small class="text-muted">Used in URLs: /training/c/{slug}</small>
    @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label class="form-label fw-semibold">Description</label>
    <textarea name="description" class="form-control" rows="3">{{ old('description', $category?->description) }}</textarea>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label fw-semibold">Parent Category</label>
        <select name="parent_id" class="form-select">
            <option value="">— None (top-level) —</option>
            @foreach($categories as $c)
                @if($category === null || $c->id !== $category->id)
                    <option value="{{ $c->id }}"
                        {{ old('parent_id', $category?->parent_id) == $c->id ? 'selected' : '' }}>
                        {{ $c->name }}
                    </option>
                @endif
            @endforeach
        </select>
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label fw-semibold">Required Role</label>
        <select name="required_role_id" class="form-select">
            <option value="">— Open to all members —</option>
            @foreach($roles as $role)
                <option value="{{ $role->id }}"
                    {{ old('required_role_id', $category?->required_role_id) == $role->id ? 'selected' : '' }}>
                    {{ $role->display_name }}
                </option>
            @endforeach
        </select>
        <small class="text-muted">Members need this role (or higher) to access this category.</small>
    </div>
</div>

<hr class="my-3">
<h6 class="fw-semibold mb-1">Release Schedule</h6>
<p class="text-muted small mb-3">
    Restrict access to this category until a member's <strong>Active Start Date</strong> has passed by the specified amount.
    Leave blank to make the category available immediately.
</p>
<div class="row mb-3">
    <div class="col-md-4">
        <label class="form-label fw-semibold">Available After</label>
        <div class="input-group">
            <input type="number" name="release_delay" id="cat_release_delay" min="1" max="9999"
                   class="form-control"
                   value="{{ old('release_delay', $category?->release_delay) }}"
                   placeholder="e.g. 30">
            <select name="release_delay_unit" class="form-select" style="max-width:110px;">
                <option value="days"   {{ old('release_delay_unit', $category?->release_delay_unit ?? 'days') === 'days'   ? 'selected' : '' }}>Days</option>
                <option value="months" {{ old('release_delay_unit', $category?->release_delay_unit ?? 'days') === 'months' ? 'selected' : '' }}>Months</option>
            </select>
        </div>
        <small class="text-muted">Leave blank for immediate access.</small>
    </div>
    @if($category?->release_delay)
    <div class="col-md-8 d-flex align-items-end">
        <div class="alert alert-info py-2 px-3 mb-0 w-100 small">
            <i data-feather="clock" style="width:13px;height:13px;"></i>
            Currently releasing after <strong>{{ $category->release_delay }} {{ $category->release_delay_unit ?? 'days' }}</strong>
            from each member's active start date.
        </div>
    </div>
    @endif
</div>

<div class="row">
    <div class="col-md-4 mb-3">
        <label class="form-label fw-semibold">Sort Order</label>
        <input type="number" name="sort_order" class="form-control"
               value="{{ old('sort_order', $category?->sort_order ?? 0) }}">
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label fw-semibold">Thumbnail</label>
        <input type="file" name="thumbnail" class="form-control" accept="image/*">
        @if($category?->thumbnail)
            <div class="mt-2">
                <img src="{{ Storage::url($category->thumbnail) }}" alt="thumbnail"
                     style="height:60px;border-radius:6px;object-fit:cover;">
            </div>
        @endif
    </div>
    <div class="col-md-4 mb-3 d-flex align-items-center pt-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                   {{ old('is_active', $category?->is_active ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>
</div>
