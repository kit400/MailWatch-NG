<?php

/**
 * Regression Test Suite for MW-09:
 * mtalog and mtalog_ids cleanup: prevents infinite loops on NULL msg_id,
 * prevents unintended deletion of fresh records sharing msg_id across time or hosts,
 * enforces deletion by unique mtalog_id with strict timestamp boundary,
 * verifies progress check on each iteration, and enforces execution budgets.
 */

define('MAILWATCH_TEST_RUNNER', true);
require_once __DIR__ . '/../mailscanner/functions.php';
require_once __DIR__ . '/../mailscanner/database.php';
require_once __DIR__ . '/../tools/Cron_jobs/mailwatch_db_clean.php';

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

/**
 * Mock Store simulating mtalog and mtalog_ids tables with query execution
 */
class MW09MtalogMockStore {
    public array $mtalog = [];
    public array $mtalog_ids = [];
    public array $queryLog = [];
    public int $affected_rows = 0;
    public bool $simulateNoProgress = false;

    public function query(string $query) {
        $this->queryLog[] = $query;

        // SELECT mtalog_id, msg_id FROM mtalog WHERE timestamp < (NOW() - INTERVAL X DAY) LIMIT Y
        if (preg_match("/SELECT mtalog_id, msg_id FROM \w+ WHERE timestamp < \(NOW\(\) - INTERVAL (\d+) DAY\) LIMIT (\d+)/i", $query, $m)) {
            $days = (int)$m[1];
            $limit = (int)$m[2];
            $cutoff = time() - ($days * 86400);

            $rows = [];
            foreach ($this->mtalog as $r) {
                if ($r['timestamp'] < $cutoff) {
                    $rows[] = ['mtalog_id' => $r['mtalog_id'], 'msg_id' => $r['msg_id']];
                    if (count($rows) >= $limit) {
                        break;
                    }
                }
            }

            return new class($rows) {
                private array $rows;
                private int $pos = 0;
                public int $num_rows;
                public function __construct(array $rows) {
                    $this->rows = $rows;
                    $this->num_rows = count($rows);
                }
                public function fetch_assoc() {
                    return $this->rows[$this->pos++] ?? null;
                }
            };
        }

        // DELETE LOW_PRIORITY FROM mtalog WHERE mtalog_id IN (...) AND timestamp < ...
        if (preg_match("/DELETE LOW_PRIORITY FROM \w+ WHERE mtalog_id IN \(([^)]+)\) AND timestamp < \(NOW\(\) - INTERVAL (\d+) DAY\)/i", $query, $m)) {
            if ($this->simulateNoProgress) {
                $this->affected_rows = 0;
                return true;
            }

            $ids = array_map('intval', explode(',', $m[1]));
            $days = (int)$m[2];
            $cutoff = time() - ($days * 86400);
            $deleted = 0;

            foreach ($this->mtalog as $k => $r) {
                if (in_array($r['mtalog_id'], $ids, true) && $r['timestamp'] < $cutoff) {
                    unset($this->mtalog[$k]);
                    $deleted++;
                }
            }
            $this->affected_rows = $deleted;
            return true;
        }

        // Unbatched DELETE LOW_PRIORITY FROM mtalog WHERE timestamp < (NOW() - INTERVAL X DAY)
        if (preg_match("/DELETE LOW_PRIORITY FROM \w+ WHERE timestamp < \(NOW\(\) - INTERVAL (\d+) DAY\)$/i", $query, $m)) {
            $days = (int)$m[1];
            $cutoff = time() - ($days * 86400);
            $deleted = 0;

            foreach ($this->mtalog as $k => $r) {
                if ($r['timestamp'] < $cutoff) {
                    unset($this->mtalog[$k]);
                    $deleted++;
                }
            }
            $this->affected_rows = $deleted;
            return true;
        }

        // DELETE FROM mtalog_ids WHERE smtp_id IN (...) AND smtp_id NOT IN (SELECT msg_id FROM mtalog WHERE msg_id IN (...) AND msg_id IS NOT NULL)
        if (preg_match("/DELETE FROM \w+ WHERE smtp_id IN \(([^)]+)\) AND smtp_id NOT IN/i", $query, $m)) {
            $rawList = explode(',', $m[1]);
            $candidateIds = array_map(function($s) { return trim($s, " '\""); }, $rawList);

            // Find all active msg_ids still remaining in mtalog
            $activeMsgIds = [];
            foreach ($this->mtalog as $r) {
                if (!empty($r['msg_id'])) {
                    $activeMsgIds[$r['msg_id']] = true;
                }
            }

            $deleted = 0;
            foreach ($this->mtalog_ids as $k => $r) {
                if (in_array($r['smtp_id'], $candidateIds, true) && !isset($activeMsgIds[$r['smtp_id']])) {
                    unset($this->mtalog_ids[$k]);
                    $deleted++;
                }
            }
            $this->affected_rows = $deleted;
            return true;
        }

        // Unbatched DELETE FROM mtalog_ids WHERE smtp_id IN (...) AND smtp_id NOT IN (...)
        if (preg_match("/DELETE FROM \w+ WHERE smtp_id IN \(.*?\bSELECT msg_id FROM \w+ WHERE timestamp < .*?\) AND smtp_id NOT IN/i", $query)) {
            $deleted = 0;
            $cutoff = time() - (60 * 86400);

            // Fresh msg_ids
            $freshMsgIds = [];
            foreach ($this->mtalog as $r) {
                if ($r['timestamp'] >= $cutoff && !empty($r['msg_id'])) {
                    $freshMsgIds[$r['msg_id']] = true;
                }
            }

            foreach ($this->mtalog_ids as $k => $r) {
                if (!isset($freshMsgIds[$r['smtp_id']])) {
                    unset($this->mtalog_ids[$k]);
                    $deleted++;
                }
            }
            $this->affected_rows = $deleted;
            return true;
        }

        $this->affected_rows = 0;
        return true;
    }
}

echo "=== MW-09 Regression Test Suite ===\n\n";
$all_passed = true;

// -------------------------------------------------------------
// 1. Fixture 1: Batch with only NULL msg_id values
// -------------------------------------------------------------
echo "1. Fixture 1: Batch with only NULL msg_id values (Infinite Loop Prevention)\n";

$all_passed &= run_test('NULL msg_id rows are deleted by mtalog_id and loop terminates cleanly', function() {
    $store = new MW09MtalogMockStore();
    // 5 old rows with NULL msg_id
    $store->mtalog = [
        ['mtalog_id' => 1, 'msg_id' => null, 'timestamp' => time() - 100 * 86400],
        ['mtalog_id' => 2, 'msg_id' => null, 'timestamp' => time() - 95 * 86400],
        ['mtalog_id' => 3, 'msg_id' => null, 'timestamp' => time() - 90 * 86400],
        ['mtalog_id' => 4, 'msg_id' => null, 'timestamp' => time() - 85 * 86400],
        ['mtalog_id' => 5, 'msg_id' => null, 'timestamp' => time() - 80 * 86400],
    ];
    database::$link = $store;

    $totalDeleted = cleanMtalogWithIds(2, 60);

    // All 5 rows must be deleted
    assert_equals(5, $totalDeleted, 'Must delete all 5 NULL msg_id rows');
    assert_equals(0, count($store->mtalog), 'mtalog must have 0 rows left');
    // Loop must terminate after 3 batches (2 + 2 + 1) without infinite loop
    assert_true(count($store->queryLog) <= 8, 'Loop must terminate cleanly within reasonable query count');
});

// -------------------------------------------------------------
// 2. Fixture 2: Old and fresh records sharing the same msg_id
// -------------------------------------------------------------
echo "\n2. Fixture 2: Old and fresh records sharing the same msg_id (Preservation of Fresh Logs)\n";

$all_passed &= run_test('Old record deleted while fresh record with same msg_id and mtalog_ids mapping are preserved', function() {
    $store = new MW09MtalogMockStore();
    $sharedMsgId = 'REUSED_QID_42';

    // Old record from 90 days ago
    $store->mtalog[] = ['mtalog_id' => 101, 'msg_id' => $sharedMsgId, 'timestamp' => time() - 90 * 86400];
    // Fresh record from today (10 minutes ago)
    $store->mtalog[] = ['mtalog_id' => 202, 'msg_id' => $sharedMsgId, 'timestamp' => time() - 600];

    // mtalog_ids mapping
    $store->mtalog_ids[] = ['smtpd_id' => 'IN_Q1', 'smtp_id' => $sharedMsgId];

    database::$link = $store;

    $totalDeleted = cleanMtalogWithIds(10, 60);

    // Only the old record must be deleted
    assert_equals(1, $totalDeleted, 'Must delete exactly 1 old record');
    assert_equals(1, count($store->mtalog), 'Exactly 1 record must remain in mtalog');

    $remainingRecord = reset($store->mtalog);
    assert_equals(202, $remainingRecord['mtalog_id'], 'Remaining record must be the fresh record (mtalog_id 202)');
    assert_equals($sharedMsgId, $remainingRecord['msg_id'], 'Remaining record must retain msg_id');

    // mtalog_ids entry must be preserved because fresh record 202 still references it!
    assert_equals(1, count($store->mtalog_ids), 'mtalog_ids entry for reused msg_id must be PRESERVED for active fresh message');
});

// -------------------------------------------------------------
// 3. Fixture 3: Different hosts with the same queue ID (msg_id)
// -------------------------------------------------------------
echo "\n3. Fixture 3: Different hosts with identical queue ID\n";

$all_passed &= run_test('Old log from Host A deleted, fresh log from Host B with same queue ID preserved', function() {
    $store = new MW09MtalogMockStore();
    $queueId = 'IDENTICAL_QID';

    // Host A: old record
    $store->mtalog[] = ['mtalog_id' => 301, 'host' => 'mx1.example.com', 'msg_id' => $queueId, 'timestamp' => time() - 120 * 86400];
    // Host B: fresh record
    $store->mtalog[] = ['mtalog_id' => 302, 'host' => 'mx2.example.com', 'msg_id' => $queueId, 'timestamp' => time() - 300];

    database::$link = $store;

    $totalDeleted = cleanMtalogWithIds(10, 60);

    assert_equals(1, $totalDeleted, 'Must delete exactly 1 record (Host A)');
    assert_equals(1, count($store->mtalog), 'Host B fresh record must remain');
    $remaining = reset($store->mtalog);
    assert_equals(302, $remaining['mtalog_id'], 'Host B record must remain');
});

// -------------------------------------------------------------
// 4. Fixture 4: Orphaned mtalog_ids cleanup
// -------------------------------------------------------------
echo "\n4. Fixture 4: Orphaned mtalog_ids cleanup\n";

$all_passed &= run_test('When old record is deleted and msg_id is no longer in mtalog, mtalog_ids mapping is purged', function() {
    $store = new MW09MtalogMockStore();
    $orphanId = 'ORPHAN_MSG_123';

    $store->mtalog[] = ['mtalog_id' => 401, 'msg_id' => $orphanId, 'timestamp' => time() - 100 * 86400];
    $store->mtalog_ids[] = ['smtpd_id' => 'IN_ORPHAN', 'smtp_id' => $orphanId];

    database::$link = $store;

    $totalDeleted = cleanMtalogWithIds(10, 60);

    assert_equals(1, $totalDeleted, 'Old mtalog record must be deleted');
    assert_equals(0, count($store->mtalog), 'mtalog must be empty');
    assert_equals(0, count($store->mtalog_ids), 'mtalog_ids mapping must be purged when no active mtalog references exist');
});

// -------------------------------------------------------------
// 5. Fixture 5: Progress verification & Loop Safety
// -------------------------------------------------------------
echo "\n5. Fixture 5: Progress verification & Loop Safety\n";

$all_passed &= run_test('Loop breaks immediately when zero progress is made on a batch', function() {
    $store = new MW09MtalogMockStore();
    $store->mtalog = [
        ['mtalog_id' => 501, 'msg_id' => 'TEST', 'timestamp' => time() - 100 * 86400],
    ];
    // Simulate zero progress on DELETE
    $store->simulateNoProgress = true;
    database::$link = $store;

    $totalDeleted = cleanMtalogWithIds(10, 60);

    assert_equals(0, $totalDeleted, 'Total deleted must be 0');
    // Must stop after 1 SELECT + 1 DELETE attempt, NOT loop indefinitely!
    assert_true(count($store->queryLog) <= 2, 'Must break on zero progress without looping, executed: ' . count($store->queryLog));
});

// -------------------------------------------------------------
// 6. Fixture 6: Execution Budgets (maxBatches & maxExecutionTime)
// -------------------------------------------------------------
echo "\n6. Fixture 6: Execution Budgets\n";

$all_passed &= run_test('Batch budget limit stops mtalog cleanup when maxBatches reached', function() {
    $store = new MW09MtalogMockStore();
    for ($i = 1; $i <= 30; $i++) {
        $store->mtalog[] = ['mtalog_id' => $i, 'msg_id' => "MSG_$i", 'timestamp' => time() - 100 * 86400];
    }
    database::$link = $store;

    // batchSize = 5, maxBatches = 3 -> should delete 15 rows
    $totalDeleted = cleanMtalogWithIds(5, 60, 3);

    assert_equals(15, $totalDeleted, 'Should delete exactly 3 batches of 5 = 15 rows');
    assert_equals(15, count($store->mtalog), '15 rows must remain');
});

// -------------------------------------------------------------
// 7. Fixture 7: Unbatched Cleanup (batchSize = 0)
// -------------------------------------------------------------
echo "\n7. Fixture 7: Unbatched Cleanup (batchSize = 0)\n";

$all_passed &= run_test('Unbatched cleanup deletes all expired records and preserves fresh records', function() {
    $store = new MW09MtalogMockStore();
    $reused = 'UNBATCHED_REUSED';

    $store->mtalog = [
        ['mtalog_id' => 701, 'msg_id' => $reused, 'timestamp' => time() - 100 * 86400],
        ['mtalog_id' => 702, 'msg_id' => null, 'timestamp' => time() - 90 * 86400],
        ['mtalog_id' => 703, 'msg_id' => $reused, 'timestamp' => time() - 60], // fresh!
    ];
    $store->mtalog_ids = [
        ['smtpd_id' => 'SMTPD_7', 'smtp_id' => $reused],
    ];
    database::$link = $store;

    $totalDeleted = cleanMtalogWithIds(0, 60);

    assert_equals(2, $totalDeleted, 'Must delete 2 expired rows');
    assert_equals(1, count($store->mtalog), '1 fresh row must remain');
    assert_equals(703, reset($store->mtalog)['mtalog_id'], 'Remaining row must be fresh row 703');
    assert_equals(1, count($store->mtalog_ids), 'mtalog_ids entry for fresh row must be preserved');
});

echo "\n===================================\n";
if ($all_passed) {
    echo "ALL MW-09 REGRESSION TESTS PASSED SUCCESSFULLY! ✓\n\n";
    exit(0);
} else {
    echo "SOME MW-09 TESTS FAILED! ✗\n\n";
    exit(1);
}
