<?php

/**
 * Regression Test Suite for MW-05:
 * HTML cache and sensitive runtime data located outside web document root,
 * web server access denial, active TTL purging, and cache invalidation on logout.
 */

require_once __DIR__ . '/../mailscanner/functions.php';
require_once __DIR__ . '/../mailscanner/dashboard.inc.php';
require_once __DIR__ . '/../mailscanner/SessionGuard.php';

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

echo "=== MW-05 Regression Test Suite ===\n\n";
$all_passed = true;

// -------------------------------------------------------------
// 1. Unit Tests: Cache Directory Resolution Outside DocumentRoot
// -------------------------------------------------------------
echo "1. Unit Tests: mailwatch_cache_dir resolution\n";

$all_passed &= run_test('mailwatch_cache_dir returns a path outside web document root', function() {
    $dir = mailwatch_cache_dir();
    $docRoot = realpath(__DIR__ . '/..');

    assert_true(!empty($dir), 'Cache directory must not be empty');
    assert_true(is_dir($dir), 'Cache directory must exist or be created: ' . $dir);
    assert_true(is_writable($dir), 'Cache directory must be writable: ' . $dir);

    // Critical security check: cache dir must NOT be inside the webroot
    $realDir = realpath($dir) ?: $dir;
    assert_false(strpos($realDir, $docRoot . '/mailscanner') === 0, 'SECURITY VIOLATION: Cache directory is inside web root: ' . $realDir);
});

$all_passed &= run_test('mailwatch_cache_dir handles subdirectories properly', function() {
    $subDir = mailwatch_cache_dir('dash_cache');
    assert_true(is_dir($subDir), 'Subdirectory dash_cache must exist');
    assert_true(is_writable($subDir), 'Subdirectory dash_cache must be writable');
    assert_true(strpos($subDir, 'dash_cache') !== false, 'Path must contain dash_cache');
});

// -------------------------------------------------------------
// 2. Unit Tests: Dashboard Widget Cache Storage & Isolation
// -------------------------------------------------------------
echo "\n2. Unit Tests: Widget cache storage and DocumentRoot isolation\n";

$all_passed &= run_test('set_dashboard_widget_cache writes outside webroot and NOT into temp/dash_cache', function() {
    $testKey = 'test_widget_sample_' . time();
    $testContent = '<div class="test">Sensitive Email Content</div>';

    set_dashboard_widget_cache($testKey, $testContent);

    // Verify it was written to mailwatch_cache_dir('dash_cache')
    $expectedDir = mailwatch_cache_dir('dash_cache');
    $expectedFile = $expectedDir . '/' . $testKey . '.cache';
    assert_true(file_exists($expectedFile), 'Cache file must exist in mailwatch_cache_dir: ' . $expectedFile);

    // Verify it was NOT written into mailscanner/temp/dash_cache
    $legacyFile = __DIR__ . '/../mailscanner/temp/dash_cache/' . $testKey . '.cache';
    assert_false(file_exists($legacyFile), 'SECURITY VIOLATION: Cache file written into DocumentRoot temp/dash_cache: ' . $legacyFile);

    // Verify content matches
    $retrieved = get_dashboard_widget_cache($testKey, 60);
    assert_equals($testContent, $retrieved);

    // Clean up
    @unlink($expectedFile);
});

// -------------------------------------------------------------
// 3. Unit Tests: Active TTL Expiration & Retention Policy
// -------------------------------------------------------------
echo "\n3. Unit Tests: Active TTL Expiration & Retention Policy\n";

$all_passed &= run_test('get_dashboard_widget_cache purges expired file from disk upon access', function() {
    $expireKey = 'test_expire_' . time();
    $content = '<div>Data to expire</div>';

    set_dashboard_widget_cache($expireKey, $content);
    $cacheFile = mailwatch_cache_dir('dash_cache') . '/' . $expireKey . '.cache';
    assert_true(file_exists($cacheFile), 'Cache file must exist initially');

    // Backdate mtime to simulate past TTL (100 seconds ago)
    touch($cacheFile, time() - 100);

    // Request with TTL = 30 seconds
    $res = get_dashboard_widget_cache($expireKey, 30);
    assert_false($res, 'Expired cache must return false');

    // Crucial check: expired file MUST be actively deleted from disk!
    assert_false(file_exists($cacheFile), 'SECURITY VIOLATION: Expired cache file was not purged from disk!');
});

$all_passed &= run_test('purge_expired_dashboard_cache purges files older than maxAge', function() {
    $dir = mailwatch_cache_dir('dash_cache');
    $oldFile1 = $dir . '/test_old_1_' . time() . '.cache';
    $oldFile2 = $dir . '/test_old_2_' . time() . '.cache';
    $freshFile = $dir . '/test_fresh_' . time() . '.cache';

    file_put_contents($oldFile1, 'old1');
    file_put_contents($oldFile2, 'old2');
    file_put_contents($freshFile, 'fresh');

    touch($oldFile1, time() - 7200); // 2 hours ago
    touch($oldFile2, time() - 4000); // > 1 hour ago
    touch($freshFile, time() - 10);   // 10 seconds ago

    purge_expired_dashboard_cache($dir, 3600);

    assert_false(file_exists($oldFile1), 'Old file 1 should be purged');
    assert_false(file_exists($oldFile2), 'Old file 2 should be purged');
    assert_true(file_exists($freshFile), 'Fresh file should be retained');

    @unlink($freshFile);
});

// -------------------------------------------------------------
// 4. Unit Tests: Invalidation on User Logout & Role Filter Hash
// -------------------------------------------------------------
echo "\n4. Unit Tests: User Cache Invalidation on Logout\n";

$all_passed &= run_test('clear_dashboard_widget_cache removes user-specific cached widgets', function() {
    $userFilter1 = "(to_address='alice@example.com')";
    $userHash1 = md5($userFilter1 . '_');

    $userFilter2 = "(to_address='bob@example.com')";
    $userHash2 = md5($userFilter2 . '_');

    $keyAlice = "w_recent_threats_24h_" . $userHash1;
    $keyBob = "w_recent_threats_24h_" . $userHash2;

    set_dashboard_widget_cache($keyAlice, 'Alice data');
    set_dashboard_widget_cache($keyBob, 'Bob data');

    $fileAlice = mailwatch_cache_dir('dash_cache') . '/' . $keyAlice . '.cache';
    $fileBob = mailwatch_cache_dir('dash_cache') . '/' . $keyBob . '.cache';

    assert_true(file_exists($fileAlice));
    assert_true(file_exists($fileBob));

    // Simulate Alice logout
    clear_dashboard_widget_cache($userHash1);

    // Alice cache must be deleted, Bob cache preserved
    assert_false(file_exists($fileAlice), 'Alice cached widgets must be purged on logout');
    assert_true(file_exists($fileBob), 'Bob cached widgets must not be affected by Alice logout');

    // Clean up Bob
    clear_dashboard_widget_cache($userHash2);
    assert_false(file_exists($fileBob));
});

// -------------------------------------------------------------
// 5. Unit Tests: Web Server Security Configuration Integrity
// -------------------------------------------------------------
echo "\n5. Unit Tests: Web Server Security Configuration Integrity\n";

$all_passed &= run_test('mailscanner/temp/.htaccess explicitly denies all web access', function() {
    $htaccessFile = __DIR__ . '/../mailscanner/temp/.htaccess';
    assert_true(file_exists($htaccessFile), 'temp/.htaccess must exist');
    $content = file_get_contents($htaccessFile);
    assert_true(strpos($content, 'Require all denied') !== false, 'temp/.htaccess must contain "Require all denied"');
    assert_true(strpos($content, 'Deny from all') !== false, 'temp/.htaccess must contain "Deny from all"');
});

$all_passed &= run_test('mailscanner/temp/index.php sends 403 Forbidden', function() {
    $indexPhp = __DIR__ . '/../mailscanner/temp/index.php';
    assert_true(file_exists($indexPhp), 'temp/index.php must exist');
    $content = file_get_contents($indexPhp);
    assert_true(strpos($content, '403 Forbidden') !== false, 'temp/index.php must send 403 Forbidden');
});

$all_passed &= run_test('conf/mailwatch.conf denies web access to temp, lib, tools, and sensitive extensions', function() {
    $confFile = __DIR__ . '/../conf/mailwatch.conf';
    assert_true(file_exists($confFile), 'conf/mailwatch.conf must exist');
    $content = file_get_contents($confFile);
    assert_true(strpos($content, '<Directory "/var/www/html/mailscanner/temp">') !== false, 'Must have temp Directory block');
    assert_true(strpos($content, '<Directory "/var/www/html/mailscanner/lib">') !== false, 'Must have lib Directory block');
    assert_true(strpos($content, '<Directory "/var/www/html/mailscanner/tools">') !== false, 'Must have tools Directory block');
    assert_true(strpos($content, 'Require all denied') !== false, 'Must enforce Require all denied');
    assert_true(strpos($content, 'cache') !== false, 'FilesMatch must block .cache files');
});

$all_passed &= run_test('mailscanner/.htaccess blocks sensitive file extensions', function() {
    $mainHtaccess = __DIR__ . '/../mailscanner/.htaccess';
    assert_true(file_exists($mainHtaccess), 'mailscanner/.htaccess must exist');
    $content = file_get_contents($mainHtaccess);
    assert_true(strpos($content, 'Require all denied') !== false);
    assert_true(strpos($content, 'cache') !== false);
    assert_true(strpos($content, 'json') !== false);
});

echo "\n===================================\n";
if ($all_passed) {
    echo "ALL MW-05 REGRESSION TESTS PASSED SUCCESSFULLY! ✓\n";
    exit(0);
} else {
    echo "SOME MW-05 REGRESSION TESTS FAILED! ✗\n";
    exit(1);
}
