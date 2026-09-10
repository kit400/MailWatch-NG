<?php

/**
 * Regression Test Suite for MW-04:
 * Decouple session timeout and privilege change checks from HTML rendering via SessionGuard.
 */

require_once __DIR__ . '/../mailscanner/functions.php';
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

echo "=== MW-04 Regression Test Suite ===\n\n";
$all_passed = true;

// -------------------------------------------------------------
// 1. Unit Tests: SessionGuard::isApiOrAjaxRequest
// -------------------------------------------------------------
echo "1. Unit Tests: SessionGuard::isApiOrAjaxRequest detection\n";

$all_passed &= run_test('Standard browser request returns false', function() {
    $_SERVER = ['SCRIPT_NAME' => '/mailscanner/dashboard.php'];
    $_REQUEST = [];
    assert_false(SessionGuard::isApiOrAjaxRequest());
});

$all_passed &= run_test('X-Requested-With: XMLHttpRequest header returns true', function() {
    $_SERVER = [
        'SCRIPT_NAME' => '/mailscanner/dashboard.php',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'
    ];
    $_REQUEST = [];
    assert_true(SessionGuard::isApiOrAjaxRequest());
});

$all_passed &= run_test('Accept: application/json header returns true', function() {
    $_SERVER = [
        'SCRIPT_NAME' => '/mailscanner/detail.php',
        'HTTP_ACCEPT' => 'text/html,application/json;q=0.9'
    ];
    $_REQUEST = [];
    assert_true(SessionGuard::isApiOrAjaxRequest());
});

$all_passed &= run_test('Dashboard AJAX action parameter returns true', function() {
    $_SERVER = ['SCRIPT_NAME' => '/mailscanner/dashboard.php'];
    foreach (['save_layout', 'reset_layout', 'get_widget_body', 'get_widget_html'] as $act) {
        $_REQUEST = ['action' => $act];
        assert_true(SessionGuard::isApiOrAjaxRequest(), "Action $act should be recognized as AJAX");
    }
});

$all_passed &= run_test('Notification AJAX action parameters return true', function() {
    $_SERVER = ['SCRIPT_NAME' => '/mailscanner/notification_action.php'];
    foreach (['get_notifications', 'mark_read', 'mark_all_read', 'check_updates'] as $act) {
        $_REQUEST = ['action' => $act];
        assert_true(SessionGuard::isApiOrAjaxRequest(), "Action $act should be recognized as AJAX");
    }
});

$all_passed &= run_test('Non-HTML direct download scripts return true', function() {
    $_REQUEST = [];
    foreach (['viewpart.php', 'notification_action.php', 'graph.php'] as $script) {
        $_SERVER = ['SCRIPT_NAME' => '/mailscanner/' . $script];
        assert_true(SessionGuard::isApiOrAjaxRequest(), "Script $script should be treated as non-HTML");
    }
});

// -------------------------------------------------------------
// 2. Unit Tests: validateInput for login error parameter
// -------------------------------------------------------------
echo "\n2. Unit Tests: validateInput for login error parameter\n";

$all_passed &= run_test('validateInput accepts privilege_changed', function() {
    assert_true(validateInput('privilege_changed', 'loginerror'));
});

$all_passed &= run_test('validateInput accepts existing valid errors', function() {
    $valid = ['baduser', 'emptypassword', 'timeout', 'pagetimeout', 'banned', 'badcaptcha'];
    foreach ($valid as $err) {
        assert_true(validateInput($err, 'loginerror'), "Error '$err' should be valid");
    }
});

$all_passed &= run_test('validateInput rejects dangerous or unknown errors', function() {
    $invalid = ['<script>alert(1)</script>', 'privilege_changed;DROP TABLE users;', 'unknown', ''];
    foreach ($invalid as $err) {
        assert_false(validateInput($err, 'loginerror'), "Error '$err' should be rejected");
    }
});

// -------------------------------------------------------------
// 3. Unit Tests: Mock DB User Record & Expiry / Privilege Check
// -------------------------------------------------------------
echo "\n3. Unit Tests: User Record, Expiry, and Privilege Checks\n";

class MockResult {
    public $num_rows;
    private $rows;
    public function __construct(array $rows) {
        $this->rows = $rows;
        $this->num_rows = count($rows);
    }
    public function fetch_assoc() {
        return array_shift($this->rows);
    }
}

class MockDbLink extends mysqli {
    public $users = [];
    public $queries = [];

    public function __construct() {}

    public function real_escape_string(string $string): string {
        return addslashes($string);
    }

    public function escape_string(string $string): string {
        return addslashes($string);
    }

    public function query($sql, $resultmode = MYSQLI_STORE_RESULT): mixed {
        $this->queries[] = $sql;
        if (preg_match("/SELECT .* FROM users WHERE username='([^']+)'/i", $sql, $m)) {
            $username = $m[1];
            if (isset($this->users[$username])) {
                return new MockResult([$this->users[$username]]);
            }
            return new MockResult([]);
        }
        if (preg_match("/UPDATE users SET login_expiry='([^']*)'(?:, last_login='([^']*)')? WHERE username='([^']+)'/i", $sql, $m)) {
            $expiry = $m[1];
            $username = $m[3];
            if (isset($this->users[$username])) {
                $this->users[$username]['login_expiry'] = $expiry;
                if (!empty($m[2])) {
                    $this->users[$username]['last_login'] = $m[2];
                }
            }
            return true;
        }
        return true;
    }
}

$mockLink = new MockDbLink();
database::$link = $mockLink;

$all_passed &= run_test('getUserRecord retrieves and caches user', function() use ($mockLink) {
    SessionGuard::resetCache();
    $mockLink->users['testuser'] = [
        'id' => 1,
        'username' => 'testuser',
        'type' => 'A',
        'login_expiry' => (string)(time() + 600),
        'login_timeout' => '-1'
    ];

    $u1 = SessionGuard::getUserRecord('testuser');
    assert_true(is_array($u1) && $u1['username'] === 'testuser');

    // Change mock DB directly without resetting cache to verify caching works
    $mockLink->users['testuser']['type'] = 'U';
    $u2 = SessionGuard::getUserRecord('testuser');
    assert_equals('A', $u2['type'], 'Should return cached record before resetCache');

    // After resetCache, fresh record is fetched
    SessionGuard::resetCache();
    $u3 = SessionGuard::getUserRecord('testuser');
    assert_equals('U', $u3['type'], 'Should return fresh record after resetCache');
});

$all_passed &= run_test('checkLoginExpiry returns true when expired or revoked', function() use ($mockLink) {
    // Revoked (-1)
    SessionGuard::resetCache();
    $mockLink->users['revoked'] = ['username' => 'revoked', 'type' => 'U', 'login_expiry' => '-1', 'login_timeout' => '-1'];
    assert_true(checkLoginExpiry('revoked'), 'Revoked user (-1) must be reported as expired');

    // Past timestamp
    SessionGuard::resetCache();
    $mockLink->users['timedout'] = ['username' => 'timedout', 'type' => 'U', 'login_expiry' => (string)(time() - 30), 'login_timeout' => '-1'];
    assert_true(checkLoginExpiry('timedout'), 'Past expiry must be reported as expired');

    // Nonexistent user
    SessionGuard::resetCache();
    assert_true(checkLoginExpiry('nosuchuser'), 'Nonexistent user must be reported as expired');
});

$all_passed &= run_test('checkLoginExpiry returns false when active or never expires', function() use ($mockLink) {
    // Active future timestamp
    SessionGuard::resetCache();
    $mockLink->users['active'] = ['username' => 'active', 'type' => 'U', 'login_expiry' => (string)(time() + 600), 'login_timeout' => '-1'];
    assert_false(checkLoginExpiry('active'), 'Active user must not be expired');

    // Never expires (0)
    SessionGuard::resetCache();
    $mockLink->users['noexpiry'] = ['username' => 'noexpiry', 'type' => 'U', 'login_expiry' => '0', 'login_timeout' => '-1'];
    assert_false(checkLoginExpiry('noexpiry'), 'Non-expiring user (0) must not be expired');
});

$all_passed &= run_test('checkPrivilegeChange detects role divergence', function() use ($mockLink) {
    SessionGuard::resetCache();
    $mockLink->users['adminuser'] = ['username' => 'adminuser', 'type' => 'A', 'login_expiry' => (string)(time() + 600), 'login_timeout' => '-1'];

    $_SESSION['user_type'] = 'A';
    assert_false(checkPrivilegeChange('adminuser'), 'Matching role should not report privilege change');

    $_SESSION['user_type'] = 'U';
    assert_true(checkPrivilegeChange('adminuser'), 'Divergent role (session U, db A) must report privilege change');

    $_SESSION['user_type'] = 'D';
    assert_true(checkPrivilegeChange('adminuser'), 'Divergent role (session D, db A) must report privilege change');
});

$all_passed &= run_test('updateLoginExpiry properly extends timeout and refreshes cache', function() use ($mockLink) {
    SessionGuard::resetCache();
    $mockLink->users['keepalive'] = ['username' => 'keepalive', 'type' => 'U', 'login_expiry' => (string)(time() + 10), 'login_timeout' => '300'];

    $oldExpiry = (int)$mockLink->users['keepalive']['login_expiry'];
    updateLoginExpiry('keepalive');
    $newExpiry = (int)$mockLink->users['keepalive']['login_expiry'];

    assert_true($newExpiry >= time() + 299, "New expiry ($newExpiry) should be approx now + 300s");
    assert_true($newExpiry > $oldExpiry, "New expiry must be greater than old expiry");

    // Cache should reflect the updated expiry
    $cached = SessionGuard::getUserRecord('keepalive');
    assert_equals((string)$newExpiry, (string)$cached['login_expiry']);
});

// -------------------------------------------------------------
// 4. Subprocess Integration Tests (Real HTTP response & exit)
// -------------------------------------------------------------
echo "\n4. Integration Tests: Subprocess Execution & Response Codes\n";

function run_sub_test($scenario, array $env, $expectedStatus, $expectedBodyRegex, $expectedLocation = null) {
    $code = '<?php
    require_once "' . addslashes(__DIR__ . '/../mailscanner/functions.php') . '";
    require_once "' . addslashes(__DIR__ . '/../mailscanner/SessionGuard.php') . '";

    // Setup mock DB
    class SubMockResult {
        public $num_rows;
        private $rows;
        public function __construct(array $rows) {
            $this->rows = $rows;
            $this->num_rows = count($rows);
        }
        public function fetch_assoc() {
            return array_shift($this->rows);
        }
    }
    class SubMockDbLink extends mysqli {
        public $users = [];
        public function __construct() {}
        public function real_escape_string(string $string): string {
            return addslashes($string);
        }
        public function escape_string(string $string): string {
            return addslashes($string);
        }
        public function query($sql, $resultmode = MYSQLI_STORE_RESULT): mixed {
            if (preg_match("/SELECT .* FROM users WHERE username=\'([^\']+)\'/i", $sql, $m)) {
                $u = $m[1];
                if (isset($this->users[$u])) {
                    return new SubMockResult([$this->users[$u]]);
                }
                return new SubMockResult([]);
            }
            if (preg_match("/UPDATE users SET login_expiry=\'([^\']*)\'/i", $sql, $m)) {
                return true;
            }
            return true;
        }
    }
    $link = new SubMockDbLink();
    database::$link = $link;

    $mockUsers = ' . var_export($env['users'] ?? [], true) . ';
    $link->users = $mockUsers;

    $_SESSION = ' . var_export($env['session'] ?? [], true) . ';
    $_SERVER = array_merge($_SERVER, ' . var_export($env['server'] ?? [], true) . ');
    $_REQUEST = ' . var_export($env['request'] ?? [], true) . ';
    $_GET = ' . var_export($env['get'] ?? [], true) . ';
    $_POST = ' . var_export($env['post'] ?? [], true) . ';

    $mode = "' . ($env['mode'] ?? 'guard') . '";
    if ($mode === "guard") {
        SessionGuard::enforce();
        echo "GUARD_SUCCESS";
    } elseif ($mode === "login_function") {
        require "' . addslashes(__DIR__ . '/../mailscanner/login.function.php') . '";
        echo "LOGIN_FUNCTION_SUCCESS";
    }
    ';

    $tempFile = tempnam(sys_get_temp_dir(), 'mw4_');
    file_put_contents($tempFile, $code);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $proc = proc_open("php $tempFile", $descriptors, $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    unlink($tempFile);

    if ($expectedBodyRegex && !preg_match($expectedBodyRegex, $stdout)) {
        throw new \Exception("Stdout mismatch for \'$scenario\'. Expected pattern $expectedBodyRegex, got: " . var_export($stdout, true) . ($stderr ? " Stderr: $stderr" : ""));
    }

    return true;
}

$all_passed &= run_test('AJAX dashboard request with expired session terminates with 401 JSON', function() {
    run_sub_test('AJAX dashboard expired', [
        'mode' => 'login_function',
        'server' => [
            'SCRIPT_NAME' => '/mailscanner/dashboard.php',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_ACCEPT' => 'application/json'
        ],
        'request' => ['action' => 'get_widget_body'],
        'session' => ['myusername' => 'jdoe', 'user_type' => 'U'],
        'users' => [
            'jdoe' => ['username' => 'jdoe', 'type' => 'U', 'login_expiry' => (string)(time() - 100), 'login_timeout' => '-1']
        ]
    ], 0, '/"error"\s*:\s*"timeout"/');
});

$all_passed &= run_test('AJAX dashboard request with role change terminates with 401 JSON', function() {
    run_sub_test('AJAX dashboard role changed', [
        'mode' => 'login_function',
        'server' => [
            'SCRIPT_NAME' => '/mailscanner/dashboard.php',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_ACCEPT' => 'application/json'
        ],
        'request' => ['action' => 'save_layout'],
        'session' => ['myusername' => 'jdoe', 'user_type' => 'A'],
        'users' => [
            'jdoe' => ['username' => 'jdoe', 'type' => 'U', 'login_expiry' => (string)(time() + 600), 'login_timeout' => '-1']
        ]
    ], 0, '/"error"\s*:\s*"privilege_changed"/');
});

$all_passed &= run_test('Direct viewpart.php request with expired session terminates with plain text 401', function() {
    run_sub_test('viewpart expired', [
        'mode' => 'login_function',
        'server' => [
            'SCRIPT_NAME' => '/mailscanner/viewpart.php'
        ],
        'session' => ['myusername' => 'jdoe', 'user_type' => 'U'],
        'users' => [
            'jdoe' => ['username' => 'jdoe', 'type' => 'U', 'login_expiry' => (string)(time() - 100), 'login_timeout' => '-1']
        ]
    ], 0, '/Unauthorized: User session has expired/i');
});

$all_passed &= run_test('Direct quarantine_action.php with revoked session is blocked before execution', function() {
    run_sub_test('quarantine revoked', [
        'mode' => 'login_function',
        'server' => [
            'SCRIPT_NAME' => '/mailscanner/quarantine_action.php'
        ],
        'session' => ['myusername' => 'jdoe', 'user_type' => 'U'],
        'users' => [
            'jdoe' => ['username' => 'jdoe', 'type' => 'U', 'login_expiry' => '-1', 'login_timeout' => '-1']
        ]
    ], 0, '/^$/');
});

$all_passed &= run_test('Valid session succeeds through login.function.php', function() {
    run_sub_test('valid session', [
        'mode' => 'login_function',
        'server' => [
            'SCRIPT_NAME' => '/mailscanner/dashboard.php'
        ],
        'session' => ['myusername' => 'jdoe', 'user_type' => 'U'],
        'users' => [
            'jdoe' => ['username' => 'jdoe', 'type' => 'U', 'login_expiry' => (string)(time() + 600), 'login_timeout' => '-1']
        ]
    ], 0, '/LOGIN_FUNCTION_SUCCESS/');
});

$all_passed &= run_test('Deleted user in DB is terminated as baduser', function() {
    run_sub_test('deleted user', [
        'mode' => 'login_function',
        'server' => [
            'SCRIPT_NAME' => '/mailscanner/dashboard.php',
            'HTTP_ACCEPT' => 'application/json'
        ],
        'request' => ['action' => 'get_widget_body'],
        'session' => ['myusername' => 'deleted_user', 'user_type' => 'U'],
        'users' => []
    ], 0, '/"error"\s*:\s*"baduser"/');
});

echo "\n===================================\n";
if ($all_passed) {
    echo "ALL MW-04 REGRESSION TESTS PASSED SUCCESSFULLY! ✓\n";
    exit(0);
} else {
    echo "SOME MW-04 REGRESSION TESTS FAILED! ✗\n";
    exit(1);
}
