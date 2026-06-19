<?php

namespace App\Providers;

use App\Http\ViewComposers\SiteSettingsComposer;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Inject $siteSettings into every layout so logo/color/mode are admin-controlled
        View::composer(['layouts.admin', 'layouts.member', 'layouts.public'], SiteSettingsComposer::class);

        RedirectIfAuthenticated::redirectUsing(fn () => route('member.dashboard'));
    }
}
