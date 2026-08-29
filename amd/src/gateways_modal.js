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
 * Entry point called by Moodle's core payment modal.
 *
 * Unlike gateways that can complete a payment inside the modal, Paddle needs a
 * full page: billing details that Paddle's own overlay never asks for are
 * collected on pay.php, and Paddle.js then opens its overlay on checkout.php.
 * So process() navigates away rather than resolving.
 *
 * The returned promise is deliberately never settled. Core keeps its spinner up
 * until the browser leaves the page, which is the behaviour we want; resolving
 * would briefly close the modal and show the payment as finished before the
 * navigation happens.
 *
 * @module     paygw_paddle/gateways_modal
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function () {
    return {
        /**
         * Send the payer to the Paddle billing details page.
         *
         * @param {String} component Moodle payment component.
         * @param {String} paymentArea Moodle payment area.
         * @param {Number} itemId Moodle item id.
         * @returns {Promise} A promise that never settles; the page navigates away.
         */
        process: function (component, paymentArea, itemId) {
            window.location.href = M.cfg.wwwroot + '/payment/gateway/paddle/pay.php' +
                '?component=' + encodeURIComponent(component) +
                '&paymentarea=' + encodeURIComponent(paymentArea) +
                '&itemid=' + encodeURIComponent(itemId);
            return new Promise(function () {
                return;
            });
        }
    };
});
