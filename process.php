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
 * Post-checkout processing page.
 *
 * This is the return URL after a successful Paddle checkout.
 * The webhook is the true source of truth for payment confirmation.
 * This page shows a success/pending message to the user.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

require_login();

$component = required_param('component', PARAM_ALPHANUMEXT);
$paymentarea = required_param('paymentarea', PARAM_ALPHANUMEXT);
$itemid = required_param('itemid', PARAM_INT);
$transactionid = optional_param('transaction_id', '', PARAM_TEXT);

$PAGE->set_url(new moodle_url('/payment/gateway/paddle/process.php', [
    'component' => $component,
    'paymentarea' => $paymentarea,
    'itemid' => $itemid,
]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('paymentprocessing', 'paygw_paddle'));
$PAGE->set_heading(get_string('paymentprocessing', 'paygw_paddle'));

echo $OUTPUT->header();

// Check if webhook already processed the payment.
$enrolled = false;
if (!empty($transactionid)) {
    $txnrec = $DB->get_record('paygw_paddle_transactions', [
        'paddle_transaction_id' => $transactionid,
    ]);
    if ($txnrec && $txnrec->status === 'completed') {
        $enrolled = true;
    }
}

if ($enrolled) {
    echo $OUTPUT->notification(get_string('paymentsuccess', 'paygw_paddle'), 'notifysuccess');

    // Try to redirect to the course.
    if ($component === 'enrol_fee' && $paymentarea === 'fee') {
        $enrolinstance = $DB->get_record('enrol', ['id' => $itemid]);
        if ($enrolinstance) {
            $courseurl = new moodle_url('/course/view.php', ['id' => $enrolinstance->courseid]);
            echo html_writer::tag('p',
                html_writer::link($courseurl, get_string('gotocourse', 'paygw_paddle'),
                    ['class' => 'btn btn-primary']));
        }
    }
} else {
    echo $OUTPUT->notification(get_string('paymentpending', 'paygw_paddle'), 'notifyinfo');
    echo html_writer::tag('p', get_string('paymentpendingdetail', 'paygw_paddle'));

    // Auto-refresh after a few seconds.
    $refreshurl = $PAGE->url->out(false) . '&transaction_id=' . urlencode($transactionid);
    echo html_writer::tag('p',
        html_writer::link($refreshurl, get_string('refreshstatus', 'paygw_paddle'),
            ['class' => 'btn btn-secondary']));

    $PAGE->requires->js_init_code("setTimeout(function (){ window.location.reload(); }, 5000);");
}

echo $OUTPUT->footer();
