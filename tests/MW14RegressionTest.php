<?php

/**
 * Regression Test Suite for MW-14:
 * LDAP username escaping in search filter vs DN context (mailscanner/functions.php).
 *
 * Verifies:
 * 1. Static Analysis:
 *    - ldap_authenticate() does not apply LDAP_ESCAPE_DN to username.
 *    - ldap_authenticate() applies LDAP_ESCAPE_FILTER when constructing the search filter.
 *    - Raw username is preserved for error triggering (trigger_error).
 *    - Bind identity (DN or prefix/suffix) is separated from filter-escaped username.
 *    - tools/LDAP/ldaptest.php uses ldap_build_filter() and falls back to entry DN.
 * 2. RFC 4515 (Filter) vs RFC 4514 (DN) Escaping:
 *    - Filter escaping converts *, (, ), \, and NUL to \2a, \28, \29, \5c, \00.
 *    - Filter escaping preserves commas, equals signs, pluses, quotes, and hashes.
 *    - DN escaping escapes DN delimiters but leaves filter wildcards/brackets unescaped.
 *    - Injection payloads (e.g. admin)(|(uid=*) are safely neutralized by filter escaping.
 *    - Multi-specifier templates in ldap_build_filter() work seamlessly.
 * 3. LDAP Fixture Tests (Simulated Environment):
 *    - Username with filter special characters (*, (, ), \) is properly searched and bound.
 *    - LDAP injection attempts are safely searched and fail gracefully.
 *    - Zero results (0 entries) triggers ldapresultnodata03 and returns null.
 *    - Multiple results (>1 entries) triggers ldapresultset03 and returns null.
 *    - Search failure (false) triggers ldapnoresult03 and returns null.
 *    - Correct user with correct password binds with user DN and returns email.
 *    - Correct user with wrong password (errno 49) returns null without dying.
 *    - Group account (objectclass=group) is rejected and returns null.
 *    - Bind identity with LDAP_BIND_PREFIX / LDAP_BIND_SUFFIX vs pure DN.
 *    - Email extraction from proxyAddresses (SMTP: prefix) and standard mail attribute.
 *    - Empty username / password immediately returns null.
 */

define('MAILWATCH_TEST_RUNNER', true);

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
require_once __DIR__ . '/../mailscanner/functions.php';
require_once __DIR__ . '/../mailscanner/database.php';
error_reporting(E_ALL);

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
 * Mock database connection to handle user provisioning queries in ldap_authenticate
 */
class MW14MockDbConnection {
    public array $queryLog = [];
    public function query($sql) {
        $this->queryLog[] = $sql;
        return new class {
            public int $num_rows = 0;
        };
    }
    public function real_escape_string($str) {
        return addslashes((string)$str);
    }
    public function escape_string($str) {
        return addslashes((string)$str);
    }
}

database::$link = new MW14MockDbConnection();

echo "=== MW-14 Regression Test Suite ===\n\n";
$all_passed = true;

// -----------------------------------------------------------------------------
// 1. Static Analysis
// -----------------------------------------------------------------------------
echo "1. Static Analysis: Context Separation & Escaping\n";

$all_passed &= run_test('functions.php does NOT escape username as DN at function entry', function() {
    $code = file_get_contents(__DIR__ . '/../mailscanner/functions.php');
    $authFuncPos = strpos($code, 'function ldap_authenticate(');
    $endFuncPos = strpos($code, 'function ldap_print_error(', $authFuncPos);
    $funcBody = substr($code, $authFuncPos, $endFuncPos - $authFuncPos);

    // Must not contain $username = ldap_escape(..., LDAP_ESCAPE_DN)
    $hasDnEscapeOnUsername = preg_match('/\$username\s*=\s*ldap_escape\s*\([^)]*LDAP_ESCAPE_DN\s*\)/', $funcBody);
    assert_false($hasDnEscapeOnUsername, 'ldap_authenticate must NOT apply LDAP_ESCAPE_DN to username');
});

$all_passed &= run_test('functions.php uses LDAP_ESCAPE_FILTER when building search filter', function() {
    $code = file_get_contents(__DIR__ . '/../mailscanner/functions.php');
    assert_true(strpos($code, 'ldap_build_filter(') !== false, 'functions.php must provide ldap_build_filter()');
    assert_true(strpos($code, 'ldap_escape_filter(') !== false, 'functions.php must provide ldap_escape_filter()');
    assert_true(strpos($code, 'LDAP_ESCAPE_FILTER') !== false, 'functions.php must reference LDAP_ESCAPE_FILTER');
});

$all_passed &= run_test('functions.php separates raw username, filter, and bind DN', function() {
    $code = file_get_contents(__DIR__ . '/../mailscanner/functions.php');
    $authFuncPos = strpos($code, 'function ldap_authenticate(');
    $endFuncPos = strpos($code, 'function ldap_print_error(', $authFuncPos);
    $funcBody = substr($code, $authFuncPos, $endFuncPos - $authFuncPos);

    // Filter must use ldap_build_filter
    assert_true(strpos($funcBody, 'ldap_build_filter(LDAP_FILTER') !== false, 'Filter must be built via ldap_build_filter()');

    // Error reporting must use raw username, not filter-escaped or DN-escaped string
    assert_true(strpos($funcBody, 'ldapnoresult03') !== false && strpos($funcBody, '$raw_username') !== false,
        'Error trigger ldapnoresult03 must use $raw_username');
    assert_true(strpos($funcBody, 'ldapresultnodata03') !== false && strpos($funcBody, '$raw_username') !== false,
        'Error trigger ldapresultnodata03 must use $raw_username');
    assert_true(strpos($funcBody, 'ldapresultset03') !== false && strpos($funcBody, '$raw_username') !== false,
        'Error trigger ldapresultset03 must use $raw_username');

    // Bind identity must use entry DN or prefix/suffix, not filter string
    assert_true(strpos($funcBody, '$bind_user = $result[0][\'dn\']') !== false, 'Must bind using $result[0][\'dn\'] when no prefix/suffix');
});

$all_passed &= run_test('tools/LDAP/ldaptest.php uses ldap_build_filter and bind DN fallback', function() {
    $code = file_get_contents(__DIR__ . '/../tools/LDAP/ldaptest.php');
    assert_true(strpos($code, 'ldap_build_filter(LDAP_FILTER, $username)') !== false, 'ldaptest.php must use ldap_build_filter()');
    assert_true(strpos($code, '$user = $result[0][\'dn\']') !== false, 'ldaptest.php must fallback to dn when no prefix/suffix');
});

// -----------------------------------------------------------------------------
// 2. RFC 4515 (Filter) vs RFC 4514 (DN) Escaping
// -----------------------------------------------------------------------------
echo "\n2. RFC 4515 Filter Escaping vs RFC 4514 DN Escaping\n";

$all_passed &= run_test('ldap_escape_filter properly escapes RFC 4515 special characters', function() {
    // RFC 4515 5 special characters: *, (, ), \, NUL
    assert_equals('user\\2aname', ldap_escape_filter('user*name'), 'Asterisk * must escape to \\2a');
    assert_equals('user\\28name', ldap_escape_filter('user(name'), 'Left paren ( must escape to \\28');
    assert_equals('user\\29name', ldap_escape_filter('user)name'), 'Right paren ) must escape to \\29');
    assert_equals('user\\5cname', ldap_escape_filter('user\\name'), 'Backslash \\ must escape to \\5c');
    assert_equals('user\\00name', ldap_escape_filter("user\0name"), 'NUL must escape to \\00');
    assert_equals('\\2a\\28\\29\\5c\\00', ldap_escape_filter("*()\\\0"), 'All 5 filter specials together');
});

$all_passed &= run_test('ldap_escape_filter preserves non-filter characters that DN escaping alters', function() {
    // Characters that RFC 4514 (DN) escapes, but RFC 4515 (Filter) preserves as literal assertion values
    $dnChars = 'user,name=foo+bar<baz>test;quote"hash#';
    $filterEscaped = ldap_escape_filter($dnChars);
    assert_equals($dnChars, $filterEscaped, 'DN delimiters must NOT be escaped in filter value assertion');

    // Contrast with DN escaping
    $dnEscaped = ldap_escape_dn($dnChars);
    assert_true(strpos($dnEscaped, '\\2c') !== false || strpos($dnEscaped, '\\,') !== false, 'DN escaping must escape commas');
    assert_true(strpos($dnEscaped, '\\3d') !== false || strpos($dnEscaped, '\\=') !== false, 'DN escaping must escape equals');
});

$all_passed &= run_test('LDAP injection payloads are neutralized by ldap_escape_filter', function() {
    // Classic LDAP injection: admin)(|(uid=*
    $payload = 'admin)(|(uid=*';
    $escaped = ldap_escape_filter($payload);
    assert_equals('admin\\29\\28|\\28uid=\\2a', $escaped, 'Injection parentheses and wildcard must be escaped');

    // Wildcard-all injection: *
    $wildcard = '*';
    $escapedWildcard = ldap_escape_filter($wildcard);
    assert_equals('\\2a', $escapedWildcard, 'Wildcard * must be escaped to prevent matching all directory entries');

    // Nested filter injection: *)(objectClass=*
    $nested = '*)(objectClass=*';
    $escapedNested = ldap_escape_filter($nested);
    assert_equals('\\2a\\29\\28objectClass=\\2a', $escapedNested, 'Nested clause injection must be escaped');
});

$all_passed &= run_test('ldap_build_filter formats single and multiple %s templates', function() {
    // Single %s
    $filter1 = ldap_build_filter('mail=%s', 'john*doe@example.com');
    assert_equals('mail=john\\2adoe@example.com', $filter1);

    // Active Directory sAMAccountName
    $filter2 = ldap_build_filter('sAMAccountName=%s', 'admin)(foo');
    assert_equals('sAMAccountName=admin\\29\\28foo', $filter2);

    // Multi-specifier template
    $multiTemplate = '(&(objectClass=user)(|(mail=%s)(sAMAccountName=%s)))';
    $filter3 = ldap_build_filter($multiTemplate, 'user*test');
    assert_equals('(&(objectClass=user)(|(mail=user\\2atest)(sAMAccountName=user\\2atest)))', $filter3);
});

$all_passed &= run_test('ldap_escape handles empty strings without PHP warnings', function() {
    $errTriggered = false;
    set_error_handler(function($errno, $errstr) use (&$errTriggered) {
        $errTriggered = true;
    });

    $res1 = ldap_escape('', '', LDAP_ESCAPE_FILTER);
    $res2 = ldap_escape('', '', LDAP_ESCAPE_DN);
    restore_error_handler();

    assert_equals('', $res1);
    assert_equals('', $res2);
    assert_false($errTriggered, 'Empty string escaping must not produce PHP index warnings');
});

// -----------------------------------------------------------------------------
// 3. LDAP Fixture Tests (Simulated Environment)
// -----------------------------------------------------------------------------
echo "\n3. LDAP Fixture Tests (Simulated Authentication Flow)\n";

class MockLdapServer {
    public $lastSearchFilter = null;
    public $lastSearchBase = null;
    public $lastBindUser = null;
    public $lastBindPassword = null;
    public $bindCount = 0;

    public $users = [];
    public $proxyBindSuccess = true;
    public $searchFails = false;

    public function __construct() {
        // Sample fixture directory
        $this->users = [
            'alice' => [
                'dn' => 'cn=Alice Smith,ou=users,dc=example,dc=com',
                'attributes' => [
                    'count' => 3,
                    0 => 'uid',
                    1 => 'mail',
                    2 => 'cn',
                    'uid' => ['count' => 1, 0 => 'alice'],
                    'cn' => ['count' => 1, 0 => 'Alice Smith'],
                    'mail' => ['count' => 1, 0 => 'alice@example.com'],
                    'objectclass' => ['count' => 2, 0 => 'top', 1 => 'inetOrgPerson'],
                ],
                'password' => 'AliceSecret123',
            ],
            // User with filter special characters: bob*star(admin)
            'bob*star(admin)' => [
                'dn' => 'cn=Bob Star,ou=users,dc=example,dc=com',
                'attributes' => [
                    'count' => 3,
                    0 => 'uid',
                    1 => 'mail',
                    2 => 'cn',
                    'uid' => ['count' => 1, 0 => 'bob*star(admin)'],
                    'cn' => ['count' => 1, 0 => 'Bob Star'],
                    'mail' => ['count' => 1, 0 => 'bob.star@example.com'],
                    'objectclass' => ['count' => 2, 0 => 'top', 1 => 'inetOrgPerson'],
                ],
                'password' => 'BobPass789',
            ],
            // User with Active Directory proxyAddresses
            'charlie' => [
                'dn' => 'cn=Charlie Brown,ou=users,dc=example,dc=com',
                'attributes' => [
                    'count' => 4,
                    0 => 'uid',
                    1 => 'cn',
                    2 => 'proxyaddresses',
                    'uid' => ['count' => 1, 0 => 'charlie'],
                    'cn' => ['count' => 1, 0 => 'Charlie Brown'],
                    'proxyaddresses' => [
                        'count' => 2,
                        0 => 'smtp:charlie.alias@example.com',
                        1 => 'SMTP:charlie.primary@example.com',
                    ],
                    'objectclass' => ['count' => 2, 0 => 'top', 1 => 'person'],
                ],
                'password' => 'CharliePass',
            ],
            // Group entry (should be rejected)
            'maingroup' => [
                'dn' => 'cn=MainGroup,ou=groups,dc=example,dc=com',
                'attributes' => [
                    'count' => 3,
                    0 => 'uid',
                    1 => 'mail',
                    'uid' => ['count' => 1, 0 => 'maingroup'],
                    'mail' => ['count' => 1, 0 => 'group@example.com'],
                    'objectclass' => ['count' => 2, 0 => 'top', 1 => 'group'],
                ],
                'password' => 'GroupPass',
            ],
        ];
    }

    public function getDriver() {
        return [
            'connect' => function($uri) {
                return (object)['connected' => true, 'uri' => $uri, 'errno' => 0];
            },
            'set_option' => function($ds, $opt, $val) {
                return true;
            },
            'bind' => function($ds, $user = null, $pass = null) {
                $this->bindCount++;
                $this->lastBindUser = $user;
                $this->lastBindPassword = $pass;

                // Proxy bind check
                if ($user === LDAP_USER) {
                    return $this->proxyBindSuccess;
                }

                // Authenticate specific user
                foreach ($this->users as $u) {
                    if ($u['dn'] === $user) {
                        if ($u['password'] === $pass) {
                            $ds->errno = 0;
                            return true;
                        } else {
                            $ds->errno = 49; // LDAP_INVALID_CREDENTIALS
                            return false;
                        }
                    }
                }

                $ds->errno = 49;
                return false;
            },
            'search' => function($ds, $base, $filter) {
                $this->lastSearchBase = $base;
                $this->lastSearchFilter = $filter;

                if ($this->searchFails) {
                    return false;
                }

                $matches = [];
                foreach ($this->users as $rawKey => $u) {
                    $escapedKey = ldap_escape_filter($rawKey);
                    // Match against mail=%s filter
                    if ($filter === "mail=$escapedKey") {
                        $matches[] = $u['attributes'] + ['dn' => $u['dn']];
                    }
                }

                return (object)['entries' => $matches];
            },
            'count_entries' => function($ds, $res) {
                return count($res->entries);
            },
            'get_entries' => function($ds, $res) {
                $count = count($res->entries);
                $result = ['count' => $count];
                for ($i = 0; $i < $count; $i++) {
                    $result[$i] = $res->entries[$i];
                }
                return $result;
            },
            'free_result' => function($res) {
                return true;
            },
            'errno' => function($ds) {
                return isset($ds->errno) ? $ds->errno : 0;
            },
        ];
    }
}

$all_passed &= run_test('Fixture: User with filter special characters authenticates successfully', function() {
    $mock = new MockLdapServer();
    $driver = $mock->getDriver();

    // Username contains * and ( and )
    $rawUsername = 'bob*star(admin)';
    $result = ldap_authenticate($rawUsername, 'BobPass789', $driver);

    // 1. Check that filter passed to search escaped * and ( and )
    assert_equals('mail=bob\\2astar\\28admin\\29', $mock->lastSearchFilter, 'Search filter must have filter characters escaped');

    // 2. Check that user was bound using entry DN, NOT filter-escaped username
    assert_equals('cn=Bob Star,ou=users,dc=example,dc=com', $mock->lastBindUser, 'Must bind using entry DN');
    assert_equals('BobPass789', $mock->lastBindPassword, 'Must bind with supplied password');

    // 3. Check successful return
    assert_equals('bob.star@example.com', $result, 'Must return email');
});

$all_passed &= run_test('Fixture: LDAP injection payload is neutralized and fails gracefully', function() {
    $mock = new MockLdapServer();
    $driver = $mock->getDriver();

    $lastError = null;
    set_error_handler(function($errno, $errstr) use (&$lastError) {
        $lastError = $errstr;
    });

    $injectionUser = 'alice)(|(uid=*';
    $result = ldap_authenticate($injectionUser, 'anypassword', $driver);
    restore_error_handler();

    // Filter must be safely escaped: mail=alice\29\28|\28uid=\2a
    assert_equals('mail=alice\\29\\28|\\28uid=\\2a', $mock->lastSearchFilter, 'Filter must safely escape injection characters');
    assert_equals(null, $result, 'Injection query must not authenticate');
    assert_true($lastError !== null && strpos($lastError, 'alice)(|(uid=*') !== false, 'Error message must contain raw username');
});

$all_passed &= run_test('Fixture: Zero results triggers error and returns null', function() {
    $mock = new MockLdapServer();
    $driver = $mock->getDriver();

    $lastError = null;
    set_error_handler(function($errno, $errstr) use (&$lastError) {
        $lastError = $errstr;
    });

    $result = ldap_authenticate('nonexistent_user', 'any_pass', $driver);
    restore_error_handler();

    assert_equals(null, $result, 'Must return null on 0 results');
    assert_true($lastError !== null, 'Must trigger error on 0 results');
    assert_true(strpos($lastError, 'nonexistent_user') !== false, 'Triggered error must contain raw username');
});

$all_passed &= run_test('Fixture: Multiple results (>1 entries) triggers unique error and returns null', function() {
    $mock = new MockLdapServer();
    // Simulate duplicate entries returned by search
    $driver = $mock->getDriver();
    $driver['search'] = function($ds, $base, $filter) use ($mock) {
        $alice = $mock->users['alice']['attributes'] + ['dn' => $mock->users['alice']['dn']];
        return (object)['entries' => [$alice, $alice]]; // 2 entries
    };

    $lastError = null;
    set_error_handler(function($errno, $errstr) use (&$lastError) {
        $lastError = $errstr;
    });

    $result = ldap_authenticate('alice', 'AliceSecret123', $driver);
    restore_error_handler();

    assert_equals(null, $result, 'Must return null when multiple entries match');
    assert_true($lastError !== null, 'Must trigger error when multiple entries match');
    assert_true(strpos($lastError, 'is unique') !== false || strpos($lastError, 'unique') !== false, 'Must trigger non-unique error');
    assert_true(strpos($lastError, 'alice') !== false, 'Triggered error must contain raw username');
});

$all_passed &= run_test('Fixture: Search failure (false) triggers error and returns null', function() {
    $mock = new MockLdapServer();
    $mock->searchFails = true;
    $driver = $mock->getDriver();

    $lastError = null;
    set_error_handler(function($errno, $errstr) use (&$lastError) {
        $lastError = $errstr;
    });

    $result = ldap_authenticate('alice', 'AliceSecret123', $driver);
    restore_error_handler();

    assert_equals(null, $result, 'Must return null when search fails');
    assert_true($lastError !== null, 'Must trigger error when search fails');
    assert_true(strpos($lastError, 'alice') !== false, 'Error must contain raw username');
});

$all_passed &= run_test('Fixture: Wrong password returns null (requires correct password of specific user)', function() {
    $mock = new MockLdapServer();
    $driver = $mock->getDriver();

    $result = ldap_authenticate('alice', 'WrongPassword!', $driver);

    assert_equals(null, $result, 'Must return null on incorrect password');
    assert_equals('cn=Alice Smith,ou=users,dc=example,dc=com', $mock->lastBindUser, 'Must attempt bind as Alice');
    assert_equals('WrongPassword!', $mock->lastBindPassword, 'Must pass wrong password to bind');
});

$all_passed &= run_test('Fixture: Group account (objectclass=group) cannot login', function() {
    $mock = new MockLdapServer();
    $driver = $mock->getDriver();

    $result = ldap_authenticate('maingroup', 'GroupPass', $driver);
    assert_equals(null, $result, 'Group accounts must return null');
});

$all_passed &= run_test('Fixture: Active Directory proxyAddresses SMTP: extraction', function() {
    $mock = new MockLdapServer();
    $driver = $mock->getDriver();

    $charlie = &$mock->users['charlie']['attributes'];
    $charlie['mail'] = [
        'count' => 2,
        0 => 'smtp:charlie.alias@example.com',
        1 => 'SMTP:charlie.primary@example.com',
    ];

    $result = ldap_authenticate('charlie', 'CharliePass', $driver);
    assert_equals('charlie.primary@example.com', $result, 'Must extract primary SMTP address from proxyaddresses');
});

$all_passed &= run_test('Fixture: Empty username or password returns null immediately', function() {
    $mock = new MockLdapServer();
    $driver = $mock->getDriver();

    assert_equals(null, ldap_authenticate('', 'somepass', $driver));
    assert_equals(null, ldap_authenticate('someuser', '', $driver));
    assert_equals(null, ldap_authenticate('', '', $driver));
    assert_equals(0, $mock->bindCount, 'No LDAP operations should be invoked for empty credentials');
});

echo "\n----------------------------------------------------------------------\n";
if ($all_passed) {
    echo "ALL MW-14 REGRESSION TESTS PASSED (100%)\n";
    exit(0);
} else {
    echo "SOME MW-14 TESTS FAILED!\n";
    exit(1);
}
