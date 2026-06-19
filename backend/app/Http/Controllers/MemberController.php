<?php

namespace App\Http\Controllers;

use App\Models\CommissionLedger;
use App\Models\CommissionPayout;
use App\Models\TrainingCategory;
use App\Models\TrainingContentBlock;
use App\Models\TrainingLesson;
use App\Services\CommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class MemberController extends Controller
{
    public function dashboard()
    {
        $user = auth()->user()->load(['sponsors', 'sponsees', 'role']);
        return view('member.dashboard', compact('user'));
    }

    public function network(Request $request)
    {
        $user = auth()->user()->load([
            'sponsors.role',
            'sponsees.role',
        ]);
        $tab = $request->get('tab', 'members');
        return view('member.network', compact('user', 'tab'));
    }

    public function trainingIndex()
    {
        $user       = auth()->user()->load('role');
        $categories = TrainingCategory::whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->with(['children', 'lessons', 'requiredRole'])
            ->get();
        $featured = TrainingLesson::where('is_published', true)
            ->where('is_featured', true)
            ->with(['category', 'requiredRole'])
            ->orderBy('sort_order')->limit(6)->get();
        return view('member.training.index', compact('user', 'categories', 'featured'));
    }

    public function trainingCategory(string $slug)
    {
        $user     = auth()->user()->load('role');
        $category = TrainingCategory::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $category->load(['children.lessons', 'requiredRole']);

        // Check category-level role access
        if ($category->requiredRole && (!$user->role || $user->role->level < $category->requiredRole->level)) {
            return redirect()->route('member.training')
                ->with('error', 'You need ' . $category->requiredRole->display_name . ' access to view this category.');
        }

        // Check category-level time access
        if ($category->isTimeLocked($user)) {
            $availAt = $category->availableAtForUser($user);
            $msg = $availAt
                ? 'This category is available on ' . $availAt->format('M j, Y') . '.'
                : 'This category is not yet available. Your active start date has not been set.';
            return redirect()->route('member.training')->with('error', $msg);
        }

        $lessons   = $category->lessons()->where('is_published', true)->with('requiredRole')->get();
        $breadcrumb = $category->breadcrumb();

        return view('member.training.category', compact('user', 'category', 'lessons', 'breadcrumb'));
    }

    public function trainingLesson(string $slug)
    {
        $user   = auth()->user()->load('role');
        $lesson = TrainingLesson::where('slug', $slug)->where('is_published', true)
            ->with(['category.parent', 'requiredRole'])
            ->firstOrFail();

        if (!$lesson->userCanAccess($user)) {
            $needed = \App\Models\Role::find($lesson->effectiveRoleId());
            return redirect()->route('member.training.category', $lesson->category->slug)
                ->with('error', 'You need ' . ($needed?->display_name ?? 'a higher membership') . ' to access this lesson.');
        }

        $blocks    = $lesson->contentBlocks;
        $breadcrumb = $lesson->category->breadcrumb();

        $siblings = $lesson->category->lessons()->where('is_published', true)->with('requiredRole')->get();
        $siblingIds = $siblings->pluck('id')->values();
        $currentIdx = $siblingIds->search($lesson->id);
        $prev = $currentIdx > 0 ? $siblings[$currentIdx - 1] : null;
        $next = $currentIdx < $siblings->count() - 1 ? $siblings[$currentIdx + 1] : null;

        return view('member.training.lesson', compact('user', 'lesson', 'blocks', 'breadcrumb', 'siblings', 'prev', 'next'));
    }

    public function trainingDownload(TrainingContentBlock $block)
    {
        $user   = auth()->user()->load('role');
        $lesson = $block->lesson()->with(['category', 'requiredRole'])->firstOrFail();

        if (!$lesson->userCanAccess($user)) {
            abort(403, 'Access denied.');
        }

        if (!$block->file_path || !Storage::disk('public')->exists($block->file_path)) {
            abort(404);
        }

        return Storage::disk('public')->download($block->file_path, $block->file_name ?: 'download');
    }

    public function referral()
    {
        $user = auth()->user()->load(['sponsees.role']);
        $stats = [
            'total_invited' => $user->sponsees->count(),
            'active'        => $user->sponsees->where('pivot.status', 'active')->count(),
            'pending'       => $user->sponsees->where('pivot.status', 'pending')->count(),
        ];
        return view('member.referral', compact('user', 'stats'));
    }

    public function profile()
    {
        $user = auth()->user()->load(['role', 'sponsors', 'sponsees']);
        return view('member.profile', compact('user'));
    }

    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:30',
        ]);

        if ($request->hasFile('profile_photo')) {
            $request->validate([
                'profile_photo' => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            ]);

            // Delete old photo
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }

            $path = $request->file('profile_photo')->store('profile-photos', 'public');
            $data['profile_photo'] = $path;
        }

        $user->update($data);

        return back()->with('success', 'Profile updated successfully.');
    }

    public function removePhoto(Request $request)
    {
        $user = auth()->user();

        if ($user->profile_photo) {
            Storage::disk('public')->delete($user->profile_photo);
            $user->update(['profile_photo' => null]);
        }

        return back()->with('success', 'Profile photo removed.');
    }

    public function updateAddress(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city'          => 'nullable|string|max:100',
            'state'         => 'nullable|string|max:100',
            'postal_code'   => 'nullable|string|max:20',
            'country'       => 'nullable|string|max:100',
        ]);

        $user->update($data);

        return back()->with('address_success', 'Address updated successfully.');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($request->current_password, auth()->user()->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        auth()->user()->update(['password' => $request->password]);

        return back()->with('password_success', 'Password changed successfully.');
    }

    // ── Commission portal ─────────────────────────────────────────────────────

    public function commissions(CommissionService $service)
    {
        $user    = auth()->user();
        $balance = $service->getBalance($user);
        $lifetime = $service->getLifetimePaid($user);

        $recent = CommissionLedger::where('earner_id', $user->id)
            ->with('commissionPlan')
            ->latest()
            ->limit(10)
            ->get();

        $pendingPayout = CommissionPayout::where('earner_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->latest()
            ->first();

        return view('member.commissions.overview', compact('user', 'balance', 'lifetime', 'recent', 'pendingPayout'));
    }

    public function commissionHistory(Request $request)
    {
        $user = auth()->user();

        $query = CommissionLedger::where('earner_id', $user->id)
            ->with('commissionPlan')
            ->latest();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $entries = $query->paginate(25)->withQueryString();

        return view('member.commissions.history', compact('user', 'entries'));
    }

    public function commissionPayouts()
    {
        $user    = auth()->user();
        $payouts = CommissionPayout::where('earner_id', $user->id)
            ->latest()
            ->paginate(20);

        return view('member.commissions.payouts', compact('user', 'payouts'));
    }
}
