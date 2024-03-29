<?php
/* For licensing terms, see /license.txt */

// Language files that need to be included
$language_file = array('document', 'slideshow', 'gradebook', 'create_course');
require_once '../inc/global.inc.php';

api_protect_admin_script();

if (!api_get_configuration_value('document_manage_deleted_files')) {
    api_not_allowed(true);
}

$lib_path = api_get_path(LIBRARY_PATH);
require_once $lib_path.'fileManage.lib.php';

$courseInfo = api_get_course_info();
$sessionId = api_get_session_id();
$files = DocumentManager::getDeletedDocuments($courseInfo, $sessionId);

$actions = Display::url(
    get_lang('DownloadAll'),
    api_get_self().'?'.api_get_cidreq().'&action=download_all',
    ['class' => 'btn btn-default']
);

$actions .= Display::url(
    get_lang('DeleteAll'),
    api_get_self().'?'.api_get_cidreq().'&action=delete_all',
    ['class' => 'btn btn-danger']
);

$action = isset($_GET['action']) ? $_GET['action'] : '';
$id = isset($_GET['id']) ? intval($_GET['id']) : '';
$currentUrl = api_get_self().'?'.api_get_cidreq();

switch ($action) {
    case 'delete':
        DocumentManager::purgeDocument($id, $courseInfo, $sessionId);
        Display::addFlash(Display::return_message(get_lang('Deleted')));
        header('Location: '.$currentUrl);
        exit;
        break;
    case 'delete_all':
        DocumentManager::purgeDocuments($courseInfo, $sessionId);
        Display::addFlash(Display::return_message(get_lang('Deleted')));
        header('Location: '.$currentUrl);
        exit;
        break;
    case 'download':
        DocumentManager::downloadDeletedDocument($id, $courseInfo, $sessionId);
        break;
    case 'download_all':
        DocumentManager::downloadAllDeletedDocument($courseInfo, $sessionId);
        break;
}

$interbreadcrumb[] = array(
    "url" => api_get_path(WEB_CODE_PATH).'document/document.php?'.api_get_cidreq(),
    "name" => get_lang('Documents'),
);
$template = new Template(get_lang('DeletedDocuments'));
$template->assign('files', $files);
$template->assign('actions', $actions);
$template->assign('web_cid_query', api_get_cidreq());

$content = $template->fetch('default/document/recycle.tpl');
$template->assign('content', $content);
$template->display_one_col_template();

