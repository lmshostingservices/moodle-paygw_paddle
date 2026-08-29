<?php
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
 * Admin tree entries for the Paddle payment gateway plugin.
 *
 * Credentials live on each payment account, configured through
 * gateway::add_configuration_to_gateway_form(), so this plugin adds no
 * site-wide settings of its own. Setting $settings to null tells Moodle core
 * not to render an empty settings page for it.
 *
 * The two management pages are registered here so administrators can reach
 * them from Site administration > Plugins > Payment gateways rather than by
 * typing a URL.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$reportspage = new admin_externalpage(
    'paygw_paddle_reports',
    get_string('reports', 'paygw_paddle'),
    new moodle_url('/payment/gateway/paddle/admin/reports.php'),
    'paygw/paddle:viewreports'
);
$ADMIN->add('paymentgateways', $reportspage);

$pricemappage = new admin_externalpage(
    'paygw_paddle_pricemap',
    get_string('pricemap', 'paygw_paddle'),
    new moodle_url('/payment/gateway/paddle/admin/pricemap.php'),
    'paygw/paddle:manage'
);
$ADMIN->add('paymentgateways', $pricemappage);

$settings = null;
