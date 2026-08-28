<?php

/*
 * MailWatch for MailScanner
 * Copyright (C) 2003-2011  Steve Freegard (steve@freegard.name)
 * Copyright (C) 2011  Garrod Alwood (garrod.alwood@lorodoes.com)
 * Copyright (C) 2014-2021  MailWatch Team (https://github.com/mailwatch/1.2.0/graphs/contributors)
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.
 *
 * In addition, as a special exception, the copyright holder gives permission to link the code of this program with
 * those files in the PEAR library that are licensed under the PHP License (or with modified versions of those files
 * that use the same license as those files), and distribute linked combinations including the two.
 * You must obey the GNU General Public License in all respects for all of the code used other than those files in the
 * PEAR library that are licensed under the PHP License. If you modify this program, you may extend this exception to
 * your version of the program, but you are not obligated to do so.
 * If you do not wish to do so, delete this exception statement from your version.
 *
 * You should have received a copy of the GNU General Public License along with this program; if not, write to the Free
 * Software Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

require_once __DIR__ . '/functions.php';

disableBrowserCache();
session_regenerate_id(true);

$_SESSION['token'] = generateToken();

if (file_exists(__DIR__ . '/conf.php') && isset($_GET['error'])) {
    $loginerror = deepSanitizeInput($_GET['error'], 'url');
    if (false === validateInput($loginerror, 'loginerror')) {
        header('Location: login.php');
    }
}
echo '<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>' . __('mwloginpage01') . '</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="shortcut icon" href="images/favicon.png">
    <link rel="stylesheet" href="style.css" type="text/css">';
if (is_file(__DIR__ . '/skin.css')) {
    echo '
    <link rel="stylesheet" href="skin.css" type="text/css">';
}
echo '
</head>
<body class="loginbody">
<script>
    setInterval(function () {
        var len1 = document.getElementById("myusername").value.length;
        var len2 = document.getElementById("mypassword").value.length;

        var prev1 = document.getElementById("myusername_length").value;
        var prev2 = document.getElementById("mypassword_length").value;

        if (len1 === prev1 && len2 === prev2) {
            location.reload();
        } else {
            document.getElementById("myusername_length").value = len1;
            document.getElementById("mypassword_length").value = len2;
        }
    }, 60000);
    //if session could be timed out display a message to reload the page and hide login form
    function enableTimeoutNotice (timeout){
      setTimeout(function() {
       timeoutnotice = document.getElementById("sessiontimeout");
       timeoutnotice.setAttribute("class", timeoutnotice.getAttribute("class").replace("hidden", ""));
       loginfieldset = document.getElementById("loginfieldset");
       loginfieldset.setAttribute("class", loginfieldset.getAttribute("class") + " hidden");
      }, timeout*1000*0.95);
    function refreshCaptcha() {
        var img = document.getElementById("captchaImg");
        if (img) {
            img.src = "captcha.php?t=" + new Date().getTime();
        }
        var input = document.getElementById("captcha");
        if (input) {
            input.value = "";
            input.focus();
        }
    }
    ' . ((defined('SESSION_TIMEOUT') && SESSION_TIMEOUT > 0) ? 'enableTimeoutNotice(' . SESSION_TIMEOUT . ');' : '') . '
</script>
<div class="login">
    <div class="center login-logo-container"><img src=".' . IMAGES_DIR . MW_LOGO . '" alt="' . __('mwlogo99') . '" class="login-logo-img"></div>
    <h1>' . __('mwlogin01') . '</h1>
    <div class="inner-container">';
if (file_exists(__DIR__ . '/conf.php')) {
    $secStatus = get_login_security_status();
    $isBanned = !empty($secStatus['is_banned']);
    $requireCaptcha = !empty($secStatus['require_captcha']);

    echo '
        <form name="loginform" class="loginform" method="post" action="checklogin.php" autocomplete="off">
            <fieldset class="hidden" id="sessiontimeout">
               <p class="loginerror">' . __('pagetimeoutreload01') . '</p>
            </fieldset>
            <fieldset id="loginfieldset">';
    if ($isBanned) {
        echo '
                <div class="login-banned-card">
                    <div class="ban-icon">⛔</div>
                    <div class="ban-title">' . (__('ipbannedtitle01', false) ?: 'Access Temporarily Blocked') . '</div>
                    <div class="ban-desc">' . (__('ipbanneddesc01', false) ?: 'Too many failed login attempts.') . '</div>
                    <div class="ban-time">⏱️ Try again in ' . $secStatus['ban_remaining_minutes'] . ' min</div>
                </div>';
    } elseif (isset($_GET['error'])) {
        $error = __('errorund01');
        switch ($loginerror) {
            case 'baduser':
                $error = __('badup01');
                break;
            case 'emptypassword':
                $error = __('emptypassword01');
                break;
            case 'timeout':
                $error = __('sessiontimeout01');
                break;
            case 'pagetimeout':
                $error = __('pagetimeout01');
                break;
            case 'banned':
                $error = __('banned01');
                break;
            case 'badcaptcha':
                $error = __('badcaptcha01');
                break;
        }
        echo '
                <p class="loginerror">' . $error . '</p>';
    }

    $disabledAttr = $isBanned ? ' disabled' : '';

    echo '
                <p><label for="myusername">' . __('username') . '</label></p>
                <p><input name="myusername" type="text" id="myusername"' . ($isBanned ? '' : ' autofocus') . $disabledAttr . '></p>
                <input type="hidden" id="myusername_length" name="myusername_length">

                <p><label for="mypassword">' . __('password') . '</label></p>
                <p><input name="mypassword" type="password" id="mypassword"' . $disabledAttr . '></p>
                <input type="hidden" id="mypassword_length" name="mypassword_length">';

    if ($requireCaptcha && !$isBanned) {
        $cLabel = __('captchalabel01');
        if (empty($cLabel) || false !== stripos($cLabel, 'translation')) {
            $cLabel = 'Security Verification';
        }
        $cPlaceholder = __('captchaplaceholder01');
        if (empty($cPlaceholder) || false !== stripos($cPlaceholder, 'translation')) {
            $cPlaceholder = 'Enter code from image';
        }
        $cRefresh = __('captcharefresh01');
        if (empty($cRefresh) || false !== stripos($cRefresh, 'translation')) {
            $cRefresh = 'Refresh code';
        }

        echo '
                <div class="login-captcha-group">
                    <p><label for="captcha">' . htmlspecialchars($cLabel) . '</label></p>
                    <div class="captcha-img-row">
                        <img src="captcha.php?t=' . time() . '" id="captchaImg" class="captcha-img" alt="CAPTCHA" title="' . htmlspecialchars($cRefresh) . '" onclick="refreshCaptcha()">
                        <button type="button" class="btn-captcha-refresh" onclick="refreshCaptcha()" title="' . htmlspecialchars($cRefresh) . '">↻</button>
                    </div>
                    <p><input name="captcha" type="text" id="captcha" class="login-captcha-input" placeholder="' . htmlspecialchars($cPlaceholder) . '" maxlength="8" autocomplete="off" required></p>
                </div>';
    }

    echo '
                <p>
                    <button type="submit" name="Submit" value="loginSubmit"' . $disabledAttr . '>' . __('login01') . '</button>
                </p>
                <input type="hidden" name="token" value="' . $_SESSION['token'] . '">
            </fieldset>
        </form>';
    if (defined('PWD_RESET') && PWD_RESET === true && !$isBanned) {
        echo '
        <div class="pwdresetButton"><a href="password_reset.php?stage=1">' . __('forgottenpwd01') . '</a></div>';
    }
} else {
    echo '
        <p class="error">' . __('cannot_read_conf') . '</p>';
}
echo '
        <div style="margin-top: 18px; text-align: center; font-size: 11px; opacity: 0.8;">
            <a href="https://t.me/EFA_NG" target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: none;">
                <i class="fa fa-paper-plane" style="color: #24A1DE;"></i> Official Telegram Support: <strong>@EFA_NG</strong>
            </a>
        </div>
    </div>
</div>
</body>
</html>
';
