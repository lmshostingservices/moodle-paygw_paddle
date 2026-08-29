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
 * Form for adding or editing a course to Paddle price mapping.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_paddle\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Add or edit one course to Paddle price mapping.
 *
 * The course field is an autocomplete rather than a plain select, so the form
 * does not have to load every course on the site.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pricemap_form extends \moodleform {
    /**
     * Build the form.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement(
            'course',
            'courseid',
            get_string('coursename', 'paygw_paddle'),
            [
                'exclude' => [SITEID],
            ]
        );
        $mform->addRule('courseid', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'paddle_price_id', get_string('paddlepriceid', 'paygw_paddle'));
        $mform->setType('paddle_price_id', PARAM_ALPHANUMEXT);
        $mform->addRule('paddle_price_id', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'amount', get_string('pricemapamount', 'paygw_paddle'));
        $mform->setType('amount', PARAM_FLOAT);
        $mform->addRule('amount', get_string('required'), 'required', null, 'client');

        $mform->addElement(
            'text',
            'currency',
            get_string('pricemapcurrency', 'paygw_paddle'),
            ['maxlength' => 3, 'size' => 5]
        );
        $mform->setType('currency', PARAM_ALPHA);
        $mform->addRule('currency', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'description', get_string('pricemapdescription', 'paygw_paddle'));
        $mform->setType('description', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('addpricemapping', 'paygw_paddle'));
    }

    /**
     * Server side validation.
     *
     * @param array $data The submitted data.
     * @param array $files The submitted files.
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files) {
        global $DB;

        $errors = parent::validation($data, $files);

        $currency = strtoupper(trim($data['currency'] ?? ''));
        if (strlen($currency) !== 3
                || !in_array($currency, \paygw_paddle\gateway::get_supported_currencies(), true)) {
            $errors['currency'] = get_string('invalidcurrency', 'paygw_paddle');
        }

        if (empty($data['id'])) {
            if ($DB->record_exists('paygw_paddle_prices', ['courseid' => $data['courseid']])) {
                $errors['courseid'] = get_string('pricemapduplicate', 'paygw_paddle');
            }
        }

        return $errors;
    }
}
