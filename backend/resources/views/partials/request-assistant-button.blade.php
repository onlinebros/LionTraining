{{-- Header button for the Request Assistant: suggest a change to the page
     you are on. Super admins only, in the admin, member and product-partner
     headers (public pages get partials.request-assistant-floating). Pairs with
     partials.request-assistant-modal, which each layout includes. --}}
@if(auth()->user()?->isSuperAdmin())
<li>
    <a href="#" class="ra-bulb" data-bs-toggle="modal" data-bs-target="#requestAssistantModal"
       title="Suggest a change to this page" aria-label="Suggest a change to this page"
       style="display:flex;align-items:center;">
        @include('partials.request-assistant-icon')
    </a>
</li>
@endif
