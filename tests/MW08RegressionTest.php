<?php

/**
 * Regression Test Suite for MW-08:
 * Batch database cleanup stopping after first DELETE due to invalid affected_rows inspection on boolean result.
 * Verifies query/execute interface separation, immediate reading of dbconn()->affected_rows,
 * complete multi-batch deletion, accurate return counters, zero volume, single batch, batchSize=0,
 * error handling, and execution budget enforcement.
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
 * Mock Connection for simulating MySQL responses, queries, and affected_rows counts
 */
class MW08MockConnection {
    public int $totalRows = 0;
    public int $affected_rows = 0;
    public array $queryLog = [];
    public bool $shouldFail = false;
    public string $failMessage = 'Simulated database error';
    public int $failCode = 1064;
    public string $error = '';
    public int $errno = 0;
    public $onQuery = null;

    public function __construct(int $initialRows = 0) {
        $this->totalRows = $initialRows;
    }

    public function query(string $query): bool {
        $this->queryLog[] = $query;

        if ($this->shouldFail) {
            $this->affected_rows = -1;
            $this->error = $this->failMessage;
            $this->errno = $this->failCode;
            return false;
        }

        if (is_callable($this->onQuery)) {
            $fn = $this->onQuery;
            return $fn($this, $query);
        }

        if (preg_match('/DELETE LOW_PRIORITY FROM \w+ WHERE .*?(?: LIMIT (\d+))?$/i', $query, $matches)) {
            $limit = isset($matches[1]) ? (int)$matches[1] : 0;
            if ($limit > 0) {
                $deleted = min($this->totalRows, $limit);
            } else {
                $deleted = $this->totalRows;
            }
            $this->totalRows -= $deleted;
            $this->affected_rows = $deleted;
            return true;
        }

        $this->affected_rows = 0;
        return true;
    }
}

echo "=== MW-08 Regression Test Suite ===\n\n";
$all_passed = true;

// -------------------------------------------------------------
// 1. Interface Separation: dbquery vs dbexecute
// -------------------------------------------------------------
echo "1. Interface Separation (dbquery vs dbexecute)\n";

$all_passed &= run_test('dbexecute returns affected_rows integer on successful modifying statement', function() {
    $mock = new MW08MockConnection(100);
    database::$link = $mock;

    $affected = dbexecute("DELETE LOW_PRIORITY FROM test_table WHERE 1=1 LIMIT 25", false);
    assert_equals(25, $affected, 'dbexecute must return affected_rows (25)');
    assert_equals(75, $mock->totalRows, '75 rows should remain');
});

$all_passed &= run_test('database::execute returns affected_rows integer', function() {
    $mock = new MW08MockConnection(50);
    database::$link = $mock;

    $affected = database::execute("DELETE LOW_PRIORITY FROM test_table WHERE 1=1 LIMIT 20");
    assert_equals(20, $affected, 'database::execute must return 20');
    assert_equals(30, $mock->totalRows, '30 rows should remain');
});

$all_passed &= run_test('dbexecute returns -1 on query failure when printError is false', function() {
    $mock = new MW08MockConnection(50);
    $mock->shouldFail = true;
    database::$link = $mock;

    $affected = dbexecute("DELETE LOW_PRIORITY FROM test_table WHERE 1=1", false);
    assert_equals(-1, $affected, 'dbexecute must return -1 on failure');
});

// -------------------------------------------------------------
// 2. Multi-Batch Deletion & Accurate Counter (User Issue MW-08)
// -------------------------------------------------------------
echo "\n2. Multi-Batch Deletion (Resolution of MW-08)\n";

$all_passed &= run_test('More than two batches: 25,001 rows with batchSize 10,000 fully deleted in 4 queries', function() {
    $mock = new MW08MockConnection(25001);
    database::$link = $mock;

    // Call deleteInBatches
    $totalDeleted = deleteInBatches('maillog', 'timestamp < NOW()', 10000);

    // Verify all rows deleted
    assert_equals(25001, $totalDeleted, 'Total deleted must be exact count (25,001), not 0');
    assert_equals(0, $mock->totalRows, 'All 25,001 rows must be deleted, 0 remain');

    // Verify query count: batch 1 (10k), batch 2 (10k), batch 3 (5001), batch 4 (0 -> loop terminates)
    assert_equals(4, count($mock->queryLog), 'Must execute exactly 4 queries to delete 25,001 rows');
    assert_true(strpos($mock->queryLog[0], 'LIMIT 10000') !== false, 'Query 1 must have LIMIT 10000');
    assert_true(strpos($mock->queryLog[1], 'LIMIT 10000') !== false, 'Query 2 must have LIMIT 10000');
    assert_true(strpos($mock->queryLog[2], 'LIMIT 10000') !== false, 'Query 3 must have LIMIT 10000');
    assert_true(strpos($mock->queryLog[3], 'LIMIT 10000') !== false, 'Query 4 must have LIMIT 10000');
});

$all_passed &= run_test('Three small batches: 25 rows with batchSize 10', function() {
    $mock = new MW08MockConnection(25);
    database::$link = $mock;

    $totalDeleted = deleteInBatches('audit_log', 'timestamp < NOW()', 10);

    assert_equals(25, $totalDeleted, 'Total deleted must be 25');
    assert_equals(0, $mock->totalRows, '0 rows must remain');
    assert_equals(4, count($mock->queryLog), 'Must execute 4 queries (10, 10, 5, 0)');
});

// -------------------------------------------------------------
// 3. Edge Cases: Zero Volume, Single Batch, batchSize = 0
// -------------------------------------------------------------
echo "\n3. Edge Cases (Zero Volume, Single Batch, batchSize = 0)\n";

$all_passed &= run_test('Zero volume: 0 rows to delete terminates immediately and returns 0', function() {
    $mock = new MW08MockConnection(0);
    database::$link = $mock;

    $totalDeleted = deleteInBatches('maillog', 'timestamp < NOW()', 10000);

    assert_equals(0, $totalDeleted, 'Total deleted must be 0');
    assert_equals(0, $mock->totalRows, '0 rows must remain');
    assert_equals(1, count($mock->queryLog), 'Must execute exactly 1 query and exit');
});

$all_passed &= run_test('Exactly one batch: 10,000 rows with batchSize 10,000', function() {
    $mock = new MW08MockConnection(10000);
    database::$link = $mock;

    $totalDeleted = deleteInBatches('maillog', 'timestamp < NOW()', 10000);

    assert_equals(10000, $totalDeleted, 'Total deleted must be 10,000');
    assert_equals(0, $mock->totalRows, '0 rows must remain');
    assert_equals(2, count($mock->queryLog), 'Must execute 2 queries (10000, then 0)');
});

$all_passed &= run_test('batchSize = 0: unbatched delete deletes all rows in single query without LIMIT', function() {
    $mock = new MW08MockConnection(25001);
    database::$link = $mock;

    $totalDeleted = deleteInBatches('maillog', 'timestamp < NOW()', 0);

    assert_equals(25001, $totalDeleted, 'Total deleted must be 25,001');
    assert_equals(0, $mock->totalRows, '0 rows must remain');
    assert_equals(1, count($mock->queryLog), 'Must execute exactly 1 query');
    assert_false(strpos($mock->queryLog[0], 'LIMIT'), 'Unbatched query must NOT contain LIMIT');
});

// -------------------------------------------------------------
// 4. Error Handling and Execution Budget Enforcement
// -------------------------------------------------------------
echo "\n4. Error Handling & Execution Budget Limits\n";

$all_passed &= run_test('Database error on first batch: handled gracefully, returns 0, no infinite loop', function() {
    $mock = new MW08MockConnection(1000);
    $mock->shouldFail = true;
    database::$link = $mock;

    $totalDeleted = deleteInBatches('bad_table', '1=1', 100);

    assert_equals(0, $totalDeleted, 'Should return 0 deleted rows');
    assert_equals(1, count($mock->queryLog), 'Should abort after 1 failed query');
});

$all_passed &= run_test('Database error on subsequent batch: terminates loop and returns partial count', function() {
    $mock = new MW08MockConnection(500);
    $mock->onQuery = function($mockObj, $query) {
        if (count($mockObj->queryLog) >= 3) {
            $mockObj->affected_rows = -1;
            $mockObj->error = 'Connection dropped';
            return false;
        }
        $deleted = min($mockObj->totalRows, 100);
        $mockObj->totalRows -= $deleted;
        $mockObj->affected_rows = $deleted;
        return true;
    };
    database::$link = $mock;

    $totalDeleted = deleteInBatches('maillog', '1=1', 100);

    assert_equals(200, $totalDeleted, 'Should return count from successful batches (200)');
    assert_equals(300, $mock->totalRows, '300 rows remain unpurged');
    assert_equals(3, count($mock->queryLog), 'Should stop at failure query');
});

$all_passed &= run_test('Batch budget limit: stops when maxBatches is reached', function() {
    $mock = new MW08MockConnection(50000);
    database::$link = $mock;

    // Request maxBatches = 3 with batchSize = 1000
    $totalDeleted = deleteInBatches('maillog', '1=1', 1000, 3);

    assert_equals(3000, $totalDeleted, 'Should delete exactly 3 batches of 1000 = 3000');
    assert_equals(47000, $mock->totalRows, '47000 rows remain');
    assert_equals(3, count($mock->queryLog), 'Should execute exactly 3 queries');
});

$all_passed &= run_test('Time budget limit: stops when execution time exceeds limit', function() {
    $mock = new MW08MockConnection(50000);
    $mock->onQuery = function($mockObj, $query) {
        $deleted = min($mockObj->totalRows, 1000);
        $mockObj->totalRows -= $deleted;
        $mockObj->affected_rows = $deleted;
        // Simulate a delay of 150ms per batch
        usleep(150000);
        return true;
    };
    database::$link = $mock;

    // Limit time to 1 second
    $startTime = microtime(true);
    $totalDeleted = deleteInBatches('maillog', '1=1', 1000, 0, 1);
    $elapsed = microtime(true) - $startTime;

    assert_true($totalDeleted > 0, 'Should have deleted at least some rows');
    assert_true($totalDeleted < 50000, 'Should have stopped before deleting all 50,000 rows');
    assert_true($elapsed >= 0.8 && $elapsed <= 2.5, 'Elapsed time should be around 1 second, got: ' . $elapsed);
});

echo "\n===================================\n";
if ($all_passed) {
    echo "ALL MW-08 REGRESSION TESTS PASSED SUCCESSFULLY! ✓\n\n";
    exit(0);
} else {
    echo "SOME MW-08 TESTS FAILED! ✗\n\n";
    exit(1);
}
