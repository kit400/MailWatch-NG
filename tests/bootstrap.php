<?php

/*
 * MailWatch for MailScanner - PHPUnit Test Bootstrap
 */

if (!defined('MAILWATCH_TEST_RUNNER')) {
    define('MAILWATCH_TEST_RUNNER', true);
}
if (!defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', true);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Prepare configuration file if missing
$conf = dirname(__DIR__) . '/mailscanner/conf.php';
$example = dirname(__DIR__) . '/mailscanner/conf.php.example';
if (!file_exists($conf) && file_exists($example)) {
    $content = file_get_contents($example);
    $content = str_replace(
        "define('MAILWATCH_HOME', '/var/www/html/mailscanner');",
        "define('MAILWATCH_HOME', __DIR__);",
        $content
    );
    file_put_contents($conf, $content);
}

// Load core functions and classes
require_once dirname(__DIR__) . '/mailscanner/functions.php';
