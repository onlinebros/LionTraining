{{-- The Request Assistant button for pages with no header bar (the public
     layout: storefronts, sign-in screens). Super admins only, so a visitor or
     member never sees it. Pairs with partials.request-assistant-modal. --}}
@if(auth()->user()?->isSuperAdmin())
<a href="#" class="ra-bulb" data-bs-toggle="modal" data-bs-target="#requestAssistantModal"
   title="Suggest a change to this page" aria-label="Suggest a change to this page"
   style="position:fixed;left:16px;bottom:16px;z-index:1040;width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#1b1b1f;border:1px solid rgba(212,175,55,.6);box-shadow:0 0 0 1px rgba(212,175,55,.28), 0 6px 22px rgba(212,175,55,.25);">
    @include('partials.request-assistant-icon')
</a>
@endif
