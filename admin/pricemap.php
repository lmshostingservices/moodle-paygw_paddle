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
 * Map Moodle courses to Paddle catalog price IDs.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use paygw_paddle\form\pricemap_form;

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

admin_externalpage_setup('paygw_paddle_pricemap');

$baseurl = new moodle_url('/payment/gateway/paddle/admin/pricemap.php');

if ($action === 'delete' && $id > 0) {
    require_sesskey();
    $DB->delete_records('paygw_paddle_prices', ['id' => $id]);
    redirect(
        $baseurl,
        get_string('pricemapdeleted', 'paygw_paddle'),
        null,
        notification::NOTIFY_SUCCESS
    );
}

if ($action === 'toggle' && $id > 0) {
    require_sesskey();
    $record = $DB->get_record('paygw_paddle_prices', ['id' => $id], '*', MUST_EXIST);
    $record->active = $record->active ? 0 : 1;
    $record->timemodified = time();
    $DB->update_record('paygw_paddle_prices', $record);
    redirect($baseurl);
}

$editing = null;
if ($action === 'edit' && $id > 0) {
    $editing = $DB->get_record('paygw_paddle_prices', ['id' => $id], '*', MUST_EXIST);
}

$form = new pricemap_form($baseurl->out(false));
if ($editing) {
    $form->set_data($editing);
}

if ($form->is_cancelled()) {
    redirect($baseurl);
} else if ($data = $form->get_data()) {
    $record = new stdClass();
    $record->courseid = $data->courseid;
    $record->paddle_price_id = $data->paddle_price_id;
    $record->amount = $data->amount;
    $record->currency = strtoupper($data->currency);
    $record->description = $data->description;
    $record->timemodified = time();

    if (!empty($data->id)) {
        $record->id = $data->id;
        $DB->update_record('paygw_paddle_prices', $record);
    } else {
        $record->active = 1;
        $record->timecreated = time();
        $DB->insert_record('paygw_paddle_prices', $record);
    }

    redirect(
        $baseurl,
        get_string('pricemapsaved', 'paygw_paddle'),
        null,
        notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pricemap', 'paygw_paddle'));

// Explain what this page is for, since most sites do not need it.
$guidance = html_writer::tag('h4', get_string('pricemap_whatisit', 'paygw_paddle'));
$guidance .= html_writer::tag('p', get_string('pricemap_whatisit_desc', 'paygw_paddle'));
$guidance .= html_writer::tag('h5', get_string('pricemap_howitworks', 'paygw_paddle'));
$steps = '';
foreach (['pricemap_step1', 'pricemap_step2', 'pricemap_step3', 'pricemap_step4'] as $step) {
    $steps .= html_writer::tag('li', get_string($step, 'paygw_paddle'));
}
$guidance .= html_writer::tag('ol', $steps);
$guidance .= html_writer::tag('h5', get_string('pricemap_fields', 'paygw_paddle'));
$fields = '';
foreach (['course', 'priceid', 'amount', 'currency', 'description'] as $field) {
    $fields .= html_writer::tag('li', get_string('pricemap_field_' . $field, 'paygw_paddle'));
}
$guidance .= html_writer::tag('ul', $fields);

echo $OUTPUT->box($guidance, 'generalbox');

$mappings = $DB->get_records('paygw_paddle_prices', null, 'courseid ASC');

if (empty($mappings)) {
    echo $OUTPUT->notification(
        get_string('nopricemappings', 'paygw_paddle'),
        notification::NOTIFY_INFO
    );
} else {
    $table = new html_table();
    $table->head = [
        get_string('coursename', 'paygw_paddle'),
        get_string('paddlepriceid', 'paygw_paddle'),
        get_string('pricemapamount', 'paygw_paddle'),
        get_string('pricemapcurrency', 'paygw_paddle'),
        get_string('pricemapdescription', 'paygw_paddle'),
        get_string('pricemapactive', 'paygw_paddle'),
        get_string('pricemapactions', 'paygw_paddle'),
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($mappings as $mapping) {
        $course = $DB->get_record('course', ['id' => $mapping->courseid], 'id, fullname');
        $coursename = $course
            ? format_string($course->fullname, true, ['context' => context_system::instance()])
            : get_string('coursedeleted', 'paygw_paddle', $mapping->courseid);

        $toggleurl = new moodle_url(
            $baseurl,
            [
                'action' => 'toggle',
                'id' => $mapping->id,
                'sesskey' => sesskey(),
            ]
        );
        $editurl = new moodle_url($baseurl, ['action' => 'edit', 'id' => $mapping->id]);
        $deleteurl = new moodle_url(
            $baseurl,
            [
                'action' => 'delete',
                'id' => $mapping->id,
                'sesskey' => sesskey(),
            ]
        );

        $togglelabel = $mapping->active
            ? get_string('pricemapdisable', 'paygw_paddle')
            : get_string('pricemapenable', 'paygw_paddle');

        $actions = html_writer::link(
            $toggleurl,
            $togglelabel,
            ['class' => 'btn btn-sm btn-secondary mr-1 me-1']
        );
        $actions .= html_writer::link(
            $editurl,
            get_string('edit'),
            ['class' => 'btn btn-sm btn-secondary mr-1 me-1']
        );

        // A confirm_action gives the same protection as an inline onclick
        // handler without breaking on translations that contain an apostrophe.
        $deletelink = new action_link(
            $deleteurl,
            get_string('delete'),
            new confirm_action(get_string('confirmdeletepricemap', 'paygw_paddle')),
            ['class' => 'btn btn-sm btn-danger']
        );
        $actions .= $OUTPUT->render($deletelink);

        $table->data[] = [
            $coursename,
            html_writer::tag('code', s($mapping->paddle_price_id)),
            format_float((float) $mapping->amount, 2),
            s($mapping->currency),
            s($mapping->description ?? ''),
            $mapping->active
                ? get_string('yes')
                : get_string('no'),
            $actions,
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->heading(get_string('addpricemapping', 'paygw_paddle'), 3);
$form->display();

echo $OUTPUT->footer();
