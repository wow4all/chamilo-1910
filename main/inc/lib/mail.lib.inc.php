<?php
/* For licensing terms, see /license.txt */
/**
 *
 * @package chamilo.library
 */
/**
 * Code
 */
require_once api_get_path(LIBRARY_PATH).'phpmailer/class.phpmailer.php';

// A regular expression for testing against valid email addresses.
// It should actually be revised for using the complete RFC3696 description:
// http://tools.ietf.org/html/rfc3696#section-3
//$regexp_rfc3696 = "^[0-9a-z_\.+-]+@(([0-9]{1,3}\.){3}[0-9]{1,3}|([0-9a-z][0-9a-z-]*[0-9a-z]\.)+[a-z]{2,3})$"; // Deprecated, 13-OCT-2010.

/**
 * Sends email using the phpmailer class
 * Sender name and email can be specified, if not specified
 * name and email of the platform admin are used
 *
 * @author Bert Vanderkimpen ICT&O UGent
 *
 * @param recipient_name    name of recipient
 * @param recipient_email   email of recipient
 * @param message           email body
 * @param subject           email subject
 * @return                  returns true if mail was sent
 * @see                     class.phpmailer.php
 * @deprecated use api_mail_html()
 */
function api_mail(
    $recipient_name,
    $recipient_email,
    $subject,
    $message,
    $sender_name = '',
    $sender_email = '',
    $extra_headers = '',
    $additionalParameters = array()
) {
    error_log("api_mail is deprecated. Using api_mail_html() on line ".__LINE__." of [".__FILE__."]");
    return api_mail_html(
        $recipient_name,
        $recipient_email,
        $subject,
        $message,
        $sender_name,
        $sender_email,
        $extra_headers,
        null,
        null,
        $additionalParameters
    );
}

/**
 * Sends an HTML email using the phpmailer class (and multipart/alternative to downgrade gracefully)
 * Sender name and email can be specified, if not specified
 * name and email of the platform admin are used
 *
 * @author Bert Vanderkimpen ICT&O UGent
 * @author Yannick Warnier <yannick.warnier@beeznest.com>
 *
 * @param string    name of recipient
 * @param string    email of recipient
 * @param string    email subject
 * @param string    email body
 * @param string    sender name
 * @param string    sender e-mail
 * @param array     extra headers in form $headers = array($name => $value) to allow parsing
 * @param array     data file (path and filename)
 * @param array     data to attach a file (optional)
 * @param bool      True for attaching a embedded file inside content html (optional)
 * @return          returns true if mail was sent
 * @see             class.phpmailer.php
 */
function api_mail_html(
    $recipient_name,
    $recipient_email,
    $subject,
    $message,
    $senderName = '',
    $senderEmail = '',
    $extra_headers = array(),
    $data_file = array(),
    $embedded_image = false,
    $additionalParameters = array()
) {
    global $platform_email;

    $mail = new PHPMailer();
    $mail->Mailer = $platform_email['SMTP_MAILER'];
    $mail->Host = $platform_email['SMTP_HOST'];
    $mail->Port = $platform_email['SMTP_PORT'];
    $mail->CharSet = $platform_email['SMTP_CHARSET'];
    // Stay far below SMTP protocol 980 chars limit.
    $mail->WordWrap = 200;

    if ($platform_email['SMTP_AUTH']) {
        $mail->SMTPAuth = 1;
        $mail->Username = $platform_email['SMTP_USER'];
        $mail->Password = $platform_email['SMTP_PASS'];
    }

    // 5 = low, 1 = high
    $mail->Priority = 3;
    $mail->SMTPKeepAlive = true;

    // Default values
    $notification = new Notification();
    $defaultEmail = $notification->getDefaultPlatformSenderEmail();
    $defaultName = $notification->getDefaultPlatformSenderName();

    // Error to admin.
    $mail->AddCustomHeader('Errors-To: '.$defaultEmail);

    // If the parameter is set don't use the admin.
    $senderName = !empty($senderName) ? $senderName : $defaultEmail;
    $senderEmail = !empty($senderEmail) ? $senderEmail : $defaultName;

    // Reply to first
    if (isset($extra_headers['reply_to'])) {
        $mail->AddReplyTo(
            $extra_headers['reply_to']['mail'],
            $extra_headers['reply_to']['name']
        );
        $mail->Sender = $extra_headers['reply_to']['mail'];
        unset($extra_headers['reply_to']);
    }

    $mail->SetFrom($senderEmail, $senderName);

    $mail->Subject = $subject;
    $mail->AltBody = strip_tags(
        str_replace('<br />', "\n", api_html_entity_decode($message))
    );

    // Send embedded image.
    if ($embedded_image) {
        // Get all images html inside content.
        preg_match_all("/<img\s+.*?src=[\"\']?([^\"\' >]*)[\"\']?[^>]*>/i", $message, $m);
        // Prepare new tag images.
        $new_images_html = array();
        $i = 1;
        if (!empty($m[1])) {
            foreach ($m[1] as $image_path) {
                $real_path = realpath($image_path);
                $filename  = basename($image_path);
                $image_cid = $filename.'_'.$i;
                $encoding = 'base64';
                $image_type = mime_content_type($real_path);
                $mail->AddEmbeddedImage(
                    $real_path,
                    $image_cid,
                    $filename,
                    $encoding,
                    $image_type
                );
                $new_images_html[] = '<img src="cid:'.$image_cid.'" />';
                $i++;
            }
        }

        // Replace origin image for new embedded image html.
        $x = 0;
        if (!empty($m[0])) {
            foreach ($m[0] as $orig_img) {
                $message = str_replace($orig_img, $new_images_html[$x], $message);
                $x++;
             }
        }
    }
    $message = str_replace(array("\n\r", "\n", "\r"), '<br />', $message);
    $mail->Body = '<html><head></head><body>'.$message.'</body></html>';

    // Attachment ...
    if (!empty($data_file)) {
        $mail->AddAttachment($data_file['path'], $data_file['filename']);
    }

    // Only valid addresses are accepted.
    if (is_array($recipient_email)) {
        foreach ($recipient_email as $dest) {
            if (api_valid_email($dest)) {
                $mail->AddAddress($dest, $recipient_name);
            }
        }
    } else {
        if (api_valid_email($recipient_email)) {
            $mail->AddAddress($recipient_email, $recipient_name);
        } else {
            return 0;
        }
    }

    if (is_array($extra_headers) && count($extra_headers) > 0) {
        foreach ($extra_headers as $key => $value) {
            switch (strtolower($key)) {
                case 'encoding':
                case 'content-transfer-encoding':
                    $mail->Encoding = $value;
                    break;
                case 'charset':
                    $mail->Charset = $value;
                    break;
                case 'contenttype':
                case 'content-type':
                    $mail->ContentType = $value;
                    break;
                default:
                    $mail->AddCustomHeader($key.':'.$value);
                    break;
            }
        }
    } else {
        if (!empty($extra_headers)) {
            $mail->AddCustomHeader($extra_headers);
        }
    }

    // WordWrap the html body (phpMailer only fixes AltBody) FS#2988
    $mail->Body = $mail->WrapText($mail->Body, $mail->WordWrap);
    // Send the mail message.
    if (!$mail->Send()) {
        error_log('ERROR: mail not sent to '.$recipient_name.' ('.$recipient_email.') because of '.$mail->ErrorInfo.'<br />');
        return 0;
    }

    $plugin = new AppPlugin();
    $installedPluginsList = $plugin->getInstalledPluginListObject();
    foreach ($installedPluginsList as $installedPlugin) {
        if ($installedPlugin->isMailPlugin and array_key_exists("smsType", $additionalParameters)) {
            $className = str_replace("Plugin", "", get_class($installedPlugin));
            $smsObject = new $className;
            $smsObject->send($additionalParameters);
        }
    }

    // Clear all the addresses.
    $mail->ClearAddresses();
    return 1;
}
