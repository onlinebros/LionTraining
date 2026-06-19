<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\TrainingCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TrainingCategoryController extends Controller
{
    public function index()
    {
        $roots = TrainingCategory::whereNull('parent_id')
            ->orderBy('sort_order')->orderBy('name')
            ->with(['children.children', 'requiredRole'])
            ->get();
        return view('admin.training.categories.index', compact('roots'));
    }

    public function create()
    {
        $categories = TrainingCategory::orderBy('name')->get();
        $roles      = Role::orderBy('level')->get();
        return view('admin.training.categories.create', compact('categories', 'roles'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'slug'               => 'nullable|string|max:255|unique:training_categories,slug',
            'description'        => 'nullable|string',
            'parent_id'          => 'nullable|exists:training_categories,id',
            'required_role_id'   => 'nullable|exists:roles,id',
            'sort_order'         => 'nullable|integer',
            'is_active'          => 'boolean',
            'thumbnail'          => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'release_delay'      => 'nullable|integer|min:1|max:9999',
            'release_delay_unit' => 'nullable|in:days,months',
        ]);

        $data['slug']      = $data['slug'] ? \Illuminate\Support\Str::slug($data['slug']) : TrainingCategory::uniqueSlug($data['name']);
        $data['is_active'] = $request->boolean('is_active', true);

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail'] = $request->file('thumbnail')->store('training/thumbnails', 'public');
        }

        TrainingCategory::create($data);

        return redirect()->route('admin.training.categories.index')->with('success', 'Category created.');
    }

    public function edit(TrainingCategory $category)
    {
        $categories = TrainingCategory::where('id', '!=', $category->id)->orderBy('name')->get();
        $roles      = Role::orderBy('level')->get();
        return view('admin.training.categories.edit', compact('category', 'categories', 'roles'));
    }

    public function update(Request $request, TrainingCategory $category)
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'slug'               => 'nullable|string|max:255|unique:training_categories,slug,' . $category->id,
            'description'        => 'nullable|string',
            'parent_id'          => 'nullable|exists:training_categories,id',
            'required_role_id'   => 'nullable|exists:roles,id',
            'sort_order'         => 'nullable|integer',
            'is_active'          => 'boolean',
            'thumbnail'          => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'release_delay'      => 'nullable|integer|min:1|max:9999',
            'release_delay_unit' => 'nullable|in:days,months',
        ]);

        // Prevent self-reference or circular parent
        if ($data['parent_id'] ?? null) {
            if ((int) $data['parent_id'] === $category->id) {
                return back()->withErrors(['parent_id' => 'A category cannot be its own parent.']);
            }
        }

        $data['slug']      = $data['slug'] ? \Illuminate\Support\Str::slug($data['slug']) : TrainingCategory::uniqueSlug($data['name'], $category->id);
        $data['is_active'] = $request->boolean('is_active');

        if ($request->hasFile('thumbnail')) {
            if ($category->thumbnail) Storage::disk('public')->delete($category->thumbnail);
            $data['thumbnail'] = $request->file('thumbnail')->store('training/thumbnails', 'public');
        }

        $category->update($data);

        return redirect()->route('admin.training.categories.index')->with('success', 'Category updated.');
    }

    public function destroy(TrainingCategory $category)
    {
        if ($category->thumbnail) Storage::disk('public')->delete($category->thumbnail);
        $category->delete();
        return redirect()->route('admin.training.categories.index')->with('success', 'Category deleted.');
    }
}
