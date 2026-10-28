{{--
    Relabels State/ZIP as Province/Postal code when Canada is chosen, on any form
    marked data-q3-address-form. Only the labels change: the server normalises
    and checks the province and postal code either way.
--}}
@push('scripts')
<script>
document.querySelectorAll('[data-q3-address-form]').forEach(function (form) {
    var country = form.querySelector('[name="country"]');
    var region  = form.querySelector('[data-q3-region-label]');
    var postal  = form.querySelector('[data-q3-postal-label]');
    if (!country) { return; }

    function relabel() {
        var ca = country.value === 'CA';
        if (region) { region.textContent = ca ? 'Province' : 'State'; }
        if (postal) { postal.textContent = ca ? 'Postal code' : 'ZIP code'; }
    }

    country.addEventListener('change', relabel);
    relabel();
});
</script>
@endpush
