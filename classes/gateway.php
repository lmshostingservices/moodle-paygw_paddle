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
 * Paddle payment gateway class.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_paddle;

/**
 * The Paddle gateway definition.
 *
 * All credentials are held per payment account rather than site-wide, so a site
 * can run separate Paddle accounts for different faculties or tenants.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gateway extends \core_payment\gateway {
    /**
     * Returns the list of currencies supported by the Paddle gateway.
     *
     * @return string[] ISO 4217 currency codes.
     */
    public static function get_supported_currencies(): array {
        return [
            'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'NZD', 'SGD', 'HKD',
            'JPY', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF',
            'BRL', 'MXN', 'INR', 'THB', 'TWD', 'KRW', 'ZAR', 'ARS',
            'CLP', 'COP', 'PEN', 'CNY', 'RUB', 'TRY',
        ];
    }

    /**
     * Configuration form for one Paddle payment account.
     *
     * @param \core_payment\form\account_gateway $form The gateway configuration form.
     * @return void
     */
    public static function add_configuration_to_gateway_form(\core_payment\form\account_gateway $form): void {
        $mform = $form->get_mform();

        $mform->addElement('passwordunmask', 'apikey', get_string('apikey', 'paygw_paddle'));
        $mform->setType('apikey', PARAM_TEXT);
        $mform->addHelpButton('apikey', 'apikey', 'paygw_paddle');

        $mform->addElement('text', 'clienttoken', get_string('clienttoken', 'paygw_paddle'));
        $mform->setType('clienttoken', PARAM_TEXT);
        $mform->addHelpButton('clienttoken', 'clienttoken', 'paygw_paddle');

        $mform->addElement('passwordunmask', 'webhooksecret', get_string('webhooksecret', 'paygw_paddle'));
        $mform->setType('webhooksecret', PARAM_TEXT);
        $mform->addHelpButton('webhooksecret', 'webhooksecret', 'paygw_paddle');

        $mform->addElement(
            'select',
            'environment',
            get_string('environment', 'paygw_paddle'),
            [
                'live' => get_string('environment_live', 'paygw_paddle'),
                'sandbox' => get_string('environment_sandbox', 'paygw_paddle'),
            ]
        );
        $mform->setDefault('environment', 'sandbox');
        $mform->addHelpButton('environment', 'environment', 'paygw_paddle');

        $mform->addElement('text', 'defaultproductid', get_string('defaultproductid', 'paygw_paddle'));
        $mform->setType('defaultproductid', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('defaultproductid', 'defaultproductid', 'paygw_paddle');

        $mform->addElement('text', 'defaultpriceid', get_string('defaultpriceid', 'paygw_paddle'));
        $mform->setType('defaultpriceid', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('defaultpriceid', 'defaultpriceid', 'paygw_paddle');

        $mform->addElement(
            'select',
            'refundaction',
            get_string('refundaction', 'paygw_paddle'),
            [
                'unenrol' => get_string('refundaction_unenrol', 'paygw_paddle'),
                'nothing' => get_string('refundaction_nothing', 'paygw_paddle'),
            ]
        );
        $mform->setDefault('refundaction', 'unenrol');
        $mform->addHelpButton('refundaction', 'refundaction', 'paygw_paddle');

        $mform->addElement('text', 'checkouturl', get_string('checkouturl', 'paygw_paddle'));
        $mform->setType('checkouturl', PARAM_URL);
        $mform->addHelpButton('checkouturl', 'checkouturl', 'paygw_paddle');
    }

    /**
     * Validates the gateway configuration form submission.
     *
     * @param \core_payment\form\account_gateway $form The gateway configuration form.
     * @param \stdClass $data The submitted data.
     * @param array $files Any submitted files.
     * @param array $errors Errors to report, keyed by field name.
     * @return void
     */
    public static function validate_gateway_form(
        \core_payment\form\account_gateway $form,
        \stdClass $data,
        array $files,
        array &$errors
    ): void {
        if ($data->enabled) {
            if (empty($data->apikey)) {
                $errors['apikey'] = get_string('required');
            }
            if (empty($data->clienttoken)) {
                $errors['clienttoken'] = get_string('required');
            }
            if (empty($data->webhooksecret)) {
                $errors['webhooksecret'] = get_string('required');
            }
            if (empty($data->defaultproductid) && empty($data->defaultpriceid)) {
                $errors['defaultproductid'] = get_string('productid_or_priceid_required', 'paygw_paddle');
            }
        }
    }
}
