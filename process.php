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
 * Return page shown after the Paddle checkout closes.
 *
 * The webhook, not this page, confirms the payment. While Paddle's notification
 * is still in flight the page re-checks a fixed number of times and then stops,
 * so a misconfigured webhook cannot leave a payer reloading indefinitely.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

require_login();

// Access control: require_login() only, for the same reason as pay.php -- this
// is the page a payer lands on after checkout. It deliberately does not use a
// capability check; instead the confirmation lookups below are scoped to
// $USER->id, so one user can never read another user's payment status.

/** @var int How many times the page re-checks before giving up. */
const PAYGW_PADDLE_MAX_POLL_ATTEMPTS = 12;

/** @var int How long to wait between checks, in milliseconds. */
const PAYGW_PADDLE_POLL_DELAY_MS = 5000;

$component = required_param('component', PARAM_ALPHANUMEXT);
$paymentarea = required_param('paymentarea', PARAM_ALPHANUMEXT);
$itemid = required_param('itemid', PARAM_INT);
$transactionid = optional_param('transaction_id', '', PARAM_ALPHANUMEXT);
$attempt = optional_param('attempt', 0, PARAM_INT);

$urlparams = [
    'component' => $component,
    'paymentarea' => $paymentarea,
    'itemid' => $itemid,
];
if ($transactionid !== '') {
    $urlparams['transaction_id'] = $transactionid;
}

$PAGE->set_url(new moodle_url('/payment/gateway/paddle/process.php', $urlparams));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('paymentprocessing', 'paygw_paddle'));
$PAGE->set_heading(get_string('paymentprocessing', 'paygw_paddle'));

// The payment is confirmed once the webhook has marked the transaction complete
// and Moodle has a matching payment record for this learner.
$confirmed = false;
if ($transactionid !== '') {
    $confirmed = $DB->record_exists(
        'paygw_paddle_transactions',
        [
            'paddle_transaction_id' => $transactionid,
            'userid' => $USER->id,
            'status' => 'completed',
        ]
    );
}
if (!$confirmed) {
    $confirmed = $DB->record_exists(
        'payments',
        [
            'component' => $component,
            'paymentarea' => $paymentarea,
            'itemid' => $itemid,
            'userid' => $USER->id,
            'gateway' => 'paddle',
        ]
    );
}

echo $OUTPUT->header();

if ($confirmed) {
    echo $OUTPUT->notification(
        get_string('paymentsuccess', 'paygw_paddle'),
        \core\output\notification::NOTIFY_SUCCESS
    );

    if ($component === 'enrol_fee' && $paymentarea === 'fee') {
        $enrolinstance = $DB->get_record('enrol', ['id' => $itemid], 'id, courseid');
        if ($enrolinstance) {
            $courseurl = new moodle_url('/course/view.php', ['id' => $enrolinstance->courseid]);
            echo html_writer::div(
                html_writer::link(
                    $courseurl,
                    get_string('gotocourse', 'paygw_paddle'),
                    ['class' => 'btn btn-primary']
                ),
                'mt-3'
            );
        }
    }
} else if ($attempt < PAYGW_PADDLE_MAX_POLL_ATTEMPTS) {
    echo $OUTPUT->notification(
        get_string('paymentpending', 'paygw_paddle'),
        \core\output\notification::NOTIFY_INFO
    );
    echo html_writer::tag('p', get_string('paymentpendingdetail', 'paygw_paddle'));

    $nexturl = new moodle_url(
        '/payment/gateway/paddle/process.php',
        $urlparams + ['attempt' => $attempt + 1]
    );

    $PAGE->requires->js_call_amd(
        'paygw_paddle/poll_status',
        'init',
        [
            $nexturl->out(false),
            PAYGW_PADDLE_POLL_DELAY_MS,
        ]
    );
} else {
    echo $OUTPUT->notification(
        get_string('paymenttakinglonger', 'paygw_paddle'),
        \core\output\notification::NOTIFY_WARNING
    );

    $retryurl = new moodle_url('/payment/gateway/paddle/process.php', $urlparams);
    echo html_writer::div(
        html_writer::link(
            $retryurl,
            get_string('refreshstatus', 'paygw_paddle'),
            ['class' => 'btn btn-secondary']
        ),
        'mt-3'
    );
}

echo $OUTPUT->footer();
