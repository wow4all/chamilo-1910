<?php
/* For licensing terms, see /license.txt */

/**
 * Notification class
 * This class provides methods for the Notification management.
 * Include/require it in your code to use its features.
 * @package chamilo.library
 */
class Notification extends Model
{
    public $table;
    public $columns = array(
        'id',
        'dest_user_id',
        'dest_mail',
        'title',
        'content',
        'send_freq',
        'created_at',
        'sent_at'
    );

    //Max length of the notification.content field
    public $max_content_length = 254;
    public $debug = false;

    /* message, invitation, group messages */
    public $type;
    public $adminName;
    public $adminEmail;
    public $titlePrefix;

    //mail_notify_message ("At once", "Daily", "No")
    const NOTIFY_MESSAGE_AT_ONCE = 1;
    const NOTIFY_MESSAGE_DAILY = 8;
    const NOTIFY_MESSAGE_WEEKLY = 12;
    const NOTIFY_MESSAGE_NO = 0;

    //mail_notify_invitation ("At once", "Daily", "No")
    const NOTIFY_INVITATION_AT_ONCE = 1;
    const NOTIFY_INVITATION_DAILY = 8;
    const NOTIFY_INVITATION_WEEKLY = 12;
    const NOTIFY_INVITATION_NO = 0;

    // mail_notify_group_message ("At once", "Daily", "No")
    const NOTIFY_GROUP_AT_ONCE = 1;
    const NOTIFY_GROUP_DAILY = 8;
    const NOTIFY_GROUP_WEEKLY = 12;
    const NOTIFY_GROUP_NO = 0;
    const NOTIFICATION_TYPE_MESSAGE = 1;
    const NOTIFICATION_TYPE_INVITATION = 2;
    const NOTIFICATION_TYPE_GROUP = 3;
    const NOTIFICATION_TYPE_WALL_MESSAGE = 4;
    const NOTIFICATION_TYPE_DIRECT_MESSAGE = 5;

    /**
     *
     */
    public function __construct()
    {
        $this->table = Database::get_main_table(TABLE_NOTIFICATION);
        // Default no-reply email
        $this->adminEmail = api_get_setting('noreply_email_address');
        $this->adminName = api_get_setting('siteName');
        $this->titlePrefix = '['.api_get_setting('siteName').'] ';

        // If no-reply email doesn't exist use the admin name/email
        if (empty($this->adminEmail)) {
            $this->adminEmail = api_get_setting('emailAdministrator');
            $this->adminName = api_get_person_name(
                api_get_setting('administratorName'),
                api_get_setting('administratorSurname'),
                null,
                PERSON_NAME_EMAIL_ADDRESS
            );
        }
    }

    /**
     * @return string
     */
    public function getTitlePrefix()
    {
        return $this->titlePrefix;
    }

    /**
     * @return string
     */
    public function getDefaultPlatformSenderEmail()
    {
        return $this->adminEmail;
    }

    /**
     * @return string
     */
    public function getDefaultPlatformSenderName()
    {
        return $this->adminName;
    }

    /**
     *  Send the notifications
     *  @param int notification frequency
     */
    public function send($frequency = 8)
    {
        $notifications = $this->find(
            'all',
            array('where' => array('sent_at IS NULL AND send_freq = ?' => $frequency))
        );

        if (!empty($notifications)) {
            foreach ($notifications as $item_to_send) {
                // Sending email
                api_mail_html(
                    $item_to_send['dest_mail'],
                    $item_to_send['dest_mail'],
                    Security::filter_terms($item_to_send['title']),
                    Security::filter_terms($item_to_send['content']),
                    $this->adminName,
                    $this->adminEmail
                );
                if ($this->debug) {
                    error_log('Sending message to: '.$item_to_send['dest_mail']);
                }

                // Updating
                $item_to_send['sent_at'] = api_get_utc_datetime();
                $this->update($item_to_send);
                if ($this->debug) {
                    error_log('Updating record : '.print_r($item_to_send, 1));
                }
            }
        }
    }

    /**
     * @param string $title
     * @param array $senderInfo
     *
     * @return string
     */
    public function formatTitle($title, $senderInfo)
    {
        $newTitle = $this->getTitlePrefix();

        switch ($this->type) {
            case self::NOTIFICATION_TYPE_MESSAGE:
                if (!empty($senderInfo)) {
                    $senderName = api_get_person_name(
                        $senderInfo['firstname'],
                        $senderInfo['lastname'],
                        null,
                        PERSON_NAME_EMAIL_ADDRESS
                    );
                    $newTitle .= sprintf(get_lang('YouHaveANewMessageFromX'), $senderName);
                }
                break;
            case self::NOTIFICATION_TYPE_DIRECT_MESSAGE:
                $newTitle = $title;
                break;
            case self::NOTIFICATION_TYPE_INVITATION:
                if (!empty($senderInfo)) {
                    $senderName = api_get_person_name(
                        $senderInfo['firstname'],
                        $senderInfo['lastname'],
                        null,
                        PERSON_NAME_EMAIL_ADDRESS
                    );
                    $newTitle .= sprintf(get_lang('YouHaveANewInvitationFromX'), $senderName);
                }
                break;
            case self::NOTIFICATION_TYPE_GROUP:
                if (!empty($senderInfo)) {
                    $senderName = $senderInfo['group_info']['name'];
                    $newTitle .= sprintf(get_lang('YouHaveReceivedANewMessageInTheGroupX'), $senderName);
                    $senderName = api_get_person_name(
                        $senderInfo['user_info']['firstname'],
                        $senderInfo['user_info']['lastname'],
                        null,
                        PERSON_NAME_EMAIL_ADDRESS
                    );
                    $newTitle .= $senderName;
                }
                break;
        }

        return $newTitle;
    }

    /**
     * Save message notification
     * @param	int	$type message type
     * NOTIFICATION_TYPE_MESSAGE,
     * NOTIFICATION_TYPE_INVITATION,
     * NOTIFICATION_TYPE_GROUP
     * @param	array	$user_list recipients: user list of ids
     * @param	string	$title
     * @param	string	$content
     * @param	array	$sender_info
     * result of api_get_user_info() or GroupPortalManager:get_group_data()
     */
    public function save_notification(
        $type,
        $user_list,
        $title,
        $content,
        $senderInfo = array()
    ) {
        $this->type = intval($type);
        $content = $this->formatContent($content, $senderInfo);
        $titleToNotification = $this->formatTitle($title, $senderInfo);

        $setting_to_check = '';
        $avoid_my_self = false;

        switch ($this->type) {
            case self::NOTIFICATION_TYPE_DIRECT_MESSAGE:
            case self::NOTIFICATION_TYPE_MESSAGE:
                $setting_to_check = 'mail_notify_message';
                $defaultStatus = self::NOTIFY_MESSAGE_AT_ONCE;
                break;
            case self::NOTIFICATION_TYPE_INVITATION:
                $setting_to_check = 'mail_notify_invitation';
                $defaultStatus = self::NOTIFY_INVITATION_AT_ONCE;
                break;
            case self::NOTIFICATION_TYPE_GROUP:
                $setting_to_check = 'mail_notify_group_message';
                $defaultStatus = self::NOTIFY_GROUP_AT_ONCE;
                $avoid_my_self = true;
                break;
            default:
                $defaultStatus = self::NOTIFY_MESSAGE_AT_ONCE;
                break;
        }

        $settingInfo = UserManager::get_extra_field_information_by_name($setting_to_check);

        if (!empty($user_list)) {
            foreach ($user_list as $user_id) {
                if ($avoid_my_self) {
                    if ($user_id == api_get_user_id()) {
                        continue;
                    }
                }
                $userInfo = api_get_user_info($user_id);

                // Extra field was deleted or removed? Use the default status.
                if (empty($settingInfo)) {
                    $userSetting = $defaultStatus;
                } else {
                    $extra_data = UserManager::get_extra_user_data($user_id);
                    $userSetting = $extra_data[$setting_to_check];
                }

                $sendDate = null;
                switch ($userSetting) {
                    // No notifications
                    case self::NOTIFY_MESSAGE_NO:
                    case self::NOTIFY_INVITATION_NO:
                    case self::NOTIFY_GROUP_NO:
                        break;
                    // Send notification right now!
                    case self::NOTIFY_MESSAGE_AT_ONCE:
                    case self::NOTIFY_INVITATION_AT_ONCE:
                    case self::NOTIFY_GROUP_AT_ONCE:

                        $extraHeaders = array(
                            'reply_to' => array(
                                'name' => $senderInfo['complete_name'],
                                'mail' => $senderInfo['email']
                            )
                        );

                        if (!empty($userInfo['email'])) {
                            api_mail_html(
                                $userInfo['complete_name'],
                                $userInfo['mail'],
                                Security::filter_terms($titleToNotification),
                                Security::filter_terms($content),
                                $this->adminName,
                                $this->adminEmail,
                                $extraHeaders
                            );
                        }
                        $sendDate = api_get_utc_datetime();
                }

                // Saving the notification to be sent some day.
                $params = array(
                    'sent_at' => $sendDate,
                    'dest_user_id' => $user_id,
                    'dest_mail' => $userInfo['email'],
                    'title' => $title,
                    'content' => cut($content, $this->max_content_length),
                    'send_freq' => $userSetting
                );

                $this->save($params);
            }
        }
    }

    /**
     * Formats the content in order to add the welcome message,
     * the notification preference, etc
     * @param   string 	$content
     * @param   array	$sender_info result of api_get_user_info() or
     * GroupPortalManager:get_group_data()
     * @return string
     * */
    public function formatContent($content, $sender_info)
    {
        $new_message_text = $link_to_new_message = '';

        switch ($this->type) {
            case self::NOTIFICATION_TYPE_DIRECT_MESSAGE:
                $new_message_text = $content;
                $link_to_new_message = Display::url(
                    get_lang('SeeMessage'),
                    api_get_path(WEB_CODE_PATH) . 'messages/inbox.php'
                );
                break;
            case self::NOTIFICATION_TYPE_MESSAGE:
                if (!empty($sender_info)) {
                    $senderName = api_get_person_name(
                        $sender_info['firstname'],
                        $sender_info['lastname'],
                        null,
                        PERSON_NAME_EMAIL_ADDRESS
                    );
                    $new_message_text = sprintf(get_lang('YouHaveANewMessageFromX'), $senderName);
                }
                $link_to_new_message = Display::url(
                    get_lang('SeeMessage'),
                    api_get_path(WEB_CODE_PATH) . 'messages/inbox.php'
                );
                break;
            case self::NOTIFICATION_TYPE_INVITATION:
                if (!empty($sender_info)) {
                    $senderName = api_get_person_name(
                        $sender_info['firstname'],
                        $sender_info['lastname'],
                        null,
                        PERSON_NAME_EMAIL_ADDRESS
                    );
                    $new_message_text = sprintf(get_lang('YouHaveANewInvitationFromX'), $senderName);
                }
                $link_to_new_message = Display::url(
                    get_lang('SeeInvitation'),
                    api_get_path(WEB_CODE_PATH) . 'social/invitations.php'
                );
                break;
            case self::NOTIFICATION_TYPE_GROUP:
                $topic_page = intval($_REQUEST['topics_page_nr']);
                if (!empty($sender_info)) {
                    $senderName = $sender_info['group_info']['name'];
                    $new_message_text = sprintf(get_lang('YouHaveReceivedANewMessageInTheGroupX'), $senderName);
                    $senderName = api_get_person_name(
                        $sender_info['user_info']['firstname'],
                        $sender_info['user_info']['lastname'],
                        null,
                        PERSON_NAME_EMAIL_ADDRESS
                    );
                    $senderName = Display::url(
                        $senderName,
                        api_get_path(WEB_CODE_PATH).'social/profile.php?'.$sender_info['user_info']['user_id']
                    );
                    $new_message_text .= '<br />'.get_lang('User').': '.$senderName;
                }
                $group_url = api_get_path(WEB_CODE_PATH).'social/group_topics.php?id='.$sender_info['group_info']['id'].'&topic_id='.$sender_info['group_info']['topic_id'].'&msg_id='.$sender_info['group_info']['msg_id'].'&topics_page_nr='.$topic_page;
                $link_to_new_message = Display::url(get_lang('SeeMessage'), $group_url);
                break;
        }
        $preference_url = api_get_path(WEB_CODE_PATH).'auth/profile.php';

        // You have received a new message text
        if (!empty($new_message_text)) {
            $content = $new_message_text.'<br /><hr><br />'.$content;
        }

        // See message with link text
        if (!empty($link_to_new_message) && api_get_setting('allow_message_tool') == 'true') {
            $content = $content.'<br /><br />'.$link_to_new_message;
        }

        // You have received this message because you are subscribed text
        $content = $content.'<br /><hr><i>'.
            sprintf(
                get_lang('YouHaveReceivedThisNotificationBecauseYouAreSubscribedOrInvolvedInItToChangeYourNotificationPreferencesPleaseClickHereX'),
                Display::url($preference_url, $preference_url)
            ).'</i>';

        return $content;
    }
}
