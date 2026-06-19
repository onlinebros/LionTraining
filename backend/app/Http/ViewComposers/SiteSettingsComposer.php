<?php

namespace App\Http\ViewComposers;

use App\Models\SiteSetting;
use Illuminate\View\View;

class SiteSettingsComposer
{
    public function compose(View $view): void
    {
        $view->with('siteSettings', SiteSetting::getAll());
    }
}
