<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Referral code → sponsor name, for the partner's personal copy of the company
 * website (q3.life/{code}).
 *
 * The site uses it to greet the visitor with the name of the person who sent
 * them and to point its Join buttons at /join/{code}. It returns the same name
 * that /join/{code} already shows to anyone holding the link, and nothing more:
 * no email, no id, no position.
 *
 * Found by the same rule as the join page — activated accounts only — so the
 * site never offers a Join button that would 404 one click later.
 */
class PublicSponsorController extends Controller
{
    public function show(string $code): JsonResponse
    {
        $sponsor = User::query()->activated()->where('referral_code', strtoupper($code))->first();

        if (! $sponsor) {
            return response()->json(['message' => 'No partner has that referral code.'], 404);
        }

        return response()->json([
            'code' => $sponsor->referral_code,
            'name' => $sponsor->name,
        ]);
    }
}
