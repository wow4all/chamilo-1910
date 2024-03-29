<?php
/* For licensing terms, see /license.txt */

/**
 * This script contains the server part of the AJAX interaction process.
 * The client part is located * in lp_api.php or other api's.
 * @package chamilo.learnpath
 * @author Yannick Warnier <ywarnier@beeznest.org>
 */

use ChamiloSession as Session;

// Flag to allow for anonymous user - needs to be set before global.inc.php.
$use_anonymous = true;

// Name of the language file that needs to be included.
$language_file[] = 'learnpath';

require_once 'back_compat.inc.php';
require_once 'learnpath.class.php';
require_once 'scorm.class.php';
require_once 'aicc.class.php';
require_once 'learnpathItem.class.php';
require_once 'scormItem.class.php';
require_once 'aiccItem.class.php';

/**
 * Writes an item's new values into the database and returns the operation result
 * @param   int $lp_id Learnpath ID
 * @param   int $user_id User ID
 * @param   int $view_id View ID
 * @param   int $item_id Item ID
 * @param   float  $score Current score
 * @param   float  $max Maximum score
 * @param   float  $min Minimum score
 * @param   string  $status Lesson status
 * @param   int  $time Session time
 * @param   string  $suspend Suspend data
 * @param   string  $location Lesson location
 * @param   array   $interactions Interactions array
 * @param   string  $core_exit Core exit SCORM string
 * @param   int     $sessionId Session ID
 * @param   int     $courseId Course ID
 * @param   int     $lmsFinish Whether the call was issued from SCORM's LMSFinish()
 * @param   int     $userNavigatesAway Whether the user is moving to another item
 * @param   int     $statusSignalReceived Whether the SCO called SetValue(lesson_status)
 * @return bool|null|string The resulting JS string
 */
function save_item(
    $lp_id,
    $user_id,
    $view_id,
    $item_id,
    $score = -1.0,
    $max = -1.0,
    $min = -1.0,
    $status = '',
    $time = 0,
    $suspend = '',
    $location = '',
    $interactions = array(),
    $core_exit = 'none',
    $sessionId = null,
    $courseId = null,
    $lmsFinish = 0,
    $userNavigatesAway = 0,
    $statusSignalReceived = 0
) {
    $debug = 0;
    $return = null;

    if ($debug) {
        error_log('lp_ajax_save_item.php : save_item() params: ');
        error_log("item_id: $item_id");
        error_log("lp_id: $lp_id - user_id: - $user_id - view_id: $view_id - item_id: $item_id");
        error_log("score: $score - max:$max - min: $min - status:$status");
        error_log("time:$time - suspend: $suspend - location: $location - core_exit: $core_exit");
        error_log("finish: $lmsFinish - navigatesAway: $userNavigatesAway");
    }

    $myLP = learnpath::getLpFromSession(api_get_course_id(), $lp_id, $user_id);

    if (!is_a($myLP, 'learnpath')) {
        if ($debug) {
            error_log("mylp variable is not an learnpath object");
        }

        return null;
    }

    $prerequisitesCheck = $myLP->prerequisites_match($item_id);

    /** @var learnpathItem $myLPI */
    $myLPI = $myLP->items[$item_id];

    if (empty($myLPI)) {
        if ($debug > 0) {
            error_log("item #$item_id not found in the items array: ".print_r($myLP->items, 1));
        }

        return false;
    }

    // This functions sets the $this->db_item_view_id variable needed in get_status() see BT#5069
    $myLPI->set_lp_view($view_id);

    // Launch the prerequisites check and set error if needed
    if ($prerequisitesCheck !== true) {
        // If prerequisites were not matched, don't update any item info
        if ($debug) {
            error_log("prereq_check: ".intval($prerequisitesCheck));
        }

        return $return;
    } else {
        if ($debug > 1) {
            error_log('Prerequisites are OK');
        }

        if (isset($max) && $max != -1) {
            $myLPI->max_score = $max;
            $myLPI->set_max_score($max);
            if ($debug > 1) {
                error_log("Setting max_score: $max");
            }
        }

        if (isset($min) && $min != -1 && $min != 'undefined') {
            $myLPI->min_score = $min;
            if ($debug > 1) {
                error_log("Setting min_score: $min");
            }
        }

        // set_score function used to save the status, but this is not the case anymore
        if (isset($score) && $score != -1) {
            if ($debug > 1) {
                error_log('Calling set_score('.$score.')', 0);
            }

            $myLPI->set_score($score);

            if ($debug > 1) {
                error_log('Done calling set_score '.$myLPI->get_score(), 0);
            }
        } else {
            if ($debug > 1) {
                error_log("Score not updated");
            }
        }

        $statusIsSet = false;
        // Default behaviour.
        if (isset($status) && $status != '' && $status != 'undefined') {
            if ($debug > 1) {
                error_log('Calling set_status('.$status.')', 0);
            }

            $myLPI->set_status($status);
            $statusIsSet = true;
            if ($debug > 1) {
                error_log('Done calling set_status: checking from memory: '.$myLPI->get_status(false), 0);
            }
        } else {
            if ($debug > 1) {
                error_log("Status not updated");
            }
        }

        $my_type = $myLPI->get_type();
        // Set status to completed for hotpotatoes if score > 80%.
        if ($my_type == 'hotpotatoes') {
            if ((empty($status) ||
                $status == 'undefined' ||
                $status == 'not attempted') &&
                $max > 0
            ) {
                if (($score/$max) > 0.8) {
                    $myStatus = 'completed';
                    if ($debug > 1) {
                        error_log('Calling set_status('.$myStatus.') for hotpotatoes', 0);
                    }
                    $myLPI->set_status($myStatus);
                    $statusIsSet = true;
                    if ($debug > 1) {
                        error_log('Done calling set_status for hotpotatoes - now '.$myLPI->get_status(false), 0);
                    }
                }
            } elseif ($status == 'completed' && $max > 0 && ($score/$max) < 0.8) {
                $myStatus = 'failed';
                if ($debug > 1) {
                    error_log('Calling set_status('.$myStatus.') for hotpotatoes', 0);
                }
                $myLPI->set_status($myStatus);
                $statusIsSet = true;
                if ($debug > 1) {
                    error_log('Done calling set_status for hotpotatoes - now '.$myLPI->get_status(false), 0);
                }
            }
        } elseif ($my_type == 'sco') {
            /*
             * This is a specific implementation for SCORM 1.2, matching page 26 of SCORM 1.2's RTE
             * "Normally the SCO determines its own status and passes it to the LMS.
             * 1) If cmi.core.credit is set to "credit" and there is a mastery
             *    score in the manifest (adlcp:masteryscore), the LMS can change
             *    the status to either passed or failed depending on the
             *    student's score compared to the mastery score.
             * 2) If there is no mastery score in the manifest
             *    (adlcp:masteryscore), the LMS cannot override SCO
             *    determined status.
             * 3) If the student is taking the SCO for no-credit, there is no
             *    change to the lesson_status, with one exception.  If the
             *    lesson_mode is "browse", the lesson_status may change to
             *    "browsed" even if the cmi.core.credit is set to no-credit.
             * "
             * Additionally, the LMS behaviour should be:
             * If a SCO sets the cmi.core.lesson_status then there is no problem.
             * However, the SCORM does not force the SCO to set the cmi.core.lesson_status.
             * There is some additional requirements that must be adhered to
             * successfully handle these cases:
             * Upon initial launch
             *   the LMS should set the cmi.core.lesson_status to "not attempted".
             * Upon receiving the LMSFinish() call or the user navigates away,
             *   the LMS should set the cmi.core.lesson_status for the SCO to "completed".
             * After setting the cmi.core.lesson_status to "completed",
             *   the LMS should now check to see if a Mastery Score has been
             *   specified in the cmi.student_data.mastery_score, if supported,
             *   or the manifest that the SCO is a member of.
             *   If a Mastery Score is provided and the SCO did set the
             *   cmi.core.score.raw, the LMS shall compare the cmi.core.score.raw
             *   to the Mastery Score and set the cmi.core.lesson_status to
             *   either "passed" or "failed".  If no Mastery Score is provided,
             *   the LMS will leave the cmi.core.lesson_status as "completed"
             */
            $masteryScore = $myLPI->get_mastery_score();
            if ($masteryScore == -1 || empty($masteryScore)) {
                $masteryScore = false;
            }
            $credit = $myLPI->get_credit();

            /**
             * 1) If cmi.core.credit is set to "credit" and there is a mastery
             *    score in the manifest (adlcp:masteryscore), the LMS can change
             *    the status to either passed or failed depending on the
             *    student's score compared to the mastery score.
             */
            if ($credit == 'credit' &&
                $masteryScore &&
                (isset($score) && $score != -1) &&
                !$statusIsSet && !$statusSignalReceived
            ) {
                if ($score >= $masteryScore) {
                    $myLPI->set_status('passed');
                } else {
                    $myLPI->set_status('failed');
                }
                $statusIsSet = true;
            }

            /**
             *  2) If there is no mastery score in the manifest
             *    (adlcp:masteryscore), the LMS cannot override SCO
             *    determined status.
             */
            if (!$statusIsSet && !$masteryScore && !$statusSignalReceived) {
                if (!empty($status)) {
                    $myLPI->set_status($status);
                    $statusIsSet = true;
                }
                //if no status was set directly, we keep the previous one
            }

            /**
             * 3) If the student is taking the SCO for no-credit, there is no
             *    change to the lesson_status, with one exception.  If the
             *    lesson_mode is "browse", the lesson_status may change to
             *    "browsed" even if the cmi.core.credit is set to no-credit.
             */
            if (!$statusIsSet && $credit == 'no-credit' && !$statusSignalReceived) {
                $mode = $myLPI->get_lesson_mode();
                if ($mode == 'browse' && $status == 'browsed') {
                    $myLPI->set_status($status);
                    $statusIsSet = true;
                }
                //if no status was set directly, we keep the previous one
            }

            /**
             * If a SCO sets the cmi.core.lesson_status then there is no problem.
             * However, the SCORM does not force the SCO to set the
             * cmi.core.lesson_status.  There is some additional requirements
             * that must be adhered to successfully handle these cases:
             */
            if (!$statusIsSet && empty($status) && !$statusSignalReceived) {
                /**
                 * Upon initial launch the LMS should set the
                 * cmi.core.lesson_status to "not attempted".
                 */
                // this case should be handled by LMSInitialize() and xajax_switch_item()
                /**
                 * Upon receiving the LMSFinish() call or the user navigates
                 * away, the LMS should set the cmi.core.lesson_status for the
                 * SCO to "completed".
                 */
                if ($lmsFinish || $userNavigatesAway) {
                    $myStatus = 'completed';
                    /**
                     * After setting the cmi.core.lesson_status to "completed",
                     * the LMS should now check to see if a Mastery Score has been
                     * specified in the cmi.student_data.mastery_score, if supported,
                     * or the manifest that the SCO is a member of.
                     * If a Mastery Score is provided and the SCO did set the
                     * cmi.core.score.raw, the LMS shall compare the cmi.core.score.raw
                     * to the Mastery Score and set the cmi.core.lesson_status to
                     * either "passed" or "failed".  If no Mastery Score is provided,
                     * the LMS will leave the cmi.core.lesson_status as "completed"
                     */
                    if ($masteryScore && (isset($score) && $score != -1)) {
                        if ($score >= $masteryScore) {
                            $myStatus = 'passed';
                        } else {
                            $myStatus = 'failed';
                        }
                    }
                    $myLPI->set_status($myStatus);
                    $statusIsSet = true;
                }
            }
            // End of type=='sco'
        }

        // If no previous condition changed the SCO status, proceed with a
        // generic behaviour
        if (!$statusIsSet && !$statusSignalReceived) {

            // Default behaviour
            if (isset($status) && $status != '' && $status != 'undefined') {
                if ($debug > 1) {
                    error_log('Calling set_status('.$status.')', 0);
                }

                $myLPI->set_status($status);

                if ($debug > 1) {
                    error_log('Done calling set_status: checking from memory: '.$myLPI->get_status(false), 0);
                }
            } else {
                if ($debug > 1) {
                    error_log("Status not updated");
                }
            }
        }

        if (isset($time) && $time != '' && $time != 'undefined') {
            // If big integer, then it's a timestamp, otherwise it's normal scorm time.
            if ($debug > 1) {
                error_log('Calling set_time('.$time.') ', 0);
            }
            if ($time == intval(strval($time)) && $time > 1000000) {
                if ($debug > 1) {
                    error_log("Time is INT");
                }
                $real_time = time() - $time;
                if ($debug > 1) {
                    error_log('Calling $real_time '.$real_time.' ', 0);
                }
                $myLPI->set_time($real_time, 'int');
            } else {
                if ($debug > 1) {
                    error_log("Time is in SCORM format");
                }
                if ($debug > 1) {
                    error_log('Calling $time '.$time.' ', 0);
                }
                $myLPI->set_time($time, 'scorm');
            }
            //if ($debug > 1) { error_log('Done calling set_time - now '.$myLPI->get_total_time(), 0); }
        } else {
            //$time = $myLPI->get_total_time();
        }

        if (isset($suspend) && $suspend != '' && $suspend != 'undefined') {
            $myLPI->current_data = $suspend;
        }

        if (isset($location) && $location != '' && $location!='undefined') {
            $myLPI->set_lesson_location($location);
        }

        // Deal with interactions provided in arrays in the following format:
        // id(0), type(1), time(2), weighting(3), correct_responses(4), student_response(5), result(6), latency(7)
        if (is_array($interactions) && count($interactions) > 0) {
            foreach ($interactions as $index => $interaction) {
                //$mylpi->add_interaction($index,$interactions[$index]);
                //fix DT#4444
                $clean_interaction = str_replace('@.|@', ',', $interactions[$index]);
                $myLPI->add_interaction($index, $clean_interaction);
            }
        }

        if ($core_exit != 'undefined') {
            $myLPI->set_core_exit($core_exit);
        }
        $myLP->save_item($item_id, false);
    }

    $myStatusInDB = $myLPI->get_status(true);
    if ($debug) {
        error_log("Status in DB: $myStatusInDB");
    }

    if ($myStatusInDB != 'completed' &&
        $myStatusInDB != 'passed' &&
        $myStatusInDB != 'browsed' &&
        $myStatusInDB != 'failed'
    ) {
        $myStatusInMemory = $myLPI->get_status(false);
        if ($myStatusInMemory != $myStatusInDB) {
            $myStatus = $myStatusInMemory;
        } else {
            $myStatus = $myStatusInDB;
        }
    } else {
        $myStatus = $myStatusInDB;
    }

    $myTotal = $myLP->get_total_items_count_without_chapters();
    $myComplete = $myLP->get_complete_items_count();
    $myProgressMode = $myLP->get_progress_bar_mode();
    $myProgressMode = $myProgressMode == '' ? '%' : $myProgressMode;

    if ($debug > 1) {
        error_log("mystatus: $myStatus", 0);
        error_log("myprogress_mode: $myProgressMode", 0);
        error_log("progress: $myComplete / $myTotal", 0);
    }

    if ($myLPI->get_type() != 'sco') {
        // If this object's JS status has not been updated by the SCORM API, update now.
        $return .= "olms.lesson_status='".$myStatus."';";
    }
    $return .= "update_toc('".$myStatus."','".$item_id."');";
    $update_list = $myLP->get_update_queue();

    foreach ($update_list as $my_upd_id => $my_upd_status) {
        if ($my_upd_id != $item_id) {
            /* Only update the status from other items (i.e. parents and brothers),
            do not update current as we just did it already. */
            $return .= "update_toc('".$my_upd_status."','".$my_upd_id."');";
        }
    }
    $return .= "update_progress_bar('$myComplete', '$myTotal', '$myProgressMode');";

    if (!isset($_SESSION['login_as'])) {
        // If $_SESSION['login_as'] is set, then the user is an admin logged as the user.
        $tbl_track_login = Database :: get_statistic_table(TABLE_STATISTIC_TRACK_E_LOGIN);

        $sql = "SELECT login_id, login_date
                FROM $tbl_track_login
                WHERE login_user_id= ".api_get_user_id()."
                ORDER BY login_date DESC
                LIMIT 0,1";

        $q_last_connection = Database::query($sql);
        if (Database::num_rows($q_last_connection) > 0) {
            $current_time = api_get_utc_datetime();
            $row = Database::fetch_array($q_last_connection);
            $i_id_last_connection = $row['login_id'];
            $sql = "UPDATE $tbl_track_login
                    SET logout_date='".$current_time."'
                    WHERE login_id = $i_id_last_connection";
            Database::query($sql);
        }
    }

    if ($myLP->get_type() == 2) {
         $return .= "update_stats();";
    }

    // To be sure progress is updated.
    $myLP->save_last();

    Session::write('lpobject', serialize($myLP));
    if ($debug > 0) {
        error_log('---------------- lp_ajax_save_item.php : save_item end ----- ');
    }

    return $return;
}

$interactions = array();
if (isset($_REQUEST['interact'])) {
    if (is_array($_REQUEST['interact'])) {
        foreach ($_REQUEST['interact'] as $idx => $interac) {
            $interactions[$idx] = preg_split('/,/', substr($interac, 1, -1));
            if (!isset($interactions[$idx][7])) { // Make sure there are 7 elements.
                $interactions[$idx][7] = '';
            }
        }
    }
}

echo save_item(
    (!empty($_REQUEST['lid']) ? $_REQUEST['lid'] : null),
    (!empty($_REQUEST['uid']) ? $_REQUEST['uid'] : null),
    (!empty($_REQUEST['vid']) ? $_REQUEST['vid'] : null),
    (!empty($_REQUEST['iid']) ? $_REQUEST['iid'] : null),
    (!empty($_REQUEST['s']) ? $_REQUEST['s'] : null),
    (!empty($_REQUEST['max']) ? $_REQUEST['max'] : null),
    (!empty($_REQUEST['min']) ? $_REQUEST['min'] : null),
    (!empty($_REQUEST['status']) ? $_REQUEST['status'] : null),
    (!empty($_REQUEST['t']) ? $_REQUEST['t'] : null),
    (!empty($_REQUEST['suspend']) ? $_REQUEST['suspend'] : null),
    (!empty($_REQUEST['loc']) ? $_REQUEST['loc'] : null),
    $interactions,
    (!empty($_REQUEST['core_exit']) ? $_REQUEST['core_exit'] : ''),
    (!empty($_REQUEST['session_id']) ? $_REQUEST['session_id'] : ''),
    (!empty($_REQUEST['course_id']) ? $_REQUEST['course_id'] : ''),
    (empty($_REQUEST['finish']) ? 0 : 1),
    (empty($_REQUEST['userNavigatesAway']) ? 0 : 1),
    (empty($_REQUEST['statusSignalReceived']) ? 0 : 1)
);
