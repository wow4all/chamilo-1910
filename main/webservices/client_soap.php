<?php
/* For licensing terms, see /license.txt */

/*
 *
 * 1. This script creates users everytime the page is executed using the Chamilo Webservices
 * 2. The username is generated everytime with a random value from 0 to 1000
 * 3. The default user extra field (profile) is "uid" is created when calling the WSCreateUserPasswordCrypted for the first time, you can change this value.
 *    In this field your third party user_id will be registered. See the main/admin/user_fields.php to view the current user fields.
 * 4. You need to create manually a course called Test(with code TEST) After the user was created the new user will be added to this course via webservices.
 */

exit; //Uncomment this in order to execute the page

require_once '../inc/global.inc.php';
$libpath = api_get_path(LIBRARY_PATH);
require_once $libpath.'nusoap/nusoap.php';

// Create the client instancete one course with code
$url = api_get_path(WEB_CODE_PATH)."webservices/registration.soap.php?wsdl";
//$url = api_get_path(WEB_CODE_PATH)."webservices/lp.php?wsdl";
global $_configuration;
// see the main/inc/configuration.php file to get this value
$security_key = $_configuration['security_key'];

$client = new nusoap_client($url, true);
$client->xml_encoding = 'UTF-8';
$client->http_encoding = 'UTF-8';
$client->charencoding = 'UTF-8';

$soap_error = $client->getError();

if (!empty($soap_error)) {
    $error_message = 'Nusoap object creation failed: ' . $soap_error;
    throw new Exception($error_message);
}

$client->debug_flag = true;
// This should be the IP address of the client
$ip_address = $_SERVER['SERVER_ADDR'];
//$ip_address = "192.168.1.13";

//Secret key
$secret_key = sha1($ip_address.$security_key);// Hash of the combination of IP Address + Chamilo security key

//Creating a random user_id, this values need to be provided from your system
$random_user_id = rand(0, 1000);
//Creating a random username this values need to be provided from your system
$generate_user_name = 'jbrion'.$random_user_id;
//Creating a password (the username)
$generate_password = sha1($generate_user_name);
$user_field = 'external_user_id';
$externalCourseId = 'external_course_id';

/*$file = base64_encode(file_get_contents('/home/jmontoya/Downloads/Oefeningen_Gezondheid_deel_1.zip'));

$params = [
    'secret_key' => $secret_key,
    'file_data' => $file,
    'filename' => 'Oefeningen_Gezondheid_deel_1.zip',
    'course_id_name' => 'external_course_id',
    'course_id_value' => '2',
    'session_id_name' => 'external_session_id',
    'session_id_value' => '1',
];

//1. Create user webservice
$result = $client->call(
    'WSImportLP',
    array('params' => $params)
);


if ($result) {
   // print_r($params);
    echo '<br /><br />';
} else {
    $err = $client->getError();
   // var_dump($result);
    var_dump($err);
}
var_dump($result);exit;
*/

$params = array(
    'firstname' => 'Jon',
    'lastname' => 'Brion',
    'status' => '5', // 5 STUDENT - 1 TEACHER
    'email' => 'jon@example.com',
    'loginname' => $generate_user_name,
    'password' => $generate_password, // encrypted using sha1
    'encrypt_method' => 'sha1',
    'language' => 'english',
    'official_code' => 'official',
    'phone' => '00000000',
    'expiration_date' => '0000-00-00',
    /* the extra user field that will be automatically created
    in the user profile see: main/admin/user_fields.php */
    'original_user_id_name'     => $user_field,
    // third party user id
    'original_user_id_value'    => $random_user_id,
    'secret_key'                => $secret_key,
    //Extra fields
    'extra' => array(
        array('field_name' => 'ruc', 'field_value' => '123'),
        array('field_name' => 'DNI', 'field_value' => '4200000')
    ),
);

//1. Create user webservice
$user_id = $client->call(
    'WSCreateUserPasswordCrypted',
    array('createUserPasswordCrypted' => $params)
);

if (!empty($user_id) && is_numeric($user_id)) {

    // 2. Get user info of the new user
    echo '<h2>Trying to create an user via webservices</h2>';
    $original_params = $params;

    $params = array(
        'original_user_id_value' => $random_user_id,
        // third party user id
        'original_user_id_name' => $user_field,
        // the system field in the user profile (See Profiling)
        'secret_key' => $secret_key,
    );

    $result = $client->call('WSGetUser', array('GetUser' => $params));

    if ($result) {
        echo "Random user was created user_id: $user_id <br /><br />";
        echo 'User info: <br />';
        print_r($original_params);
        echo '<br /><br />';
    } else {
        echo $result;
    }

    //3. Updating user info

    $params = array(
        'firstname' => 'Jon edited',
        'lastname' => 'Brion edited',
        'status' => '5',
        // STUDENT
        'email' => 'jon@example.com',
        'username' => $generate_user_name,
        'password' => $generate_password,
        // encrypted using sha1
        'encrypt_method' => 'sha1',
        'phone' => '00000000',
        'expiration_date' => '0000-00-00',
        'original_user_id_name' => $user_field, // the extra user field that will be automatically created in the user profile see: main/admin/user_fields.php
        'original_user_id_value' => $random_user_id, // third party user id
        'secret_key' => $secret_key,
        'extra' => array(
            array('field_name' => 'ruc', 'field_value' => '666 edited'),
            array('field_name' => 'DNI', 'field_value' => '888 edited'),
        )
    );
    $result = $client->call('WSEditUserPasswordCrypted', array('editUserPasswordCrypted' => $params));

    if ($result) {
        echo "Random user was update user_id: $user_id <br /><br />";
        echo 'User info: <br />';
        print_r($params);
        echo '<br /><br />';
    } else {
        $err = $client->getError();
        var_dump($result);
        var_dump($err);
    }

    $params = array(
        'ids' => array(
            array(
                'original_user_id_name' => $user_field,
                'original_user_id_value' => $random_user_id
            )
        ),
        'secret_key' => $secret_key
    );

    // Disable user
    echo "Random user was disable user_id: $user_id <br /><br />";
    $result = $client->call('WSDisableUsers', array('user_ids' => $params));

    // Enable user
    $result = $client->call('WSEnableUsers', array('user_ids' => $params));

    echo "Random user was enable user_id: $user_id <br /><br />";

    $externalCourseIdValue = '666'.$random_user_id;

    //4 Creating course TEST123
    $params = array(
        'courses' => array(
            array(
                'title' => 'PRUEBA '.$random_user_id, //Chamilo string course code
                'category_code' => 'LANG',
                'wanted_code' => $externalCourseIdValue,
                'course_language' => 'english',
                'original_course_id_name' => $externalCourseId,
                'original_course_id_value' => $externalCourseIdValue,
            )
        ),
        'secret_key'=> $secret_key,
    );

    //5 .Adding user to the course TEST. The course TEST must be created manually in Chamilo
    echo "<h2>Trying to Create course called $externalCourseIdValue via webservices</h2>";
    var_dump($params);

    $result = $client->call('WSCreateCourse', array('createCourse' => $params));

    //5 .Adding user to the course TEST. The course TEST must be created manually in Chamilo
    echo "<h2>Trying to add user to a course called $externalCourseIdValue via webservices</h2>";

    $course_info = api_get_course_info($externalCourseIdValue);

    if (!empty($course_info)) {
        $params = array(
            'course' => $externalCourseIdValue, //Chamilo string course code
            'user_id' => $user_id,
            'secret_key' => $secret_key,
        );
        $result = $client->call('WSSubscribeUserToCourseSimple', array('subscribeUserToCourseSimple' => $params));
        echo "Course $externalCourseIdValue was created<br/>";
    } else {
        echo "Course $externalCourseIdValue does not exists<br/>";
        exit;
    }

    if ($result == 1) {
        echo "User $user_id was added to course $externalCourseIdValue<br/>";
    } else {
        echo $result;
    }

    $params = [
        'original_user_id_value'   => $random_user_id,
        'original_user_id_name'     => $user_field,
        'original_course_id_value'  => $externalCourseIdValue,
        'original_course_id_name'   => $externalCourseId,
        'secret_key' => $secret_key,
    ];

    //5 .Adding user to the course TEST. The course TEST must be created manually in Chamilo
    echo "<h2>Trying to remove user to a course called $externalCourseIdValue via webservices</h2>";

    $result = $client->call('WSUnSubscribeUserFromCourseSimple', array('unSubscribeUserFromCourseSimple' => $params));

    if ($result) {
        var_dump($result);
        echo 'User removed from course';
    } else {
        $err = $client->getError();
        var_dump($result);
        var_dump($err);
    }

    exit;



    //4. Adding course Test to the Session Session1

    $course_id_list = array(
        array('course_code' => 'TEST1'),
        array('course_code' => 'TEST2'),
    );
    $params = array(
        'coursessessions' => array(
            array(
                'original_course_id_values' => $course_id_list,
                'original_course_id_name' => 'course_id_name',
                'original_session_id_value' => '1',
                'original_session_id_name' => 'session_id_value',
            ),
        ),
        'secret_key' => $secret_key,
    );

    //$result = $client->call('WSSuscribeCoursesToSession', array('subscribeCoursesToSession' => $params));

    // ------------------------
    //Calling the WSSubscribeUserToCourse

    $course_array = array(
        'original_course_id_name' => 'TEST',
        'original_course_id_value' => 'TEST',
    );

    $user_array = array(
        'original_user_id_value' => $user_id,
        'original_user_id_name' => 'name',
    );
    $user_courses = array();

    $user_courses[] = array(
        'course_id' => $course_array,
        'user_id' => $user_array,
        'status' => '1',
    );

    $params = array(
        'userscourses' => $user_courses,
        'secret_key' => $secret_key,
    );

    $result = $client->call(
        'WSSubscribeUserToCourse',
        array('subscribeUserToCourse' => $params)
    );
    var_dump($result);

} else {
    echo 'User was not created, activate the debug=true in the registration.soap.php file and see the error logs';
}

// Check for an error
$err = $client->getError();

if ($err) {
    // Display the error
    echo '<h2>Constructor error</h2><pre>' . $err . '</pre>';
}

if ($client->fault) {
    echo '<h2>Fault</h2><pre>';
    print_r($result);
    echo '</pre>';
} else {
    // Check for errors
    $err = $client->getError();
    if ($err) {
        // Display the error
        echo '<h2>Error</h2><pre>' . $err . '</pre>';
    } else {
        // Display the result
        echo '<h2>There are no errors</h2>';
        var_dump($result);
    }
}
