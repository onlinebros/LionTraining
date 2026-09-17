<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sponsorship;
use App\Models\User;

class SponsorController extends Controller
{
    public function index()
    {
        // Activated accounts only — an unclaimed holding spot can be the parent
        // of an imported leg, which makes it look like a sponsor here.
        $sponsors = User::query()->activated()->has('sponsees')
            ->withCount('sponsees')->latest()->paginate(20);
        return view('admin.sponsors.index', compact('sponsors'));
    }

    public function relationships()
    {
        $sponsorships = Sponsorship::with(['sponsor', 'sponsored'])->latest()->paginate(20);
        return view('admin.sponsors.relationships', compact('sponsorships'));
    }
}
