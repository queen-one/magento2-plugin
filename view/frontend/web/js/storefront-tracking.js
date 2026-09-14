define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'Rejoiner_Acr/js/event-mapper',
    'mage/cookies'
], function ($, customerData, mapper) {
    'use strict';

    var instance,
        SECTION = 'queen-one-connect',
        SCRIPT_ID = 'queen-one-connect-tag',
        MAX_BUFFER = 100,
        LOAD_TIMEOUT = 15000;

    return function (config) {
        var queue = [], failed = false, loaded = false, started = false, allowed = false,
            timer, refreshPending = false, initialized = false, pageSent = false,
            productSent = false, cartFingerprint, identityFingerprint, lastCartId,
            section, storageKey, script, subscription;

        function warn(code) {
            if (config.debug && window.console) {
                window.console.warn('[Queen One] ' + code);
            }
        }

        function guarded(fn) {
            return function () {
                try { return fn.apply(null, arguments); } catch (error) { warn('tracking_callback_failed'); }
            };
        }

        function hasConsent() {
            var cookies;
            if (!config.cookieRestriction) { return true; }
            try {
                cookies = JSON.parse($.mage.cookies.get(config.cookieName) || '{}');
                return !!cookies && cookies[config.websiteId] === 1;
            } catch (error) {
                return false;
            }
        }

        function rememberCart(cartId) {
            lastCartId = cartId;
            try {
                if (cartId) { window.sessionStorage.setItem(storageKey, cartId); }
                else { window.sessionStorage.removeItem(storageKey); }
            } catch (error) { /* Commerce must work with storage disabled. */ }
        }

        function stop(code) {
            failed = true;
            queue = [];
            clearTimeout(timer);
            if (subscription) { subscription.dispose(); }
            $(document).off('.queenOneConnect');
            warn(code);
        }

        function matchesSite() {
            var tag = window.__qotag, site;
            if (tag && typeof tag.getSiteId === 'function') { site = tag.getSiteId(); }
            return !site || site === config.siteId;
        }

        function deliver(event) {
            var result;
            if (!hasConsent()) { return; }
            try {
                if (!matchesSite()) { stop('site_id_conflict'); return; }
                result = window.__qotag.push(event);
                if (result && typeof result.then === 'function') {
                    Promise.resolve(result).catch(function () { stop('tag_push_rejected'); });
                }
            } catch (error) { stop('tag_push_failed'); }
        }

        function publish(type, data) {
            var event;
            if (failed || !hasConsent()) { return false; }
            event = {type: type, siteid: config.siteId, time: new Date().toISOString(), data: data};
            if (loaded) { deliver(event); }
            else if (queue.length < MAX_BUFFER) { queue.push(event); }
            else { stop('tag_buffer_limit'); }
            return !failed;
        }

        function onLoad() {
            if (failed) { return; }
            if (!window.__qotag || typeof window.__qotag.push !== 'function') {
                stop('tag_api_missing'); return;
            }
            if (!matchesSite()) { stop('site_id_conflict'); return; }
            loaded = true;
            clearTimeout(timer);
            if (!hasConsent()) { checkConsent(); }
            while (queue.length && !failed) { deliver(queue.shift()); }
        }

        function loadTag() {
            var existing = Array.prototype.slice.call(document.querySelectorAll('script[src]')),
                conflict = false;
            existing.forEach(function (element) {
                if (element.id === SCRIPT_ID || /\/(queen-one(-tag|-core)?)\.js(?:[?#]|$)/.test(element.src)) {
                    if (element.getAttribute('data-site-id') && element.getAttribute('data-site-id') !== config.siteId) {
                        conflict = true;
                    }
                    if (element.src === config.tagUrl) { script = element; }
                    else if (!/\/queen-one-core\.js(?:[?#]|$)/.test(element.src)) { conflict = true; }
                }
            });
            if (conflict || !matchesSite()) { stop('existing_tag_conflict'); return; }
            if (!script && window.__qotag) { stop('unowned_tag_present'); return; }
            timer = setTimeout(function () { stop('tag_load_timeout'); }, LOAD_TIMEOUT);
            if (script) {
                if (script.getAttribute('data-site-id') !== config.siteId) { stop('site_id_conflict'); return; }
                if (window.__qotag && typeof window.__qotag.push === 'function') { onLoad(); return; }
            } else {
                script = document.createElement('script');
                script.id = SCRIPT_ID;
                script.async = true;
                script.src = config.tagUrl;
                script.setAttribute('data-site-id', config.siteId);
            }
            script.addEventListener('load', guarded(onLoad));
            script.addEventListener('error', function () { stop('tag_load_failed'); });
            if (!script.parentNode) { document.head.appendChild(script); }
        }

        function processSnapshot(snapshot) {
            var user, userKey, cart, cartKey;
            if (failed || !hasConsent() || !snapshot || snapshot.version !== 1 ||
                ['ready', 'error'].indexOf(snapshot.status) === -1 || snapshot.store_id !== config.storeId ||
                snapshot.site_id !== config.siteId) { return; }
            if (Object.prototype.hasOwnProperty.call(snapshot, 'identity')) {
                user = mapper.identity(snapshot.identity);
                userKey = JSON.stringify(user);
                if (userKey !== identityFingerprint) {
                    if (!user || publish('user_identified', user)) { identityFingerprint = userKey; }
                }
            }
            if (snapshot.status !== 'ready') { warn('cart_section_unavailable'); return; }
            try { cart = mapper.cart(snapshot.cart); }
            catch (error) { warn('cart_snapshot_invalid'); return; }
            cartKey = JSON.stringify(cart);
            if (cartKey === cartFingerprint) { return; }
            if (cart) {
                if (publish('cart_set', cart)) { cartFingerprint = cartKey; rememberCart(cart.cart_id); }
            } else if (lastCartId) {
                if (publish('cart_reset', {cart_id: lastCartId})) {
                    cartFingerprint = cartKey; rememberCart(null);
                }
            } else { cartFingerprint = cartKey; }
        }

        function refresh() {
            if (refreshPending || failed || !hasConsent() || !initialized) { return; }
            refreshPending = true;
            // One server refresh avoids publishing another customer's persisted section at startup.
            customerData.reload([SECTION], true).done(guarded(function (sections) {
                refreshPending = false;
                processSnapshot(sections[SECTION]);
            })).fail(function () { refreshPending = false; warn('section_reload_failed'); });
        }

        function checkConsent() {
            var next = hasConsent(), product;
            if (failed) { return; }
            if (!next) {
                allowed = false;
                if (queue.some(function (event) { return event.type === 'page_viewed'; })) { pageSent = false; }
                if (queue.some(function (event) { return event.type === 'product_viewed'; })) { productSent = false; }
                queue = [];
                identityFingerprint = undefined;
                cartFingerprint = undefined;
                return;
            }
            if (allowed) { return; }
            allowed = true;
            if (!started) {
                started = true;
                try { lastCartId = window.sessionStorage.getItem(storageKey); } catch (error) { /* optional */ }
                loadTag();
            }
            if (!pageSent) { pageSent = publish('page_viewed', {url: window.location.href}); }
            product = mapper.productViewed(config.product);
            if (product && !productSent) { productSent = publish('product_viewed', product); }
            refresh();
        }

        try {
            if (!config.siteId || !config.storeId || !/^https:\/\//.test(config.tagUrl)) { return; }
            if (instance) {
                if (instance.siteId !== config.siteId || instance.tagUrl !== config.tagUrl) { warn('duplicate_config_conflict'); }
                return;
            }
            instance = {siteId: config.siteId, tagUrl: config.tagUrl};
            storageKey = 'queen-one:last-cart:' + config.siteId + ':' + config.storeId;
            section = customerData.get(SECTION);
            subscription = section.subscribe(guarded(function (snapshot) {
                checkConsent();
                if (initialized && !refreshPending) { processSnapshot(snapshot); }
            }));
            $(document).on('user:allowed:save:cookie.queenOneConnect', guarded(checkConsent));
            $(document).on('visibilitychange.queenOneConnect', guarded(checkConsent));
            checkConsent();
            customerData.getInitCustomerData().done(guarded(function () {
                initialized = true;
                refresh();
            }));
        } catch (error) { stop('tracking_initialization_failed'); }
    };
});
