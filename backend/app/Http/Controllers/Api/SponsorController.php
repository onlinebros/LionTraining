<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sponsorship;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class SponsorController extends Controller
{
    public function registerWithSponsor(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::min(8)],
            'sponsor_email' => 'required|email|exists:users,email',
            'notes' => 'nullable|string|max:1000',
        ]);

        $sponsor = User::where('email', $request->sponsor_email)->firstOrFail();

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'sponsored_id' => $user->id,
            'status' => 'pending',
            'notes' => $request->notes,
        ]);

        $token = $user->createToken('lion-auth')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'sponsor' => ['name' => $sponsor->name, 'email' => $sponsor->email],
        ], 201);
    }

    public function mySponsees(Request $request)
    {
        $sponsees = $request->user()->sponsees()->withPivot('status', 'notes', 'accepted_at')->get();

        return response()->json(['sponsees' => $sponsees]);
    }

    public function updateSponsorshipStatus(Request $request, Sponsorship $sponsorship)
    {
        if ($sponsorship->sponsor_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'status' => 'required|in:active,inactive',
        ]);

        $sponsorship->update([
            'status' => $request->status,
            'accepted_at' => $request->status === 'active' ? now() : $sponsorship->accepted_at,
        ]);

        return response()->json(['sponsorship' => $sponsorship]);
    }

    public function mySponsors(Request $request)
    {
        $sponsors = $request->user()->sponsors()->withPivot('status', 'notes', 'accepted_at')->get();

        return response()->json(['sponsors' => $sponsors]);
    }
}
