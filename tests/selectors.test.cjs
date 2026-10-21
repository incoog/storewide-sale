const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

test('category and product selectors use scoped, nonce-protected WooCommerce widgets', () => {
    const configs = [];
    const fields = ['categories', 'products'].map(kind => ({
        data: key => key === 'kind' ? kind : `Search ${kind}`,
        selectWoo: config => configs.push({ kind, config })
    }));
    const values = { '#sws-percent': '20', '#sws-cutoff': '', '#sws-categories': ['2'], '#sws-products': ['4', '5'] };
    const texts = {};
    const outputs = selector => ({ length: 1, val: () => values[selector], text: value => { texts[selector] = value; } });
    const page = { length: 1, find: selector => selector === '.sws-search' ? { each: fn => fields.forEach(field => fn.call(field)) } : { length: 1, on: () => {} } };
    const $ = value => {
        if (typeof value === 'function') return value();
        if (typeof value === 'object') return value;
        return value === '.sws' ? page : outputs(value);
    };
    $.fn = { selectWoo: () => {} };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/selectors.js'), 'utf8'), { jQuery: $, swsSelectors: { url: '/ajax', nonce: 'search-nonce' } });
    assert.equal(configs.length, 2);
    for (const { kind, config } of configs) {
        assert.equal(config.multiple, true);
        assert.equal(config.minimumInputLength, kind === 'products' ? 2 : 1);
        const request = config.ajax.data({ term: 'vase' });
        assert.equal(request.nonce, 'search-nonce');
        assert.equal(request.kind, kind);
        assert.equal(request.action, 'sws_search');
        assert.equal(config.ajax.processResults({ results: [{ id: 1, text: '<unsafe>' }] }).results[0].text, '<unsafe>');
        assert.equal(config.ajax.processResults({}).results.length, 0);
        assert.equal(config.templateResult, undefined, 'keep SelectWoo default text escaping');
    }
    assert.equal(texts['#sws-summary-percent'], '20');
    assert.equal(texts['#sws-summary-categories'], 1);
    assert.equal(texts['#sws-summary-products'], 2);
});
