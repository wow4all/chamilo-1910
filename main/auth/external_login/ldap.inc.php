<?php

// External login module : LDAP
/**
 * This files is included by newUser.ldap.php and login.ldap.php
 * It implements the functions nedded by both files
 * */
//Includes the configuration file
require_once dirname(__FILE__).'/../../inc/global.inc.php';
require_once dirname(__FILE__).'/../../inc/conf/auth.conf.php';

/**
 * Returns a transcoded and trimmed string
 *
 * @param string
 * @return string
 * @author ndiechburg <noel@cblue.be>
 * */
function extldap_purify_string($string)
{
    global $extldap_config;
    if (isset($extldap_config['encoding'])) {
        return trim(api_to_system_encoding($string, $extldap_config['encoding']));
    } else {
        return trim($string);
    }
}

/**
 * Establishes a connection to the LDAP server and sets the protocol version
 *
 * @return resource ldap link identifier or false
 * @author ndiechburg <noel@cblue.be>
 * */
function extldap_connect()
{
    global $extldap_config;

    if (!is_array($extldap_config['host'])) {
        $extldap_config['host'] = array($extldap_config['host']);
    }

    foreach ($extldap_config['host'] as $host) {
        //Trying to connect
        if (isset($extldap_config['port'])) {
            $ds = ldap_connect($host, $extldap_config['port']);
        } else {
            $ds = ldap_connect($host);
        }
        if (!$ds) {
            $port = isset($extldap_config['port']) ? $ldap_config['port'] : 389;
            error_log('EXTLDAP ERROR : cannot connect to '.$extldap_config['host'].':'.$port);
        } else {
            break;
        }
    }
    if (!$ds) {
        error_log('EXTLDAP ERROR : no valid server found');
        return false;
    }
    //Setting protocol version
    if (isset($extldap_config['protocol_version'])) {
        if (!ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, $extldap_config['protocol_version'])) {
            ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 2);
        }
    }

    //Setting protocol version
    if (isset($extldap_config['referrals'])) {
        if (!ldap_set_option($ds, LDAP_OPT_REFERRALS, $extldap_config['referrals'])) {
            ldap_set_option($ds, LDAP_OPT_REFERRALS, $extldap_config['referrals']);
        }
    }

    return $ds;
}

/**
 * Authenticate user on external ldap server and return user ldap entry if that succeeds
 *
 * @return mixed false if user cannot authenticate on ldap, user ldap entry if tha succeeds
 * @author ndiechburg <noel@cblue.be>
 * Modified by hubert.borderiou@grenet.fr
 * Add possibility to get user info from LDAP without check password (if CAS auth and LDAP profil update)
 *
 * */
function extldap_authenticate($username, $password, $in_auth_with_no_password = false)
{
    global $extldap_config;

    if (empty($username) or empty($password)) {
        return false;
    }

    $ds = extldap_connect();
    if (!$ds) {
        return false;
    }

    //Connection as admin to search dn of user
    $ldapbind = @ldap_bind($ds, $extldap_config['admin_dn'], $extldap_config['admin_password']);
    if ($ldapbind === false) {
        error_log('EXTLDAP ERROR : cannot connect with admin login/password');
        return false;
    }

    $useExtraField = api_get_configuration_value('ldap_use_extra_field');
    if (!empty($useExtraField)) {
        $criteria = $username;
        if (preg_match('/@/', $username)) {
            // nothing
        } else {
            $user = api_get_user_info_from_username($username);
            $extra = UserManager::get_extra_user_data_by_field($user['user_id'], $useExtraField, true);

            if (!empty($extra['extra_'.$useExtraField])) {
                $criteria = $extra['extra_'.$useExtraField];
            } else {
                $criteria = $user['email'];
            }
        }
        $user_search = extldap_get_user_search_string($criteria);
    } else {
        $user_search = extldap_get_user_search_string($username);
    }
    //Search distinguish name of user
    $sr = ldap_search($ds, $extldap_config['base_dn'], $user_search);
    if (!$sr) {
        error_log('EXTLDAP ERROR : ldap_search('.$ds.', '.$extldap_config['base_dn'].", $user_search) failed");
        return false;
    }
    $entries_count = ldap_count_entries($ds, $sr);

    if ($entries_count > 1) {
        error_log(
            'EXTLDAP ERROR : more than one entry for that user ( ldap_search(ds, '.$extldap_config['base_dn'].", $user_search) )"
        );
        return false;
    }
    if ($entries_count < 1) {
        error_log(
            'EXTLDAP ERROR :  No entry for that user ( ldap_search(ds, '.$extldap_config['base_dn'].", $user_search) )"
        );
        return false;
    }
    $users = ldap_get_entries($ds, $sr);
    $user  = $users[0];

    // If we just want to have user info from LDAP and not to check password
    if ($in_auth_with_no_password) {
        return $user;
    }
    //now we try to autenthicate the user in the ldap
    $ubind = @ldap_bind($ds, $user['dn'], $password);
    if ($ubind !== false) {
        return $user;
    } else {
        error_log('EXTLDAP : Wrong password for '.$user['dn']);
        return false;
    }
}

/**
 * Return an array with userinfo compatible with chamilo using $extldap_user_correspondance
 * configuration array declared in ldap.conf.php file
 *
 * @param array ldap user
 * @param array correspondance array (if not set use extldap_user_correspondance declared in auth.conf.php
 * @return array userinfo array
 * @author ndiechburg <noel@cblue.be>
 * */
function extldap_get_chamilo_user($ldap_user, $cor = null)
{
    global $extldap_user_correspondance;
    if (is_null($cor)) {
        $cor = $extldap_user_correspondance;
    }

    $chamilo_user = array();
    foreach ($cor as $chamilo_field => $ldap_field) {
        if (is_array($ldap_field)) {
            $chamilo_user[$chamilo_field] = extldap_get_chamilo_user($ldap_user, $ldap_field);
            continue;
        }

        switch ($ldap_field) {
            case 'func':
                $func = "extldap_get_$chamilo_field";
                if (function_exists($func)) {
                    $chamilo_user[$chamilo_field] = extldap_purify_string($func($ldap_user));
                } else {
                    error_log("EXTLDAP WARNING : You forgot to declare $func");
                }
                break;
            default:
                //if string begins with "!", then this is a constant
                if ($ldap_field[0] === '!') {
                    $chamilo_user[$chamilo_field] = trim($ldap_field, "!\t\n\r\0");
                    break;
                }
                if (isset($ldap_user[$ldap_field][0])) {
                    $chamilo_user[$chamilo_field] = extldap_purify_string($ldap_user[$ldap_field][0]);
                } else {
                    error_log('EXTLDAP WARNING : '.$ldap_field.'[0] field is not set in ldap array');
                }
                break;
        }
    }
    return $chamilo_user;
}

/**
 * Please declare here all the function you use in extldap_user_correspondance
 * All these functions must have an $ldap_user parameter. This parameter is the
 * array returned by the ldap for the user
 * */

/**
 * example function for email
 * */
/*
  function extldap_get_email($ldap_user){
  return $ldap_user['cn'].$ldap['sn'].'@gmail.com';
  }
 */
function extldap_get_status($ldap_user)
{
    return STUDENT;
}

function extldap_get_admin($ldap_user)
{
    return false;
}

/**
 * return the string used to search a user in ldap
 *
 * @param string username
 * @return string the serach string
 * @author ndiechburg <noel@cblue.be>
 * */
function extldap_get_user_search_string($username)
{
    global $extldap_config;
    // init
    $filter = '('.$extldap_config['user_search'].')';
    // replacing %username% by the actual username
    $filter = str_replace('%username%', $username, $filter);
    // append a global filter if needed
    if (isset($extldap_config['filter']) && $extldap_config['filter'] != "") {
        $filter = '(&'.$filter.'('.$extldap_config['filter'].'))';
    }

    return $filter;
}

/**
 * Imports all LDAP users into Chamilo
 * @return bool false on error, true otherwise
 */
function extldap_import_all_users()
{
    global $extldap_config;
    //echo "Connecting...\n";
    $ds = extldap_connect();
    if (!$ds) {
        return false;
    }
    //echo "Binding...\n";
    $ldapbind = false;
    //Connection as admin to search dn of user
    $ldapbind = @ldap_bind($ds, $extldap_config['admin_dn'], $extldap_config['admin_password']);
    if ($ldapbind === false) {
        error_log('EXTLDAP ERROR : cannot connect with admin login/password');
        return false;
    }
    //browse ASCII values from a to z to avoid 1000 results limit of LDAP
    $count    = 0;
    $alphanum = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
    for ($a = 97; $a <= 122; $a++) {
        $alphanum[] = chr($a);
    }
    foreach ($alphanum as $char1) {
        foreach ($alphanum as $char2) {
            //$user_search = "uid=*";
            $user_search = "sAMAccountName=$char1$char2*";
            //Search distinguish name of user
            $sr = ldap_search($ds, $extldap_config['base_dn'], $user_search);
            if (!$sr) {
                error_log('EXTLDAP ERROR : ldap_search('.$ds.', '.$extldap_config['base_dn'].", $user_search) failed");
                return false;
            }
            //echo "Getting entries\n";
            $users = ldap_get_entries($ds, $sr);
            //echo "Entries: ".$users['count']."\n";
            for ($key = 0; $key < $users['count']; $key++) {
                $user_id = extldap_add_user_by_array($users[$key], true);
                $count++;
                if ($user_id) {
                    // echo "User #$user_id created or updated\n";
                } else {
                    // echo "User was not created\n";
                }
            }
        }
    }
    //echo "Found $count users in total\n";
    @ldap_close($ds);
}

/**
 * Insert users from an array of user fields
 */
function extldap_add_user_by_array($data, $update_if_exists = true)
{
    global $extldap_user_correspondance;

    $lastname  = api_convert_encoding($data[$extldap_user_correspondance['lastname']][0], api_get_system_encoding(), 'UTF-8');
    $firstname = api_convert_encoding($data[$extldap_user_correspondance['firstname']][0], api_get_system_encoding(), 'UTF-8');
    $email     = $data[$extldap_user_correspondance['email']][0];
    $username  = $data[$extldap_user_correspondance['username']][0];

    // TODO the password, if encrypted at the source, will be encrypted twice, which makes it useless. Try to fix that.
    $passwordKey = isset($extldap_user_correspondance['password']) ? $extldap_user_correspondance['password'] : 'userPassword';
    $password        = $data[$passwordKey][0];

    // Structure
  /*  $structure       = $data['edupersonprimaryorgunitdn'][0];
    $array_structure = explode(",", $structure);
    $array_val       = explode("=", $array_structure[0]);
    $etape           = $array_val[1];
    $array_val       = explode("=", $array_structure[1]);
    $annee           = $array_val[1];
*/
    // To ease management, we add the step-year (etape-annee) code
    //$official_code = $etape."-".$annee;
    $official_code = api_convert_encoding($data[$extldap_user_correspondance['official_code']][0], api_get_system_encoding(), 'UTF-8');
    $auth_source   = 'ldap';

    // No expiration date for students (recover from LDAP's shadow expiry)
    $expiration_date = '0000-00-00 00:00:00';
    $active          = 1;
    if (empty($status)) {
        $status = 5;
    }
    if (empty($phone)) {
        $phone = '';
    }
    if (empty($picture_uri)) {
        $picture_uri = '';
    }
    // Adding user
    $user_id = 0;
    if (UserManager::is_username_available($username)) {
        //echo "$username\n";
        $user_id = UserManager::create_user(
            $firstname,
            $lastname,
            $status,
            $email,
            $username,
            $password,
            $official_code,
            api_get_setting('platformLanguage'),
            $phone,
            $picture_uri,
            $auth_source,
            $expiration_date,
            $active
        );
    } else {
        if ($update_if_exists) {
            $user = UserManager::get_user_info($username);
            $user_id = $user['user_id'];
            //echo "$username\n";
            UserManager::update_user(
                $user_id,
                $firstname,
                $lastname,
                $username,
                null,
                $auth_source,
                $email,
                $status,
                $official_code,
                $phone,
                $picture_uri,
                $expiration_date,
                $active
            );
        }
    }
    return $user_id;
}

