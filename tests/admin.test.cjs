const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/admin.js'), 'utf8');

function harness({ work = '1', phase = 'starting', result, fetchImpl, missing = false } = {}) {
    const timers = new Map();
    const events = new Map();
    const requests = [];
    const status = { dataset: { work, phase }, textContent: '' };
    let nextId = 0;
    let reloads = 0;
    const context = {
        document: {
            getElementById: () => missing ? null : status,
            querySelectorAll: () => [{ addEventListener: (name, callback) => events.set(name, callback) }]
        },
        swsAdmin: { url: '/wp-admin/admin-ajax.php', nonce: 'test-nonce' },
        location: { reload: () => { reloads++; } },
        AbortController, URLSearchParams,
        setTimeout: (fn, delay) => { const id = ++nextId; timers.set(id, { fn, delay }); return id; },
        clearTimeout: id => timers.delete(id),
        fetch: async (url, options) => {
            requests.push({ url, options });
            if (fetchImpl) return fetchImpl(url, options);
            return { ok: true, json: async () => result || { success: true, data: { phase: 'starting', counts: { managed: 4, done: 1 } } } };
        }
    };
    vm.runInNewContext(source, context);
    return {
        status, timers, requests,
        reloads: () => reloads,
        submit: () => events.get('submit')(),
        run: delay => {
            const entry = [...timers].find(([, timer]) => timer.delay === delay);
            assert.ok(entry, `expected timer ${delay}`);
            timers.delete(entry[0]);
            return entry[1].fn();
        }
    };
}

test('idle pages and missing progress controls never send requests', () => {
    for (const options of [{ work: '0' }, { missing: true }]) {
        const h = harness(options);
        assert.equal(h.timers.size, 0);
        assert.equal(h.requests.length, 0);
    }
});

test('batch requests carry nonce and same-origin credentials, then continue', async () => {
    const h = harness();
    await h.run(1200);
    assert.equal(h.requests[0].url, '/wp-admin/admin-ajax.php');
    assert.equal(h.requests[0].options.credentials, 'same-origin');
    assert.equal(h.requests[0].options.body.get('nonce'), 'test-nonce');
    assert.equal(h.requests[0].options.body.get('action'), 'sws_process');
    assert.match(h.status.textContent, /4 hanterade, 1 klara/);
    assert.equal(h.reloads(), 0);
    assert.deepEqual([...h.timers.values()].map(timer => timer.delay), [2000]);
});

test('submitting a settings form stops polling', async () => {
    const h = harness();
    h.submit();
    await h.run(1200);
    assert.equal(h.requests.length, 0);
});

test('phase changes, conflicts and completed active state reload the lists', async () => {
    for (const data of [
        { phase: 'active', counts: {} },
        { phase: 'starting', counts: { conflicts: 1 } },
        { phase: 'active', counts: { pending: 0 } }
    ]) {
        const h = harness({ phase: data.phase === 'active' && 'pending' in data.counts ? 'active' : 'starting', result: { success: true, data } });
        await h.run(1200);
        assert.equal(h.reloads(), 1);
        assert.equal(h.timers.size, 0);
    }
});

test('server-side batch errors are visible and stop automatic retries', async () => {
    const h = harness({ result: { success: true, data: { error: 'Write failed', phase: 'starting', counts: {} } } });
    await h.run(1200);
    assert.equal(h.status.textContent, 'Write failed');
    assert.equal(h.timers.size, 0);
});

test('rejected requests show the response message', async () => {
    const h = harness({ fetchImpl: async () => ({ ok: false, json: async () => ({ success: false, data: { message: 'Permission denied' } }) }) });
    await h.run(1200);
    assert.equal(h.status.textContent, 'Permission denied');
    assert.equal(h.reloads(), 0);
});

test('request timeout aborts the fetch and explains how to resume', async () => {
    const h = harness({ fetchImpl: (url, options) => new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(Object.assign(new Error('Aborted'), { name: 'AbortError' })));
    }) });
    const pending = h.run(1200);
    h.run(45000);
    await pending;
    assert.match(h.status.textContent, /tog för lång tid/);
    assert.equal(h.timers.size, 0);
});

test('an in-flight response cannot reload a submitted form', async () => {
    let resolve;
    const h = harness({ fetchImpl: () => new Promise(done => { resolve = done; }) });
    const pending = h.run(1200);
    h.submit();
    resolve({ ok: true, json: async () => ({ success: true, data: { phase: 'active', counts: {} } }) });
    await pending;
    assert.equal(h.reloads(), 0);
    assert.equal(h.timers.size, 0);
});
