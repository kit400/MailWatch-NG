<?php

/**
 * Regression Test Suite for MW-03:
 * Address characters (_ and %) turning into SQL LIKE wildcards in access filters.
 */

// Mock DB constants if needed
if (!defined('FILTER_TO_ONLY')) {
    define('FILTER_TO_ONLY', false);
}

// Minimal dbconn mock if running standalone without live MySQL
if (!function_exists('dbconn')) {
}

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

echo "=== MW-03 Regression Test Suite ===\n\n";
$all_passed = true;

// 1. Test escape_like_pattern
echo "1. Unit Tests: escape_like_pattern and safe_like_value\n";

$all_passed &= run_test('Underscore is escaped with escape char', function() {
    assert_equals('first=_last@example.test', escape_like_pattern('first_last@example.test', '='));
});

$all_passed &= run_test('Percent is escaped with escape char', function() {
    assert_equals('user=%name@example.test', escape_like_pattern('user%name@example.test', '='));
});

$all_passed &= run_test('Escape char itself is doubled', function() {
    assert_equals('user==name@example.test', escape_like_pattern('user=name@example.test', '='));
});

$all_passed &= run_test('Combined special characters escaped properly', function() {
    // o'connor=_%@test.com: '=' -> '==', '_' -> '=_', '%' -> '=%' => '==_=%'
    assert_equals("o'connor===_=%@test.com", escape_like_pattern("o'connor=_%@test.com", '='));
});

$all_passed &= run_test('Safe characters like + and @ are preserved without escaping', function() {
    assert_equals('user+tag@example.test', escape_like_pattern('user+tag@example.test', '='));
});

$all_passed &= run_test('Empty string returns empty string', function() {
    assert_equals('', escape_like_pattern('', '='));
});

// 2. Test address_filter_sql structure
echo "\n2. Unit Tests: address_filter_sql generation\n";

$all_passed &= run_test('Admin role returns 1=1', function() {
    assert_equals('1=1', address_filter_sql(['user@example.com'], 'A'));
});

$all_passed &= run_test('Empty address list returns 1=0 (fail-closed)', function() {
    assert_equals('1=0', address_filter_sql([], 'U'));
    assert_equals('1=0', address_filter_sql(['', '  '], 'U'));
});

$all_passed &= run_test('User filter contains ESCAPE clause for LIKE patterns', function() {
    $sql = address_filter_sql(['first_last@example.test'], 'U');
    assert_true(strpos($sql, "ESCAPE '='") !== false, "SQL must contain ESCAPE '='");
    assert_true(strpos($sql, "first=_last@example.test") !== false, "SQL must contain escaped address");
    assert_true(strpos($sql, "to_address = 'first_last@example.test'") !== false, "SQL must contain exact equality match");
});

$all_passed &= run_test('Domain Admin filter handles @ and domain-only', function() {
    $sql = address_filter_sql(['first_last@example.test', 'example.org'], 'D');
    assert_true(strpos($sql, "to_address LIKE 'first=_last@example.test,%' ESCAPE '='") !== false);
    assert_true(strpos($sql, "to_domain='example.org'") !== false);
});

// 3. Integration Tests: SQLite execution simulation
echo "\n3. Integration Tests: SQLite Query Matching Matrix\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("
    CREATE TABLE maillog (
        id TEXT PRIMARY KEY,
        from_address TEXT,
        to_address TEXT,
        subject TEXT
    )
");

$stmt = $pdo->prepare("INSERT INTO maillog (id, from_address, to_address, subject) VALUES (?, ?, ?, ?)");
$test_rows = [
    ['msg-01', 'sender@example.com', 'first_last@example.test', 'Single recipient target'],
    ['msg-02', 'sender@example.com', 'first_last@example.test,other@example.test', 'Start of list target'],
    ['msg-03', 'sender@example.com', 'other@example.test,first_last@example.test,third@example.test', 'Middle of list target'],
    ['msg-04', 'sender@example.com', 'other@example.test,first_last@example.test', 'End of list target'],
    ['msg-05', 'sender@example.com', 'other@example.test, first_last@example.test', 'End of list with space'],
    ['msg-06', 'sender@example.com', 'firstXlast@example.test,other@example.test', 'Attacker similar recipient (X instead of _)'],
    ['msg-07', 'sender@example.com', 'first1last@example.test,other@example.test', 'Attacker similar recipient (1 instead of _)'],
    ['msg-08', 'sender@example.com', 'user%name@example.test,other@example.test', 'Percent recipient target'],
    ['msg-09', 'sender@example.com', 'userSOMETHINGname@example.test,other@example.test', 'Attacker similar percent expansion'],
    ['msg-10', 'sender@example.com', 'user+tag@example.test,other@example.test', 'Plus tag recipient'],
    ['msg-11', 'sender@example.com', 'o\'connor@example.test,other@example.test', 'Quote in recipient'],
    ['msg-12', 'sender@example.com', 'oXconnor@example.test,other@example.test', 'Similar quote target with X'],
    ['msg-13', 'first_last@example.test', 'somebody@example.com', 'From address match']
];
foreach ($test_rows as $row) {
    $stmt->execute($row);
}

$all_passed &= run_test('User with underscore (first_last) matches only own emails, not firstXlast or first1last', function() use ($pdo) {
    $filter_sql = address_filter_sql(['first_last@example.test'], 'U');
    $q = $pdo->query("SELECT id FROM maillog WHERE ($filter_sql) ORDER BY id");
    $matched_ids = $q->fetchAll(PDO::FETCH_COLUMN);

    // Expected: msg-01, msg-02, msg-03, msg-04, msg-05, msg-13
    $expected = ['msg-01', 'msg-02', 'msg-03', 'msg-04', 'msg-05', 'msg-13'];
    assert_equals($expected, $matched_ids, 'Matched IDs mismatch for first_last');

    // Crucial security check: msg-06 and msg-07 MUST NOT be matched!
    assert_false(in_array('msg-06', $matched_ids, true), 'SECURITY VIOLATION: firstXlast matched by first_last filter!');
    assert_false(in_array('msg-07', $matched_ids, true), 'SECURITY VIOLATION: first1last matched by first_last filter!');
});

$all_passed &= run_test('User with percent (user%name) matches only exact percent, not wildcard expansion', function() use ($pdo) {
    $filter_sql = address_filter_sql(['user%name@example.test'], 'U');
    $q = $pdo->query("SELECT id FROM maillog WHERE ($filter_sql) ORDER BY id");
    $matched_ids = $q->fetchAll(PDO::FETCH_COLUMN);

    assert_equals(['msg-08'], $matched_ids);
    assert_false(in_array('msg-09', $matched_ids, true), 'SECURITY VIOLATION: userSOMETHINGname matched by user%name filter!');
});

$all_passed &= run_test('User with quote (o\'connor) executes safely and matches only own email', function() use ($pdo) {
    $filter_sql = address_filter_sql(["o'connor@example.test"], 'U');
    $sqlite_sql = str_replace("\\'", "''", $filter_sql);
    $q = $pdo->query("SELECT id FROM maillog WHERE ($sqlite_sql) ORDER BY id");
    $matched_ids = $q->fetchAll(PDO::FETCH_COLUMN);

    assert_equals(['msg-11'], $matched_ids);
    assert_false(in_array('msg-12', $matched_ids, true), 'SECURITY VIOLATION: oXconnor matched by o\'connor filter!');
});

$all_passed &= run_test('User with plus (user+tag) matches correctly', function() use ($pdo) {
    $filter_sql = address_filter_sql(['user+tag@example.test'], 'U');
    $q = $pdo->query("SELECT id FROM maillog WHERE ($filter_sql) ORDER BY id");
    $matched_ids = $q->fetchAll(PDO::FETCH_COLUMN);

    assert_equals(['msg-10'], $matched_ids);
});

// 4. Operations access check: viewmail, viewpart, quarantine
echo "\n4. Simulated Operations Access Check (viewmail, viewpart, quarantine)\n";

$all_passed &= run_test('Simulate viewmail access control: unauthorized similar message returns empty', function() use ($pdo) {
    $user_filter = address_filter_sql(['first_last@example.test'], 'U');
    $global_filter = '(' . $user_filter . ')';

    // Authorized view
    $q1 = $pdo->query("SELECT * FROM maillog WHERE id='msg-02' AND $global_filter");
    assert_true($q1->fetch() !== false, 'Authorized viewmail query should find msg-02');

    // Unauthorized view of similar email (msg-06 belongs to firstXlast)
    $q2 = $pdo->query("SELECT * FROM maillog WHERE id='msg-06' AND $global_filter");
    assert_true($q2->fetch() === false, 'Unauthorized viewmail query on msg-06 MUST NOT return any row');
});

$all_passed &= run_test('Simulate viewpart / download access control: unauthorized message returns empty', function() use ($pdo) {
    $user_filter = address_filter_sql(['first_last@example.test'], 'U');
    $global_filter = '(' . $user_filter . ')';

    // Authorized viewpart
    $q1 = $pdo->query("SELECT id FROM maillog WHERE id='msg-04' AND $global_filter");
    assert_true($q1->fetch() !== false, 'Authorized viewpart should find msg-04');

    // Unauthorized viewpart on similar address
    $q2 = $pdo->query("SELECT id FROM maillog WHERE id='msg-06' AND $global_filter");
    assert_true($q2->fetch() === false, 'Unauthorized viewpart on msg-06 MUST NOT return any row');
});

$all_passed &= run_test('Simulate quarantine list items access control: unauthorized message blocked', function() use ($pdo) {
    $user_filter = address_filter_sql(['first_last@example.test'], 'U');
    $global_filter = '(' . $user_filter . ')';

    // Authorized quarantine check
    $q1 = $pdo->query("SELECT id FROM maillog WHERE id='msg-03' AND ($global_filter)");
    assert_true($q1->fetch() !== false, 'Authorized quarantine list should find msg-03');

    // Unauthorized quarantine check
    $q2 = $pdo->query("SELECT id FROM maillog WHERE id='msg-06' AND ($global_filter)");
    assert_true($q2->fetch() === false, 'Unauthorized quarantine list on msg-06 MUST NOT return any row');
});

echo "\n===================================\n";
if ($all_passed) {
    echo "ALL TESTS PASSED SUCCESSFULLY! ✓\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED! ✗\n";
    exit(1);
}
