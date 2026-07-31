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
 * Admin page: Course to Paddle Price ID mapping.
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
$PAGE->set_url(new moodle_url('/payment/gateway/paddle/admin/pricemap.php'));
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pricemap', 'paygw_paddle'));
$PAGE->set_heading(get_string('pricemap', 'paygw_paddle'));

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

// Handle form submissions.
if ($action === 'save' && confirm_sesskey()) {
    $courseid = required_param('courseid', PARAM_INT);
    $priceid = required_param('paddle_price_id', PARAM_TEXT);
    $amount = required_param('amount', PARAM_FLOAT);
    $currency = required_param('currency', PARAM_ALPHA);
    $description = optional_param('description', '', PARAM_TEXT);

    $record = new stdClass();
    $record->courseid = $courseid;
    $record->paddle_price_id = $priceid;
    $record->amount = $amount;
    $record->currency = strtoupper($currency);
    $record->description = $description;
    $record->active = 1;
    $record->timemodified = time();

    if ($id > 0) {
        $record->id = $id;
        $DB->update_record('paygw_paddle_prices', $record);
    } else {
        $record->timecreated = time();
        $DB->insert_record('paygw_paddle_prices', $record);
    }

    redirect(new moodle_url('/payment/gateway/paddle/admin/pricemap.php'),
        get_string('pricemapsaved', 'paygw_paddle'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'delete' && confirm_sesskey() && $id > 0) {
    $DB->delete_records('paygw_paddle_prices', ['id' => $id]);
    redirect(new moodle_url('/payment/gateway/paddle/admin/pricemap.php'),
        get_string('pricemapdeleted', 'paygw_paddle'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'toggle' && confirm_sesskey() && $id > 0) {
    $rec = $DB->get_record('paygw_paddle_prices', ['id' => $id]);
    if ($rec) {
        $rec->active = $rec->active ? 0 : 1;
        $rec->timemodified = time();
        $DB->update_record('paygw_paddle_prices', $rec);
    }
    redirect(new moodle_url('/payment/gateway/paddle/admin/pricemap.php'));
}

// Display page.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pricemap', 'paygw_paddle'));

echo html_writer::start_div('alert alert-light', ['style' => 'border: 1px solid #e0e0e0; background: #fafafa; padding: 20px; margin-bottom: 20px; border-radius: 8px;']);

echo html_writer::tag('h4', get_string('pricemap_whatisit', 'paygw_paddle'), ['style' => 'margin-top:0;']);
echo html_writer::tag('p', get_string('pricemap_whatisit_desc', 'paygw_paddle'));

echo html_writer::tag('h5', get_string('pricemap_howitworks', 'paygw_paddle'), ['style' => 'margin-top: 16px;']);
echo html_writer::start_tag('ol', ['style' => 'padding-left: 20px; margin-bottom: 12px;']);
echo html_writer::tag('li', get_string('pricemap_step1', 'paygw_paddle'));
echo html_writer::tag('li', get_string('pricemap_step2', 'paygw_paddle'));
echo html_writer::tag('li', get_string('pricemap_step3', 'paygw_paddle'));
echo html_writer::tag('li', get_string('pricemap_step4', 'paygw_paddle'));
echo html_writer::end_tag('ol');

echo html_writer::tag('h5', get_string('pricemap_fields', 'paygw_paddle'), ['style' => 'margin-top: 16px;']);
echo html_writer::start_tag('ul', ['style' => 'padding-left: 20px; margin-bottom: 0;']);
echo html_writer::tag('li', get_string('pricemap_field_course', 'paygw_paddle'));
echo html_writer::tag('li', get_string('pricemap_field_priceid', 'paygw_paddle'));
echo html_writer::tag('li', get_string('pricemap_field_amount', 'paygw_paddle'));
echo html_writer::tag('li', get_string('pricemap_field_currency', 'paygw_paddle'));
echo html_writer::tag('li', get_string('pricemap_field_description', 'paygw_paddle'));
echo html_writer::end_tag('ul');

echo html_writer::end_div();

// Get existing mappings.
$mappings = $DB->get_records('paygw_paddle_prices', null, 'courseid ASC');

if (empty($mappings)) {
    echo html_writer::tag('p', get_string('nopricemappings', 'paygw_paddle'), ['class' => 'alert alert-info']);
}

// Display table.
if (!empty($mappings)) {
    $table = new html_table();
    $table->head = [
        get_string('coursename', 'paygw_paddle'),
        get_string('paddlepriceid', 'paygw_paddle'),
        get_string('pricemapamount', 'paygw_paddle'),
        get_string('pricemapcurrency', 'paygw_paddle'),
        get_string('pricemapdescription', 'paygw_paddle'),
        get_string('pricemapactive', 'paygw_paddle'),
        '',
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($mappings as $map) {
        $course = $DB->get_record('course', ['id' => $map->courseid], 'id, fullname');
        $coursename = $course ? $course->fullname : "Course #{$map->courseid} (deleted)";
        $status = $map->active ? 'Yes' : 'No';

        $toggleurl = new moodle_url('/payment/gateway/paddle/admin/pricemap.php', [
            'action' => 'toggle', 'id' => $map->id, 'sesskey' => sesskey()
        ]);
        $deleteurl = new moodle_url('/payment/gateway/paddle/admin/pricemap.php', [
            'action' => 'delete', 'id' => $map->id, 'sesskey' => sesskey()
        ]);

        $actions = html_writer::link($toggleurl, $map->active ? 'Disable' : 'Enable', ['class' => 'btn btn-sm btn-secondary mr-1']);
        $actions .= html_writer::link($deleteurl, 'Delete', [
            'class' => 'btn btn-sm btn-danger',
            'onclick' => "return confirm('" . get_string('confirmdeletepricemap', 'paygw_paddle') . "');"
        ]);

        $table->data[] = [
            s($coursename),
            s($map->paddle_price_id),
            number_format($map->amount, 2),
            s($map->currency),
            s($map->description ?? ''),
            $status,
            $actions,
        ];
    }

    echo html_writer::table($table);
}

// Add new mapping form.
echo html_writer::tag('h3', get_string('addpricemapping', 'paygw_paddle'), ['class' => 'mt-4']);

$courses = $DB->get_records('course', ['visible' => 1], 'fullname ASC', 'id, fullname');
$courseoptions = [];
foreach ($courses as $c) {
    if ($c->id == SITEID) continue;
    $courseoptions[$c->id] = $c->fullname;
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => new moodle_url('/payment/gateway/paddle/admin/pricemap.php'),
    'class' => 'form-inline mt-2',
]);
echo html_writer::input_hidden_params(new moodle_url('', ['action' => 'save', 'sesskey' => sesskey()]));

echo html_writer::tag('div',
    html_writer::label(get_string('coursename', 'paygw_paddle'), 'courseid', true, ['class' => 'mr-2']) .
    html_writer::select($courseoptions, 'courseid', '', ['' => 'Select course...'], ['class' => 'form-control mr-3', 'id' => 'courseid', 'required' => 'required']),
    ['class' => 'form-group mb-2']
);

echo html_writer::tag('div',
    html_writer::label(get_string('paddlepriceid', 'paygw_paddle'), 'paddle_price_id', true, ['class' => 'mr-2']) .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'paddle_price_id', 'id' => 'paddle_price_id', 'placeholder' => 'pri_...', 'class' => 'form-control mr-3', 'required' => 'required']),
    ['class' => 'form-group mb-2']
);

echo html_writer::tag('div',
    html_writer::label(get_string('pricemapamount', 'paygw_paddle'), 'amount', true, ['class' => 'mr-2']) .
    html_writer::empty_tag('input', ['type' => 'number', 'name' => 'amount', 'id' => 'amount', 'step' => '0.01', 'min' => '0', 'placeholder' => '99.00', 'class' => 'form-control mr-3', 'required' => 'required', 'style' => 'width:120px']),
    ['class' => 'form-group mb-2']
);

echo html_writer::tag('div',
    html_writer::label(get_string('pricemapcurrency', 'paygw_paddle'), 'currency', true, ['class' => 'mr-2']) .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'currency', 'id' => 'currency', 'placeholder' => 'USD', 'maxlength' => '3', 'class' => 'form-control mr-3', 'required' => 'required', 'style' => 'width:80px']),
    ['class' => 'form-group mb-2']
);

echo html_writer::tag('div',
    html_writer::label(get_string('pricemapdescription', 'paygw_paddle'), 'description', true, ['class' => 'mr-2']) .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'description', 'id' => 'description', 'placeholder' => 'Course enrolment', 'class' => 'form-control mr-3']),
    ['class' => 'form-group mb-2']
);

echo html_writer::tag('div',
    html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('addpricemapping', 'paygw_paddle'), 'class' => 'btn btn-primary']),
    ['class' => 'form-group mb-2']
);

echo html_writer::end_tag('form');

echo $OUTPUT->footer();
