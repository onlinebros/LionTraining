<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sponsorship;
use App\Models\User;

class SponsorController extends Controller
{
    public function index()
    {
        $sponsors = User::has('sponsees')->withCount('sponsees')->latest()->paginate(20);
        return view('admin.sponsors.index', compact('sponsors'));
    }

    public function relationships()
    {
        $sponsorships = Sponsorship::with(['sponsor', 'sponsored'])->latest()->paginate(20);
        return view('admin.sponsors.relationships', compact('sponsorships'));
    }
}
