<?php

namespace App\Http\Controllers;

use App\Models\CommissionLedger;
use App\Models\CommissionPayout;
use App\Models\TrainingCategory;
use App\Models\TrainingContentBlock;
use App\Models\TrainingLesson;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\Genealogy\GenealogyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class MemberController extends Controller
{
    public function dashboard(GenealogyService $genealogy)
    {
        $user = auth()->user()->load(['role']);

        $stats = [
            'directs'   => $genealogy->directs($user)->count(),
            'team'      => $genealogy->teamSize($user),
            'depth'     => $genealogy->depthOf($user->placement_path),
            'by_level'  => $genealogy->teamCountsByLevel($user),
            'sponsor'   => $user->sponsor,
        ];

        return view('member.dashboard', compact('user', 'stats'));
    }

    /**
     * The genealogy tree — the screen the pre-launch phase exists to make good.
     *
     * `view_from` re-roots the tree at any partner in the viewer's own downline,
     * which is how a large team stays navigable past the render depth. It is
     * checked against the viewer's subtree, never trusted: without that check
     * any id in the URL would expose the whole company's genealogy.
     */
    public function network(Request $request, GenealogyService $genealogy)
    {
        $user = auth()->user()->load('role');
        $root = $user;

        if ($viewFrom = $request->integer('view_from')) {
            $candidate = User::find($viewFrom);

            if ($candidate && $genealogy->descendants($user)->where('users.id', $candidate->id)->exists()) {
                $root = $candidate;
            }
        }

        return view('member.network', [
            'user'     => $user,
            'root'     => $root,
            'tree'     => $genealogy->subtree($root),
            'upline'   => $genealogy->upline($user),
            'byLevel'  => $genealogy->teamCountsByLevel($user),
            'directs'  => $genealogy->directs($user)->count(),
            'teamSize' => $genealogy->teamSize($user),
            'depth'    => $genealogy->depthOf($user->placement_path),
            'isReRooted' => $root->id !== $user->id,
            'spots'      => $genealogy->spotCounts($user),
        ]);
    }

    /**
     * Holding spots in this partner's organisation: claimed and still waiting.
     *
     * Its own screen rather than rows in the tree. An unclaimed spot is a
     * position with nobody in it — showing them inline would mean a partner's
     * team page listed people who cannot be contacted and are not yet members,
     * and the count at the top of that page would stop meaning "my team".
     *
     * What a partner sees here is a count and, for spots they personally
     * enrolled or that head one of their own legs, the identifier the partner
     * company knows them by. Never a name or an email of somebody who has not
     * joined us: that data came from another company's database and belongs to
     * the person it describes, not to the upline waiting on them.
     */
    public function spots(GenealogyService $genealogy)
    {
        $user = auth()->user()->load('role');

        $counts = $genealogy->spotCounts($user);

        // Ordered by id and paginated without a total.
        //
        // `placement_path` is indexed with GIST, which cannot answer an ORDER
        // BY — asking for one sorts the whole result set, and for a partner
        // near the top of an imported organisation that is a million rows
        // sorted to show fifty. Id is the same arbitrary-but-stable ordering as
        // far as anybody reading this list is concerned, and it is the primary
        // key.
        //
        // simplePaginate because paginate() runs a COUNT over the same million
        // rows to work out how many pages there are, and the count that matters
        // is already on the page above from spotCounts().
        $waiting = $genealogy->descendants($user, includeHolding: true)
            ->holding()
            ->with('partnerCompany:id,name')
            ->orderBy('id')
            ->simplePaginate(50);

        $recentlyClaimed = $genealogy->descendants($user)
            ->whereNotNull('claimed_at')
            ->latest('claimed_at')
            ->limit(10)
            ->get(['id', 'name', 'claimed_at', 'partner_company_id']);

        return view('member.spots', [
            'user'            => $user,
            'counts'          => $counts,
            'waiting'         => $waiting,
            'recentlyClaimed' => $recentlyClaimed,
        ]);
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

        $blocks    = $lesson->contentBlocks()->with('videoAsset')->get();
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

        if (!$block->file_path) {
            abort(404);
        }

        $fileName = $block->file_name ?: basename($block->file_path);

        // Kartra files live on the private local disk; fall back to public disk for uploads
        if (Storage::disk('local')->exists($block->file_path)) {
            return Storage::disk('local')->download($block->file_path, $fileName);
        }

        if (Storage::disk('public')->exists($block->file_path)) {
            return Storage::disk('public')->download($block->file_path, $fileName);
        }

        abort(404);
    }

    public function referral()
    {
        $user = auth()->user()->load('role');
        $genealogy = app(GenealogyService::class);

        // Counted off the genealogy, not the legacy pivot. Sponsorship is
        // immediate and permanent now, so a "pending" count would sit at zero
        // forever; total team is the number that actually moves during
        // pre-launch.
        $stats = [
            'total_invited' => $genealogy->directs($user)->count(),
            'active'        => $genealogy->directs($user)->where('is_active', true)->count(),
            'team'          => $genealogy->teamSize($user),
        ];

        $recruits = $user->recruits()->with('role')->latest('id')->get();

        return view('member.referral', compact('user', 'stats', 'recruits'));
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
