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
 * Paddle transaction and webhook event reports.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$page = optional_param('page', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$statusfilter = optional_param('status', '', PARAM_ALPHA);
$tab = optional_param('tab', 'transactions', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);

admin_externalpage_setup('paygw_paddle_reports');

$perpage = 50;
$baseurl = new moodle_url('/payment/gateway/paddle/admin/reports.php');

/**
 * Build the WHERE clause and parameters for the transaction report.
 *
 * @param string $search Free text to match against transaction id or payer.
 * @param string $statusfilter A transaction status to restrict to, or ''.
 * @return array [string $where, array $params]
 */
function paygw_paddle_transaction_filter(string $search, string $statusfilter): array {
    global $DB;

    $where = '1 = 1';
    $params = [];

    if ($search !== '') {
        $like = [];
        $fields = ['t.paddle_transaction_id', 'u.email', 'u.firstname', 'u.lastname'];
        foreach ($fields as $index => $field) {
            $name = 'search' . $index;
            $like[] = $DB->sql_like($field, ':' . $name, false);
            $params[$name] = '%' . $DB->sql_like_escape($search) . '%';
        }
        $where .= ' AND (' . implode(' OR ', $like) . ')';
    }

    if ($statusfilter !== '') {
        $where .= ' AND t.status = :status';
        $params['status'] = $statusfilter;
    }

    return [$where, $params];
}

// The CSV export must run before any page output, because it sends its own
// HTTP headers.
if ($download !== '' && $tab === 'transactions') {
    [$where, $params] = paygw_paddle_transaction_filter($search, $statusfilter);

    $sql = "SELECT t.id, t.paddle_transaction_id, t.paddle_customer_id, t.amount, t.currency,
                   t.tax, t.status, t.component, t.itemid, t.timecreated,
                   u.firstname, u.lastname, u.email
              FROM {paygw_paddle_transactions} t
         LEFT JOIN {user} u ON u.id = t.userid
             WHERE {$where}
          ORDER BY t.timecreated DESC";

    $columns = [
        'transactionid' => get_string('transactionid', 'paygw_paddle'),
        'customerid' => get_string('paddlecustomerid', 'paygw_paddle'),
        'user' => get_string('transactionuser', 'paygw_paddle'),
        'email' => get_string('transactionemail', 'paygw_paddle'),
        'amount' => get_string('transactionamount', 'paygw_paddle'),
        'currency' => get_string('pricemapcurrency', 'paygw_paddle'),
        'tax' => get_string('transactiontax', 'paygw_paddle'),
        'status' => get_string('transactionstatus', 'paygw_paddle'),
        'component' => get_string('transactioncomponent', 'paygw_paddle'),
        'itemid' => get_string('transactionitemid', 'paygw_paddle'),
        'date' => get_string('transactiondate', 'paygw_paddle'),
    ];

    // A recordset streams the rows rather than loading every transaction the
    // site has ever taken into memory.
    $recordset = $DB->get_recordset_sql($sql, $params);

    \core\dataformat::download_data(
        'paddle_transactions_' . userdate(time(), '%Y-%m-%d'),
        $download,
        $columns,
        $recordset,
        function ($record) {
            return [
                'transactionid' => $record->paddle_transaction_id,
                'customerid' => $record->paddle_customer_id,
                'user' => trim($record->firstname . ' ' . $record->lastname),
                'email' => $record->email,
                'amount' => format_float((float) $record->amount, 2, false),
                'currency' => $record->currency,
                'tax' => format_float((float) $record->tax, 2, false),
                'status' => $record->status,
                'component' => $record->component,
                'itemid' => $record->itemid,
                'date' => userdate($record->timecreated),
            ];
        }
    );
    $recordset->close();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reports', 'paygw_paddle'));

$tabs = [
    new tabobject(
        'transactions',
        new moodle_url($baseurl, ['tab' => 'transactions']),
        get_string('tabtransactions', 'paygw_paddle')
    ),
    new tabobject(
        'events',
        new moodle_url($baseurl, ['tab' => 'events']),
        get_string('tabevents', 'paygw_paddle')
    ),
];
echo $OUTPUT->tabtree($tabs, $tab);

/**
 * Map a transaction status to a Bootstrap badge class.
 *
 * Both the Bootstrap 4 and Bootstrap 5 class names are emitted so the report
 * renders correctly on every supported Moodle version.
 *
 * @param string $status The stored status.
 * @return string A class attribute value.
 */
function paygw_paddle_status_badge_class(string $status): string {
    $variants = [
        'completed' => 'success',
        'pending' => 'warning',
        'failed' => 'danger',
        'refunded' => 'info',
        'chargeback' => 'dark',
        'success' => 'success',
        'error' => 'danger',
        'skipped' => 'warning',
    ];
    $variant = $variants[$status] ?? 'secondary';
    return "badge badge-{$variant} text-bg-{$variant}";
}

/**
 * Return the translated label for a status, falling back to the raw value.
 *
 * @param string $prefix Either 'status' or 'result'.
 * @param string $value The stored value.
 * @return string
 */
function paygw_paddle_status_label(string $prefix, string $value): string {
    $key = $prefix . '_' . $value;
    if (get_string_manager()->string_exists($key, 'paygw_paddle')) {
        return get_string($key, 'paygw_paddle');
    }
    return $value;
}

if ($tab === 'transactions') {
    [$where, $params] = paygw_paddle_transaction_filter($search, $statusfilter);

    $sql = "SELECT t.*, u.firstname, u.lastname, u.email
              FROM {paygw_paddle_transactions} t
         LEFT JOIN {user} u ON u.id = t.userid
             WHERE {$where}
          ORDER BY t.timecreated DESC";

    $countsql = "SELECT COUNT(1)
                   FROM {paygw_paddle_transactions} t
              LEFT JOIN {user} u ON u.id = t.userid
                  WHERE {$where}";

    $total = $DB->count_records_sql($countsql, $params);
    $records = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

    $statusoptions = [
        '' => get_string('allstatuses', 'paygw_paddle'),
        'pending' => get_string('status_pending', 'paygw_paddle'),
        'completed' => get_string('status_completed', 'paygw_paddle'),
        'failed' => get_string('status_failed', 'paygw_paddle'),
        'refunded' => get_string('status_refunded', 'paygw_paddle'),
        'chargeback' => get_string('status_chargeback', 'paygw_paddle'),
    ];

    echo html_writer::start_tag(
        'form',
        [
            'method' => 'get',
            'action' => $baseurl->out(false),
            'class' => 'd-flex flex-wrap align-items-center mb-3',
        ]
    );
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'transactions']);

    echo html_writer::label(
        get_string('searchtransactions', 'paygw_paddle'),
        'paddle-search',
        true,
        ['class' => 'sr-only visually-hidden']
    );
    echo html_writer::empty_tag(
        'input',
        [
            'type' => 'text',
            'id' => 'paddle-search',
            'name' => 'search',
            'value' => $search,
            'placeholder' => get_string('searchtransactions', 'paygw_paddle'),
            'class' => 'form-control mr-2 me-2 mb-2',
        ]
    );

    echo html_writer::label(
        get_string('filterbystatus', 'paygw_paddle'),
        'paddle-status',
        true,
        ['class' => 'sr-only visually-hidden']
    );
    echo html_writer::select(
        $statusoptions,
        'status',
        $statusfilter,
        false,
        [
            'id' => 'paddle-status',
            'class' => 'form-control custom-select form-select mr-2 me-2 mb-2',
        ]
    );

    echo html_writer::empty_tag(
        'input',
        [
            'type' => 'submit',
            'value' => get_string('filter', 'paygw_paddle'),
            'class' => 'btn btn-secondary mr-2 me-2 mb-2',
        ]
    );

    $csvurl = new moodle_url(
        $baseurl,
        [
            'tab' => 'transactions',
            'search' => $search,
            'status' => $statusfilter,
            'download' => 'csv',
        ]
    );
    echo html_writer::link(
        $csvurl,
        get_string('exportcsv', 'paygw_paddle'),
        ['class' => 'btn btn-outline-primary mb-2']
    );
    echo html_writer::end_tag('form');

    if (empty($records)) {
        echo $OUTPUT->notification(
            get_string('notransactions', 'paygw_paddle'),
            \core\output\notification::NOTIFY_INFO
        );
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

        foreach ($records as $record) {
            $payer = trim($record->firstname . ' ' . $record->lastname);
            $table->data[] = [
                html_writer::tag('code', s($record->paddle_transaction_id)),
                s($payer) . html_writer::empty_tag('br')
                    . html_writer::tag('small', s($record->email)),
                format_float((float) $record->amount, 2) . ' ' . s($record->currency),
                format_float((float) $record->tax, 2),
                html_writer::tag(
                    'span',
                    s(paygw_paddle_status_label('status', $record->status)),
                    ['class' => paygw_paddle_status_badge_class($record->status)]
                ),
                userdate($record->timecreated),
            ];
        }

        echo html_writer::table($table);
        $pagingparams = [
            'tab' => 'transactions',
            'search' => $search,
            'status' => $statusfilter,
        ];
        echo $OUTPUT->paging_bar(
            $total,
            $page,
            $perpage,
            new moodle_url($baseurl, $pagingparams)
        );
    }
} else {
    $total = $DB->count_records('paygw_paddle_events');
    $events = $DB->get_records(
        'paygw_paddle_events',
        null,
        'timecreated DESC',
        '*',
        $page * $perpage,
        $perpage
    );

    if (empty($events)) {
        echo $OUTPUT->notification(
            get_string('noevents', 'paygw_paddle'),
            \core\output\notification::NOTIFY_INFO
        );
    } else {
        $table = new html_table();
        $table->head = [
            get_string('eventid', 'paygw_paddle'),
            get_string('transactionid', 'paygw_paddle'),
            get_string('eventtype', 'paygw_paddle'),
            get_string('eventresult', 'paygw_paddle'),
            get_string('transactiondate', 'paygw_paddle'),
        ];
        $table->attributes['class'] = 'generaltable';

        foreach ($events as $event) {
            $table->data[] = [
                html_writer::tag('code', s($event->paddle_event_id)),
                html_writer::tag('code', s($event->paddle_transaction_id ?: '-')),
                s($event->event_type),
                html_writer::tag(
                    'span',
                    s(paygw_paddle_status_label('result', $event->result)),
                    ['class' => paygw_paddle_status_badge_class($event->result)]
                ),
                userdate($event->timecreated),
            ];
        }

        echo html_writer::table($table);
        echo $OUTPUT->paging_bar(
            $total,
            $page,
            $perpage,
            new moodle_url($baseurl, ['tab' => 'events'])
        );
    }
}

echo $OUTPUT->footer();
