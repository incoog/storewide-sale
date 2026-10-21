(function ($) {
    'use strict';
    $(function () {
        const $page = $('.sws');
        if (!$page.length || typeof swsSelectors === 'undefined' || !$.fn.selectWoo) return;
        $page.find('.sws-search').each(function () {
            const $field = $(this);
            const kind = $field.data('kind');
            $field.selectWoo({
                width: '100%', multiple: true, closeOnSelect: false,
                placeholder: $field.data('placeholder'), minimumInputLength: kind === 'products' ? 2 : 1,
                language: {
                    inputTooShort: () => kind === 'products' ? 'Skriv minst två tecken…' : 'Börja skriva för att söka…',
                    noResults: () => 'Inga träffar', searching: () => 'Söker…', errorLoading: () => 'Sökningen misslyckades. Försök igen.'
                },
                ajax: {
                    url: swsSelectors.url, dataType: 'json', delay: 250, cache: true,
                    data: params => ({ action: 'sws_search', nonce: swsSelectors.nonce, kind, term: params.term || '' }),
                    processResults: data => ({ results: Array.isArray(data.results) ? data.results : [] })
                }
            });
        });
        const update = () => {
            $('#sws-summary-percent').text($('#sws-percent').val() || '0');
            $('#sws-summary-date').text($('#sws-cutoff').val() || 'Ingen gräns');
            for (const kind of ['categories', 'products']) $('#sws-summary-' + kind).text(($('#sws-' + kind).val() || []).length);
        };
        $page.find('#sws-percent, #sws-cutoff, .sws-search').on('input change', update);
        if ($page.find('#sws-percent').length) update();
    });
})(jQuery);
