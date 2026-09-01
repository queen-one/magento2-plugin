/**
 * Copyright © 2026 Queen One. All rights reserved.
 * See COPYING.txt for license details.
 */
define([], function() {
    'use strict';

    var MODULE_ID = 'magento2';

    function parseJson(value, fallback) {
        if (value === undefined || value === null || value === '') {
            return fallback;
        }

        if (typeof value !== 'string') {
            return value;
        }

        try {
            return JSON.parse(value);
        } catch (error) {
            return fallback;
        }
    }

    function toNumber(value) {
        var number = Number(value);

        return Number.isFinite(number) ? number : null;
    }

    function moneyFromCents(value, currencyCode) {
        var cents = toNumber(value),
            currency = String(currencyCode || '').trim();

        if (cents === null || !currency) {
            return null;
        }

        return {
            amount: cents / 100,
            currency_code: currency
        };
    }

    function mapProduct(item, currencyCode) {
        var productId = String(item.product_id || '').trim(),
            quantity = toNumber(item.item_qty),
            price = moneyFromCents(item.price, currencyCode),
            totalAmount = moneyFromCents(item.qty_price, currencyCode),
            product;

        if (!productId || quantity === null || !price || !totalAmount) {
            return null;
        }

        product = {
            product_id: productId,
            price: price,
            quantity: quantity,
            total_amount: totalAmount
        };

        if (item.variant_id) {
            product.variant_id = String(item.variant_id);
        }

        return product;
    }

    function mapProducts(items, currencyCode) {
        return (items || []).map(function(item) {
            return mapProduct(item, currencyCode);
        }).filter(function(item) {
            return item !== null;
        });
    }

    function ensureQueue(targetWindow) {
        var resolveReady,
            queue;

        if (targetWindow.__qotag && typeof targetWindow.__qotag.push === 'function') {
            return targetWindow.__qotag;
        }

        queue = {
            _q: [],
            push: function(event) {
                if (!event.timestamp) {
                    event.timestamp = new Date().toISOString();
                }
                this._q.push(event);
            },
            on: function() {}
        };

        queue.ready = new Promise(function(resolve) {
            resolveReady = resolve;
        });
        queue._resolve = resolveReady;
        targetWindow.__qotag = queue;

        return queue;
    }

    function pushEvent(targetWindow, siteId, type, data) {
        ensureQueue(targetWindow).push({
            type: type,
            timestamp: new Date().toISOString(),
            site_id: siteId,
            module_id: MODULE_ID,
            schema_version: 1,
            data: data
        });
    }

    function buildProductViewed(product) {
        var productId = String((product || {}).product_id || '').trim();

        if (!productId) {
            return null;
        }

        return {product_id: productId};
    }

    function buildCart(cartData, cartItems) {
        var data = parseJson(cartData, {}),
            items = parseJson(cartItems, []),
            cartId = String(data.cart_id || '').trim(),
            cartValue = moneyFromCents(
                data.queen_one_cart_value !== undefined
                    ? data.queen_one_cart_value
                    : data.cart_value,
                data.currency_code
            ),
            itemCount = toNumber(data.total_items_count !== undefined
                ? data.total_items_count
                : data.cart_item_count),
            result;

        if (!cartId || !cartValue || itemCount === null) {
            return null;
        }

        result = {
            cart_id: cartId,
            cart_value: cartValue,
            cart_item_count: itemCount,
            products: mapProducts(items, data.currency_code)
        };

        if (data.return_url) {
            result.return_url = String(data.return_url);
        }
        if (data.promo) {
            result.promo = String(data.promo);
        }

        return result;
    }

    function buildOrder(cartData, cartItems) {
        var data = parseJson(cartData, {}),
            items = parseJson(cartItems, []),
            orderId = String(data.customer_order_number || '').trim(),
            totalPrice = moneyFromCents(
                data.queen_one_total_price !== undefined
                    ? data.queen_one_total_price
                    : data.cart_value,
                data.currency_code
            ),
            subtotalPrice = moneyFromCents(data.queen_one_subtotal_price, data.currency_code),
            result;

        if (!orderId || !totalPrice) {
            return null;
        }

        result = {
            order_id: orderId,
            total_price: totalPrice,
            items: mapProducts(items, data.currency_code)
        };

        if (data.cart_id) {
            result.checkout_id = String(data.cart_id);
        }
        if (subtotalPrice) {
            result.subtotal_price = subtotalPrice;
        }

        return result;
    }

    function buildUserIdentified(customerEmail) {
        var customer = parseJson(customerEmail, {}),
            email = String(customer.email || '').trim();

        return email ? {email: email} : null;
    }

    return {
        ensureQueue: ensureQueue,
        pushEvent: pushEvent,
        buildProductViewed: buildProductViewed,
        buildCart: buildCart,
        buildOrder: buildOrder,
        buildUserIdentified: buildUserIdentified
    };
});
