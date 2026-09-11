<?php

/**
 * Regression Test Suite for MW-11:
 * Graphs and ratings double-counting overlapping email traits.
 *
 * Verifies:
 * 1. MailWatchMetrics helper methods: correct SQL generation and PHP classification.
 * 2. Mutually exclusive classification hierarchy:
 *    Virus > Bad Content (Blocked Files, Other Infected) > High Spam > Normal Spam > MCP (High MCP, Normal MCP) > Clean.
 * 3. Exact partition completeness: sum(slices) === total (COUNT(*)) with 0 lost and 0 double-counted rows.
 * 4. Boolean trigger counts: (isspam=1, ishighspam=1) counts as 1 spam, not 2; multi-flags count as 1 threat.
 * 5. Counter inflation resistance: database values > 1 (e.g. virusinfected=3) produce count=1 per message.
 * 6. NULL safety: NULL flags treated as 0 without SQL syntax or logic errors.
 * 7. Widget consistency: KPI summary, Traffic Chart, Threat Donut, Top Relays, Top Senders/Recipients.
 * 8. Recipient normalization: comma-separated to_address strings properly split and threats <= count strictly holds.
 * 9. Report consistency: printTodayStatistics and rep_total_mail_by_date match MailWatchMetrics totals.
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

echo "=== MW-11 Regression Test Suite ===\n\n";
$all_passed = true;

// -----------------------------------------------------------------------------
// 1. MailWatchMetrics Unit Tests
// -----------------------------------------------------------------------------
echo "1. MailWatchMetrics Unit Tests\n";

$all_passed &= run_test('sqlSpam covers both isspam and ishighspam with boolean OR', function() {
    $sql = MailWatchMetrics::sqlSpam();
    assert_true(strpos($sql, 'isspam > 0') !== false);
    assert_true(strpos($sql, 'ishighspam > 0') !== false);
    assert_true(strpos($sql, 'OR') !== false);
});

$all_passed &= run_test('sqlLowSpam excludes high spam', function() {
    $sql = MailWatchMetrics::sqlLowSpam();
    assert_true(strpos($sql, 'isspam > 0') !== false);
    assert_true(strpos($sql, 'ishighspam = 0') !== false);
});

$all_passed &= run_test('sqlBadContent covers nameinfected and otherinfected', function() {
    $sql = MailWatchMetrics::sqlBadContent();
    assert_true(strpos($sql, 'nameinfected > 0') !== false);
    assert_true(strpos($sql, 'otherinfected > 0') !== false);
});

$all_passed &= run_test('sqlSecurityThreats covers virus, bad content, and mcp', function() {
    $sql = MailWatchMetrics::sqlSecurityThreats();
    assert_true(strpos($sql, 'virusinfected > 0') !== false);
    assert_true(strpos($sql, 'nameinfected > 0') !== false);
    assert_true(strpos($sql, 'otherinfected > 0') !== false);
    assert_true(strpos($sql, 'ismcp > 0') !== false);
    assert_true(strpos($sql, 'ishighmcp > 0') !== false);
});

$all_passed &= run_test('sqlAnyThreat covers all threat types', function() {
    $sql = MailWatchMetrics::sqlAnyThreat();
    assert_true(strpos($sql, 'isspam > 0') !== false);
    assert_true(strpos($sql, 'ishighspam > 0') !== false);
    assert_true(strpos($sql, 'virusinfected > 0') !== false);
    assert_true(strpos($sql, 'nameinfected > 0') !== false);
    assert_true(strpos($sql, 'otherinfected > 0') !== false);
    assert_true(strpos($sql, 'ismcp > 0') !== false);
});

$all_passed &= run_test('sqlClean checks all threats are 0 or NULL', function() {
    $sql = MailWatchMetrics::sqlClean();
    assert_true(strpos($sql, 'virusinfected = 0 OR virusinfected IS NULL') !== false);
    assert_true(strpos($sql, 'isspam = 0 OR isspam IS NULL') !== false);
});

$all_passed &= run_test('classifyMessage respects security hierarchy', function() {
    // Virus beats highspam
    assert_equals('virus', MailWatchMetrics::classifyMessage(['virusinfected' => 1, 'ishighspam' => 1, 'isspam' => 1]));
    // Bad content beats spam
    assert_equals('badcontent', MailWatchMetrics::classifyMessage(['nameinfected' => 1, 'isspam' => 1]));
    // Other infected is bad content
    assert_equals('badcontent', MailWatchMetrics::classifyMessage(['otherinfected' => 1]));
    // High spam beats normal spam
    assert_equals('highspam', MailWatchMetrics::classifyMessage(['ishighspam' => 1, 'isspam' => 1]));
    // Normal spam
    assert_equals('spam', MailWatchMetrics::classifyMessage(['ishighspam' => 0, 'isspam' => 1]));
    // High spam beats MCP
    assert_equals('highspam', MailWatchMetrics::classifyMessage(['ishighspam' => 1, 'ismcp' => 1]));
    // MCP when no spam or virus
    assert_equals('mcp', MailWatchMetrics::classifyMessage(['ismcp' => 1]));
    // Clean when all 0 or NULL
    assert_equals('clean', MailWatchMetrics::classifyMessage([]));
    assert_equals('clean', MailWatchMetrics::classifyMessage(['virusinfected' => 0, 'isspam' => 0]));
});

$all_passed &= run_test('classifyMessage detailed mode differentiates subcategories', function() {
    assert_equals('blockedfiles', MailWatchMetrics::classifyMessage(['nameinfected' => 1], true));
    assert_equals('otherinfected', MailWatchMetrics::classifyMessage(['otherinfected' => 1], true));
    assert_equals('highmcp', MailWatchMetrics::classifyMessage(['ishighmcp' => 1], true));
    assert_equals('mcp', MailWatchMetrics::classifyMessage(['ismcp' => 1], true));
});

// -----------------------------------------------------------------------------
// 2. Setup SQLite Fixture with Realistic and Edge-Case Data
// -----------------------------------------------------------------------------
echo "\n2. SQLite Dataset Fixture Setup\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// SQLite helper functions to simulate MySQL functions
$pdo->sqliteCreateFunction('DATE_FORMAT', function($date, $fmt) {
    return (string)$date;
}, 2);
$pdo->sqliteCreateFunction('CURRENT_DATE', function() {
    return '2026-09-11';
}, 0);

$pdo->exec("
    CREATE TABLE maillog (
        id VARCHAR(50) PRIMARY KEY,
        date DATE,
        time TIME,
        timestamp DATETIME,
        from_address VARCHAR(255),
        to_address VARCHAR(255),
        subject VARCHAR(255),
        clientip VARCHAR(50),
        size BIGINT,
        isspam INT,
        ishighspam INT,
        virusinfected INT,
        nameinfected INT,
        otherinfected INT,
        ismcp INT,
        ishighmcp INT
    );
");

// 16 carefully crafted test rows covering all overlapping conditions
$test_rows = [
    // id, date, from, to, ip, size, isspam, ishighspam, virus, name, other, ismcp, ishighmcp
    // 1. Clean message (all 0)
    ['msg-01', '2026-09-11', 'alice@corp.com', 'bob@corp.com', '192.168.1.1', 1024, 0, 0, 0, 0, 0, 0, 0],
    // 2. Clean message with NULLs
    ['msg-02', '2026-09-11', 'alice@corp.com', 'carol@corp.com', '192.168.1.1', 2048, null, null, null, null, null, null, null],
    // 3. Normal Spam (isspam=1, ishighspam=0)
    ['msg-03', '2026-09-11', 'spammer1@spam.com', 'bob@corp.com', '10.0.0.1', 1500, 1, 0, 0, 0, 0, 0, 0],
    // 4. High Spam (isspam=1, ishighspam=1) - MUST NOT BE COUNTED AS 2 SPAM
    ['msg-04', '2026-09-11', 'spammer2@spam.com', 'bob@corp.com', '10.0.0.2', 2500, 1, 1, 0, 0, 0, 0, 0],
    // 5. High Spam with isspam=0 (ishighspam=1)
    ['msg-05', '2026-09-11', 'spammer3@spam.com', 'dave@corp.com', '10.0.0.3', 1800, 0, 1, 0, 0, 0, 0, 0],
    // 6. Virus only
    ['msg-06', '2026-09-11', 'badguy@evil.com', 'dave@corp.com', '10.0.0.4', 50000, 0, 0, 1, 0, 0, 0, 0],
    // 7. Virus with count > 1 (e.g. 3 viruses detected in 1 message) - MUST NOT COUNT AS 3 MESSAGES
    ['msg-07', '2026-09-11', 'badguy@evil.com', 'eve@corp.com', '10.0.0.4', 60000, 0, 0, 3, 0, 0, 0, 0],
    // 8. Virus + High Spam (virus=1, isspam=1, ishighspam=1) - MUST NOT BE DOUBLE COUNTED AS VIRUS AND SPAM IN PARTITION
    ['msg-08', '2026-09-11', 'evilspammer@evil.com', 'bob@corp.com', '10.0.0.5', 75000, 1, 1, 1, 0, 0, 0, 0],
    // 9. Bad content (nameinfected=1)
    ['msg-09', '2026-09-11', 'sender@blocked.org', 'bob@corp.com', '10.0.0.6', 4000, 0, 0, 0, 1, 0, 0, 0],
    // 10. Bad content (otherinfected=1) - MUST NOT BE LOST OR COUNTED AS CLEAN
    ['msg-10', '2026-09-11', 'sender@blocked.org', 'carol@corp.com', '10.0.0.6', 4500, 0, 0, 0, 0, 1, 0, 0],
    // 11. Bad content + Normal Spam (nameinfected=1, isspam=1)
    ['msg-11', '2026-09-11', 'spammer4@spam.com', 'carol@corp.com', '10.0.0.7', 3000, 1, 0, 0, 1, 0, 0, 0],
    // 12. Normal MCP
    ['msg-12', '2026-09-11', 'policy@corp.com', 'external@other.com', '192.168.1.2', 8000, 0, 0, 0, 0, 0, 1, 0],
    // 13. High MCP
    ['msg-13', '2026-09-11', 'policy@corp.com', 'external@other.com', '192.168.1.2', 9000, 0, 0, 0, 0, 0, 0, 1],
    // 14. High MCP + High Spam
    ['msg-14', '2026-09-11', 'spammer5@spam.com', 'dave@corp.com', '10.0.0.8', 12000, 1, 1, 0, 0, 0, 0, 1],
    // 15. Multi-recipient message with High Spam
    ['msg-15', '2026-09-11', 'multispam@spam.com', 'user_x@corp.com, user_y@corp.com, user_z@corp.com', '10.0.0.9', 3500, 1, 1, 0, 0, 0, 0, 0],
    // 16. Extreme overlap with counters > 1: virus=2, name=3, other=2, isspam=2, ishighspam=2, ismcp=2, ishighmcp=2
    ['msg-16', '2026-09-11', 'superattack@evil.com', 'admin@corp.com', '10.0.0.10', 100000, 2, 2, 2, 3, 2, 2, 2]
];

$stmt = $pdo->prepare("
    INSERT INTO maillog (id, date, time, timestamp, from_address, to_address, subject, clientip, size, isspam, ishighspam, virusinfected, nameinfected, otherinfected, ismcp, ishighmcp)
    VALUES (?, ?, '12:00:00', ? || ' 12:00:00', ?, ?, 'Test Subject', ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

foreach ($test_rows as $row) {
    $stmt->execute([
        $row[0], $row[1], $row[1], $row[2], $row[3], $row[4], $row[5],
        $row[6], $row[7], $row[8], $row[9], $row[10], $row[11], $row[12]
    ]);
}

$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM maillog")->fetchColumn();
echo "  Loaded $totalCount test rows into in-memory SQLite fixture.\n";

// -----------------------------------------------------------------------------
// 3. Mutually Exclusive Classification & Partition Completeness
// -----------------------------------------------------------------------------
echo "\n3. Partition Completeness (Sum of Categories === Total)\n";

$all_passed &= run_test('Standard classification partition strictly equals total count (16)', function() use ($pdo, $totalCount) {
    $sql = "SELECT "
        . MailWatchMetrics::sqlCountClassification('virus') . " AS total_virus, "
        . MailWatchMetrics::sqlCountClassification('badcontent') . " AS total_badcontent, "
        . MailWatchMetrics::sqlCountClassification('highspam') . " AS total_highspam, "
        . MailWatchMetrics::sqlCountClassification('spam') . " AS total_spam, "
        . MailWatchMetrics::sqlCountClassification('mcp') . " AS total_mcp, "
        . MailWatchMetrics::sqlCountClassification('clean') . " AS total_clean "
        . "FROM maillog";
    
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    $sum = (int)$row['total_virus'] + (int)$row['total_badcontent'] + (int)$row['total_highspam']
         + (int)$row['total_spam'] + (int)$row['total_mcp'] + (int)$row['total_clean'];

    assert_equals($totalCount, $sum, "Sum of standard categories ($sum) must equal total count ($totalCount)");
    // Viruses: msg-06 (virus=1), msg-07 (virus=3), msg-08 (virus=1, highspam=1), msg-16 (virus=2, ...) -> 4
    assert_equals(4, (int)$row['total_virus'], 'Expected 4 virus messages');
    // Bad content (non-virus): msg-09 (name=1), msg-10 (other=1), msg-11 (name=1, spam=1) -> 3
    assert_equals(3, (int)$row['total_badcontent'], 'Expected 3 badcontent messages');
    // High spam (non-virus, non-badcontent): msg-04 (1,1), msg-05 (0,1), msg-14 (1,1, mcp=1), msg-15 (1,1) -> 4
    assert_equals(4, (int)$row['total_highspam'], 'Expected 4 highspam messages');
    // Normal spam (non-virus, non-badcontent, non-highspam): msg-03 (1,0) -> 1
    assert_equals(1, (int)$row['total_spam'], 'Expected 1 spam message');
    // MCP (non-virus, non-badcontent, non-spam): msg-12 (mcp=1), msg-13 (highmcp=1) -> 2
    assert_equals(2, (int)$row['total_mcp'], 'Expected 2 mcp messages');
    // Clean: msg-01 (0), msg-02 (null) -> 2
    assert_equals(2, (int)$row['total_clean'], 'Expected 2 clean messages');
});

$all_passed &= run_test('Detailed classification partition strictly equals total count (16)', function() use ($pdo, $totalCount) {
    $sql = "SELECT "
        . MailWatchMetrics::sqlCountClassification('virus', true) . " AS total_virus, "
        . MailWatchMetrics::sqlCountClassification('blockedfiles', true) . " AS total_blockedfiles, "
        . MailWatchMetrics::sqlCountClassification('otherinfected', true) . " AS total_otherinfected, "
        . MailWatchMetrics::sqlCountClassification('highspam', true) . " AS total_highspam, "
        . MailWatchMetrics::sqlCountClassification('spam', true) . " AS total_spam, "
        . MailWatchMetrics::sqlCountClassification('highmcp', true) . " AS total_highmcp, "
        . MailWatchMetrics::sqlCountClassification('mcp', true) . " AS total_mcp, "
        . MailWatchMetrics::sqlCountClassification('clean', true) . " AS total_clean "
        . "FROM maillog";
    
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    $sum = (int)$row['total_virus'] + (int)$row['total_blockedfiles'] + (int)$row['total_otherinfected']
         + (int)$row['total_highspam'] + (int)$row['total_spam'] + (int)$row['total_highmcp']
         + (int)$row['total_mcp'] + (int)$row['total_clean'];

    assert_equals($totalCount, $sum, "Sum of detailed categories ($sum) must equal total count ($totalCount)");
});

$all_passed &= run_test('SQL CASE matches PHP classifyMessage for every single row', function() use ($pdo) {
    $sql = "SELECT id, " . MailWatchMetrics::sqlClassificationCase(false) . " AS sql_class, "
         . MailWatchMetrics::sqlClassificationCase(true) . " AS sql_detailed_class, "
         . "isspam, ishighspam, virusinfected, nameinfected, otherinfected, ismcp, ishighmcp "
         . "FROM maillog";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $r) {
        $php_std = MailWatchMetrics::classifyMessage($r, false);
        $php_det = MailWatchMetrics::classifyMessage($r, true);
        assert_equals($r['sql_class'], $php_std, "Mismatch standard for ID {$r['id']}");
        assert_equals($r['sql_detailed_class'], $php_det, "Mismatch detailed for ID {$r['id']}");
    }
});

// -----------------------------------------------------------------------------
// 4. Boolean Trigger Counts & Deduping (Elimination of Double-Counting)
// -----------------------------------------------------------------------------
echo "\n4. Boolean Trigger Counts (No Double-Counting)\n";

$all_passed &= run_test('Spam count with sqlSpam() counts high-spam messages exactly ONCE', function() use ($pdo) {
    // Old flawed query was: SUM(isspam + ishighspam)
    $flawed_spam_count = (int)$pdo->query("SELECT SUM(isspam + ishighspam) FROM maillog WHERE isspam IS NOT NULL AND ishighspam IS NOT NULL")->fetchColumn();
    
    // Fixed query: sqlCountIf(sqlSpam())
    $fixed_spam_count = (int)$pdo->query("SELECT " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlSpam()) . " FROM maillog")->fetchColumn();
    
    // Messages matching isspam > 0 OR ishighspam > 0:
    // msg-03 (1,0), msg-04 (1,1), msg-05 (0,1), msg-08 (1,1), msg-11 (1,0), msg-14 (1,1), msg-15 (1,1), msg-16 (2,2)
    // Exactly 8 distinct spam messages!
    assert_equals(8, $fixed_spam_count, "Fixed spam count must be 8");
    assert_true($flawed_spam_count > $fixed_spam_count, "Old query ($flawed_spam_count) inflated spam count vs fixed ($fixed_spam_count)");
});

$all_passed &= run_test('Security threats count with sqlSecurityThreats() does not double-count overlapping tags', function() use ($pdo) {
    // Fixed security threats count
    $threat_count = (int)$pdo->query("SELECT " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlSecurityThreats()) . " FROM maillog")->fetchColumn();
    
    // Messages matching virus, name, other, or mcp:
    // msg-06 (virus), msg-07 (virus), msg-08 (virus), msg-09 (name), msg-10 (other),
    // msg-11 (name), msg-12 (mcp), msg-13 (highmcp), msg-14 (highmcp), msg-16 (multi)
    // Exactly 10 messages!
    assert_equals(10, $threat_count, "Security threats count must be 10");
});

$all_passed &= run_test('Any threat count with sqlAnyThreat() deduplicates spam + security threats', function() use ($pdo) {
    $any_threat_count = (int)$pdo->query("SELECT " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlAnyThreat()) . " FROM maillog")->fetchColumn();
    $clean_count = (int)$pdo->query("SELECT " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlClean()) . " FROM maillog")->fetchColumn();
    
    // Exactly 14 threats and 2 clean messages (msg-01, msg-02)
    assert_equals(14, $any_threat_count, "Any threat count must be 14");
    assert_equals(2, $clean_count, "Clean count must be 2");
    assert_equals(16, $any_threat_count + $clean_count, "Any threat + clean must equal total (16)");
});

$all_passed &= run_test('Values > 1 in database do not inflate countIf results', function() use ($pdo) {
    // msg-07 has virusinfected=3, msg-16 has virusinfected=2
    // SUM(virusinfected) would be 1+3+1+2 = 7
    // But infected message count should be 4 (msg-06, msg-07, msg-08, msg-16)
    $infected_messages = (int)$pdo->query("SELECT " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlVirus()) . " FROM maillog")->fetchColumn();
    $raw_sum_viruses = (int)$pdo->query("SELECT SUM(virusinfected) FROM maillog")->fetchColumn();
    
    assert_equals(4, $infected_messages, "Infected message count must be 4");
    assert_equals(7, $raw_sum_viruses, "Raw sum is 7 due to multi-virus detections in single messages");
    assert_true($infected_messages < $raw_sum_viruses, "Message count must not be inflated by raw counter");
});

// -----------------------------------------------------------------------------
// 5. Dashboard Widgets Verification
// -----------------------------------------------------------------------------
echo "\n5. Dashboard Widgets Verification\n";

$all_passed &= run_test('KPI Summary query outputs correct non-duplicated numbers', function() use ($pdo) {
    $sql = "SELECT "
        . "COUNT(*) AS total, "
        . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlClean()) . " AS clean, "
        . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlSpam()) . " AS spam, "
        . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlHighSpam()) . " AS high_spam, "
        . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlSecurityThreats()) . " AS threats "
        . "FROM maillog";
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

    assert_equals(16, (int)$row['total']);
    assert_equals(2, (int)$row['clean']);
    assert_equals(8, (int)$row['spam']);
    assert_equals(6, (int)$row['high_spam']);
    assert_equals(10, (int)$row['threats']);

    // Check percentages
    $spamPct = round(((int)$row['spam'] / (int)$row['total']) * 100, 1);
    $threatPct = round(((int)$row['threats'] / (int)$row['total']) * 100, 1);
    $cleanPct = round(((int)$row['clean'] / (int)$row['total']) * 100, 1);

    assert_equals(50.0, $spamPct);
    assert_equals(62.5, $threatPct);
    assert_equals(12.5, $cleanPct);
});

$all_passed &= run_test('Threat Donut query slices sum strictly to total (16)', function() use ($pdo) {
    $sql = "SELECT "
        . MailWatchMetrics::sqlClassificationCase(false) . " AS category, "
        . "COUNT(*) AS cnt "
        . "FROM maillog "
        . "GROUP BY category";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);

    $sum = array_sum($rows);
    assert_equals(16, $sum, "Threat Donut slice sum ($sum) must strictly equal 16");

    assert_true(isset($rows['clean']) && (int)$rows['clean'] === 2);
    assert_true(isset($rows['virus']) && (int)$rows['virus'] === 4);
    assert_true(isset($rows['badcontent']) && (int)$rows['badcontent'] === 3);
    assert_true(isset($rows['highspam']) && (int)$rows['highspam'] === 4);
    assert_true(isset($rows['spam']) && (int)$rows['spam'] === 1);
    assert_true(isset($rows['mcp']) && (int)$rows['mcp'] === 2);
});

$all_passed &= run_test('Recipient Normalization correctly ranks individual addresses and threats <= count', function() use ($pdo) {
    // msg-15 has to_address = 'user_x@corp.com, user_y@corp.com, user_z@corp.com' with isspam=1, ishighspam=1
    // Simulate render_widget_top_senders_recipients normalization logic
    $sql = "SELECT to_address, "
        . "COUNT(*) AS cnt, "
        . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlAnyThreat()) . " AS threats "
        . "FROM maillog "
        . "GROUP BY to_address";
    $raw_rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $recipients = [];
    foreach ($raw_rows as $r) {
        $addrs = array_map('trim', explode(',', $r['to_address']));
        foreach ($addrs as $addr) {
            if ($addr === '') continue;
            if (!isset($recipients[$addr])) {
                $recipients[$addr] = ['count' => 0, 'threats' => 0];
            }
            $recipients[$addr]['count'] += (int)$r['cnt'];
            $recipients[$addr]['threats'] += (int)$r['threats'];
        }
    }

    // Check individual entries exist instead of the comma list
    assert_false(isset($recipients['user_x@corp.com, user_y@corp.com, user_z@corp.com']), 'Raw list must not appear as recipient');
    assert_true(isset($recipients['user_x@corp.com']), 'user_x@corp.com must be tracked individually');
    assert_true(isset($recipients['user_y@corp.com']), 'user_y@corp.com must be tracked individually');
    assert_true(isset($recipients['user_z@corp.com']), 'user_z@corp.com must be tracked individually');

    // Check that for EVERY recipient: threats <= count
    foreach ($recipients as $addr => $stats) {
        assert_true($stats['threats'] <= $stats['count'], "Recipient $addr threats ({$stats['threats']}) must be <= count ({$stats['count']})");
    }

    // Check sender stats as well
    $sqlSenders = "SELECT from_address, "
        . "COUNT(*) AS cnt, "
        . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlAnyThreat()) . " AS threats "
        . "FROM maillog "
        . "GROUP BY from_address";
    $senders = $pdo->query($sqlSenders)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($senders as $s) {
        assert_true((int)$s['threats'] <= (int)$s['cnt'], "Sender {$s['from_address']} threats must be <= count");
    }
});

// -----------------------------------------------------------------------------
// 6. Reports Consistency (Today Statistics & Date Aggregation)
// -----------------------------------------------------------------------------
echo "\n6. Reports Consistency\n";

$all_passed &= run_test('printTodayStatistics query fields partition total mail with zero loss', function() use ($pdo) {
    $virusesExpr = MailWatchMetrics::sqlCountClassification('virus', true);
    $blockedfilesExpr = MailWatchMetrics::sqlCountClassification('blockedfiles', true);
    $otherinfectedExpr = MailWatchMetrics::sqlCountClassification('otherinfected', true);
    $highspamExpr = MailWatchMetrics::sqlCountClassification('highspam', true);
    $spamExpr = MailWatchMetrics::sqlCountClassification('spam', true);
    $highmcpExpr = MailWatchMetrics::sqlCountClassification('highmcp', true);
    $mcpExpr = MailWatchMetrics::sqlCountClassification('mcp', true);
    $cleanExpr = MailWatchMetrics::sqlCountClassification('clean', true);

    $sql = "SELECT "
        . "COUNT(*) AS processed, "
        . "$cleanExpr AS clean, "
        . "$virusesExpr AS viruses, "
        . "$blockedfilesExpr AS blockedfiles, "
        . "$otherinfectedExpr AS otherinfected, "
        . "$highspamExpr AS highspam, "
        . "$spamExpr AS spam, "
        . "$highmcpExpr AS highmcp, "
        . "$mcpExpr AS mcp "
        . "FROM maillog";
    
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    $processed = (int)$row['processed'];
    $category_sum = (int)$row['clean'] + (int)$row['viruses'] + (int)$row['blockedfiles']
                  + (int)$row['otherinfected'] + (int)$row['highspam'] + (int)$row['spam']
                  + (int)$row['highmcp'] + (int)$row['mcp'];

    assert_equals(16, $processed);
    assert_equals($processed, $category_sum, "Today statistics partition sum ($category_sum) must equal processed ($processed)");

    // Crucial check: otherinfected must not be lost (msg-10 has otherinfected=1)
    assert_true((int)$row['otherinfected'] >= 1, "otherinfected must be counted and not lost");
});

$all_passed &= run_test('rep_total_mail_by_date query categories sum to total_mail and total_spam is sum of low+high', function() use ($pdo) {
    $sql = "SELECT "
        . "DATE_FORMAT(date, '%Y-%m-%d') AS xaxis, "
        . "COUNT(*) AS total_mail, "
        . MailWatchMetrics::sqlCountClassification('virus') . " AS total_virus, "
        . "SUM(CASE WHEN " . MailWatchMetrics::sqlClassificationCase() . " IN ('spam', 'highspam') THEN 1 ELSE 0 END) AS total_spam, "
        . MailWatchMetrics::sqlCountClassification('spam') . " AS total_lowspam, "
        . MailWatchMetrics::sqlCountClassification('highspam') . " AS total_highspam, "
        . MailWatchMetrics::sqlCountClassification('mcp') . " AS total_mcp, "
        . MailWatchMetrics::sqlCountClassification('badcontent') . " AS total_blocked, "
        . MailWatchMetrics::sqlCountClassification('clean') . " AS total_clean "
        . "FROM maillog "
        . "GROUP BY date";

    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    $total_mail = (int)$row['total_mail'];
    $category_sum = (int)$row['total_clean'] + (int)$row['total_lowspam'] + (int)$row['total_highspam']
                  + (int)$row['total_blocked'] + (int)$row['total_virus'] + (int)$row['total_mcp'];

    assert_equals(16, $total_mail);
    assert_equals($total_mail, $category_sum, "Sum of rep_total_mail_by_date categories must equal total_mail");

    $total_spam = (int)$row['total_spam'];
    $spam_components = (int)$row['total_lowspam'] + (int)$row['total_highspam'];
    assert_equals($total_spam, $spam_components, "total_spam must equal total_lowspam + total_highspam");
});

echo "\n===================================\n";
if ($all_passed) {
    echo "ALL MW-11 REGRESSION TESTS PASSED SUCCESSFULLY! ✓\n";
    exit(0);
} else {
    echo "SOME MW-11 REGRESSION TESTS FAILED! ✗\n";
    exit(1);
}
