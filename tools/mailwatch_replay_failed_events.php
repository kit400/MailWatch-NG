<?php

/**
 * MailWatch Failed Events Replay Utility
 *
 * Scans the dead-letter queue directory for failed logging events (*.json),
 * replays them into the MailWatch maillog database, and cleans up replayed files.
 *
 * Usage:
 *   php mailwatch_replay_failed_events.php [options]
 *
 * Options:
 *   --dry-run       Simulate replay without modifying the database or removing files
 *   --dir=<path>    Override default failed events directory (/var/spool/mailwatch/failed_events)
 *   --limit=<num>   Maximum number of events to process in one run
 *   --verbose       Detailed output per event
 *   --help          Show this help message
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

// Auto-detect MailWatch functions.php
$candidates = [
    dirname(__DIR__) . '/mailscanner/functions.php',
    dirname(__DIR__, 2) . '/mailscanner/functions.php',
    '/var/www/html/mailscanner/functions.php',
    '/usr/local/share/mailwatch/mailscanner/functions.php',
];

$pathToFunctions = null;
foreach ($candidates as $candidate) {
    if (file_exists($candidate)) {
        $pathToFunctions = $candidate;
        break;
    }
}

if ($pathToFunctions === null) {
    die("Error: Could not locate MailWatch functions.php\n");
}

require_once $pathToFunctions;
require_once dirname($pathToFunctions) . '/database.php';

// Parse command-line options
$options = getopt('', ['dry-run', 'dir:', 'limit:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "MailWatch Failed Events Replay Utility\n\n";
    echo "Usage:\n";
    echo "  php " . basename(__FILE__) . " [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run       Simulate replay without modifying the database or removing files\n";
    echo "  --dir=<path>    Override default failed events directory\n";
    echo "  --limit=<num>   Maximum number of events to process\n";
    echo "  --verbose       Detailed progress output\n";
    echo "  --help          Show this help message\n";
    exit(0);
}

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 0;

$failedDir = $options['dir'] ?? '/var/spool/mailwatch/failed_events';
if (!is_dir($failedDir)) {
    // Check fallback directories
    $fallbacks = [
        '/var/spool/MailScanner/failed_events',
        '/var/cache/mailwatch/failed_events',
        '/tmp/mailwatch_failed_events',
    ];
    foreach ($fallbacks as $fb) {
        if (is_dir($fb)) {
            $failedDir = $fb;
            break;
        }
    }
}

if (!is_dir($failedDir)) {
    echo "Failed events directory not found or empty ($failedDir).\n";
    exit(0);
}

$files = glob(rtrim($failedDir, '/') . '/*.json');
if ($files === false || count($files) === 0) {
    echo "No failed events to replay in $failedDir.\n";
    exit(0);
}

// Sort oldest first (FIFO)
sort($files);

if ($limit > 0 && count($files) > $limit) {
    $files = array_slice($files, 0, $limit);
}

$total = count($files);
$replayed = 0;
$alreadyExists = 0;
$failed = 0;

$dbAvailable = false;
if (is_object(database::$link)) {
    $dbAvailable = true;
} else {
    try {
        set_error_handler(static function() {});
        $testConn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)(defined('DB_PORT') ? DB_PORT : 3306));
        restore_error_handler();
        if ($testConn && !$testConn->connect_error) {
            $testConn->close();
            $dbAvailable = true;
        }
    } catch (\Throwable $e) {
        $dbAvailable = false;
    }
}

if (!$dbAvailable && !$dryRun) {
    echo "Error: Database is not available. Cannot replay failed events.\n";
    exit(1);
}

echo "Found $total event(s) in $failedDir" . ($dryRun ? " [DRY RUN]" : "") . "\n";

foreach ($files as $file) {
    $content = @file_get_contents($file);
    if ($content === false) {
        echo "  [FAIL] Could not read file: $file\n";
        $failed++;
        continue;
    }

    $data = json_decode($content, true);
    if (!is_array($data)) {
        echo "  [FAIL] Corrupt or invalid JSON in $file\n";
        $failed++;
        continue;
    }

    $msg = $data['fields'] ?? $data;
    $msgId = $msg['id'] ?? null;
    $timestamp = $msg['timestamp'] ?? date('Y-m-d H:i:s');

    if (empty($msgId)) {
        echo "  [FAIL] Missing message ID in $file\n";
        $failed++;
        continue;
    }

    // Check if message already exists in maillog
    if ($dbAvailable) {
        $checkSql = "SELECT maillog_id FROM maillog WHERE id = '" . safe_value($msgId) . "' LIMIT 1";
        $res = dbquery($checkSql, false);
        if ($res && $res->num_rows > 0) {
            if ($verbose) {
                echo "  [EXISTS] Message $msgId already in database. Purging DLQ file.\n";
            }
            if (!$dryRun) {
                @unlink($file);
            }
            $alreadyExists++;
            continue;
        }
    }

    // Build insert columns and values
    $insertFields = [
        'timestamp'       => safe_value($timestamp),
        'id'              => safe_value($msgId),
        'size'            => (int)($msg['size'] ?? 0),
        'from_address'    => safe_value($msg['from'] ?? ''),
        'from_domain'     => safe_value($msg['from_domain'] ?? ''),
        'to_address'      => safe_value($msg['to'] ?? ''),
        'to_domain'       => safe_value($msg['to_domain'] ?? ''),
        'subject'         => safe_value($msg['subject'] ?? ''),
        'clientip'        => safe_value($msg['clientip'] ?? ''),
        'archive'         => safe_value($msg['archiveplaces'] ?? ''),
        'isspam'          => (int)($msg['isspam'] ?? 0),
        'ishighspam'      => (int)($msg['ishigh'] ?? 0),
        'issaspam'        => (int)($msg['issaspam'] ?? 0),
        'isrblspam'       => (int)($msg['isrblspam'] ?? 0),
        'spamwhitelisted' => (int)($msg['spamwhitelisted'] ?? 0),
        'spamblacklisted' => (int)($msg['spamblacklisted'] ?? 0),
        'sascore'         => is_numeric($msg['sascore'] ?? null) ? (float)$msg['sascore'] : 0.0,
        'spamreport'      => safe_value($msg['spamreport'] ?? ''),
        'virusinfected'   => (int)($msg['virusinfected'] ?? 0),
        'nameinfected'    => (int)($msg['nameinfected'] ?? 0),
        'otherinfected'   => (int)($msg['otherinfected'] ?? 0),
        'report'          => safe_value($msg['reports'] ?? ''),
        'ismcp'           => (int)($msg['ismcp'] ?? 0),
        'ishighmcp'       => (int)($msg['ishighmcp'] ?? 0),
        'issamcp'         => (int)($msg['issamcp'] ?? 0),
        'mcpwhitelisted'  => (int)($msg['mcpwhitelisted'] ?? 0),
        'mcpblacklisted'  => (int)($msg['mcpblacklisted'] ?? 0),
        'mcpsascore'      => is_numeric($msg['mcpsascore'] ?? null) ? (float)$msg['mcpsascore'] : 0.0,
        'mcpreport'       => safe_value($msg['mcpreport'] ?? ''),
        'hostname'        => safe_value($msg['hostname'] ?? ''),
        'date'            => safe_value($msg['date'] ?? substr($timestamp, 0, 10)),
        'time'            => safe_value($msg['time'] ?? substr($timestamp, 11, 8)),
        'headers'         => safe_value($msg['headers'] ?? ''),
        'quarantined'     => (int)($msg['quarantined'] ?? 0),
        'rblspamreport'   => safe_value($msg['rblspamreport'] ?? ''),
        'token'           => safe_value(substr($msg['token'] ?? '', 0, 64)),
        'messageid'       => safe_value($msg['messageid'] ?? ''),
    ];

    $cols = implode('`, `', array_keys($insertFields));
    $vals = implode("', '", array_values($insertFields));
    $insertSql = "INSERT INTO `maillog` (`$cols`) VALUES ('$vals')";

    if ($dryRun) {
        if ($verbose) {
            echo "  [DRY-RUN] Would insert message $msgId into maillog\n";
        }
        $replayed++;
        continue;
    }

    try {
        $insertRes = dbquery($insertSql, false);
        if ($insertRes) {
            @unlink($file);
            if ($verbose) {
                echo "  [OK] Successfully replayed message $msgId\n";
            }
            $replayed++;
        } else {
            echo "  [FAIL] Database insert failed for message $msgId: " . ($GLOBALS['link']->error ?? 'unknown error') . "\n";
            $failed++;
        }
    } catch (\Throwable $e) {
        echo "  [FAIL] Exception during insert for message $msgId: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "Summary: $total processed, $replayed replayed, $alreadyExists already existed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
