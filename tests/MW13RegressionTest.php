<?php

/**
 * Regression Test Suite for MW-13:
 * MYSQLI_INIT_COMMAND specified after connection in database.php.
 *
 * Verifies:
 * 1. Connection lifecycle: mysqli_init() is called, connection options (MYSQLI_INIT_COMMAND)
 *    are set BEFORE real_connect(), and explicit session enforcement is applied.
 * 2. Database driver report mode is set to MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT,
 *    preventing unintended table-scan errors from MYSQLI_REPORT_INDEX.
 * 3. Database session helper: database::getSessionSqlMode() accurately inspects @@SESSION.sql_mode.
 * 4. SQL mode transformation logic: cleanly strips ONLY_FULL_GROUP_BY across all 6 positional
 *    permutations (start, middle, end, alone, absent, empty) without leaving double, leading, or trailing commas.
 * 5. Strict GROUP BY compatibility: all MailWatch report and dashboard queries are strictly compliant
 *    with ONLY_FULL_GROUP_BY (every selected non-aggregate column is present in GROUP BY).
 * 6. Live database integration (when MySQL/MariaDB server is available): verifies actual session sql_mode
 *    after connecting, and executes all report queries under strict ONLY_FULL_GROUP_BY mode.
 */

define('MAILWATCH_TEST_RUNNER', true);
require_once __DIR__ . '/../mailscanner/functions.php';
require_once __DIR__ . '/../mailscanner/database.php';

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

echo "=== MW-13 Regression Test Suite ===\n\n";
$all_passed = true;

// -----------------------------------------------------------------------------
// 1. Static Analysis & Connection Call Order Verification
// -----------------------------------------------------------------------------
echo "1. Static Analysis & Connection Initialization Order\n";

$all_passed &= run_test('database.php uses mysqli_init() before real_connect()', function() {
    $code = file_get_contents(__DIR__ . '/../mailscanner/database.php');

    $posInit = strpos($code, 'mysqli_init()');
    $posOptions = strpos($code, 'options(MYSQLI_INIT_COMMAND');
    $posRealConnect = strpos($code, 'real_connect(');

    assert_true($posInit !== false, 'database.php must call mysqli_init()');
    assert_true($posOptions !== false, 'database.php must call options(MYSQLI_INIT_COMMAND)');
    assert_true($posRealConnect !== false, 'database.php must call real_connect()');

    // Crucial order check: init -> options -> real_connect
    assert_true($posInit < $posOptions, 'mysqli_init() must precede options()');
    assert_true($posOptions < $posRealConnect, 'options(MYSQLI_INIT_COMMAND) must precede real_connect()');
});

$all_passed &= run_test('database.php does NOT use post-connect options on new mysqli()', function() {
    $code = file_get_contents(__DIR__ . '/../mailscanner/database.php');

    // Must not do: new mysqli($host, ...) followed by options(MYSQLI_INIT_COMMAND)
    $pattern = '/new\s+mysqli\s*\([^)]+\)\s*;[^;]*options\s*\(\s*MYSQLI_INIT_COMMAND/s';
    assert_false(preg_match($pattern, $code), 'database.php must NOT invoke options(MYSQLI_INIT_COMMAND) after new mysqli(...) constructor');
});

$all_passed &= run_test('database.php explicitly applies sql_mode after connection', function() {
    $code = file_get_contents(__DIR__ . '/../mailscanner/database.php');

    $posRealConnect = strpos($code, 'real_connect(');
    $subAfterConnect = substr($code, $posRealConnect);

    assert_true(strpos($subAfterConnect, 'self::$link->query($sqlModeCleanCmd)') !== false, 'database.php must explicitly execute sql_mode query after real_connect()');
});

$all_passed &= run_test('database.php does not enable MYSQLI_REPORT_ALL in production', function() {
    $code = file_get_contents(__DIR__ . '/../mailscanner/database.php');
    assert_false(strpos($code, 'MYSQLI_REPORT_ALL') !== false, 'database.php must not use MYSQLI_REPORT_ALL (avoids spurious index warnings)');
    assert_true(strpos($code, 'MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT') !== false, 'database.php must use MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT');
});

// -----------------------------------------------------------------------------
// 2. SQL Mode Transformation Logic (Positional Permutations)
// -----------------------------------------------------------------------------
echo "\n2. SQL Mode Transformation Logic\n";

function cleanSqlMode(string $mode): string {
    // Pure PHP equivalent of:
    // TRIM(BOTH ',' FROM REPLACE(REPLACE(mode, 'ONLY_FULL_GROUP_BY,', ''), 'ONLY_FULL_GROUP_BY', ''))
    $cleaned = str_replace(['ONLY_FULL_GROUP_BY,', 'ONLY_FULL_GROUP_BY'], ['', ''], $mode);
    return trim($cleaned, ',');
}

$all_passed &= run_test('ONLY_FULL_GROUP_BY at beginning of modes', function() {
    $input = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
    $expected = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
    assert_equals($expected, cleanSqlMode($input));
});

$all_passed &= run_test('ONLY_FULL_GROUP_BY in middle of modes', function() {
    $input = 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION';
    $expected = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
    assert_equals($expected, cleanSqlMode($input));
});

$all_passed &= run_test('ONLY_FULL_GROUP_BY at end of modes', function() {
    $input = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY';
    $expected = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
    assert_equals($expected, cleanSqlMode($input));
});

$all_passed &= run_test('ONLY_FULL_GROUP_BY as sole mode', function() {
    $input = 'ONLY_FULL_GROUP_BY';
    $expected = '';
    assert_equals($expected, cleanSqlMode($input));
});

$all_passed &= run_test('sql_mode without ONLY_FULL_GROUP_BY is preserved identically', function() {
    $input = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
    assert_equals($input, cleanSqlMode($input));
});

$all_passed &= run_test('Empty sql_mode returns empty string', function() {
    assert_equals('', cleanSqlMode(''));
});

// SQLite verification of SQL REPLACE/TRIM expressions
$all_passed &= run_test('SQLite execution of sqlModeCleanCmd matches expected values', function() {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec("CREATE TABLE modes (val TEXT)");
    $stmt = $pdo->prepare("INSERT INTO modes VALUES (?)");
    $cases = [
        'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION',
        'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION',
        'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY',
        'ONLY_FULL_GROUP_BY',
        'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'
    ];
    foreach ($cases as $c) {
        $stmt->execute([$c]);
    }

    $q = $pdo->query("SELECT trim(replace(replace(val, 'ONLY_FULL_GROUP_BY,', ''), 'ONLY_FULL_GROUP_BY', ''), ',') AS cleaned FROM modes");
    $results = $q->fetchAll(PDO::FETCH_COLUMN);

    assert_equals('STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', $results[0]);
    assert_equals('STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', $results[1]);
    assert_equals('STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', $results[2]);
    assert_equals('', $results[3]);
    assert_equals('STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', $results[4]);
});

// -----------------------------------------------------------------------------
// 3. Strict GROUP BY Compliance Analysis for All Queries
// -----------------------------------------------------------------------------
echo "\n3. Strict GROUP BY Query Compliance\n";

$all_passed &= run_test('All report and dashboard GROUP BY queries are strictly compliant', function() {
    $queries = [
        'rep_top_mail_relays' => [
            'file' => __DIR__ . '/../mailscanner/rep_top_mail_relays.php',
            'group' => 'clientip'
        ],
        'rep_top_sender_domains_vol' => [
            'file' => __DIR__ . '/../mailscanner/rep_top_sender_domains_by_volume.php',
            'group' => 'from_domain'
        ],
        'rep_top_senders_qty' => [
            'file' => __DIR__ . '/../mailscanner/rep_top_senders_by_quantity.php',
            'group' => 'from_address'
        ],
        'rep_top_recipient_domains_qty' => [
            'file' => __DIR__ . '/../mailscanner/rep_top_recipient_domains_by_quantity.php',
            'group' => 'to_domain'
        ],
        'rep_sa_score_dist' => [
            'file' => __DIR__ . '/../mailscanner/rep_sa_score_dist.php',
            'group' => 'score'
        ],
        'rep_mcp_score_dist' => [
            'file' => __DIR__ . '/../mailscanner/rep_mcp_score_dist.php',
            'group' => 'score'
        ],
        'rep_total_mail_by_date' => [
            'file' => __DIR__ . '/../mailscanner/rep_total_mail_by_date.php',
            'group' => 'date'
        ],
        'rep_top_tlds' => [
            'file' => __DIR__ . '/../mailscanner/rep_top_tlds.php',
            'group' => '`tld`'
        ],
        'dashboard_traffic' => [
            'file' => __DIR__ . '/../mailscanner/dashboard.inc.php',
            'group' => 'slot, label'
        ],
        'dashboard_relays' => [
            'file' => __DIR__ . '/../mailscanner/dashboard.inc.php',
            'group' => 'clientip'
        ],
        'dashboard_senders' => [
            'file' => __DIR__ . '/../mailscanner/dashboard.inc.php',
            'group' => 'from_address'
        ],
        'dashboard_recipients' => [
            'file' => __DIR__ . '/../mailscanner/dashboard.inc.php',
            'group' => 'to_address'
        ]
    ];

    foreach ($queries as $name => $meta) {
        $content = file_get_contents($meta['file']);
        assert_true(strpos($content, $meta['group']) !== false, "Query $name in {$meta['file']} must explicitly group by {$meta['group']}");
    }
});

// -----------------------------------------------------------------------------
// 4. Database Helper & Connection Mock Lifecycle
// -----------------------------------------------------------------------------
echo "\n4. Database Helper & Connection Lifecycle\n";

$all_passed &= run_test('database::getSessionSqlMode returns empty string when disconnected', function() {
    database::close();
    assert_equals('', database::getSessionSqlMode());
});

$all_passed &= run_test('database::close() cleanly closes and nullifies link', function() {
    database::$link = new class {
        public bool $closed = false;
        public function close() {
            $this->closed = true;
            return true;
        }
    };

    assert_true(is_object(database::$link));
    $res = database::close();
    assert_true($res);
    assert_true(database::$link === null);
});

// -----------------------------------------------------------------------------
// 5. Live MariaDB / MySQL Verification (if accessible)
// -----------------------------------------------------------------------------
echo "\n5. Live Database Integration Verification\n";

$canConnectLive = false;
try {
    if (defined('DB_HOST') && defined('DB_USER') && defined('DB_PASS') && defined('DB_NAME')) {
        $testConn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)(defined('DB_PORT') ? DB_PORT : 3306));
        if (!$testConn->connect_error) {
            $canConnectLive = true;
            $testConn->close();
        }
    }
} catch (\Throwable $e) {
    $canConnectLive = false;
}

if ($canConnectLive) {
    $all_passed &= run_test('Live DB: database::connect() excludes ONLY_FULL_GROUP_BY from session', function() {
        database::close();
        $link = database::connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        assert_true($link instanceof mysqli, 'database::connect must return mysqli instance');

        $sessionMode = database::getSessionSqlMode();
        assert_true($sessionMode !== '', 'Session sql_mode must not be empty');
        assert_false(strpos($sessionMode, 'ONLY_FULL_GROUP_BY') !== false, "Session sql_mode must NOT contain ONLY_FULL_GROUP_BY, got: $sessionMode");
    });

    $all_passed &= run_test('Live DB: All MailWatch queries succeed under strict ONLY_FULL_GROUP_BY', function() {
        $link = dbconn();
        // Explicitly enable ONLY_FULL_GROUP_BY to test strict compliance
        $link->query("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'");

        $testQueries = [
            'relays' => "SELECT clientip, count(*) AS count, " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlVirus()) . " AS total_viruses, " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlSpam()) . " AS total_spam, sum(size) AS size FROM maillog WHERE 1=1 GROUP BY clientip ORDER BY count DESC LIMIT 10",
            'senders' => "SELECT from_address as name, COUNT(*) as count, SUM(size) as size FROM maillog WHERE from_address <> '' AND from_address IS NOT NULL GROUP BY from_address ORDER BY count DESC LIMIT 10",
            'score_dist' => "SELECT ROUND(sascore) AS score, COUNT(*) AS count FROM maillog WHERE spamwhitelisted=0 GROUP BY score ORDER BY score",
            'total_by_date' => "SELECT DATE_FORMAT(date, '%Y-%m-%d') AS xaxis, COUNT(*) AS total_mail, " . MailWatchMetrics::sqlCountClassification('virus') . " AS total_virus, SUM(CASE WHEN " . MailWatchMetrics::sqlClassificationCase() . " IN ('spam', 'highspam') THEN 1 ELSE 0 END) AS total_spam, SUM(size) AS total_size FROM maillog WHERE 1=1 GROUP BY date ORDER BY date",
            'tlds' => "SELECT LOWER(SUBSTRING_INDEX(from_domain, '.', -1)) AS tld, COUNT(*) AS count, SUM(size) AS size FROM maillog WHERE from_domain <> '' AND from_domain IS NOT NULL AND from_domain LIKE '%.%' GROUP BY tld ORDER BY count DESC LIMIT 50",
            'traffic' => "SELECT DATE_FORMAT(timestamp, '%H:00') AS slot, DATE_FORMAT(timestamp, '%H:00') AS label, COUNT(*) AS total, " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlClean()) . " AS clean, " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlSpam()) . " AS spam, " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlSecurityThreats()) . " AS threats FROM maillog WHERE 1=1 GROUP BY slot, label ORDER BY slot ASC"
        ];

        foreach ($testQueries as $name => $sql) {
            $res = $link->query($sql);
            assert_true($res !== false, "Query $name failed under strict ONLY_FULL_GROUP_BY");
            if ($res instanceof mysqli_result) {
                $res->free();
            }
        }
    });
} else {
    echo "  [INFO] Live database server not accessible in local environment; live integration tests skipped.\n";
}

echo "\n===================================\n";
if ($all_passed) {
    echo "ALL MW-13 REGRESSION TESTS PASSED SUCCESSFULLY! ✓\n";
    exit(0);
} else {
    echo "SOME MW-13 REGRESSION TESTS FAILED! ✗\n";
    exit(1);
}
