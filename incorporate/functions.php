<?php
/*
 * Copyright (C) 2015 Medigraf
 * Waxtotem, 2015.10.02
 *
 */

include_once 'cam_con_ini.php';
include_once 'queryintojson.php';

function getConnection() {
    $dbhost = HOST;
    $dbname = DATABASE;
    $dbh = new PDO("mysql:host=$dbhost;dbname=$dbname", USER, PASSWORD, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $dbh;
}

function sec_session_start() {
    $sessionName = 'CAMCRM';   // Set a custom session name
    $secure = SECURE;

    // This stops JavaScript being able to access the session id.
    $httponly = true;

    // Forces sessions to only use cookies.
    if(ini_set('session.use_only_cookies', 1) === FALSE) {
        header("Location: ../error.php?err=Could not initiate a safe session (ini_set)");
        exit();
    }

    // Gets current cookies params.
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params($cookieParams["lifetime"], $cookieParams["path"], $cookieParams["domain"], $secure, $httponly);

    // Sets the session name to the one set above.
    session_name($sessionName);

    session_start();            // Start the PHP session
    session_regenerate_id();    // regenerated the session, delete the old one.

}

function login($mail, $password) {
    $mail = trim($mail);

    $sql = "SELECT usr.USR_Id,
                   usr.USR_Username,
                   usr.USR_Mail,
                   usr.USR_AGN_Id,
                   COALESCE(agn.AGN_Nombre, 'Administrador') AGN_Nombre,
                   COALESCE(agn.AGN_Logo1, 'admin.png') AGN_Logo1,
                   COALESCE(agn.AGN_Logo2, 'admin.png') AGN_Logo2,
                   usr.USR_Tipo,
                   usr.USR_Password,
                   usr.USR_Salt,
                   COALESCE(agn.AGN_Header, '') AGN_Header,
                   USR_AdminAccess
            FROM camUsuarios usr
            LEFT JOIN camAgencias agn
            ON usr.USR_AGN_Id = agn.AGN_Id
            WHERE USR_Control = :control
            AND USR_Mail = :mail
            LIMIT 1";

    $structure = array(
        'usr_id' => 'USR_Id',
        'usr_username' => 'USR_Username',
        'usr_mail' => 'USR_Mail',
        'usr_agn_id' => 'USR_AGN_Id',
        'usr_agn_name' => 'AGN_Nombre',
        'usr_agn_logo1' => 'AGN_Logo1',
        'usr_agn_logo2' => 'AGN_Logo2',
        'usr_type' => 'USR_Tipo',
        'usr_password' => 'USR_Password',
        'usr_salt' => 'USR_Salt',
        'usr_agn_header' => 'AGN_Header',
        'usr_adm_access' => 'USR_AdminAccess',
    );

    $params = array(
        'control' => 1,
        'mail' => $mail
    );

    $result = restructureQuery($structure, getConnection(), $sql, $params, 0, PDO::FETCH_ASSOC);

    if(count($result)) {
        if(rightResult($result)) {
            $userId = $result[0]['usr_id'];
            $username = $result[0]['usr_username'];
            $email = $result[0]['usr_mail'];
            $agnId = $result[0]['usr_agn_id'];
            $agency = $result[0]['usr_agn_name'];
            $agnLogo1 = $result[0]['usr_agn_logo1'];
            $agnLogo2 = $result[0]['usr_agn_logo2'];
            $type = $result[0]['usr_type'];
            $dbPassword = $result[0]['usr_password'];
            $userSalt = $result[0]['usr_salt'];
            $agnHeader = $result[0]['usr_agn_header'];
            $adminAccess = $result[0]['usr_adm_access'];

            //If the user exists we check if the account is locked
            //from too many login attempts

            if(checkbrute($userId) == true) {
                //Account is locked
                //Send an email to user saying their account is locked
                return false;
            } else {
                //hash the password with the unique salt.
                $passwordSha = $password;
                $passwordFinal = hash('sha512', $passwordSha . $userSalt);

                //Check if the password in the database matches
                //the password the user submitted.
                if($dbPassword == $passwordFinal) {
                    //Password is correct!

                    //Get the user-agent string of the user.
                    $userBrowser = $_SERVER['HTTP_USER_AGENT'];

                    //---------- INTEGERS ----------

                    //XSS protection as we might print this value
                    $userId = preg_replace("/[^0-9]+/", "", $userId);
                    $_SESSION['user_id'] = $userId;

                    //XSS protection as we might print this value
                    $agnId = preg_replace("/[^0-9]+/", "", $agnId);
                    $_SESSION['usr_agn_id'] = $agnId;

                    //XSS protection as we might print this value
                    $type = preg_replace("/[^0-9]+/", "", $type);
                    $_SESSION['usr_type'] = $type;

                    //XSS protection as we might print this value
                    $adminAccess = preg_replace("/[^0-9]+/", "", $adminAccess);
                    $_SESSION['usr_adm_access'] = $adminAccess;

                    //---------- UTF8 STRINGS ----------

                    $agency = utf8_encode($agency);
                    $_SESSION['usr_agn_nombre'] = $agency;

                    //---------- EMAIL ----------

                   $_SESSION['email'] = $email;

                    //------------- STRINGS -------------

                    $_SESSION['usr_agn_logo1'] = $agnLogo1;
                    $_SESSION['usr_agn_logo2'] = $agnLogo2;
                    $_SESSION['usr_agn_header'] = $agnHeader;

                    //---------- LOGIN STRINGS ----------

                    $_SESSION['login_string'] = hash('sha512', $passwordFinal . $userBrowser);

                    //Login successful.
                    return true;
                } else {
                    //Password is not correct
                    //We record this attempt in the database
                    $now = time();
                    $sql_i =
                        "INSERT INTO camAttempts(
                            ATT_USR_Id,
                            ATT_Time
                         ) VALUES (
                            :usr_id,
                            :time
                         )";
                    $structure_i = array();
                    $params_i = array(
                        'usr_id' => $userId,
                        'time' => $now
                    );
                    $result = restructureQuery($structure_i, getConnection(), $sql_i, $params_i, 1, PDO::FETCH_ASSOC);
                    return false;
                }
            }

        } else {
            return false;
        }
    } else {
        //No user exists.
        return false;
    }

}

function login_check() {
    if(isset(
            $_SESSION['user_id'],
            //$_SESSION['username'],
            $_SESSION['email'],
            $_SESSION['usr_agn_id'],
            $_SESSION['usr_agn_nombre'],
            //$_SESSION['usr_agn_logo1'],
            //$_SESSION['usr_agn_logo2'],
            $_SESSION['usr_type'],
            $_SESSION['login_string'],
            $_SESSION['usr_adm_access']
        )
    ) {
        $loginString = $_SESSION['login_string'];
        $userId = $_SESSION['user_id'];
        //$username = $_SESSION['username'];
        $email = $_SESSION['email'];
        $agnId = $_SESSION['usr_agn_id'];
        $type = $_SESSION['usr_type'];
        $agency = $_SESSION['usr_agn_nombre'];
        $agnLogo1 = $_SESSION['usr_agn_logo1'];
        $agnLogo2 = $_SESSION['usr_agn_logo2'];
        $agnHeader = $_SESSION['usr_agn_header'];
        $adminAccess = $_SESSION['usr_adm_access'];

        //Get the user-agent string of the user.
        $userBrowser = $_SERVER['HTTP_USER_AGENT'];

        $sql = "SELECT USR_Password
                FROM camUsuarios
                WHERE USR_Id = :usr_id
                LIMIT 1";

        $structure = array(
            'password' => 'USR_Password'
        );

        $params = array(
            'usr_id' => $userId
        );

        $result = restructureQuery($structure, getConnection(), $sql, $params, 0, PDO::FETCH_ASSOC);

        if(count($result)) {
            if(rightResult($result)) {
                //If the user exists get variables from result.
                $password = $result[0]['password'];
                $loginCheck = hash('sha512', $password . $userBrowser);
                if($loginCheck == $loginString) {
                    //Logged In!!!!
                    return true;
                } else {
                    //Not logged in
                    return false;
                }
            } else {
                //Not logged in
                return false;
            }
        } else {
            //Not logged in
            return false;
        }

    } else {
        if(isset($_SESSION['user_control'])) {
            return true;
        } else {
          return false;
        }
        //Not logged in
    }
}

function checkbrute($userId) {
    //Get timestamp of current time
    $now = time();
    //All login attempts are counted from the past 2 hours.
    $validAttempts = $now - (2 * 60 * 60);
    $sql = "SELECT ATT_Time
            FROM camAttempts
            WHERE ATT_USR_Id = :usr_id
            AND ATT_Time > :valid_attempts";
    $structure = array(
        'password' => 'DIS_Password'
    );
    $params = array(
        'usr_id' => $userId,
        'valid_attempts' => $validAttempts
    );
    $result = generalQuery(getConnection(), $sql, $params, 0, PDO::FETCH_ASSOC);
    if(count($result)) {
        if(rightResult($result)) {
            //If there have been more than 5 failed logins
            if(count($result) > 5) {
                return true;
            } else {
                return false;
            }
        } else {
            //Could not create a prepared statement
            header("Location: ../error.php?err=Database error: cannot prepare statement");
            exit();
        }
    }
    return false;
}

function admin_access_check($mysqli) {
    $adminAccess = isset($_SESSION['usr_adm_access']) ? $_SESSION['usr_adm_access'] : 0;
    $adminAccess = intval($adminAccess);
    return ($adminAccess > 0);
}

function esc_url($url) {

    if('' == $url) {
        return $url;
    }

    $url = preg_replace('|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\\x80-\\xff]|i', '', $url);

    $strip = array('%0d', '%0a', '%0D', '%0A');
    $url = (string) $url;

    $count = 1;
    while ($count) {
        $url = str_replace($strip, '', $url, $count);
    }

    $url = str_replace(';//', '://', $url);

    $url = htmlentities($url);

    $url = str_replace('&amp;', '&#038;', $url);
    $url = str_replace("'", '&#039;', $url);

    if($url[0] !== '/') {
        // We're only interested in relative links from $_SERVER['PHP_SELF']
        return '';
    } else {
        return $url;
    }
}

function own_array_column($array, $column) {
    $myFunction = function($interlnalArray, $internalColumn) {
        $internalValues = array();
        foreach($interlnalArray as $current) {
            $internalValues[] = $current[$internalColumn];
        }
        $internalValues = array_values($internalValues);
        return $internalValues;
    };
    $version = phpversion();
    $elements = explode('.', $version);
    $first = (integer)($elements[0]);
    if($first === 5) {
        $count = count($elements);
        switch($count) {
            case 1:
                $proyectos_values = $myFunction($array, $column);
                break;
            case 2:
                $second = (integer)($elements[1]);
                if($second === 5) {
                    $proyectos_values = $myFunction($array, $column);
                } else if($second > 5) {
                    $proyectos_values = array_column($array, $column);
                } else {
                    $proyectos_values = $myFunction($array, $column);
                }
                break;
            case 3:
            default:
                $second = (integer)($elements[1]);
                $third = (integer)($elements[2]);
                if($second === 5) {
                    if($third >= 0) {
                        $proyectos_values = array_column($array, $column);
                    } else {
                        $proyectos_values = $myFunction($array, $column);
                    }
                } else if($second > 5) {
                    $proyectos_values = array_column($array, $column);
                } else {
                    $proyectos_values = $myFunction($array, $column);
                }

        }
    } else if($first > 5) {
        $proyectos_values = array_column($array, $column);
    } else {
        $proyectos_values = $myFunction($array, $column);
    }
    $proyectos_values = array_values($proyectos_values);
    return $proyectos_values;
}

/*
 * Function taken from:
 * http://php.net/manual/es/function.array-filter.php
 * Adapted and customized by Javier Corona, Medigraf, 2015-10-27
 */

function filterByValue($array, $index, $value, $equal) {
    $newArray = array();
    if(is_array($array) && count($array) > 0) {
        foreach(array_keys($array) as $key) {
            $temp[$key] = $array[$key][$index];
            if($equal) {
                if($temp[$key] == $value) {
                    $newArray[$key] = $array[$key];
                }
            } else {
                if($temp[$key] != $value) {
                    $newArray[$key] = $array[$key];
                }
            }
        }
    }
    return $newArray;
}

/*
 *Gottten from http://php.net/manual/es/function.checkdate.php
 */
function validateDate($date, $format = 'Y-m-d H:i:s') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) == $date;
}
/*
function update_users_from_webservice() {
}
*/
