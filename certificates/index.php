<?php
/* For licensing terms, see /license.txt */
/**
 * Show specified user certificate
 * @package chamilo.certificate
 */

/**
 * Initialization
 */

$language_file= array('admin', 'gradebook', 'document');

require_once '../main/inc/global.inc.php';
require_once api_get_path(LIBRARY_PATH).'certificate.lib.php';

$action = isset($_GET['action']) ? $_GET['action'] : null;

$certificate = new Certificate($_GET['id']);

switch ($action) {
    case 'export':
        if (
            api_get_configuration_value('hide_certificate_export_link') ||
            (api_is_student() && api_get_configuration_value('hide_certificate_export_link_students'))
        ) {
            api_not_allowed(true);
        }

        $certificate->generate(array('hide_print_button' => true));

        if ($certificate->html_file_is_generated()) {
            $certificatePathList[] = $certificate->html_file;

            $pdfParams = array(
                'orientation' => 'landscape',
                'top' => 0,
                'right' => 0,
                'bottom' => 0,
                'left' => 0
            );

            $pdfParams['orientation'] = 'landscape';
            $pageFormat = $pdfParams['orientation'] == 'landscape' ? 'A4-L' : 'A4';

            $userInfo = api_get_user_info($certificate->user_id);

            $pdfName = replace_dangerous_char(get_lang('Certificate') . ' ' . $userInfo['username']);

            $pdf = new PDF($pageFormat, $pdfParams['orientation'], $pdfParams);
            $pdf->html_to_pdf($certificatePathList, $pdfName, null, false, false);
        }
        break;
    default:
        //Show certificate HTML
        $certificate->show();
}
