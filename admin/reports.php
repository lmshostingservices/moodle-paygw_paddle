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
 * Admin page: Transaction reports and webhook event log.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);
$PAGE->set_url(new moodle_url('/payment/gateway/paddle/admin/reports.php'));
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('reports', 'paygw_paddle'));
$PAGE->set_heading(get_string('reports', 'paygw_paddle'));

$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;
$search = optional_param('search', '', PARAM_TEXT);
$statusfilter = optional_param('status', '', PARAM_ALPHA);
$tab = optional_param('tab', 'transactions', PARAM_ALPHA);
$format = optional_param('format', '', PARAM_ALPHA);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reports', 'paygw_paddle'));

// Tab navigation.
$tabs = [];
$tabs[] = new tabobject('transactions', new moodle_url('/payment/gateway/paddle/admin/reports.php', ['tab' => 'transactions']), 'Transactions');
$tabs[] = new tabobject('events', new moodle_url('/payment/gateway/paddle/admin/reports.php', ['tab' => 'events']), 'Webhook Events');
echo $OUTPUT->tabtree($tabs, $tab);

if ($tab === 'transactions') {
    // Build query.
    $where = '1=1';
    $params = [];

    if (!empty($search)) {
        $where .= ' AND (t.paddle_transaction_id LIKE :search1 OR u.email LIKE :search2 OR u.firstname LIKE :search3 OR u.lastname LIKE :search4)';
        $params['search1'] = "%{$search}%";
        $params['search2'] = "%{$search}%";
        $params['search3'] = "%{$search}%";
        $params['search4'] = "%{$search}%";
    }

    if (!empty($statusfilter)) {
        $where .= ' AND t.status = :status';
        $params['status'] = $statusfilter;
    }

    $sql = "SELECT t.*, u.firstname, u.lastname, u.email
            FROM {paygw_paddle_transactions} t
            LEFT JOIN {user} u ON u.id = t.userid
            WHERE {$where}
            ORDER BY t.timecreated DESC";

    $countsql = "SELECT COUNT(*)
                 FROM {paygw_paddle_transactions} t
                 LEFT JOIN {user} u ON u.id = t.userid
                 WHERE {$where}";

    $total = $DB->count_records_sql($countsql, $params);
    $records = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

    // CSV export.
    if ($format === 'csv') {
        $allrecords = $DB->get_records_sql($sql, $params);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="paddle_transactions_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Transaction ID', 'Customer ID', 'User', 'Email', 'Amount', 'Currency', 'Tax', 'Status', 'Component', 'Item ID', 'Date']);
        foreach ($allrecords as $r) {
            fputcsv($out, [
                $r->paddle_transaction_id,
                $r->paddle_customer_id,
                "{$r->firstname} {$r->lastname}",
                $r->email,
                number_format($r->amount, 2),
                $r->currency,
                number_format($r->tax, 2),
                $r->status,
                $r->component,
                $r->itemid,
                userdate($r->timecreated),
            ]);
        }
        fclose($out);
        exit;
    }

    // Search form.
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => new moodle_url('/payment/gateway/paddle/admin/reports.php'), 'class' => 'form-inline mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'transactions']);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'search', 'value' => $search, 'placeholder' => get_string('searchtransactions', 'paygw_paddle'), 'class' => 'form-control mr-2']);
    echo html_writer::select(
        ['' => get_string('allstatuses', 'paygw_paddle'), 'pending' => 'Pending', 'completed' => 'Completed', 'failed' => 'Failed', 'refunded' => 'Refunded', 'chargeback' => 'Chargeback'],
        'status', $statusfilter, false, ['class' => 'form-control mr-2']
    );
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Filter', 'class' => 'btn btn-secondary mr-2']);
    $csvurl = new moodle_url('/payment/gateway/paddle/admin/reports.php', ['tab' => 'transactions', 'search' => $search, 'status' => $statusfilter, 'format' => 'csv']);
    echo html_writer::link($csvurl, get_string('exportcsv', 'paygw_paddle'), ['class' => 'btn btn-outline-primary']);
    echo html_writer::end_tag('form');

    if (empty($records)) {
        echo html_writer::tag('p', get_string('notransactions', 'paygw_paddle'), ['class' => 'alert alert-info']);
    } else {
        $table = new html_table();
        $table->head = [
            get_string('transactionid', 'paygw_paddle'),
            get_string('transactionuser', 'paygw_paddle'),
            get_string('transactionamount', 'paygw_paddle'),
            get_string('transactiontax', 'paygw_paddle'),
            get_string('transactionstatus', 'paygw_paddle'),
            get_string('transactiondate', 'paygw_paddle'),
        ];
        $table->attributes['class'] = 'generaltable';

        foreach ($records as $r) {
            $statusclass = '';
            switch ($r->status) {
                case 'completed': $statusclass = 'badge badge-success'; break;
                case 'pending': $statusclass = 'badge badge-warning'; break;
                case 'failed': $statusclass = 'badge badge-danger'; break;
                case 'refunded': $statusclass = 'badge badge-info'; break;
                case 'chargeback': $statusclass = 'badge badge-dark'; break;
                default: $statusclass = 'badge badge-secondary';
            }

            $table->data[] = [
                html_writer::tag('code', s($r->paddle_transaction_id)),
                s("{$r->firstname} {$r->lastname}") . html_writer::empty_tag('br') . html_writer::tag('small', s($r->email)),
                number_format($r->amount, 2) . ' ' . s($r->currency),
                number_format($r->tax, 2),
                html_writer::tag('span', s($r->status), ['class' => $statusclass]),
                userdate($r->timecreated),
            ];
        }

        echo html_writer::table($table);
        echo $OUTPUT->paging_bar($total, $page, $perpage, new moodle_url('/payment/gateway/paddle/admin/reports.php', ['tab' => 'transactions', 'search' => $search, 'status' => $statusfilter]));
    }

} else {
    // Events tab.
    $total = $DB->count_records('paygw_paddle_events');
    $events = $DB->get_records('paygw_paddle_events', null, 'timecreated DESC', '*', $page * $perpage, $perpage);

    if (empty($events)) {
        echo html_writer::tag('p', get_string('noevents', 'paygw_paddle'), ['class' => 'alert alert-info']);
    } else {
        $table = new html_table();
        $table->head = ['Event ID', get_string('transactionid', 'paygw_paddle'), get_string('eventtype', 'paygw_paddle'), get_string('eventresult', 'paygw_paddle'), get_string('transactiondate', 'paygw_paddle')];
        $table->attributes['class'] = 'generaltable';

        foreach ($events as $ev) {
            $resultclass = '';
            switch ($ev->result) {
                case 'success': $resultclass = 'badge badge-success'; break;
                case 'error': $resultclass = 'badge badge-danger'; break;
                case 'skipped': $resultclass = 'badge badge-warning'; break;
                default: $resultclass = 'badge badge-secondary';
            }

            $table->data[] = [
                html_writer::tag('code', s($ev->paddle_event_id)),
                html_writer::tag('code', s($ev->paddle_transaction_id ?: '-')),
                s($ev->event_type),
                html_writer::tag('span', s($ev->result), ['class' => $resultclass]),
                userdate($ev->timecreated),
            ];
        }

        echo html_writer::table($table);
        echo $OUTPUT->paging_bar($total, $page, $perpage, new moodle_url('/payment/gateway/paddle/admin/reports.php', ['tab' => 'events']));
    }
}

echo $OUTPUT->footer();
