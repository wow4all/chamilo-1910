<?php
/* For licensing terms, see /license.txt */
/**
 * @package chamilo.calendar
 */

// name of the language file that needs to be included
$language_file = array('agenda', 'group', 'announcements');

require_once '../inc/global.inc.php';
require_once 'agenda.lib.php';
require_once 'agenda.inc.php';

$interbreadcrumb[] = array(
    'url' => api_get_path(WEB_CODE_PATH) . "calendar/agenda_js.php",
    'name' => get_lang('Agenda')
);

$agenda = new Agenda();
$type = isset($_REQUEST['type']) ? $_REQUEST['type'] : null;
$agenda->setType($type);
$events = $agenda->get_events(
    null,
    null,
    api_get_course_int_id(),
    api_get_group_id(),
    null,
    'array'
);

$this_section = SECTION_MYAGENDA;

if (!empty($GLOBALS['_cid']) && $GLOBALS['_cid'] != -1) {
    // Agenda is inside a course tool
    $url = api_get_self() . '?' . api_get_cidreq();
    $this_section = SECTION_COURSES;
} else {
    // Agenda is out of the course tool (e.g personal agenda)
    
    // Little hack to sort the events by start date in personal agenda (Agenda events List view - See #8014)
    usort($events, function($a, $b) {
        $t1 = strtotime($a['start']);
        $t2 = strtotime($b['start']);
        return $t1 - $t2;
    });
    
    $url = false;
    foreach ($events as &$event) {
        $event['url'] = api_get_self() . '?cid=' . $event['course_id'] .
        '&type=' . $event['type'];
    }
}

$tpl = new Template(get_lang('Events'));

$tpl->assign('agenda_events', $events);

$actions = $agenda->displayActions('list');
$tpl->assign('url', $url);
$tpl->assign('actions', $actions);
$tpl->assign('is_allowed_to_edit', api_is_allowed_to_edit());

if (api_is_allowed_to_edit()) {
    if (isset($_GET['action']) && $_GET['action'] == 'change_visibility') {
        $courseInfo = api_get_course_info();
        if (empty($courseInfo)) {
            // This happens when list agenda is not inside a course
            if (
                ($type == 'course' || $type == 'session') &&
                isset($_GET['cid']) &&
                intval($_GET['cid']) !== 0
            ) {
                // For course and session event types
                // Just needs course ID
                $courseInfo = array('real_id' => intval($_GET['cid']));
                $agenda->changeVisibility($_GET['id'], $_GET['visibility'], $courseInfo);
            } else {
                // personal and admin do not have visibility property
            }
        }
        header('Location: '. api_get_self());
        exit;
    }
}

// Loading Agenda template
$content = $tpl->fetch('default/agenda/event_list.tpl');

$tpl->assign('content', $content);

// Loading main Chamilo 1 col template
$tpl->display_one_col_template();
