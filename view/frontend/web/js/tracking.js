/**
 * Copyright © 2017 Rejoiner. All rights reserved.
 * See COPYING.txt for license details.
 */
define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'QueenOneTracking'
], function($, customerData, queenOne) {
    'use strict';

    $.widget('rejoiner.acrTracking', {
        skipSubscription: false,

        options: {
            rejoinerSiteId: '',
            rejoinerDomain: '',
            rejoinerScriptUri: '',
            frontendTrackingMode: 'rejoiner',
            queenOneTagUrl: '',
            outputConversionData: false
        },

        _create: function() {
            var storageData = customerData.get('rejoiner-acr'),
                that = this;

            if (this.isQueenOneEnabled()) {
                queenOne.ensureQueue(window);
            }

            this.processTrackingUpdate(false);

            storageData.subscribe(function() {
                if (!that.skipSubscription) {
                    that.processTrackingUpdate(true);
                }
            });

            this.connectRemoteScripts();
        },

        processTrackingUpdate: function(isAjaxUpdate) {
            var storageData = customerData.get('rejoiner-acr')(),
                snapshot = $.extend({}, storageData);

            if (this.isQueenOneEnabled()) {
                this.trackQueenOne(snapshot, isAjaxUpdate);
            }

            if (this.isRejoinerEnabled()) {
                window._rejoiner = this.getRejoinerObject(snapshot, isAjaxUpdate);
            }

            this.clearTransientStorageData(storageData, isAjaxUpdate);
        },

        isRejoinerEnabled: function() {
            return this.options.frontendTrackingMode !== 'queen_one'
                && this.options.rejoinerSiteId
                && this.options.rejoinerDomain;
        },

        isQueenOneEnabled: function() {
            return this.options.frontendTrackingMode !== 'rejoiner'
                && this.options.rejoinerSiteId;
        },

        getRejoinerObject: function(storageData, isAjaxUpdate) {
            var _rejoiner = window._rejoiner || [];

            if (this.options.trackCartDataOnThisPage == 1 || isAjaxUpdate) {
                if (storageData.cartData) {
                    _rejoiner.push(["setCartData", JSON.parse(storageData.cartData)]);
                }
                if (storageData.cartItems) {
                    JSON.parse(storageData.cartItems).forEach(function(element) {
                        _rejoiner.push(["setCartItem", element]);
                    });
                }

                if (storageData.removedItems) {
                    JSON.parse(storageData.removedItems).forEach(function(element) {
                        _rejoiner.push(["removeCartItem", {product_id: element}]);
                    });
                }
            }

            if (!isAjaxUpdate) {
                if (this.options.rejoinerSiteId) {
                    _rejoiner.push(["setAccount", this.options.rejoinerSiteId]);
                }
                if (this.options.rejoinerDomain) {
                    _rejoiner.push(["setDomain", this.options.rejoinerDomain]);
                }
                if (this.options.trackNumberEnabled) {
                    _rejoiner.push(["trackNumbers"]);
                }
                if (this.options.persistFormsEnabled) {
                    _rejoiner.push(["persistForms"]);
                }
                if (this.options.trackProductView) {
                    _rejoiner.push(['trackProductView', this.options.trackProductView]);
                }
                if (this.options.outputConversionData
                    && storageData.convertionCartData
                    && storageData.convertionCartItems
                ) {
                    _rejoiner.push(["sendConversion", {
                        cart_data: JSON.parse(storageData.convertionCartData),
                        cart_items: JSON.parse(storageData.convertionCartItems)
                    }]);
                }
            }

            if (storageData.customerEmail) {
                _rejoiner.push(['setCustomerEmail', JSON.parse(storageData.customerEmail)]);
            }
            if (storageData.customerData) {
                _rejoiner.push(['setCustomerData', JSON.parse(storageData.customerData)]);
            }

            return _rejoiner;
        },

        trackQueenOne: function(storageData, isAjaxUpdate) {
            var cart,
                order,
                product,
                user;

            if (!isAjaxUpdate) {
                queenOne.pushEvent(window, this.options.rejoinerSiteId, 'page_viewed', {
                    url: window.location.href
                });

                product = queenOne.buildProductViewed(this.options.trackProductView);
                if (product) {
                    queenOne.pushEvent(
                        window,
                        this.options.rejoinerSiteId,
                        'product_viewed',
                        product
                    );
                }
            }

            if (this.options.trackCartDataOnThisPage == 1 || isAjaxUpdate) {
                cart = queenOne.buildCart(storageData.cartData, storageData.cartItems);
                if (cart) {
                    queenOne.pushEvent(window, this.options.rejoinerSiteId, 'cart_set', cart);
                } else if (isAjaxUpdate && storageData.removedItems) {
                    queenOne.pushEvent(window, this.options.rejoinerSiteId, 'cart_reset', {});
                }
            }

            if (!isAjaxUpdate
                && this.options.outputConversionData
                && storageData.convertionCartData
                && storageData.convertionCartItems
            ) {
                order = queenOne.buildOrder(
                    storageData.convertionCartData,
                    storageData.convertionCartItems
                );
                if (order) {
                    queenOne.pushEvent(window, this.options.rejoinerSiteId, 'order_created', order);
                }
            }

            user = queenOne.buildUserIdentified(storageData.customerEmail);
            if (user) {
                queenOne.pushEvent(window, this.options.rejoinerSiteId, 'user_identified', user);
            }
        },

        clearTransientStorageData: function(storageData, isAjaxUpdate) {
            var changed = false;

            if ((this.options.trackCartDataOnThisPage == 1 || isAjaxUpdate)
                && storageData.removedItems !== undefined
            ) {
                delete storageData.removedItems;
                changed = true;
            }

            if (!isAjaxUpdate
                && this.options.outputConversionData
                && storageData.convertionCartData !== undefined
                && storageData.convertionCartItems !== undefined
            ) {
                delete storageData.convertionCartData;
                delete storageData.convertionCartItems;
                changed = true;
            }

            if (!changed) {
                return;
            }

            this.skipSubscription = true;
            customerData.set('rejoiner-acr', storageData);
            this.skipSubscription = false;
        },

        connectRemoteScripts: function() {
            if (this.isRejoinerEnabled() && this.options.rejoinerScriptUri) {
                this.connectRemoteScript(
                    this.options.rejoinerScriptUri,
                    'rejoiner-tracking-script'
                );
            }

            if (this.isQueenOneEnabled() && this.options.queenOneTagUrl) {
                this.connectRemoteScript(
                    this.options.queenOneTagUrl,
                    'queen-one-tracking-script',
                    this.options.rejoinerSiteId
                );
            }
        },

        connectRemoteScript: function(uri, elementId, siteId) {
            var script,
                firstScript;

            if (document.getElementById(elementId)) {
                return;
            }

            script = document.createElement('script');
            script.id = elementId;
            script.type = 'text/javascript';
            script.async = true;
            script.src = uri;

            if (siteId) {
                script.setAttribute('data-site-id', siteId);
            }

            firstScript = document.getElementsByTagName('script')[0];
            if (firstScript && firstScript.parentNode) {
                firstScript.parentNode.insertBefore(script, firstScript);
            } else {
                document.head.appendChild(script);
            }
        }
    });

    return $.rejoiner.acrTracking;
});
