<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\TrainingCategory;
use App\Models\TrainingLesson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TrainingLessonController extends Controller
{
    public function index(Request $request)
    {
        $query = TrainingLesson::with(['category', 'requiredRole'])->orderBy('sort_order')->orderBy('title');

        if ($request->filled('category')) {
            $query->where('category_id', $request->category);
        }

        $lessons    = $query->paginate(25)->withQueryString();
        $categories = TrainingCategory::orderBy('name')->get();
        return view('admin.training.lessons.index', compact('lessons', 'categories'));
    }

    public function create(Request $request)
    {
        $categories = TrainingCategory::orderBy('name')->get();
        $roles      = Role::orderBy('level')->get();
        $selected   = $request->integer('category_id');
        return view('admin.training.lessons.create', compact('categories', 'roles', 'selected'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id'        => 'required|exists:training_categories,id',
            'title'              => 'required|string|max:255',
            'slug'               => 'nullable|string|max:255|unique:training_lessons,slug',
            'description'        => 'nullable|string',
            'required_role_id'   => 'nullable|exists:roles,id',
            'sort_order'         => 'nullable|integer',
            'is_published'       => 'boolean',
            'is_featured'        => 'boolean',
            'thumbnail'          => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'release_delay'      => 'nullable|integer|min:1|max:9999',
            'release_delay_unit' => 'nullable|in:days,months',
        ]);

        $data['slug']         = $data['slug'] ? \Illuminate\Support\Str::slug($data['slug']) : TrainingLesson::uniqueSlug($data['title']);
        $data['is_published'] = $request->boolean('is_published');
        $data['is_featured']  = $request->boolean('is_featured');

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail'] = $request->file('thumbnail')->store('training/thumbnails', 'public');
        }

        $lesson = TrainingLesson::create($data);

        return redirect()->route('admin.training.lessons.edit', $lesson)->with('success', 'Lesson created. Now add content blocks below.');
    }

    public function edit(TrainingLesson $lesson)
    {
        $lesson->load(['category', 'allContentBlocks', 'requiredRole']);
        $categories = TrainingCategory::orderBy('name')->get();
        $roles      = Role::orderBy('level')->get();
        return view('admin.training.lessons.edit', compact('lesson', 'categories', 'roles'));
    }

    public function update(Request $request, TrainingLesson $lesson)
    {
        $data = $request->validate([
            'category_id'        => 'required|exists:training_categories,id',
            'title'              => 'required|string|max:255',
            'slug'               => 'nullable|string|max:255|unique:training_lessons,slug,' . $lesson->id,
            'description'        => 'nullable|string',
            'required_role_id'   => 'nullable|exists:roles,id',
            'sort_order'         => 'nullable|integer',
            'is_published'       => 'boolean',
            'is_featured'        => 'boolean',
            'thumbnail'          => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'release_delay'      => 'nullable|integer|min:1|max:9999',
            'release_delay_unit' => 'nullable|in:days,months',
        ]);

        $data['slug']         = $data['slug'] ? \Illuminate\Support\Str::slug($data['slug']) : TrainingLesson::uniqueSlug($data['title'], $lesson->id);
        $data['is_published'] = $request->boolean('is_published');
        $data['is_featured']  = $request->boolean('is_featured');

        if ($request->hasFile('thumbnail')) {
            if ($lesson->thumbnail) Storage::disk('public')->delete($lesson->thumbnail);
            $data['thumbnail'] = $request->file('thumbnail')->store('training/thumbnails', 'public');
        }

        $lesson->update($data);

        return redirect()->route('admin.training.lessons.edit', $lesson)->with('success', 'Lesson saved.');
    }

    public function destroy(TrainingLesson $lesson)
    {
        if ($lesson->thumbnail) Storage::disk('public')->delete($lesson->thumbnail);
        $lesson->delete();
        return redirect()->route('admin.training.lessons.index')->with('success', 'Lesson deleted.');
    }
}
