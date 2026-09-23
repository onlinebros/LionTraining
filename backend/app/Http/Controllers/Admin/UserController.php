<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOpportunity;
use App\Support\Opportunity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        // Members only. Unclaimed holding spots have no email, no login and no
        // owner — listing them here puts rows in front of staff that they will
        // try to contact, and buries real accounts under them. They have their
        // own screen: admin.partners.spots.
        // Which business line they came in for. Filtered on the denormalised
        // column rather than the join table, because this is the primary — "who
        // came through the PlasmaGuard door" — and a member who later picked up
        // a second line should not appear under both.
        $opportunity = Opportunity::sanitise($request->query('opportunity'));

        $users = User::with('role')
            ->activated()
            ->withCount(['sponsees', 'sponsors'])
            ->when($request->role, fn($q, $role) => $q->whereHas('role', fn($q2) => $q2->where('name', $role)))
            ->when($opportunity, fn ($q, $key) => $key === Opportunity::defaultKey()
                // Null is the default: every account that predates the feature.
                ? $q->where(fn ($q2) => $q2->whereNull('primary_opportunity')->orWhere('primary_opportunity', $key))
                : $q->where('primary_opportunity', $key))
            ->latestRegistered()
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'users'         => $users,
            'opportunity'   => $opportunity,
            'opportunities' => Opportunity::all(),
        ]);
    }

    public function create()
    {
        return view('admin.users.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role_id'  => 'nullable|exists:roles,id',
        ]);

        $data['password'] = bcrypt($data['password']);
        User::create($data);

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    public function show(User $user)
    {
        $user->load([
            'sponsors', 'sponsees', 'opportunityAssociations.addedBy',
            // Only ever a handful of rows, and the Product Partner card on this
            // page renders one per grant.
            'productPartnerAssignments.grantedBy',
        ]);

        return view('admin.users.show', [
            'user'          => $user,
            'opportunities' => Opportunity::all(),
        ]);
    }

    /**
     * Put a member on a business line — see config/opportunities.php.
     *
     * Making it primary is what decides whether a card is ever asked for, so it
     * is an explicit checkbox rather than implied by adding the line. Adding
     * a second line only widens what they can see.
     */
    public function addOpportunity(Request $request, User $user)
    {
        $data = $request->validate([
            'opportunity' => ['required', 'string', Rule::in(Opportunity::keys())],
            'primary'     => ['nullable', 'boolean'],
        ]);

        $user->associateOpportunity(
            $data['opportunity'],
            UserOpportunity::SOURCE_ADMIN,
            primary: $request->boolean('primary'),
            by: $request->user(),
        );

        return back()->with('success', Opportunity::get($data['opportunity'])->name().' added to '.$user->name.'.');
    }

    /**
     * Take a line away.
     *
     * The primary is refused: removing it would leave the row pointing at a
     * line the member no longer holds, and the fix — deciding what they are
     * instead — is a different action. Move them to another line first.
     */
    public function removeOpportunity(User $user, string $opportunity)
    {
        if ($user->primary_opportunity === $opportunity) {
            return back()->withErrors([
                'error' => 'That is this member\'s primary opportunity. Add the one they should be on as primary first, then remove this.',
            ]);
        }

        $user->opportunityAssociations()->where('opportunity', $opportunity)->delete();

        return back()->with('success', Opportunity::get($opportunity)->name().' removed from '.$user->name.'.');
    }

    public function edit(User $user)
    {
        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'email'             => 'required|email|unique:users,email,' . $user->id,
            'role_id'           => 'nullable|exists:roles,id',
            'password'          => 'nullable|string|min:8',
            'active_start_date' => 'nullable|date',
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = bcrypt($data['password']);
        }

        $user->update($data);

        return redirect()->route('admin.users.show', $user)->with('success', 'User updated successfully.');
    }

    public function toggleActive(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['error' => 'You cannot deactivate your own account.']);
        }

        $user->update(['is_active' => !$user->is_active]);

        $status = $user->is_active ? 'activated' : 'deactivated';
        return back()->with('success', "{$user->name} has been {$status}.");
    }

    public function destroy(User $user)
    {
        if (!auth()->user()->isSuperAdmin()) {
            abort(403, 'Only Super Admins can delete users.');
        }
        $user->delete();
        return redirect()->route('admin.users.index')->with('success', 'User deleted.');
    }
}
