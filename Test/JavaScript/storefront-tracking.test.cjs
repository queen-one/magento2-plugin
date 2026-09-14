const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = name => fs.readFileSync(path.join(__dirname, '../../view/frontend/web/js', name + '.js'), 'utf8');
const copy = value => JSON.parse(JSON.stringify(value));

function deferred() {
    let callbacks = [], failures = [], resolved = false, result;
    return {
        done(fn) { if (resolved) fn(result); else callbacks.push(fn); return this; },
        fail(fn) { failures.push(fn); return this; },
        resolve(value) { resolved = true; result = value; callbacks.forEach(fn => fn(value)); },
        reject() { failures.forEach(fn => fn()); }
    };
}

function fixture(overrides = {}) {
    const config = {siteId: 'magento-test', storeId: '1', websiteId: '1', debug: true,
        cookieName: 'user_allowed_save_cookie', cookieRestriction: false,
        tagUrl: 'https://tag.example/queen-one.js', product: {product_id: '100'}, ...overrides};
    const events = [], scripts = [], requests = [], listeners = {}, timers = new Map(), warnings = [];
    let initializer, mapper, subscriber, timerId = 0, cookie = null;
    const init = deferred();
    const storage = new Map();
    const window = {
        location: {href: 'https://magento.test/product.html'},
        console: {warn: message => warnings.push(message)},
        sessionStorage: {getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key)}
    };
    const document = {
        head: {appendChild(script) { scripts.push(script); script.parentNode = this; }},
        querySelectorAll() { return scripts; },
        createElement() {
            const attributes = {}, handlers = {};
            return {getAttribute: key => attributes[key], setAttribute: (key, value) => { attributes[key] = value; },
                addEventListener: (name, fn) => { handlers[name] = fn; }, handlers};
        }
    };
    const $ = () => ({on: (name, fn) => { listeners[name] = fn; }, off: () => { Object.keys(listeners).forEach(key => delete listeners[key]); }});
    $.mage = {cookies: {get: () => cookie}};
    const customerData = {
        get() { return {subscribe(fn) { subscriber = fn; return {dispose() { subscriber = null; }}; }}; },
        getInitCustomerData() { return init; },
        reload() { const request = deferred(); requests.push(request); return request; }
    };
    const context = {window, document, Promise, setTimeout(fn) { timers.set(++timerId, fn); return timerId; },
        clearTimeout(id) { timers.delete(id); },
        define(deps, factory) {
            if (!deps.length) mapper = factory();
            else initializer = factory($, customerData, mapper);
        }};
    vm.runInNewContext(source('event-mapper'), context);
    vm.runInNewContext(source('storefront-tracking'), context);
    return {
        config, window, events, scripts, requests, warnings, timers, storage, mapper,
        start() { initializer(config); },
        initialize() { init.resolve(); },
        load(push = event => events.push(copy(event))) { window.__qotag = {push, getSiteId: () => config.siteId}; scripts[0].handlers.load(); },
        snapshot(value) { if (subscriber) subscriber(value); },
        respond(value) { if (subscriber) subscriber(value); requests.at(-1).resolve({'queen-one-connect': value}); },
        consent(value) { cookie = value; listeners['user:allowed:save:cookie.queenOneConnect']?.(); },
        types() { return events.map(event => event.type); }
    };
}

function snapshot(items = [{product_id: '100', variant_id: '101', sku: 'PARENT', variant_sku: 'CHILD', item_id: '9', quantity: 2, price: 12.5, total_amount: 25}]) {
    return {version: 1, status: 'ready', store_id: '1', site_id: 'magento-test', data_id: 1,
        identity: {email: 'buyer@example.test'},
        cart: {cart_id: '42', currency_code: 'EUR', cart_value: items.reduce((v, i) => v + i.total_amount, 0),
            cart_item_count: items.reduce((v, i) => v + i.quantity, 0), items, promo: ''}};
}

test('loads the external script once; owns no Tag stub, drains via public API', () => {
    const f = fixture(); f.start(); f.start();
    assert.equal(f.scripts.length, 1);
    assert.equal(f.scripts[0].src, f.config.tagUrl);
    assert.equal(f.scripts[0].getAttribute('data-site-id'), 'magento-test');
    assert.equal(f.scripts[0].async, true);
    assert.equal(f.window.__qotag, undefined);
    f.load();
    assert.deepEqual(f.types(), ['page_viewed', 'product_viewed']);
    assert.deepEqual(Object.keys(f.events[0]).sort(), ['data', 'siteid', 'time', 'type']);
});

test('fresh server snapshot, not cached startup data; repeated notifications do not duplicate', () => {
    const f = fixture(); f.start(); f.snapshot(snapshot()); f.initialize();
    f.snapshot(snapshot()); f.load();
    assert.deepEqual(f.types(), ['page_viewed', 'product_viewed']);
    f.respond(snapshot());
    f.snapshot({...snapshot(), data_id: 500});
    assert.deepEqual(f.types(), ['page_viewed', 'product_viewed', 'user_identified', 'cart_set']);
    assert.equal(f.events[3].data.products[0].product_id, '100');
    assert.equal(f.events[3].data.products[0].variant_id, '101');
    assert.deepEqual(f.events[3].data.cart_value, {amount: 25, currency_code: 'EUR'});
    assert.equal(f.events[3].data.products[0].sku, undefined);
});

test('cart reorder is ignored, quantity/coupon changes publish, empty publishes one reset', () => {
    const f = fixture(); f.start(); f.initialize(); f.load();
    const state = snapshot(); state.cart.items.push({product_id: '200', quantity: 1, price: 5, total_amount: 5});
    state.cart.cart_item_count = 3; state.cart.cart_value = 30;
    f.respond(state); f.snapshot({...copy(state), cart: {...copy(state.cart), items: [...state.cart.items].reverse()}});
    assert.equal(f.types().filter(t => t === 'cart_set').length, 1);
    state.cart.promo = 'SAVE'; f.snapshot(state);
    assert.equal(f.types().filter(t => t === 'cart_set').length, 2);
    f.snapshot(snapshot([])); f.snapshot(snapshot([]));
    assert.equal(f.types().filter(t => t === 'cart_reset').length, 1);
    assert.deepEqual(f.events.at(-1).data, {cart_id: '42'});
});

test('initial empty and extraction errors never produce false cart resets', () => {
    const f = fixture(); f.start(); f.initialize(); f.load(); f.respond(snapshot([]));
    assert.equal(f.types().includes('cart_reset'), false);
    f.snapshot(snapshot());
    f.snapshot({...snapshot([]), status: 'error'});
    f.snapshot({...snapshot([]), cart: {items: []}});
    assert.equal(f.types().includes('cart_reset'), false);
});

test('remembers only last cart ID across document navigation to clear an emptied cart', () => {
    const f = fixture(); f.storage.set('queen-one:last-cart:magento-test:1', '42');
    f.start(); f.initialize(); f.load(); f.respond(snapshot([]));
    assert.deepEqual(f.events.at(-1), {type: 'cart_reset', siteid: 'magento-test', time: f.events.at(-1).time, data: {cart_id: '42'}});
    assert.equal(f.storage.size, 0);
});

test('identity updates, logout/login; no guest form scanning or order events', () => {
    const f = fixture(); f.start(); f.initialize(); f.load(); f.respond(snapshot());
    f.snapshot({...snapshot(), identity: null}); f.snapshot(snapshot());
    f.snapshot({...snapshot(), identity: {email: 'next@example.test'}});
    assert.equal(f.types().filter(t => t === 'user_identified').length, 3);
    assert.equal(f.types().includes('order_created'), false);
});

test('consent respects website, malformed cookie, and starts from current state only', () => {
    const f = fixture({cookieRestriction: true}); f.start(); f.initialize(); f.snapshot(snapshot());
    f.consent('bad JSON'); f.consent('{"2":1}');
    assert.equal(f.scripts.length, 0); assert.equal(f.requests.length, 0);
    f.consent('{"1":1}'); f.respond(snapshot()); f.load();
    assert.deepEqual(f.types(), ['page_viewed', 'product_viewed', 'user_identified', 'cart_set']);
    f.consent('{"1":1}'); assert.equal(f.scripts.length, 1);
    f.consent('{}'); f.snapshot({...snapshot(), identity: {email: 'blocked@example.test'}});
    assert.equal(f.events.length, 4);
});

test('script error and timeout never escape; late load does not replay discarded events', () => {
    for (const reason of ['error', 'timeout']) {
        const f = fixture(); f.start(); f.initialize(); f.respond(snapshot());
        assert.doesNotThrow(() => reason === 'error' ? f.scripts[0].handlers.error() : [...f.timers.values()][0]());
        f.load(); f.snapshot(snapshot());
        assert.equal(f.events.length, 0); assert.equal(f.timers.size, 0);
    }
});

test('bounded buffer stops on overflow', () => {
    const f = fixture(); f.start(); f.initialize(); f.respond(snapshot());
    for (let i = 0; i < 110; i++) f.snapshot({...snapshot(), identity: {email: `buyer${i}@example.test`}});
    f.load(); assert.equal(f.events.length, 0);
    assert.ok(f.warnings.some(w => w.includes('tag_buffer_limit')));
});

test('throwing and rejecting Tag push are contained', async () => {
    const f = fixture(); f.start();
    assert.doesNotThrow(() => f.load(() => { throw new Error('failure'); }));
    assert.ok(f.warnings.some(w => w.includes('tag_push_failed')));
    const g = fixture(); g.start(); g.load(() => Promise.reject(new Error('failure')));
    await new Promise(resolve => setImmediate(resolve));
    assert.ok(g.warnings.some(w => w.includes('tag_push_rejected')));
});

test('conflicting pre-existing Tag cannot receive Magento events', () => {
    const f = fixture(); f.window.__qotag = {push() { assert.fail('must not publish'); }, getSiteId: () => 'another-site'};
    f.start(); assert.equal(f.scripts.length, 0);
});

test('blocked storage, unrelated store/site and malformed cart values are safe', () => {
    const f = fixture(); f.window.sessionStorage.getItem = () => { throw new Error('blocked'); };
    f.start(); f.initialize(); f.load(); f.respond({...snapshot(), store_id: '2'});
    f.snapshot({...snapshot(), site_id: 'another'});
    assert.equal(f.events.length, 2);
    const invalid = snapshot(); invalid.cart.items[0].quantity = 1.5;
    f.snapshot(invalid); assert.equal(f.types().includes('cart_set'), false);
    assert.throws(() => f.mapper.cart({...snapshot().cart, cart_value: null}));
    assert.throws(() => f.mapper.cart({...snapshot().cart, currency_code: 'eur'}));
});

test('consent withdrawn during load discards backlog and grants current page once', () => {
    const f = fixture({cookieRestriction: true}); f.start(); f.initialize();
    f.consent('{"1":1}'); f.respond(snapshot());
    f.consent('{}'); f.load(); assert.equal(f.events.length, 0);
    f.consent('{"1":1}'); f.respond(snapshot([]));
    assert.equal(f.types().filter(t => t === 'page_viewed').length, 1);
    assert.equal(f.types().filter(t => t === 'product_viewed').length, 1);
    assert.equal(f.types().includes('cart_set'), false);
});

test('matching externally installed Tag is reused without replacing its API', () => {
    const f = fixture();
    const api = {push: event => f.events.push(copy(event)), getSiteId: () => f.config.siteId};
    f.window.__qotag = api;
    f.scripts.push({src: f.config.tagUrl, getAttribute: () => f.config.siteId, parentNode: {}});
    f.start(); assert.equal(f.window.__qotag, api); assert.equal(f.scripts.length, 1);
    assert.deepEqual(f.types(), ['page_viewed', 'product_viewed']);
});

test('quantity update and free products are represented without cents conversion', () => {
    const f = fixture(); f.start(); f.initialize(); f.load(); f.respond(snapshot());
    const updated = snapshot(); updated.cart.items[0].quantity = 3; updated.cart.items[0].total_amount = 37.5;
    updated.cart.cart_item_count = 3; updated.cart.cart_value = 37.5; f.snapshot(updated);
    assert.equal(f.events.at(-1).data.products[0].quantity, 3);
    assert.equal(f.events.at(-1).data.cart_value.amount, 37.5);
    const free = snapshot([{product_id: '300', quantity: 1, price: 0, total_amount: 0}]);
    f.snapshot(free); assert.equal(f.events.at(-1).data.cart_value.amount, 0);
});

test('cart extraction errors do not suppress independently available customer identity', () => {
    const f = fixture(); f.start(); f.initialize(); f.load();
    f.respond({...snapshot(), status: 'error', cart: undefined});
    assert.deepEqual(f.types(), ['page_viewed', 'product_viewed', 'user_identified']);
    f.snapshot({version: 1, store_id: '1', site_id: 'magento-test', status: 'error'});
    f.snapshot(snapshot());
    assert.equal(f.types().filter(t => t === 'user_identified').length, 1);
    assert.equal(f.types().filter(t => t === 'cart_set').length, 1);
});
