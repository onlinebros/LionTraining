{{-- The Request Assistant's lightbulb, in brand gold so it stands out. The
     theme colours the lines of header icons itself (it sets their colour, so
     currentColor resolves to its grey), hence the explicit colours here. --}}
@once
<style>
    .ra-bulb { color: var(--q3-gold, #D4AF37); transition: filter .2s; }
    .ra-bulb svg { width: 22px; height: 22px; }
    .ra-bulb svg path { stroke: var(--q3-gold, #D4AF37) !important; fill: rgba(212, 175, 55, .18) !important; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .ra-bulb:hover { filter: drop-shadow(0 0 6px rgba(212, 175, 55, .6)); }
    .ra-bulb:hover svg path { stroke: var(--q3-gold-high, #E4C66A) !important; }
</style>
@endonce
<svg viewBox="0 0 24 24" aria-hidden="true">
    <path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/>
    <path d="M9 18h6"/>
    <path d="M10 22h4" style="fill:none"/>
</svg>
