{{-- The Request Assistant button for pages with no header bar (the public
     layout: storefronts, sign-in screens). Super admins only, so a visitor or
     member never sees it. Pairs with partials.request-assistant-modal. --}}
@if(auth()->user()?->isSuperAdmin())
<a href="#" data-bs-toggle="modal" data-bs-target="#requestAssistantModal"
   title="Suggest a change to this page" aria-label="Suggest a change to this page"
   style="position:fixed;left:16px;bottom:16px;z-index:1040;width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#1b1b1f;color:#e8c46a;border:1px solid rgba(232,196,106,.45);box-shadow:0 4px 14px rgba(0,0,0,.35);opacity:.85;"
   onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
    <svg viewBox="0 0 24 24" style="width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;">
        <path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/>
        <path d="M9 18h6"/><path d="M10 22h4"/>
    </svg>
</a>
@endif
