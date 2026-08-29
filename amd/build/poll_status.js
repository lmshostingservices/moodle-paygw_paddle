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
 * Re-checks whether Paddle has confirmed a payment yet.
 *
 * The attempt counter lives in the URL, so the server decides when to stop
 * polling and show the payer an explanation instead of reloading forever.
 *
 * @module     paygw_paddle/poll_status
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function () {
    return {
        /**
         * Reload the status page once, after a delay.
         *
         * @param {String} nextUrl The URL to load, carrying the next attempt number.
         * @param {Number} delay How long to wait, in milliseconds.
         * @returns {void}
         */
        init: function (nextUrl, delay) {
            window.setTimeout(function () {
                window.location.assign(nextUrl);
            }, delay);
        }
    };
});
