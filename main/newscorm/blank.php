<?php
/* For licensing terms, see /license.txt */
/**
 * Script that displays a blank page (with later a message saying why)
 * @package chamilo.learnpath
 * @author Yannick Warnier <ywarnier@beeznest.org>
 */

$language_file = array('learnpath', 'document','exercice');

// Flag to allow for anonymous user - needs to be set before global.inc.php.
$use_anonymous = true;
require_once '../inc/global.inc.php';

$htmlHeadXtra[] = "
<style>
body { background: none;}
</style>
";

Display::display_reduced_header();

if (isset($_GET['error'])) {
    switch ($_GET['error']){
        case 'document_deleted':
            echo '<br /><br />';
            Display::display_error_message(get_lang('DocumentHasBeenDeleted'));
            break;
        case 'prerequisites':
            echo '<br /><br />';
            Display::display_warning_message(get_lang('LearnpathPrereqNotCompleted'));
            break;
        case 'document_not_found':
            echo '<br /><br />';
            Display::display_warning_message(get_lang('FileNotFound'));
            break;
        case 'reached_one_attempt':
            echo '<br /><br />';
            Display::display_warning_message(get_lang('ReachedOneAttempt'));
            break;
        case 'x_frames_options':
            if (isset($_SESSION['x_frame_source'])) {
                $src = $_SESSION['x_frame_source'];
                $icon = '&nbsp;<i class="icon-external-link icon-2x"></i>';
                echo Display::return_message(
                    Display::url($src.$icon, $src, ['class' => 'btn generated', 'target' => '_blank']),
                    'normal',
                    false
                );
                unset($_SESSION['x_frame_source']);
            }
            break;
        default:
            break;
    }
} elseif (isset($_GET['msg']) && $_GET['msg'] == 'exerciseFinished') {
    echo '<br /><br />';
    Display::display_normal_message(get_lang('ExerciseFinished'));
}
?>
</body>
</html>
