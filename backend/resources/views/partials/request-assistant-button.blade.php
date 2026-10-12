{{-- Header button for the Request Assistant: suggest a change to the page
     you are on. Super admins only, in the admin, member and product-partner
     headers (public pages get partials.request-assistant-floating). Pairs with
     partials.request-assistant-modal, which each layout includes. --}}
@if(auth()->user()?->isSuperAdmin())
<li>
    <a href="#" data-bs-toggle="modal" data-bs-target="#requestAssistantModal"
       title="Suggest a change to this page" aria-label="Suggest a change to this page"
       style="display:flex;align-items:center;color:inherit;opacity:.75;transition:opacity .2s;"
       onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.75'">
        <svg viewBox="0 0 24 24" style="width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;">
            <path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/>
            <path d="M9 18h6"/><path d="M10 22h4"/>
        </svg>
    </a>
</li>
@endif
