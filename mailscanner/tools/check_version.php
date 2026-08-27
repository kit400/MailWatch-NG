<?php

/*
 * MailWatch for MailScanner
 * EFA-NG Update Check Tool
 *
 * Checks for new releases of EFA-NG / MailWatch-NG, creates system notifications,
 * and optionally sends email alerts to administrators.
 *
 * Usage: php check_version.php [--force] [--email] [--help]
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../notifications.inc.php';

$options = getopt('', ['force', 'email', 'help']);
if (isset($options['help'])) {
    echo "EFA-NG Version Checker\n";
    echo "Usage: php check_version.php [options]\n";
    echo "Options:\n";
    echo "  --force    Bypass the 12-hour cache and query remote repositories immediately\n";
    echo "  --email    Send email broadcast to administrators if a new update is found\n";
    echo "  --help     Display this help message\n";
    exit(0);
}

$force = isset($options['force']);
$sendEmail = isset($options['email']);

echo "[" . date('Y-m-d H:i:s') . "] Checking for EFA-NG / MailWatch-NG updates...\n";

$result = SystemNotifications::checkForUpdates($force);

if (!$result['success']) {
    echo "Warning: " . ($result['error'] ?? 'Unknown error checking for updates') . "\n";
    echo "Installed version: " . ($result['current_version'] ?? 'Unknown') . "\n";
    exit(1);
}

echo "Current installed version: " . $result['current_version'] . "\n";
echo "Latest available version:  " . $result['latest_version'] . "\n";

if ($result['has_update']) {
    echo "\n=======================================================\n";
    echo "🚀 NEW VERSION AVAILABLE: v" . $result['latest_version'] . "\n";
    echo "=======================================================\n";
    if (!empty($result['release_data']['title'])) {
        echo "Release: " . $result['release_data']['title'] . "\n";
    }
    if (!empty($result['release_data']['short_description'])) {
        echo "Summary: " . $result['release_data']['short_description'] . "\n";
    }
    echo "\nUpgrade Instructions:\n";
    echo "  " . ($result['upgrade_command'] ?? 'dnf -y update eFa MailWatch') . "\n";
    echo "=======================================================\n\n";

    if ($sendEmail) {
        dbconn();
        $verSafe = safe_value($result['latest_version']);
        $sql = "SELECT id FROM system_notifications WHERE version = '$verSafe' AND type = 'release' LIMIT 1";
        $res = dbquery($sql);
        if ($res && $row = $res->fetch_assoc()) {
            $count = SystemNotifications::broadcastNotificationEmail((int)$row['id']);
            echo "Broadcast email sent to $count administrator(s).\n";
        }
    }
} else {
    echo "✓ Your system is up to date.\n";
}

exit(0);
