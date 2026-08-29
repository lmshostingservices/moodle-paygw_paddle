// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Opens the Paddle checkout overlay.
 *
 * Paddle.js ships with a UMD wrapper: when window.define exists it registers
 * through AMD instead of assigning window.Paddle. Loading it from a plain
 * script tag while RequireJS is active therefore raises "Mismatched anonymous
 * define() module" and Paddle never becomes available.
 *
 * The fix is to let RequireJS load it. Registering the CDN URL under a module
 * name and requiring that name means RequireJS is the one that asked for the
 * anonymous define() call, so it attributes the module correctly. Nothing on
 * the page is monkey-patched.
 *
 * @module     paygw_paddle/checkout
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function () {

    var PADDLE_MODULE = 'paygw_paddle_paddlejs';
    var PADDLE_URL = 'https://cdn.paddle.com/paddle/v2/paddle';

    /**
     * Load Paddle.js through RequireJS.
     *
     * @returns {Promise} Resolves with the Paddle global.
     */
    var loadPaddle = function () {
        return new Promise(function (resolve, reject) {
            if (window.Paddle) {
                resolve(window.Paddle);
                return;
            }

            var paths = {};
            paths[PADDLE_MODULE] = PADDLE_URL;
            window.requirejs.config({paths: paths});

            window.require([PADDLE_MODULE], function (paddle) {
                resolve(window.Paddle || paddle);
            }, reject);
        });
    };

    /**
     * Build the customer object Paddle.Checkout.open() accepts.
     *
     * Paddle resolves the name shown on the invoice from the customer record
     * identified by customer.id, so that is preferred. When an id is present
     * Paddle rejects a raw address alongside it, because the address is already
     * attached to the transaction server side.
     *
     * @param {Object} data Customer data supplied by the server.
     * @returns {Object|null} The customer object, or null when there is nothing to send.
     */
    var buildCustomer = function (data) {
        if (!data) {
            return null;
        }

        var customer = {};

        if (data.id) {
            customer.id = data.id;
        } else if (data.email) {
            // Fallback when the server could not create the customer record.
            customer.email = data.email;
            if (data.address && data.address.countryCode) {
                customer.address = data.address;
            }
        }

        if (data.business) {
            customer.business = data.business;
        }

        return Object.keys(customer).length ? customer : null;
    };

    /**
     * Read the transaction id out of a Paddle checkout event.
     *
     * @param {Object} event The Paddle event.
     * @param {String} fallback The transaction id we already know.
     * @returns {String} A transaction id.
     */
    var transactionIdFromEvent = function (event, fallback) {
        var data = (event && event.data) || {};
        var transaction = data.transaction || {};
        return data.transaction_id || transaction.id || data.id || fallback || '';
    };

    return {
        /**
         * Initialise Paddle.js and open the checkout.
         *
         * @param {Object} config Configuration supplied by checkout.php.
         * @param {String} config.token Paddle client-side token.
         * @param {String} config.environment Either 'live' or 'sandbox'.
         * @param {String} config.transactionId The Paddle transaction to pay.
         * @param {String} config.processUrl Where to send the payer afterwards.
         * @param {Object} config.customer Customer data for the overlay.
         * @param {String} config.failureMessage Message to show if loading fails.
         * @returns {void}
         */
        init: function (config) {
            var statusElement = document.querySelector('[data-region="paddle-checkout-status"]');

            var showFailure = function (error) {
                window.console.error('paygw_paddle: ' + (error && error.message ? error.message : error));
                if (statusElement) {
                    statusElement.textContent = config.failureMessage;
                }
            };

            // Paddle.js opens the checkout by itself when it sees _ptxn in the
            // address bar, and that automatic path ignores the customer object.
            // Remove the parameter before initialising so the overlay is opened
            // here instead, with the customer attached.
            try {
                if (window.history && window.history.replaceState) {
                    var url = new URL(window.location.href);
                    if (url.searchParams.has('_ptxn')) {
                        url.searchParams.delete('_ptxn');
                        window.history.replaceState({}, '', url.toString());
                    }
                }
            } catch (error) {
                // Not being able to tidy the URL is not worth failing over.
                window.console.warn('paygw_paddle: could not rewrite the checkout URL.');
            }

            loadPaddle().then(function (Paddle) {
                if (config.environment === 'sandbox') {
                    Paddle.Environment.set('sandbox');
                }

                Paddle.Initialize({
                    token: config.token,
                    eventCallback: function (event) {
                        var name = event && (event.name || event.type);
                        if (name !== 'checkout.completed' && name !== 'checkout.success') {
                            return;
                        }
                        if (!config.processUrl) {
                            return;
                        }
                        var transactionId = transactionIdFromEvent(event, config.transactionId);
                        window.location.assign(
                            config.processUrl + '&transaction_id=' + encodeURIComponent(transactionId)
                        );
                    }
                });

                if (!config.transactionId) {
                    showFailure('No transaction id was supplied.');
                    return;
                }

                var openParams = {transactionId: config.transactionId};
                var customer = buildCustomer(config.customer);
                if (customer) {
                    openParams.customer = customer;
                }

                Paddle.Checkout.open(openParams);
                return;
            }).catch(showFailure);
        }
    };
});
