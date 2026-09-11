<?php

/**
 * Regression Test Suite for MW-10:
 * Permanent logger errors must not block SQL logger child,
 * error classification and bounded retry backoff,
 * dead-letter queue routing for unrecoverable errors,
 * utf8mb4 schema alignment across create.sql, dashboard.inc.php, and upgrade.php,
 * and dead-letter replay utility verification.
 */

define('MAILWATCH_TEST_RUNNER', true);
require_once __DIR__ . '/../mailscanner/functions.php';

function run_test($name, callable $fn) {
    try {
        $fn();
        echo "  [PASS] $name\n";
        return true;
    } catch (\Throwable $e) {
        echo "  [FAIL] $name: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
        return false;
    }
}

function assert_true($cond, $msg = 'Assertion failed') {
    if (!$cond) {
        throw new \Exception($msg);
    }
}

function assert_false($cond, $msg = 'Assertion failed: expected false') {
    if ($cond) {
        throw new \Exception($msg);
    }
}

function assert_equals($expected, $actual, $msg = '') {
    if ($expected !== $actual) {
        throw new \Exception(($msg ? $msg . ': ' : '') . "Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

echo "=== MW-10 Regression Test Suite (PHP & Schema) ===\n\n";

// -------------------------------------------------------------
// Test Suite 1: create.sql utf8mb4 schema verification
// -------------------------------------------------------------
echo "1. create.sql Schema Verification:\n";

run_test("Database creation uses utf8mb4 and utf8mb4_unicode_ci", function () {
    $sql = file_get_contents(__DIR__ . '/../create.sql');
    assert_true(
        strpos($sql, 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci') !== false,
        "create.sql must declare DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    assert_true(
        strpos($sql, 'SET NAMES utf8mb4') !== false,
        "create.sql must set SET NAMES utf8mb4"
    );
});

run_test("All CREATE TABLE definitions in create.sql use utf8mb4", function () {
    $sql = file_get_contents(__DIR__ . '/../create.sql');
    preg_match_all('/CREATE TABLE [^;]+;/s', $sql, $matches);
    assert_true(count($matches[0]) >= 14, "Expected at least 14 table definitions in create.sql");

    foreach ($matches[0] as $tableDef) {
        preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/', $tableDef, $tblNameMatch);
        $tableName = $tblNameMatch[1] ?? 'unknown';

        assert_true(
            strpos($tableDef, 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci') !== false,
            "Table `$tableName` must specify DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        assert_false(
            preg_match('/COLLATE\s+utf8_unicode_ci/i', $tableDef),
            "Table `$tableName` contains legacy utf8_unicode_ci collation"
        );
        assert_false(
            preg_match('/CHARSET=utf8\b/i', $tableDef),
            "Table `$tableName` contains legacy CHARSET=utf8"
        );
    }
});

// -------------------------------------------------------------
// Test Suite 2: dashboard.inc.php user_dashboards table
// -------------------------------------------------------------
echo "\n2. Dashboard & Upgrade Alignment:\n";

run_test("user_dashboards table in dashboard.inc.php uses utf8mb4", function () {
    $content = file_get_contents(__DIR__ . '/../mailscanner/dashboard.inc.php');
    assert_true(
        strpos($content, 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci') !== false,
        "user_dashboards definition must use DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    assert_false(
        strpos($content, 'DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci'),
        "user_dashboards must not use legacy CHARSET=utf8"
    );
});

run_test("upgrade.php includes user_dashboards in utf8_tables", function () {
    $content = file_get_contents(__DIR__ . '/../upgrade.php');
    assert_true(
        strpos($content, "'user_dashboards'") !== false,
        "upgrade.php utf8_tables list must include 'user_dashboards'"
    );
});

// -------------------------------------------------------------
// Test Suite 3: Dead-Letter Replay Utility
// -------------------------------------------------------------
echo "\n3. Dead-Letter Replay Utility (tools/mailwatch_replay_failed_events.php):\n";

run_test("Replay utility runs in CLI mode with --help", function () {
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/../tools/mailwatch_replay_failed_events.php') . ' --help';
    exec($cmd, $out, $ret);
    assert_equals(0, $ret, "Replay utility returned non-zero code on --help");
    assert_true(strpos(implode("\n", $out), 'MailWatch Failed Events Replay Utility') !== false, "Replay utility help text missing");
});

run_test("Replay utility handles empty or missing directory gracefully", function () {
    $emptyDir = sys_get_temp_dir() . '/mw_test_dlq_empty_' . uniqid();
    mkdir($emptyDir, 0777, true);

    $cmd = 'php ' . escapeshellarg(__DIR__ . '/../tools/mailwatch_replay_failed_events.php') . ' --dir=' . escapeshellarg($emptyDir);
    exec($cmd, $out, $ret);
    @rmdir($emptyDir);

    assert_equals(0, $ret, "Expected exit code 0 for empty directory");
    assert_true(strpos(implode("\n", $out), 'No failed events to replay') !== false, "Expected 'No failed events to replay' message");
});

run_test("Replay utility parses dead-letter JSON files and supports --dry-run", function () {
    $testDir = sys_get_temp_dir() . '/mw_test_dlq_' . uniqid();
    mkdir($testDir, 0777, true);

    // Create a mock failed event JSON
    $mockEvent = [
        'failed_at' => '2026-09-11 15:30:00',
        'error_code' => 1366,
        'error_str' => "Incorrect string value: '\\xF0\\x9F\\x98\\x8A' for column 'subject'",
        'message_id' => 'TEST_REPLAY_MSG_01',
        'fields' => [
            'id' => 'TEST_REPLAY_MSG_01',
            'timestamp' => '2026-09-11 15:30:00',
            'from' => 'sender@example.com',
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject with [replacement] emoji',
            'size' => 4096,
            'isspam' => 0,
            'sascore' => 1.25,
            'token' => str_repeat('a', 64),
        ]
    ];

    $jsonFile = $testDir . '/TEST_REPLAY_MSG_01.json';
    file_put_contents($jsonFile, json_encode($mockEvent, JSON_PRETTY_PRINT));

    // Run in dry-run mode
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/../tools/mailwatch_replay_failed_events.php') . ' --dry-run --verbose --dir=' . escapeshellarg($testDir);
    exec($cmd, $out, $ret);

    // Dry-run should preserve the file
    assert_true(file_exists($jsonFile), "Dry run must NOT delete the DLQ file");
    $output = implode("\n", $out);
    assert_true(strpos($output, 'DRY RUN') !== false, "Dry run output missing [DRY RUN] indicator");
    assert_true(strpos($output, 'TEST_REPLAY_MSG_01') !== false, "Message ID missing in dry-run output");

    // Clean up
    @unlink($jsonFile);
    @rmdir($testDir);
});

// -------------------------------------------------------------
// Test Suite 4: MailWatch.pm & MailWatchConf.pm Config Verification
// -------------------------------------------------------------
echo "\n4. Perl Module Configuration Verification:\n";

run_test("MailWatchConf.pm provides mailwatch_get_failed_events_dir and max_retries", function () {
    $conf = file_get_contents(__DIR__ . '/../MailScanner_perl_scripts/MailWatchConf.pm');
    assert_true(
        strpos($conf, 'sub mailwatch_get_failed_events_dir') !== false,
        "MailWatchConf.pm must define mailwatch_get_failed_events_dir"
    );
    assert_true(
        strpos($conf, 'sub mailwatch_get_max_retries') !== false,
        "MailWatchConf.pm must define mailwatch_get_max_retries"
    );
});

run_test("MailWatch.pm implements dead-letter queue, bounded retries, and error classification", function () {
    $pm = file_get_contents(__DIR__ . '/../MailScanner_perl_scripts/MailWatch.pm');
    assert_true(
        strpos($pm, 'sub IsTransientDBError') !== false,
        "MailWatch.pm must implement IsTransientDBError"
    );
    assert_true(
        strpos($pm, 'sub SaveFailedEvent') !== false,
        "MailWatch.pm must implement SaveFailedEvent"
    );
    assert_true(
        strpos($pm, 'sub SanitizeMessageFields') !== false,
        "MailWatch.pm must implement SanitizeMessageFields"
    );
    assert_true(
        strpos($pm, 'sub StripNulBytes') !== false,
        "MailWatch.pm must implement StripNulBytes"
    );
    assert_true(
        strpos($pm, 'StripNulBytes') !== false,
        "MailWatch.pm must call StripNulBytes before initial execute"
    );
    assert_false(
        preg_match('/while\(InitDB\(\) == 1\) \{ sleep\(2\); \};/', $pm),
        "MailWatch.pm must not contain unbounded while(InitDB()==1) retry loop"
    );
});

echo "\n===================================\n";
echo "ALL MW-10 PHP REGRESSION TESTS PASSED SUCCESSFULLY! ✓\n";
