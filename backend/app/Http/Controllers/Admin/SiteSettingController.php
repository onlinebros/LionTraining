<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Support\TrainingAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SiteSettingController extends Controller
{
    public function index()
    {
        $settings = SiteSetting::getAll();
        return view('admin.settings.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'site_name'    => 'nullable|string|max:100',
            'color_scheme' => 'nullable|in:color-1,color-2,color-3,color-4,color-5,color-6',
            'default_mode' => 'nullable|in:light,dark',
            'logo_light'   => 'nullable|image|mimes:png,jpg,jpeg,svg,gif|max:512',
            'logo_dark'    => 'nullable|image|mimes:png,jpg,jpeg,svg,gif|max:512',
            'logo_icon'    => 'nullable|image|mimes:png,jpg,jpeg,svg,gif|max:256',
            'training_visibility' => 'nullable|in:admin,members',
        ]);

        foreach (['logo_light', 'logo_dark', 'logo_icon'] as $field) {
            if ($request->hasFile($field)) {
                $old = SiteSetting::get($field);
                if ($old && str_starts_with($old, 'logos/')) {
                    Storage::disk('public')->delete($old);
                }
                $path = $request->file($field)->store('logos', 'public');
                SiteSetting::set($field, $path);
            }
        }

        foreach (['site_name', 'color_scheme', 'default_mode'] as $key) {
            if ($request->filled($key)) {
                SiteSetting::set($key, $request->input($key));
            }
        }

        // Opening the training library to members is the one setting on this
        // screen that changes what paying customers can reach, so it is called
        // out in the confirmation rather than folded into "settings saved".
        $message = 'Site settings saved successfully.';

        if ($request->filled('training_visibility')) {
            $before = TrainingAccess::visibility();
            $after  = $request->input('training_visibility');

            SiteSetting::set(TrainingAccess::KEY, $after);

            if ($before !== $after) {
                $message = $after === TrainingAccess::MEMBERS
                    ? 'Training library is now LIVE for members.'
                    : 'Training library is now hidden from members (admin preview only).';
            }
        }

        return back()->with('success', $message);
    }

    public function removeLogo(Request $request)
    {
        $field = $request->validate(['field' => 'required|in:logo_light,logo_dark,logo_icon'])['field'];

        $path = SiteSetting::get($field);
        if ($path && str_starts_with($path, 'logos/')) {
            Storage::disk('public')->delete($path);
        }
        SiteSetting::set($field, null);

        return back()->with('success', 'Logo removed.');
    }
}
