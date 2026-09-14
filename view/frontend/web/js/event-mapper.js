define([], function () {
    'use strict';

    function id(value) {
        return typeof value === 'string' && value.trim() ? value.trim() : null;
    }

    function amount(value, currency) {
        if (typeof value !== 'number' || !Number.isFinite(value) || value < 0 ||
            !/^[A-Z]{3}$/.test(currency)) {
            throw new Error('invalid_money');
        }
        return {amount: value, currency_code: currency};
    }

    function productViewed(product) {
        return product && id(product.product_id) ? {product_id: id(product.product_id)} : null;
    }

    function identity(customer) {
        var email = customer && id(customer.email);
        return email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? {email: email} : null;
    }

    function cart(snapshot) {
        var products, result, count;
        if (!snapshot || !Array.isArray(snapshot.items)) {
            throw new Error('missing_cart');
        }
        if (snapshot.items.length === 0) {
            if (snapshot.cart_item_count !== 0 || snapshot.cart_value !== 0) {
                throw new Error('inconsistent_empty_cart');
            }
            return null;
        }
        if (!id(snapshot.cart_id)) {
            throw new Error('missing_cart_id');
        }
        products = snapshot.items.map(function (item) {
            var product;
            if (!item || !id(item.product_id) || !Number.isInteger(item.quantity) ||
                item.quantity <= 0 || item.quantity > 2147483647) {
                throw new Error('invalid_item');
            }
            product = {
                product_id: id(item.product_id),
                price: amount(item.price, snapshot.currency_code),
                quantity: item.quantity,
                total_amount: amount(item.total_amount, snapshot.currency_code)
            };
            if (item.variant_id !== undefined) {
                if (!id(item.variant_id)) {
                    throw new Error('invalid_variant');
                }
                product.variant_id = id(item.variant_id);
            }
            return product;
        });
        products.sort(function (a, b) {
            var left = JSON.stringify(a), right = JSON.stringify(b);
            return left < right ? -1 : left > right ? 1 : 0;
        });
        count = products.reduce(function (sum, product) { return sum + product.quantity; }, 0);
        if (count !== snapshot.cart_item_count || count > 2147483647) {
            throw new Error('invalid_item_count');
        }
        result = {
            cart_id: id(snapshot.cart_id),
            cart_value: amount(snapshot.cart_value, snapshot.currency_code),
            cart_item_count: count,
            products: products
        };
        if (id(snapshot.promo)) {
            result.promo = id(snapshot.promo);
        }
        return result;
    }

    return {productViewed: productViewed, identity: identity, cart: cart};
});
