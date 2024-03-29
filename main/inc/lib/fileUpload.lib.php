<?php
/* For licensing terms, see /license.txt */

/**
 *	FILE UPLOAD LIBRARY
 *
 *	This is the file upload library for Chamilo.
 *	Include/require it in your code to use its functionality.
 *
 *	@package chamilo.library
 *	@todo test and reorganise
 */
require_once api_get_path(LIBRARY_PATH).'document.lib.php';

/**
 * Changes the file name extension from .php to .phps
 * Useful for securing a site.
 *
 * @author - Hugues Peeters <peeters@ipm.ucl.ac.be>
 * @param  - file_name (string) name of a file
 * @return - the filenam phps'ized
 */
function php2phps($file_name) {
    return preg_replace('/\.(php.?|phtml.?)(\.){0,1}.*$/i', '.phps', $file_name);
}

/**
 * Renames .htaccess & .HTACCESS to htaccess.txt
 *
 * @param string $filename
 * @return string
 */
function htaccess2txt($filename) {
    return str_replace(array('.htaccess', '.HTACCESS'), array('htaccess.txt', 'htaccess.txt'), $filename);
}

/**
 * This function executes our safety precautions
 * more functions can be added
 *
 * @param string $filename
 * @return string
 * @see php2phps()
 * @see htaccess2txt()
 */
function disable_dangerous_file($filename) {
    return htaccess2txt(php2phps($filename));
}

/**
 * This function generates a unique name for a file on a given location
 * file names are changed to name_#.ext
 *
 * @param string $path
 * @param string $name
 *
 * @deprecated
 *
 * @return string new unique name
 */
function unique_name($path, $name)
{
    $ext = substr(strrchr($name, '.'), 0);
    $name_no_ext = substr($name, 0, strlen($name) - strlen(strstr($name, $ext)));
    $n = 0;
    $unique = '';
    while (file_exists($path . $name_no_ext . $unique . $ext)) {
        $unique = '_' . ++$n;
    }
    return $name_no_ext . $unique . $ext;
}

/**
 * Returns the name without extension, used for the title
 *
 * @param string $name
 * @return name without the extension
 */
function get_document_title($name) {
    // If they upload .htaccess...
    $name = disable_dangerous_file($name);
    $ext = substr(strrchr($name, '.'), 0);
    return substr($name, 0, strlen($name) - strlen(strstr($name, $ext)));
}

/**
 * This function checks if the upload succeeded
 *
 * @param array $uploaded_file ($_FILES)
 * @return true if upload succeeded
 */
function process_uploaded_file($uploaded_file, $show_output = true)
{
    // Checking the error code sent with the file upload.
    if (isset($uploaded_file['error'])) {
        switch ($uploaded_file['error']) {
            case 1:
                // The uploaded file exceeds the upload_max_filesize directive in php.ini.
                if ($show_output) {
                    Display::display_error_message(get_lang('UplExceedMaxServerUpload').ini_get('upload_max_filesize'));
                }

                return false;
            case 2:
                // The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.
                // Not used at the moment, but could be handy if we want to limit the size of an upload (e.g. image upload in html editor).
                $max_file_size = intval($_POST['MAX_FILE_SIZE']);
                if ($show_output) {
                    Display::display_error_message(get_lang('UplExceedMaxPostSize'). format_file_size($max_file_size));
                }

                return false;
            case 3:
                // The uploaded file was only partially uploaded.
                if ($show_output) {
                    Display::display_error_message(get_lang('UplPartialUpload').' '.get_lang('PleaseTryAgain'));
                }

                return false;
            case 4:
                // No file was uploaded.
                if ($show_output) {
                    Display::display_error_message(get_lang('UplNoFileUploaded').' '. get_lang('UplSelectFileFirst'));
                }

                return false;
        }
    }

    if (!file_exists($uploaded_file['tmp_name'])) {
        // No file was uploaded.
        if ($show_output) {
            Display::display_error_message(get_lang('UplUploadFailed'));
        }
        return false;
    }

    if (file_exists($uploaded_file['tmp_name'])) {
        $filesize = filesize($uploaded_file['tmp_name']);
        if (empty($filesize)) {
            // No file was uploaded.
            if ($show_output) {
                Display::display_error_message(get_lang('UplUploadFailedSizeIsZero'));
            }
            return false;
        }
    }

    $course_id = api_get_course_id();
    //Checking course quota if we are in a course

    if (!empty($course_id)) {
        $max_filled_space = DocumentManager::get_course_quota();
        // Check if there is enough space to save the file
        if (!DocumentManager::enough_space($uploaded_file['size'], $max_filled_space)) {
            if ($show_output) {
                Display::display_error_message(get_lang('UplNotEnoughSpace'));
            }
            return false;
        }
    }

    // case 0: default: We assume there is no error, the file uploaded with success.
    return true;
}

/**
 * This function does the save-work for the documents.
 * It handles the uploaded file and adds the properties to the database
 * If unzip=1 and the file is a zipfile, it is extracted
 * If we decide to save ALL kinds of documents in one database,
 * we could extend this with a $type='document', 'scormdocument',...
 *
 * @param array $courseInfo
 * @param array $uploadedFile ($_FILES)
 * array(
 *  'name' => 'picture.jpg',
 *  'tmp_name' => '...', // absolute path
 * );
 * @param string $documentDir Example: /var/www/chamilo/courses/ABC/document
 * @param string $uploadPath Example: /folder1/folder2/
 * @param int $userId
 * @param int $groupId, 0 for everybody
 * @param int $toUserId, NULL for everybody
 * @param int $unzip 1/0
 * @param string $whatIfFileExists overwrite, rename or warn if exists (default)
 * @param boolean $output Optional output parameter.
 * @param bool $onlyUploadFile
 * @param string $comment
 * @param int $sessionId
 *
 * So far only use for unzip_uploaded_document function.
 * If no output wanted on success, set to false.
 * @param string $comment
 * @return string path of the saved file
 */
function handle_uploaded_document(
    $courseInfo,
    $uploadedFile,
    $documentDir,
    $uploadPath,
    $userId,
    $groupId = 0,
    $toUserId = null,
    $unzip = 0,
    $whatIfFileExists = '',
    $output = true,
    $onlyUploadFile = false,
    $comment = null,
    $sessionId = null
) {
    if (!$userId) {
        return false;
    }

    $userInfo = api_get_user_info();

    $uploadedFile['name'] = stripslashes($uploadedFile['name']);
    // Add extension to files without one (if possible)
    $uploadedFile['name'] = add_ext_on_mime($uploadedFile['name'], $uploadedFile['type']);

    if (empty($sessionId)) {
        $sessionId = api_get_session_id();
    } else {
        $sessionId = intval($sessionId);
    }

    // Just in case process_uploaded_file is not called
    $maxSpace = DocumentManager::get_course_quota();

    // Check if there is enough space to save the file
    if (!DocumentManager::enough_space($uploadedFile['size'], $maxSpace)) {
        if ($output) {
            Display::display_error_message(get_lang('UplNotEnoughSpace'));
        }

        return false;
    }

    // If the want to unzip, check if the file has a .zip (or ZIP,Zip,ZiP,...) extension
    if ($unzip == 1 && preg_match('/.zip$/', strtolower($uploadedFile['name']))) {
        return unzip_uploaded_document(
            $courseInfo,
            $userInfo,
            $uploadedFile,
            $uploadPath,
            $documentDir,
            $maxSpace,
            $sessionId,
            $groupId,
            $output
        );
    } elseif ($unzip == 1 && !preg_match('/.zip$/', strtolower($uploadedFile['name']))) {
        // We can only unzip ZIP files (no gz, tar,...)
        if ($output) {
            Display::display_error_message(
                get_lang('UplNotAZip')." ".get_lang('PleaseTryAgain')
            );
        }

        return false;
    } else {
        // Clean up the name, only ASCII characters should stay. (and strict)
        $cleanName = replace_dangerous_char($uploadedFile['name'], 'strict');

        // No "dangerous" files
        $cleanName = disable_dangerous_file($cleanName);

        // Checking file extension
        if (!filter_extension($cleanName)) {
            if ($output) {
                Display::display_error_message(
                    get_lang('UplUnableToSaveFileFilteredExtension')
                );
            }

            return false;
        } else {

            // If the upload path differs from / (= root) it will need a slash at the end
            if ($uploadPath != '/') {
                $uploadPath = $uploadPath.'/';
            }

            // Full path to where we want to store the file with trailing slash
            $whereToSave = $documentDir.$uploadPath;

            // At least if the directory doesn't exist, tell so
            if (!is_dir($whereToSave)) {
                if (!mkdir($whereToSave, api_get_permissions_for_new_directories())) {
                    if ($output) {
                        Display::display_error_message(
                            get_lang('DestDirectoryDoesntExist').' ('.$uploadPath.')'
                        );
                    }

                    return false;
                }
            }

            // Just upload the file "as is"
            if ($onlyUploadFile) {
                $errorResult = moveUploadedFile($uploadedFile, $whereToSave.$cleanName);
                if ($errorResult) {

                    return $whereToSave.$cleanName;
                } else {

                    return $errorResult;
                }
            }

            /*
                Based in the clean name we generate a new filesystem name
                Using the session_id and group_id if values are not empty
            */

            /*$fileExists = DocumentManager::documentExists(
                $uploadPath.$cleanName,
                $courseInfo,
                $sessionId,
                $groupId
            );*/

            $fileSystemName = DocumentManager::fixDocumentName(
                $cleanName,
                'file',
                $courseInfo,
                $sessionId,
                $groupId
            );

            // Name of the document without the extension (for the title)
            $documentTitle = get_document_title($uploadedFile['name']);

            // Size of the uploaded file (in bytes)
            $fileSize = $uploadedFile['size'];

            // File permissions
            $filePermissions = api_get_permissions_for_new_files();

            // Example: /var/www/chamilo/courses/xxx/document/folder/picture.jpg
            $fullPath = $whereToSave.$fileSystemName;

            // Example: /folder/picture.jpg
            $filePath = $uploadPath.$fileSystemName;

            $docId = DocumentManager::get_document_id(
                $courseInfo,
                $filePath,
                $sessionId
            );

            $documentList = DocumentManager::getDocumentByPathInCourse(
                $courseInfo,
                $filePath //$filePath
            );

            // This means that the path already exists in this course.
            if (!empty($documentList) && $whatIfFileExists != 'overwrite') {
                //$found = false;
                // Checking if we are talking about the same course + session
                /*foreach ($documentList as $document) {
                    if ($document['session_id'] == $sessionId) {
                        $found = true;
                        break;
                    }
                }*/

                //if ($found == false) {
                    $whatIfFileExists = 'rename';
                //}
            }

            // What to do if the target file exists
            switch ($whatIfFileExists) {
                // Overwrite the file if it exists
                case 'overwrite':
                    // Check if the target file exists, so we can give another message
                    $fileExists = file_exists($fullPath);

                    if (moveUploadedFile($uploadedFile, $fullPath)) {
                        chmod($fullPath, $filePermissions);

                        if ($fileExists && $docId) {
                            // UPDATE DATABASE
                            $documentId = DocumentManager::get_document_id(
                                $courseInfo,
                                $filePath
                            );
                            if (is_numeric($documentId)) {
                                // Update file size
                                update_existing_document(
                                    $courseInfo,
                                    $documentId,
                                    $uploadedFile['size']
                                );

                                // Update document item_property
                                api_item_property_update(
                                    $courseInfo,
                                    TOOL_DOCUMENT,
                                    $documentId,
                                    'DocumentUpdated',
                                    $userId,
                                    $groupId,
                                    $toUserId,
                                    null,
                                    null,
                                    $sessionId
                                );

                                // Redo visibility
                                api_set_default_visibility($documentId, TOOL_DOCUMENT);
                            } else {
                                // There might be cases where the file exists on disk but there is no registration of that in the database
                                // In this case, and if we are in overwrite mode, overwrite and create the db record
                                $documentId = add_document(
                                    $courseInfo,
                                    $filePath,
                                    'file',
                                    $fileSize,
                                    $documentTitle,
                                    $comment,
                                    0,
                                    true,
                                    $groupId,
                                    $sessionId
                                );

                                if ($documentId) {
                                    // Put the document in item_property update
                                    api_item_property_update(
                                        $courseInfo,
                                        TOOL_DOCUMENT,
                                        $documentId,
                                        'DocumentAdded',
                                        $userId,
                                        $groupId,
                                        $toUserId,
                                        null,
                                        null,
                                        $sessionId
                                    );

                                    // Redo visibility
                                    api_set_default_visibility($documentId, TOOL_DOCUMENT);
                                }
                            }

                            // If the file is in a folder, we need to update all parent folders
                            item_property_update_on_folder($courseInfo, $uploadPath, $userId);
                            // Display success message with extra info to user
                            if ($output) {
                                Display::display_confirmation_message(
                                    get_lang('UplUploadSucceeded') . '<br /> ' . $documentTitle . ' ' . get_lang('UplFileOverwritten'),
                                    false
                                );
                            }

                            return $filePath;
                        } else {

                            // Put the document data in the database
                            $documentId = add_document(
                                $courseInfo,
                                $filePath,
                                'file',
                                $fileSize,
                                $documentTitle,
                                $comment,
                                0,
                                true,
                                $groupId,
                                $sessionId
                            );

                            if ($documentId) {
                                // Put the document in item_property update
                                api_item_property_update(
                                    $courseInfo,
                                    TOOL_DOCUMENT,
                                    $documentId,
                                    'DocumentAdded',
                                    $userId,
                                    $groupId,
                                    $toUserId,
                                    null,
                                    null,
                                    $sessionId
                                );

                                // Redo visibility
                                api_set_default_visibility($documentId, TOOL_DOCUMENT);
                            }
                            // If the file is in a folder, we need to update all parent folders
                            item_property_update_on_folder($courseInfo, $uploadPath, $userId);
                            // Display success message to user
                            if ($output) {
                                Display::display_confirmation_message(get_lang('UplUploadSucceeded').'<br /> '.$documentTitle, false);
                            }

                            return $filePath;
                        }
                    } else {
                        if ($output) {
                            Display::display_error_message(get_lang('UplUnableToSaveFile'));
                        }

                        return false;
                    }
                    break;
                // Rename the file if it exists
                case 'rename':

                    // Always rename.
                    $cleanName = DocumentManager::getUniqueFileName(
                        $uploadPath,
                        $cleanName,
                        $courseInfo,
                        $sessionId,
                        $groupId
                    );

                    $fileSystemName = DocumentManager::fixDocumentName(
                        $cleanName,
                        'file',
                        $courseInfo,
                        $sessionId,
                        $groupId
                    );

                    $documentTitle = get_document_title($cleanName);

                    $fullPath = $whereToSave.$fileSystemName;
                    $filePath = $uploadPath.$fileSystemName;

                    if (moveUploadedFile($uploadedFile, $fullPath)) {

                        chmod($fullPath, $filePermissions);
                        // Put the document data in the database
                        $documentId = add_document(
                            $courseInfo,
                            $filePath,
                            'file',
                            $fileSize,
                            $documentTitle,
                            $comment, // comment
                            0, // read only
                            true, // save visibility
                            $groupId,
                            $sessionId
                        );

                        if ($documentId) {
                            // Update document item_property
                            api_item_property_update(
                                $courseInfo,
                                TOOL_DOCUMENT,
                                $documentId,
                                'DocumentAdded',
                                $userId,
                                $groupId,
                                $toUserId,
                                null,
                                null,
                                $sessionId
                            );

                            // Redo visibility
                            api_set_default_visibility($documentId, TOOL_DOCUMENT);
                        }

                        // If the file is in a folder, we need to update all parent folders
                        item_property_update_on_folder($courseInfo, $uploadPath, $userId);

                        // Display success message to user
                        if ($output) {
                            Display::display_confirmation_message(
                                get_lang('UplUploadSucceeded') . '<br />' . get_lang('UplFileSavedAs') .' '.$documentTitle,
                                false
                            );
                        }

                        return $filePath;
                    } else {
                        if ($output) {
                            Display::display_error_message(get_lang('UplUnableToSaveFile'));
                        }

                        return false;
                    }
                    break;
                default:
                    // Only save the file if it doesn't exist or warn user if it does exist
                    if (file_exists($fullPath) && $docId) {
                        if ($output) {
                            Display::display_error_message($cleanName.' '.get_lang('UplAlreadyExists'));
                        }
                    } else {
                        if (moveUploadedFile($uploadedFile, $fullPath)) {
                            chmod($fullPath, $filePermissions);

                            // Put the document data in the database
                            $documentId = add_document(
                                $courseInfo,
                                $filePath,
                                'file',
                                $fileSize,
                                $documentTitle,
                                $comment,
                                0,
                                true,
                                $groupId,
                                $sessionId
                            );

                            if ($documentId) {
                                // Update document item_property
                                api_item_property_update(
                                    $courseInfo,
                                    TOOL_DOCUMENT,
                                    $documentId,
                                    'DocumentAdded',
                                    $userId,
                                    $groupId,
                                    $toUserId,
                                    null,
                                    null,
                                    $sessionId
                                );
                                // Redo visibility
                                api_set_default_visibility($documentId, TOOL_DOCUMENT);
                            }

                            // If the file is in a folder, we need to update all parent folders
                            item_property_update_on_folder(
                                $courseInfo,
                                $uploadPath,
                                $userId
                            );

                            // Display success message to user
                            if ($output) {
                                Display::display_confirmation_message(
                                    get_lang('UplUploadSucceeded').'<br /> '.$documentTitle,
                                    false
                                );
                            }

                            return $filePath;
                        } else {
                            if ($output) {
                                Display::display_error_message(get_lang('UplUnableToSaveFile'));
                            }

                            return false;
                        }
                    }
                    break;
            }
        }
    }
}

/**
 * @param string $file
 * @param string $storePath
 *
 * @return bool
 */
function moveUploadedFile($file, $storePath)
{
    $handleFromFile = isset($file['from_file']) && $file['from_file'] ? true : false;
    $moveFile = isset($file['move_file']) && $file['move_file'] ? true : false;
    if ($moveFile) {
        copy($file['tmp_name'], $storePath);
    }
    if ($handleFromFile) {
        return file_exists($file['tmp_name']);
    } else {
        return move_uploaded_file($file['tmp_name'], $storePath);
    }
}

/**
 * Checks if there is enough place to add a file on a directory
 * on the base of a maximum directory size allowed
 * deprecated: use enough_space instead!
 * @author - Hugues Peeters <peeters@ipm.ucl.ac.be>
 * @param  - file_size (int) - size of the file in byte
 * @param  - dir (string) - Path of the directory
 *           whe the file should be added
 * @param  - max_dir_space (int) - maximum size of the diretory in byte
 * @return - boolean true if there is enough space,
 *				boolean false otherwise
 *
 * @see    - enough_size() uses  dir_total_space() function
 */
function enough_size($file_size, $dir, $max_dir_space)
{
    // If the directory is the archive directory, safely ignore the size limit
    if (api_get_path(SYS_ARCHIVE_PATH) == $dir) {
        return true;
    }

    if ($max_dir_space) {
        $already_filled_space = dir_total_space($dir);
        if (($file_size + $already_filled_space) > $max_dir_space) {
            return false;
        }
    }
    return true;
}

/**
 * Computes the size already occupied by a directory and is subdirectories
 *
 * @author - Hugues Peeters <peeters@ipm.ucl.ac.be>
 * @param  - dir_path (string) - size of the file in byte
 * @return - int - return the directory size in bytes
 */
function dir_total_space($dir_path)
{
    $save_dir = getcwd();
    chdir($dir_path) ;
    $handle = opendir($dir_path);
    $sumSize = 0;
    $dirList = array();
    while ($element = readdir($handle)) {
        if ( $element == '.' || $element == '..') {
            continue; // Skip the current and parent directories
        }
        if (is_file($element)) {
            $sumSize += filesize($element);
        }
        if (is_dir($element)) {
            $dirList[] = $dir_path.'/'.$element;
        }
    }

    closedir($handle) ;

    if (sizeof($dirList) > 0) {
        foreach ($dirList as $j) {
            $sizeDir = dir_total_space($j);	// Recursivity
            $sumSize += $sizeDir;
        }
    }
    chdir($save_dir); // Return to initial position

    return $sumSize;
}


/**
 * Tries to add an extension to files without extension
 * Some applications on Macintosh computers don't add an extension to the files.
 * This subroutine try to fix this on the basis of the MIME type sent
 * by the browser.
 *
 * Note : some browsers don't send the MIME Type (e.g. Netscape 4).
 *        We don't have solution for this kind of situation
 *
 * @author - Hugues Peeters <peeters@ipm.ucl.ac.be>
 * @author - Bert Vanderkimpen
 * @param  - file_name (string) - Name of the file
 * @param  - file_type (string) - Type of the file
 * @return - file_name (string)
 */
function add_ext_on_mime($file_name, $file_type)
{
    // Check whether the file has an extension AND whether the browser has sent a MIME Type

    if (!preg_match('/^.*\.[a-zA-Z_0-9]+$/', $file_name) && $file_type) {

        // Build a "MIME-types / extensions" connection table

        static $mime_type = array();

        $mime_type[] = 'application/msword';             $extension[] = '.doc';
        $mime_type[] = 'application/rtf';                $extension[] = '.rtf';
        $mime_type[] = 'application/vnd.ms-powerpoint';  $extension[] = '.ppt';
        $mime_type[] = 'application/vnd.ms-excel';       $extension[] = '.xls';
        $mime_type[] = 'application/pdf';                $extension[] = '.pdf';
        $mime_type[] = 'application/postscript';         $extension[] = '.ps';
        $mime_type[] = 'application/mac-binhex40';       $extension[] = '.hqx';
        $mime_type[] = 'application/x-gzip';             $extension[] = 'tar.gz';
        $mime_type[] = 'application/x-shockwave-flash';  $extension[] = '.swf';
        $mime_type[] = 'application/x-stuffit';          $extension[] = '.sit';
        $mime_type[] = 'application/x-tar';              $extension[] = '.tar';
        $mime_type[] = 'application/zip';                $extension[] = '.zip';
        $mime_type[] = 'application/x-tar';              $extension[] = '.tar';
        $mime_type[] = 'text/html';                      $extension[] = '.html';
        $mime_type[] = 'text/plain';                     $extension[] = '.txt';
        $mime_type[] = 'text/rtf';                       $extension[] = '.rtf';
        $mime_type[] = 'img/gif';                        $extension[] = '.gif';
        $mime_type[] = 'img/jpeg';                       $extension[] = '.jpg';
        $mime_type[] = 'img/png';                        $extension[] = '.png';
        $mime_type[] = 'audio/midi';                     $extension[] = '.mid';
        $mime_type[] = 'audio/mpeg';                     $extension[] = '.mp3';
        $mime_type[] = 'audio/x-aiff';                   $extension[] = '.aif';
        $mime_type[] = 'audio/x-pn-realaudio';           $extension[] = '.rm';
        $mime_type[] = 'audio/x-pn-realaudio-plugin';    $extension[] = '.rpm';
        $mime_type[] = 'audio/x-wav';                    $extension[] = '.wav';
        $mime_type[] = 'video/mpeg';                     $extension[] = '.mpg';
        $mime_type[] = 'video/mpeg4-generic';            $extension[] = '.mp4';
        $mime_type[] = 'video/quicktime';                $extension[] = '.mov';
        $mime_type[] = 'video/x-msvideo';                $extension[] = '.avi';

        $mime_type[] = 'video/x-ms-wmv';                 $extension[] = '.wmv';
        $mime_type[] = 'video/x-flv';                    $extension[] = '.flv';
        $mime_type[] = 'image/svg+xml';                  $extension[] = '.svg';
        $mime_type[] = 'image/svg+xml';                  $extension[] = '.svgz';
        $mime_type[] = 'video/ogg';                  	 $extension[] = '.ogv';
        $mime_type[] = 'audio/ogg';                  	 $extension[] = '.oga';
        $mime_type[] = 'application/ogg';                $extension[] = '.ogg';
        $mime_type[] = 'application/ogg';                $extension[] = '.ogx';
        $mime_type[] = 'application/x-freemind';         $extension[] = '.mm';

        $mime_type[] = 'application/vnd.ms-word.document.macroEnabled.12';							$extension[] = '.docm';
        $mime_type[] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';	$extension[] = '.docx';
        $mime_type[] = 'application/vnd.ms-word.template.macroEnabled.12';							$extension[] = '.dotm';
        $mime_type[] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.template';	$extension[] = '.dotx';
        $mime_type[] = 'application/vnd.ms-powerpoint.template.macroEnabled.12';					$extension[] = '.potm';
        $mime_type[] = 'application/vnd.openxmlformats-officedocument.presentationml.template';		$extension[] = '.potx';
        $mime_type[] = 'application/vnd.ms-powerpoint.addin.macroEnabled.12';						$extension[] = '.ppam';
        $mime_type[] = 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12';					$extension[] = '.ppsm';
        $mime_type[] = 'application/vnd.openxmlformats-officedocument.presentationml.slideshow';	$extension[] = '.ppsx';
        $mime_type[] = 'application/vnd.ms-powerpoint.presentation.macroEnabled.12';				$extension[] = '.pptm';
        $mime_type[] = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';	$extension[] = '.pptx';
        $mime_type[] = 'application/vnd.ms-excel.addin.macroEnabled.12';							$extension[] = '.xlam';
        $mime_type[] = 'application/vnd.ms-excel.sheet.binary.macroEnabled.12';						$extension[] = '.xlsb';
        $mime_type[] = 'application/vnd.ms-excel.sheet.macroEnabled.12';							$extension[] = '.xlsm';
        $mime_type[] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';			$extension[] = '.xlsx';
        $mime_type[] = 'application/vnd.ms-excel.template.macroEnabled.12';							$extension[] = '.xltm';
        $mime_type[] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.template';		$extension[] = '.xltx';

        // Test on PC (files with no extension get application/octet-stream)
        //$mime_type[] = 'application/octet-stream';      $extension[] = '.ext';

        // Check whether the MIME type sent by the browser is within the table

        foreach ($mime_type as $key => & $type) {
            if ($type == $file_type) {
                $file_name .=  $extension[$key];
                break;
            }
        }

        unset($mime_type, $extension, $type, $key); // Delete to eschew possible collisions
    }

    return $file_name;
}

/**
 *
 * @author Hugues Peeters <hugues.peeters@claroline.net>
 *
 * @param  array $uploaded_file - follows the $_FILES Structure
 * @param  string $base_work_dir - base working directory of the module
 * @param  string $upload_path  - destination of the upload.
 *                               This path is to append to $base_work_dir
 * @param  int $max_filled_space - amount of bytes to not exceed in the base
 *                               working directory
 *
 * @return boolean true if it succeds, false otherwise
 */
function treat_uploaded_file($uploaded_file, $base_work_dir, $upload_path, $max_filled_space, $uncompress = '')
{
    $uploaded_file['name'] = stripslashes($uploaded_file['name']);

    if (!enough_size($uploaded_file['size'], $base_work_dir, $max_filled_space)) {
        return api_failure::set_failure('not_enough_space');
    }

    if ($uncompress == 'unzip' && preg_match('/.zip$/', strtolower($uploaded_file['name']))) {
        return unzip_uploaded_file($uploaded_file, $upload_path, $base_work_dir, $max_filled_space);
    } else {
        $file_name = trim($uploaded_file['name']);

        // CHECK FOR NO DESIRED CHARACTERS
        $file_name = replace_dangerous_char($file_name, 'strict');

        // TRY TO ADD AN EXTENSION TO FILES WITOUT EXTENSION
        $file_name = add_ext_on_mime($file_name, $uploaded_file['type']);

        // HANDLE PHP FILES
        $file_name = ($file_name);

        // COPY THE FILE TO THE DESIRED DESTINATION
        if (move_uploaded_file($uploaded_file['tmp_name'], $base_work_dir.$upload_path.'/'.$file_name)) {
            set_default_settings($upload_path, $file_name);
        }

        return true;
    }
}

/**
 * Manages all the unzipping process of an uploaded file
 *
 * @author Hugues Peeters <hugues.peeters@claroline.net>
 *
 * @param  array  $uploaded_file - follows the $_FILES Structure
 * @param  string $upload_path   - destination of the upload.
 *                                This path is to append to $base_work_dir
 * @param  string $base_work_dir  - base working directory of the module
 * @param  int $max_filled_space  - amount of bytes to not exceed in the base
 *                                working directory
 *
 * @return boolean true if it succeeds false otherwise
 */
function unzip_uploaded_file($uploaded_file, $upload_path, $base_work_dir, $max_filled_space)
{
    $zip_file = new PclZip($uploaded_file['tmp_name']);

    // Check the zip content (real size and file extension)
    if (file_exists($uploaded_file['tmp_name'])) {
        $zip_content_array = $zip_file->listContent();
        $ok_scorm = false;
        $realFileSize = 0;
        foreach ($zip_content_array as & $this_content) {
            if (preg_match('~.(php.*|phtml)$~i', $this_content['filename'])) {
                return api_failure::set_failure('php_file_in_zip_file');
            } elseif (stristr($this_content['filename'], 'imsmanifest.xml')) {
                $ok_scorm = true;
            } elseif (stristr($this_content['filename'], 'LMS')) {
                $ok_plantyn_scorm1 = true;
            } elseif (stristr($this_content['filename'], 'REF')) {
                $ok_plantyn_scorm2 = true;
            } elseif (stristr($this_content['filename'], 'SCO')) {
                $ok_plantyn_scorm3 = true;
            } elseif (stristr($this_content['filename'], 'AICC')) {
                $ok_aicc_scorm = true;
            }
            $realFileSize += $this_content['size'];
        }

        if (($ok_plantyn_scorm1 && $ok_plantyn_scorm2 && $ok_plantyn_scorm3) || $ok_aicc_scorm) {
            $ok_scorm = true;
        }

        if (!$ok_scorm && defined('CHECK_FOR_SCORM') && CHECK_FOR_SCORM) {
            return api_failure::set_failure('not_scorm_content');
        }

        if (!enough_size($realFileSize, $base_work_dir, $max_filled_space)) {
            return api_failure::set_failure('not_enough_space');
        }

        // It happens on Linux that $upload_path sometimes doesn't start with '/'
        if ($upload_path[0] != '/' && substr($base_work_dir,-1,1) != '/') {
            $upload_path = '/'.$upload_path;
        }

        if ($upload_path[strlen($upload_path) - 1] == '/') {
            $upload_path=substr($upload_path, 0, -1);
        }

        /*	Uncompressing phase */

        /*
            The first version, using OS unzip, is not used anymore
            because it does not return enough information.
            We need to process each individual file in the zip archive to
            - add it to the database
            - parse & change relative html links
        */
        if (PHP_OS == 'Linux' && ! get_cfg_var('safe_mode') && false) { // *** UGent, changed by OC ***
            // Shell Method - if this is possible, it gains some speed
            exec("unzip -d \"".$base_work_dir.$upload_path."/\"".$uploaded_file['name']." " .$uploaded_file['tmp_name']);
        } else {
            // PHP method - slower...
            $save_dir = getcwd();
            chdir($base_work_dir.$upload_path);
            $unzippingState = $zip_file->extract();
            for ($j=0; $j < count($unzippingState); $j++) {
                $state = $unzippingState[$j];

                // Fix relative links in html files
                $extension = strrchr($state['stored_filename'], '.');
            }
            if ($dir = @opendir($base_work_dir.$upload_path)) {
                while ($file = readdir($dir)) {
                    if ($file != '.' && $file != '..') {

                        $filetype = 'file';
                        if (is_dir($base_work_dir.$upload_path.'/'.$file)) $filetype = 'folder';

                        $safe_file = replace_dangerous_char($file, 'strict');
                        @rename($base_work_dir.$upload_path.'/'.$file,$base_work_dir.$upload_path.'/'.$safe_file);
                        set_default_settings($upload_path, $safe_file,$filetype);
                    }
                }

                closedir($dir);
            } else {
                error_log('Could not create directory '.$base_work_dir.$upload_path.' to unzip files');
            }
            chdir($save_dir); // Back to previous dir position
        }
    }

    return true;
}

/**
 * Manages all the unzipping process of an uploaded document
 * This uses the item_property table for properties of documents
 *
 * @author Hugues Peeters <hugues.peeters@claroline.net>
 * @author Bert Vanderkimpen
 *
 * @param array  $courseInfo
 * @param array  $userInfo
 * @param array  $uploaded_file - follows the $_FILES Structure
 * @param string $upload_path   - destination of the upload.
 *                               This path is to append to $base_work_dir
 * @param string $base_work_dir  - base working directory of the module
 * @param int    $maxFilledSpace  - amount of bytes to not exceed in the base
 *                                working directory
 * @param int $sessionId
 * @param int $groupId
 * @param boolean $output Optional. If no output not wanted on success, set to false.
 *
 * @return boolean true if it succeeds false otherwise
 */
function unzip_uploaded_document(
    $courseInfo,
    $userInfo,
    $uploaded_file,
    $uploadPath,
    $base_work_dir,
    $maxFilledSpace,
    $sessionId = 0,
    $groupId = 0,
    $output = true
) {
    $zip = new PclZip($uploaded_file['tmp_name']);

    // Check the zip content (real size and file extension)
    $zip_content_array = (array)$zip->listContent();

    $realSize = 0;
    foreach ($zip_content_array as & $this_content) {
        $realSize += $this_content['size'];
    }

    if (!DocumentManager::enough_space($realSize, $maxFilledSpace)) {
        Display::display_error_message(get_lang('UplNotEnoughSpace'));
        return false;
    }

    $folder = api_get_unique_id();
    $destinationDir = api_get_path(SYS_ARCHIVE_PATH).$folder;
    mkdir($destinationDir, api_get_permissions_for_new_directories(), true);

    /*	Uncompress zip file*/
    // We extract using a callback function that "cleans" the path
    $zip->extract(
        PCLZIP_OPT_PATH,
        $destinationDir,
        PCLZIP_CB_PRE_EXTRACT,
        'clean_up_files_in_zip',
        PCLZIP_OPT_REPLACE_NEWER
    );

    // Add all documents in the unzipped folder to the database
    add_all_documents_in_folder_to_database(
        $courseInfo,
        $userInfo,
        $base_work_dir,
        $destinationDir,
        $sessionId,
        $groupId,
        $output,
        array('path' => $uploadPath)
    );

    if (is_dir($destinationDir)) {
        rmdirr($destinationDir);
    }

    return true;
}

/**
 * This function is a callback function that is used while extracting a zipfile
 * http://www.phpconcept.net/pclzip/man/en/index.php?options-pclzip_cb_pre_extract
 *
 * @param $p_event
 * @param $p_header
 * @return 1 (If the function returns 1, then the extraction is resumed)
 */
function clean_up_files_in_zip($p_event, &$p_header)
{
    $res = clean_up_path($p_header['filename']);
    return $res;
}

/**
 * This function cleans up a given path
 * by eliminating dangerous file names and cleaning them
 *
 * @param string $path
 * @return $path
 * @see disable_dangerous_file()
 * @see replace_dangerous_char()
 */
function clean_up_path(&$path)
{
    // Split the path in folders and files
    $path_array = explode('/', $path);
    // Clean up every foler and filename in the path
    foreach ($path_array as $key => & $val) {
        // We don't want to lose the dots in ././folder/file (cfr. zipfile)
        if ($val != '.') {
            $val = disable_dangerous_file(replace_dangerous_char($val));
        }
    }
    // Join the "cleaned" path (modified in-place as passed by reference)
    $path = implode('/', $path_array);
    $res = filter_extension($path);
    return $res;
}

/**
 * Checks if the file is dangerous, based on extension and/or mimetype.
 * The list of extensions accepted/rejected can be found from
 * api_get_setting('upload_extensions_exclude') and api_get_setting('upload_extensions_include')
 * @param	string 	filename passed by reference. The filename will be modified
 * if filter rules say so! (you can include path but the filename should look like 'abc.html')
 * @return	int		0 to skip file, 1 to keep file
 */
function filter_extension(&$filename)
{
    if (substr($filename, -1) == '/') {
        return 1;  // Authorize directories
    }
    $blacklist = api_get_setting('upload_extensions_list_type');
    if ($blacklist != 'whitelist') { // if = blacklist
        $extensions = explode(';', strtolower(api_get_setting('upload_extensions_blacklist')));

        $skip = api_get_setting('upload_extensions_skip');
        $ext = strrchr($filename, '.');
        $ext = substr($ext, 1);
        if (empty($ext)) {
            return 1; // We're in blacklist mode, so accept empty extensions
        }
        if (in_array(strtolower($ext), $extensions)) {
            if ($skip == 'true') {
                return 0;
            } else {
                $new_ext = api_get_setting('upload_extensions_replace_by');
                $filename = str_replace('.'.$ext, '.'.$new_ext, $filename);
                return 1;
            }
        } else {
            return 1;
        }
    } else {
        $extensions = split(';', strtolower(api_get_setting('upload_extensions_whitelist')));
        $skip = api_get_setting('upload_extensions_skip');
        $ext = strrchr($filename, '.');
        $ext = substr($ext, 1);
        if (empty($ext)) {
            return 1; // Accept empty extensions
        }
        if (!in_array(strtolower($ext), $extensions)) {
            if ($skip == 'true') {
                return 0;
            } else {
                $new_ext = api_get_setting('upload_extensions_replace_by');
                $filename = str_replace('.'.$ext, '.'.$new_ext, $filename);
                return 1;
            }
        } else {
            return 1;
        }
    }
}

/**
 * Adds a new document to the database
 *
 * @param array $_course
 * @param string $path
 * @param string $filetype
 * @param int $filesize
 * @param string $title
 * @param string $comment
 * @param int $readonly
 * @param bool $save_visibility
 * @param int $group_id
 * @param int $session_id Session ID, if any
 * @return int id if inserted document
 */
function add_document(
    $_course,
    $path,
    $filetype,
    $filesize,
    $title,
    $comment = null,
    $readonly = 0,
    $save_visibility = true,
    $group_id = null,
    $session_id = 0
) {
    $session_id = intval($session_id);

    if (empty($session_id)) {
        $session_id = api_get_session_id();
    }

    $readonly = intval($readonly);
    $comment = Database::escape_string($comment);
    $path = Database::escape_string($path);
    $filetype = Database::escape_string($filetype);
    $filesize = Database::escape_string($filesize);
    $title = Database::escape_string(htmlspecialchars($title));
    $c_id = $_course['real_id'];

    $table_document = Database::get_course_table(TABLE_DOCUMENT);
    $sql = "INSERT INTO $table_document (c_id, path, filetype, size, title, comment, readonly, session_id)
	        VALUES ($c_id, '$path','$filetype','$filesize','$title', '$comment', $readonly, $session_id)";

    if (Database::query($sql)) {
        $documentId = Database::insert_id();
        if ($documentId) {
            if ($save_visibility) {
                api_set_default_visibility($documentId, TOOL_DOCUMENT, $group_id, $_course);
            }
        }
        return $documentId;
    } else {
        return false;
    }
}

/**
 * Updates an existing document in the database
 * as the file exists, we only need to change the size
 *
 * @param array $_course
 * @param int $documentId
 * @param int $filesize
 * @param int $readonly
 * @return boolean true /false
 */
function update_existing_document($_course, $documentId, $filesize, $readonly = 0)
{
    $document_table = Database::get_course_table(TABLE_DOCUMENT);
    $documentId 	= intval($documentId);
    $filesize 		= intval($filesize);
    $readonly 		= intval($readonly);
    $course_id 		= $_course['real_id'];

    $sql = "UPDATE $document_table SET
            size = '$filesize',
            readonly = '$readonly'
			WHERE c_id = $course_id AND id = $documentId";
    if (Database::query($sql)) {
        return true;
    } else {
        return false;
    }
}

/**
 * This function updates the last_edit_date, last edit user id on all folders in a given path
 *
 * @param array $_course
 * @param string $path
 * @param int $user_id
 */
function item_property_update_on_folder($_course, $path, $user_id)
{
    // If we are in the root, just return... no need to update anything
    if ($path == '/') {
        return;
    }

    $user_id = intval($user_id);

    // If the given path ends with a / we remove it
    $endchar = substr($path, strlen($path) - 1, 1);
    if ($endchar == '/') {
        $path = substr($path, 0, strlen($path) - 1);
    }

    $table = Database::get_course_table(TABLE_ITEM_PROPERTY);

    // Get the time
    $time = date('Y-m-d H:i:s', time());

    // Det all paths in the given path
    // /folder/subfolder/subsubfolder/file
    // if file is updated, subsubfolder, subfolder and folder are updated

    $exploded_path = explode('/', $path);
    $course_id = api_get_course_int_id();
    $newpath = '';
    foreach ($exploded_path as $key => & $value) {
        // We don't want a slash before our first slash
        if ($key != 0) {
            $newpath .= '/'.$value;

            //echo 'path= '.$newpath.'<br />';
            // Select ID of given folder
            $folder_id = DocumentManager::get_document_id($_course, $newpath);

            if ($folder_id) {
                $sql = "UPDATE $table SET
				        lastedit_date='$time',lastedit_type='DocumentInFolderUpdated', lastedit_user_id='$user_id'
						WHERE c_id = $course_id AND tool='".TOOL_DOCUMENT."' AND ref='$folder_id'";
                Database::query($sql);
            }
        }
    }
}

/**
 * Returns the directory depth of the file.
 *
 * @author	Olivier Cauberghe <olivier.cauberghe@ugent.be>
 * @param	path+filename eg: /main/document/document.php
 * @return	The directory depth
 */
function get_levels($filename) {
    $levels=explode('/', $filename);
    if (empty($levels[count($levels) - 1])) {
        unset($levels[count($levels) - 1]);
    }
    return count($levels);
}

/**
 * Adds file to document table in database
 * deprecated: use file_set_default_settings instead
 *
 * @author	Olivier Cauberghe <olivier.cauberghe@ugent.be>
 * @param	path,filename
 * action:	Adds an entry to the document table with the default settings.
 */
function set_default_settings($upload_path, $filename, $filetype = 'file')
{
    global $dbTable,$_configuration;
    global $default_visibility;

    if (!$default_visibility) {
        $default_visibility = 'v';
    }
    $filetype = Database::escape_string($filetype);

    $upload_path = str_replace('\\', '/', $upload_path);
    $upload_path = str_replace('//', '/', $upload_path);

    if ($upload_path == '/') {
        $upload_path='';
    } elseif (!empty($upload_path) && $upload_path[0] != '/') {
        $upload_path="/$upload_path";
    }

    $endchar = substr($filename, strlen($filename) - 1, 1);

    if ($endchar == '/') {
        $filename = substr($filename, 0, -1);
    }
    $filename = Database::escape_string($filename);
    $query = "SELECT count(*) as bestaat FROM $dbTable
              WHERE path='$upload_path/$filename'";
    $result = Database::query($query);
    $row = Database::fetch_array($result);
    if ($row['bestaat'] > 0) {
        $query = "UPDATE $dbTable SET
		            path='$upload_path/$filename',
		            visibility='$default_visibility',
		            filetype='$filetype'
		          WHERE path='$upload_path/$filename'";
    } else {
        $query = "INSERT INTO $dbTable (path,visibility,filetype)
		          VALUES('$upload_path/$filename','$default_visibility','$filetype')";
    }
    Database::query($query);
}

/**
 * Retrieves the image path list in a html file
 *
 * @author Hugues Peeters <hugues.peeters@claroline.net>
 * @param  string $html_file
 * @return array -  images path list
 */
function search_img_from_html($html_file) {

    $img_path_list = array();

    if (!$fp = fopen($html_file, 'r')) {
        return ;
    }

    // Aearch and store occurences of the <img> tag in an array
    $size_file = (filesize($html_file) === 0) ? 1 : filesize($html_file);
    if (isset($fp) && $fp !== false) {
        $buffer = fread($fp, $size_file);
        if (strlen($buffer) >= 0 && $buffer !== false) {
            //
        } else {
            die('<center>Can not read file.</center>');
        }
    } else {
        die('<center>Can not read file.</center>');
    }
    $matches = array();
    if (preg_match_all('~<[[:space:]]*img[^>]*>~i', $buffer, $matches)) {
        $img_tag_list = $matches[0];
    }

    fclose ($fp);
    unset($buffer);

    // Search the image file path from all the <IMG> tag detected

    if (sizeof($img_tag_list) > 0) {
        foreach ($img_tag_list as & $this_img_tag) {
            if (preg_match('~src[[:space:]]*=[[:space:]]*[\"]{1}([^\"]+)[\"]{1}~i', $this_img_tag, $matches)) {
                $img_path_list[] = $matches[1];
            }
        }
        $img_path_list = array_unique($img_path_list); // Remove duplicate entries
    }

    return $img_path_list;
}

/**
 * Creates a new directory trying to find a directory name
 * that doesn't already exist
 * (we could use unique_name() here...)
 *
 * @author  Hugues Peeters <hugues.peeters@claroline.net>
 * @author  Bert Vanderkimpen
 * @param   array   $_course current course information
 * @param   int     $user_id current user id
 * @param   int     $session_id
 * @param   int     $to_group_id
 * @param   int     $to_user_id
 * @param   string  $base_work_dir /var/www/chamilo/courses/ABC/document
 * @param   string  $desired_dir_name complete path of the desired name
 * Example: /folder1/folder2
 * @param   string  $title "folder2"
 * @param   int     $visibility (0 for invisible, 1 for visible, 2 for deleted)
 * @param   bool $generateNewNameIfExists
 * @return  string  actual directory name if it succeeds,
 *          boolean false otherwise
 */
function create_unexisting_directory(
    $_course,
    $user_id,
    $session_id,
    $to_group_id,
    $to_user_id,
    $base_work_dir,
    $desired_dir_name,
    $title = null,
    $visibility = null,
    $generateNewNameIfExists = false,
    $useSuffix = true
) {
    $course_id = $_course['real_id'];
    $session_id = intval($session_id);

    $folderExists = DocumentManager::folderExists(
        $desired_dir_name,
        $_course,
        $session_id,
        $to_group_id,
        $useSuffix
    );

    if ($folderExists == true) {
        if ($generateNewNameIfExists) {
            $counter = 1;
            while (1) {
                $folderExists = DocumentManager::folderExists(
                    $desired_dir_name.'_'.$counter,
                    $_course,
                    $session_id,
                    $to_group_id
                );

                if ($folderExists == false) {
                    break;
                }
                $counter++;
            }
            $desired_dir_name = $desired_dir_name.'_'.$counter;
        } else {

            return false;
        }
    }

    $systemFolderName = $desired_dir_name;

    // Adding suffix
    $suffix = '';
    if ($useSuffix) {
        $suffix = DocumentManager::getDocumentSuffix(
            $_course,
            $session_id,
            $to_group_id
        );
    }

    $systemFolderName .= $suffix;

    if ($title == null) {
        $title = basename($desired_dir_name);
    }

    if (!is_dir($base_work_dir.$systemFolderName)) {
        $result = mkdir(
            $base_work_dir.$systemFolderName,
            api_get_permissions_for_new_directories(),
            true
        );

        if ($result) {

            // Check if pathname already exists inside document table
            $tbl_document = Database::get_course_table(TABLE_DOCUMENT);
            $sql = "SELECT id, path FROM $tbl_document
                    WHERE
                        c_id = $course_id AND
                        (
                            path = '" . $systemFolderName . "'
                        )
            ";

            $rs = Database::query($sql);
            if (Database::num_rows($rs) == 0) {

                $document_id = add_document(
                    $_course,
                    $systemFolderName,
                    'folder',
                    0,
                    $title,
                    null,
                    0,
                    true,
                    $to_group_id
                );

                if ($document_id) {
                    // Update document item_property
                    if (!empty($visibility)) {

                        $visibilities = array(
                            0 => 'invisible',
                            1 => 'visible',
                            2 => 'delete'
                        );
                        api_item_property_update(
                            $_course,
                            TOOL_DOCUMENT,
                            $document_id,
                            $visibilities[$visibility],
                            $user_id,
                            $to_group_id,
                            $to_user_id,
                            null,
                            null,
                            $session_id
                        );
                    } else {
                        api_item_property_update(
                            $_course,
                            TOOL_DOCUMENT,
                            $document_id,
                            'FolderCreated',
                            $user_id,
                            $to_group_id,
                            $to_user_id,
                            null,
                            null,
                            $session_id
                        );
                    }

                    $documentData = DocumentManager::get_document_data_by_id(
                        $document_id,
                        $_course['code'],
                        false,
                        $session_id
                    );

                    return $documentData;
                }
            } else {
                $document = Database::fetch_array($rs);
                $documentData = DocumentManager::get_document_data_by_id(
                    $document['id'],
                    $_course['code'],
                    false,
                    $session_id
                );

                /* This means the folder NOT exist in the filesystem
                 (now this was created) but there is a record in the Database*/

                return $documentData;
            }
        }
    }

    return false;
}

/**
 * Handles uploaded missing images
 *
 * @author Hugues Peeters <hugues.peeters@claroline.net>
 * @author Bert Vanderkimpen
 * @param array $_course
 * @param array $uploaded_file_collection - follows the $_FILES Structure
 * @param string $base_work_dir
 * @param string $missing_files_dir
 * @param int $user_id
 * @param int $max_filled_space
 */
function move_uploaded_file_collection_into_directory(
    $_course,
    $uploaded_file_collection,
    $base_work_dir,
    $missing_files_dir,
    $user_id,
    $to_group_id,
    $to_user_id,
    $max_filled_space
) {
    $number_of_uploaded_images = count($uploaded_file_collection['name']);
    $new_file_list = array();
    for ($i = 0; $i < $number_of_uploaded_images; $i++) {
        $missing_file['name'] = $uploaded_file_collection['name'][$i];
        $missing_file['type'] = $uploaded_file_collection['type'][$i];
        $missing_file['tmp_name'] = $uploaded_file_collection['tmp_name'][$i];
        $missing_file['error'] = $uploaded_file_collection['error'][$i];
        $missing_file['size'] = $uploaded_file_collection['size'][$i];

        $upload_ok = process_uploaded_file($missing_file);
        if ($upload_ok) {
            $new_file_list[] = handle_uploaded_document(
                $_course,
                $missing_file,
                $base_work_dir,
                $missing_files_dir,
                $user_id,
                $to_group_id,
                $to_user_id,
                $max_filled_space,
                0,
                'overwrite'
            );
        }
        unset($missing_file);
    }
    return $new_file_list;
}

/**
 * Opens the old html file and replace the src path into the img tag
 * This also works for files in subdirectories.
 * @param $original_img_path is an array
 * @param $new_img_path is an array
 */
function replace_img_path_in_html_file($original_img_path, $new_img_path, $html_file)
{
    $_course = api_get_course_info();

    // Open the file

    $fp = fopen($html_file, 'r');
    $buffer = fread($fp, filesize($html_file));
    $new_html_content = '';

    // Fix the image tags

    for ($i = 0, $fileNb = count($original_img_path); $i < $fileNb ; $i++) {
        $replace_what = $original_img_path[$i];
        // We only need the directory and the filename /path/to/file_html_files/missing_file.gif -> file_html_files/missing_file.gif
        $exploded_file_path = explode('/', $new_img_path[$i]);
        $replace_by = $exploded_file_path[count($exploded_file_path) - 2].'/'.$exploded_file_path[count($exploded_file_path) - 1];
        //$message .= "Element [$i] <b>" . $replace_what . "</b> replaced by <b>" . $replace_by . "</b><br />"; //debug
        //api_display_debug_info($message);

        $buffer = str_replace($replace_what, $replace_by, $buffer);
    }

    $new_html_content .= $buffer;

    @fclose($fp);

    // Write the resulted new file

    if (!$fp = fopen($html_file, 'w')) {
        return;
    }

    if (!fwrite($fp, $new_html_content)) {
        return;
    }
}

/**
 * Creates a file containing an html redirection to a given url
 *
 * @author Hugues Peeters <hugues.peeters@claroline.net>
 * @param string $file_path
 * @param string $url
 * @return void
 */
function create_link_file($file_path, $url)
{
    $file_content = '<html>'
        .'<head>'
        .'<meta http-equiv="refresh" content="1;url='.$url.'">'
        .'</head>'
        .'<body>'
        .'</body>'
        .'</html>';
    if (file_exists($file_path)) {
        if (!($fp = fopen ($file_path, 'w'))) {
            return false;
        }
        return fwrite($fp, $file_content);
    }
}

/**
 * Opens html file $full_file_name;
 * Parses the hyperlinks; and
 * Writes the result back in the html file.
 *
 * @author Roan Embrechts
 * @version 0.1
 */
function api_replace_links_in_html($upload_path, $full_file_name)
{
    // Open the file
    if (file_exists($full_file_name)) {
        $fp = fopen($full_file_name, 'r');
        $buffer = fread ($fp, filesize ($full_file_name));

        // Parse the contents
        $new_html_content = api_replace_links_in_string($upload_path, $buffer);

        // Write the result
        $fp = fopen($full_file_name, 'w');
        fwrite($fp, $new_html_content);
    }
}

/**
deprecated: use api_replace_parameter instead

Parse the buffer string provided as parameter
Replace the a href tags so they are displayed correctly.
- works for files in root and subdirectories
- replace relative hyperlinks to use showinframes.php?file= ...
- add target="_self" to all absolute hyperlinks
- leave local anchors untouched (e.g. #CHAPTER1)
- leave links with download.php and showinframes.php untouched

@author Roan Embrechts
@version 0.6
 */
function api_replace_links_in_string($upload_path, $buffer) {
    // Search for hyperlinks
    $matches = array();
    if (preg_match_all('/<a[\s]*href[^<]*>/i', $buffer, $matches)) {
        $tag_list = $matches[0];
    }

    // Search the filepath of all detected <a href> tags
    if (sizeof($tag_list) > 0) {
        $file_path_list = array();
        $href_list = array();

        foreach ($tag_list as & $this_tag) {
            /* Match case insensitive, the stuff between the two ~ :
                a href = <exactly one quote><one or more non-quotes><exactly one ">
                e.g. a href="www.google.be", A HREF =   "info.html"
                to match ["] escape the " or else PHP interprets it
                [\"]{1} --> matches exactly one "
                +	1 or more (like * is 0 or more)
                [\s]* matches whitespace
                $matches contains captured subpatterns
                the only one here is ([^\"]+) --> matches[1]
            */
            if (preg_match("~a href[\s]*=[\s]*[\"]{1}([^\"]+)[\"]{1}~i", $this_tag, $matches)) {
                $file_path_list[] = $matches[1]; // older
                $href_list[] = $matches[0]; // to also add target="_self"
            }
        }
    }

    // Replace the original hyperlinks by the correct ones
    for ($count = 0; $count < sizeof($href_list); $count++) {

        $replace_what[$count] = $href_list[$count];

        $is_absolute_hyperlink = strpos($replace_what[$count], 'http');
        $is_local_anchor = strpos($replace_what[$count], "#");
        if (!$is_absolute_hyperlink && !$is_local_anchor) {
            // This is a relative hyperlink
            if ((strpos($replace_what[$count], 'showinframes.php') === false) &&
                (strpos($replace_what[$count], 'download.php') === false)
            ) {
                // Fix the link to use showinframes.php
                $replace_by[$count] = 'a href = "showinframes.php?file='.$upload_path.'/'.$file_path_list[$count].'" target="_self"';
            } else {
                // URL has been already fixed, leave it as is
                $replace_by[$count] = $replace_what[$count];
            }
        } elseif ($is_absolute_hyperlink) {
            $replace_by[$count] = 'a href="'.$file_path_list[$count].'" target ="_self"';
        } else {
            // Don't change anything
            $replace_by[$count] = $replace_what[$count];
        }
        //Display::display_normal_message('Link replaced by ' . $replace_by[$count]); // debug
    }

    $buffer = str_replace($replace_what, $replace_by, $buffer);
    return $buffer;
}

/**
EXPERIMENTAL - function seems to work, needs more testing

@param $upload_path is the path where the document is stored, like "/archive/"
if it is the root level, the function expects "/"
otherwise "/path/"

This function parses all tags with $param_name parameters.
so the tags are displayed correctly.

--------------
Algorithm v1.0
--------------
given a string and a parameter,
 * OK find all tags in that string with the specified parameter (like href or src)
 * OK for every one of these tags, find the src|href|... part to edit it
 * OK change the src|href|... part to use download.php (or showinframes.php)
 * OK do some special stuff for hyperlinks

Exceptions
 * OK if download.php or showinframes.php is already in the tag, leave it alone
 * OK if mailto is in the tag, leave it alone
 * OK if the src|href param contains http://, it's absolute --> leave it alone

Special for hyperlinks (a href...)
 * OK add target="_self"
 * OK use showinframes.php instead of download.php

@author Roan Embrechts
@version 1.1
 */
function api_replace_parameter($upload_path, $buffer, $param_name = 'src')
{
    // Search for tags with $param_name as a parameter

    /*
    // [\s]*	matches whitespace
    // [\"=a-z] matches ", = and a-z
    // ([\s]*[a-z]*)*	matches all whitespace and normal alphabet
    //					characters a-z combinations but seems too slow
    //	perhaps ([\s]*[a-z]*) a maximum number of times ?
    // [\s]*[a-z]*[\s]*	matches many tags
    // the ending "i" means to match case insensitive (a matches a and A)
    */
    $matches = array();
    if (preg_match_all('/<[a-z]+[^<]*'.$param_name.'[^<]*>/i', $buffer, $matches)) {
        $tag_list = $matches[0];
    }

    // Search the filepath of parameter $param_name in all detected tags

    if (sizeof($tag_list) > 0) {
        $file_path_list = array();
        $href_list = array();

        foreach ($tag_list as & $this_tag) {
            //Display::display_normal_message(htmlentities($this_tag)); //debug
            if ( preg_match("~".$param_name."[\s]*=[\s]*[\"]{1}([^\"]+)[\"]{1}~i", $this_tag, $matches)) {
                $file_path_list[] = $matches[1]; // older
                $href_list[] = $matches[0]; // to also add target="_self"
            }
        }
    }

    // Replace the original tags by the correct ones

    for ($count = 0; $count < sizeof($href_list); $count++) {
        $replace_what[$count] = $href_list[$count];

        $is_absolute_hyperlink = strpos($replace_what[$count], 'http');
        $is_local_anchor = strpos($replace_what[$count], '#');
        if (!$is_absolute_hyperlink && !$is_local_anchor) {
            if ((strpos($replace_what[$count], 'showinframes.php') === false)
                && (strpos($replace_what[$count], 'download.php') === false)
                && (strpos($replace_what[$count], 'mailto') === false)) {

                // Fix the link to use download.php or showinframes.php
                if (preg_match("/<a([\s]*[\"\/:'=a-z0-9]*){5}href[^<]*>/i", $tag_list[$count])) {
                    $replace_by[$count] = " $param_name =\"showinframes.php?file=" . $upload_path.$file_path_list[$count]."\" target=\"_self\" ";
                } else {
                    $replace_by[$count] = " $param_name =\"download.php?doc_url=" . $upload_path.$file_path_list[$count]."\" ";
                }
            } else {
                // "mailto" or url already fixed, leave as is
                //$message .= "Already fixed or contains mailto: ";
                $replace_by[$count] = $replace_what[$count];
            }
        } elseif ($is_absolute_hyperlink) {
            //$message .= "Absolute hyperlink, don't change, add target=_self: ";
            $replace_by[$count] = " $param_name=\"" . $file_path_list[$count] . "\" target =\"_self\"";
        } else {
            // Don't change anything
            //$message .= "Local anchor, don't change: ";
            $replace_by[$count] = $replace_what[$count];
        }
        //$message .= "In tag $count, <b>" . htmlentities($tag_list[$count])
        //	. "</b>, parameter <b>" . $replace_what[$count] . "</b> replaced by <b>" . $replace_by[$count] . "</b><br>"; //debug
    }
    //if ($message) api_display_debug_info($message); //debug
    $buffer = str_replace($replace_what, $replace_by, $buffer);

    return $buffer;
}

/**
 * Checks the extension of a file, if it's .htm or .html
 * we use search_img_from_html to get all image paths in the file
 *
 * @param string $file
 * @return array paths
 * @see check_for_missing_files() uses search_img_from_html()
 */
function check_for_missing_files($file)
{
    if (strrchr($file, '.') == '.htm' || strrchr($file, '.') == '.html') {
        $img_file_path = search_img_from_html($file);
        return $img_file_path;
    }
    return false;
}

/**
 * This function builds a form that asks for the missing images in a html file
 * maybe we should do this another way?
 *
 * @param array $missing_files
 * @param string $upload_path
 * @param string $file_name
 * @return string the form
 */
function build_missing_files_form($missing_files, $upload_path, $file_name)
{
    // Do we need a / or not?
    $added_slash = ($upload_path == '/') ? '' : '/';
    $folder_id      = DocumentManager::get_document_id(api_get_course_info(), $upload_path);
    // Build the form
    $form = "<p><strong>".get_lang('MissingImagesDetected')."</strong></p>"
        ."<form method=\"post\" action=\"".api_get_self()."\" enctype=\"multipart/form-data\">"
        // Related_file is the path to the file that has missing images
        ."<input type=\"hidden\" name=\"related_file\" value=\"".$upload_path.$added_slash.$file_name."\" />"
        ."<input type=\"hidden\" name=\"upload_path\" value=\"".$upload_path."\" />"
        ."<input type=\"hidden\" name=\"id\" value=\"".$folder_id."\" />"
        ."<table border=\"0\">";
    foreach ($missing_files as & $this_img_file_path) {
        $form .= "<tr>"
            ."<td>".basename($this_img_file_path)." : </td>"
            ."<td>"
            ."<input type=\"file\" name=\"img_file[]\"/>"
            ."<input type=\"hidden\" name=\"img_file_path[]\" value=\"".$this_img_file_path."\" />"
            ."</td>"
            ."</tr>";
    }
    $form .= "</table>"
        ."<button type='submit' name=\"cancel_submit_image\" value=\"".get_lang('Cancel')."\" class=\"cancel\">".get_lang('Cancel')."</button>"
        ."<button type='submit' name=\"submit_image\" value=\"".get_lang('Ok')."\" class=\"save\">".get_lang('Ok')."</button>"
        ."</form>";
    return $form;
}

/**
 * This recursive function can be used during the upgrade process form older
 * versions of Chamilo
 * It crawls the given directory, checks if the file is in the DB and adds
 * it if it's not
 *
 * @param array $courseInfo
 * @param array $userInfo
 * @param string $base_work_dir
 * @param string $folderPath
 * @param int $sessionId
 * @param int $groupId
 * @param bool $output
 * @param array $parent
 * @param string $uploadPath
 *
 */
function add_all_documents_in_folder_to_database(
    $courseInfo,
    $userInfo,
    $base_work_dir,
    $folderPath,
    $sessionId = 0,
    $groupId = 0,
    $output = false,
    $parent = array()
) {
    if (empty($userInfo) || empty($courseInfo)) {
        return false;
    }

    $userId = $userInfo['user_id'];

    // Open dir
    $handle = opendir($folderPath);
    $files = array();

    if (is_dir($folderPath)) {
        // Run trough
        while ($file = readdir($handle)) {

            if ($file == '.' || $file == '..') {
                continue;
            }

            $parentPath = null;

            if (!empty($parent) && isset($parent['path'])) {
                $parentPath = $parent['path'];
                if ($parentPath == '/') {
                    $parentPath = null;
                }
            }

            $completePath = $parentPath.'/'.$file;

            $sysFolderPath = $folderPath.'/'.$file;

            // Is directory?
            if (is_dir($sysFolderPath)) {
                $newFolderData = create_unexisting_directory(
                    $courseInfo,
                    $userId,
                    $sessionId,
                    $groupId,
                    null,
                    $base_work_dir,
                    $completePath,
                    null,
                    null,
                    true
                );
                $files[$file] = $newFolderData;

                // Recursive
                add_all_documents_in_folder_to_database(
                    $courseInfo,
                    $userInfo,
                    $base_work_dir,
                    $sysFolderPath,
                    $sessionId,
                    $groupId,
                    $output,
                    $newFolderData
                );
            } else {

                // Rename
                $uploadedFile = array(
                    'name' => $file,
                    'tmp_name' => $sysFolderPath,
                    'size' => filesize($sysFolderPath),
                    'type' => null,
                    'from_file' => true,
                    'move_file' => true
                );

                handle_uploaded_document(
                    $courseInfo,
                    $uploadedFile,
                    $base_work_dir,
                    $parentPath,
                    $userId,
                    $groupId,
                    null,
                    0,
                    'overwrite',
                    $output,
                    false,
                    null,
                    $sessionId
                );
            }
        }
    }
}
