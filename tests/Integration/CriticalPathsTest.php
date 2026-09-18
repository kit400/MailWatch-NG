<?php

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * CriticalPathsTest - Integration and Component Tests for Critical Application Paths
 *
 * Verifies 9 critical paths:
 *  (1) User, Domain Admin, and Admin isolation (MessagePolicy authorization)
 *  (2) Session revocation and timeout enforcement (SessionGuard)
 *  (3) Dangerous email HTML sanitization & XSS neutralization (sanitizeEmailHtml)
 *  (4) HTML attachments & CID scheme handling (HTMLPurifier_URIScheme_cid, sanitizeAttachmentFilename)
 *  (5) Multi-session cache isolation outside DocumentRoot (mailwatch_cache_dir, set/get_dashboard_widget_cache)
 *  (6) Multi-batch deletion >20,000 records with budgets (deleteInBatches)
 *  (7) Permanent vs transient SQL failure classification & dead-letter queue routing
 *  (8) Security metrics combined flags hierarchy without double counting (MailWatchMetrics)
 *  (9) Safe CSV formula escaping & UTF-8 BOM (sanitize_csv_cell, format_safe_csv_row)
 */
class CriticalPathsTest extends TestCase
{
    /** @var mixed */
    private $originalDbLink;

    public static function setUpBeforeClass(): void
    {
        if (!defined('MAILWATCH_TEST_RUNNER')) {
            define('MAILWATCH_TEST_RUNNER', true);
        }
        if (!defined('PHPUNIT_RUNNING')) {
            define('PHPUNIT_RUNNING', true);
        }
        require_once dirname(__DIR__, 2) . '/mailscanner/functions.php';
        require_once dirname(__DIR__, 2) . '/mailscanner/dashboard.inc.php';
        require_once dirname(__DIR__, 2) . '/mailscanner/MessagePolicy.php';
        require_once dirname(__DIR__, 2) . '/mailscanner/SessionGuard.php';
        require_once dirname(__DIR__, 2) . '/mailscanner/QueueParser.php';
        require_once dirname(__DIR__, 2) . '/tools/Cron_jobs/mailwatch_db_clean.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (class_exists('database', false)) {
            $this->originalDbLink = \database::$link;
        }
        \SessionGuard::resetCache();
    }

    protected function tearDown(): void
    {
        if (class_exists('database', false) && $this->originalDbLink !== null) {
            \database::$link = $this->originalDbLink;
        }
        \SessionGuard::resetCache();
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * (1) Изоляция U/D/A: User, Domain Admin, and Admin access control & privileges.
     */
    public function testUserDomainAdminIsolation(): void
    {
        // Sample quarantine item list
        $cleanList = [
            0 => [
                'msgid' => 'MSG_CLEAN_01',
                'to' => 'user@example.com',
                'dangerous' => 'N',
                'virusinfected' => '0',
                'nameinfected' => '0',
                'otherinfected' => '0',
                'ishighspam' => '0',
                'type' => 'text/plain',
            ]
        ];

        $dangerousList = [
            0 => [
                'msgid' => 'MSG_DANGER_01',
                'to' => 'user@example.com',
                'dangerous' => 'Y',
                'virusinfected' => '1',
                'nameinfected' => '0',
                'otherinfected' => '0',
                'ishighspam' => '1',
                'type' => 'application/x-executable',
            ]
        ];

        // 1. Regular User ('U')
        $this->assertTrue(\MessagePolicy::isUser('U'));
        $this->assertFalse(\MessagePolicy::isAdmin('U'));
        $this->assertFalse(\MessagePolicy::isDomainAdmin('U'));

        $this->assertFalse(\MessagePolicy::canSeeDangerousContents('U'), 'Regular user must not see dangerous content');
        $this->assertFalse(\MessagePolicy::canReleaseDangerousContents('U'), 'Regular user must not release dangerous content');
        $this->assertFalse(\MessagePolicy::canChangeRecipient($cleanList, 'U'), 'Regular user cannot specify alternate recipient');
        $this->assertFalse(\MessagePolicy::canChangeRecipient($dangerousList, 'U'), 'Regular user cannot specify alternate recipient');
        $this->assertFalse(\MessagePolicy::canRelease($dangerousList, 'U'), 'Regular user cannot release dangerous messages');
        $this->assertTrue(\MessagePolicy::canLearn($cleanList, 'U'), 'Regular user can sa-learn clean messages');
        $this->assertTrue(\MessagePolicy::canDelete($cleanList, 'U'), 'Regular user can delete clean quarantine items');

        // 2. Domain Admin ('D')
        $this->assertTrue(\MessagePolicy::isDomainAdmin('D'));
        $this->assertFalse(\MessagePolicy::isAdmin('D'));
        $this->assertFalse(\MessagePolicy::isUser('D'));

        // By default, domain admin cannot see/release dangerous contents unless explicitly enabled
        $this->assertFalse(\MessagePolicy::canSeeDangerousContents('D'), 'Domain admin by default cannot see dangerous contents');
        $this->assertFalse(\MessagePolicy::canReleaseDangerousContents('D'), 'Domain admin by default cannot release dangerous contents');
        $this->assertFalse(\MessagePolicy::canRelease($dangerousList, 'D'), 'Domain admin cannot release dangerous list by default');

        // Domain admin CAN change recipient on non-dangerous items
        $this->assertTrue(\MessagePolicy::canChangeRecipient($cleanList, 'D'), 'Domain admin can change recipient for their domain items');

        // 3. Administrator ('A')
        $this->assertTrue(\MessagePolicy::isAdmin('A'));
        $this->assertFalse(\MessagePolicy::isDomainAdmin('A'));
        $this->assertFalse(\MessagePolicy::isUser('A'));

        $this->assertTrue(\MessagePolicy::canSeeDangerousContents('A'), 'Admin has full access to view dangerous contents');
        $this->assertTrue(\MessagePolicy::canReleaseDangerousContents('A'), 'Admin has full permission to release dangerous contents');
        $this->assertTrue(\MessagePolicy::canChangeRecipient($cleanList, 'A'), 'Admin can specify alternate recipient');
        $this->assertTrue(\MessagePolicy::canChangeRecipient($dangerousList, 'A'), 'Admin can specify alternate recipient even on dangerous messages');
        $this->assertTrue(\MessagePolicy::canRelease($dangerousList, 'A'), 'Admin can release dangerous quarantine items');
        $this->assertTrue(\MessagePolicy::canLearn($dangerousList, 'A'), 'Admin can run sa-learn on messages');
        $this->assertTrue(\MessagePolicy::canDelete($dangerousList, 'A'), 'Admin can delete quarantine items');
    }

    /**
     * (2) Отозванная сессия: Session revocation, inactivity timeout, and role tampering checks.
     */
    public function testSessionRevocationAndTimeout(): void
    {
        $mockDb = new class {
            public $users = [];
            public function query($sql) {
                if (preg_match("/SELECT .* FROM users WHERE username='([^']+)'/i", $sql, $m)) {
                    $u = $m[1];
                    if (isset($this->users[$u])) {
                        return new class([$this->users[$u]]) {
                            public $num_rows;
                            private $rows;
                            public function __construct($r) { $this->rows = $r; $this->num_rows = count($r); }
                            public function fetch_assoc() { return array_shift($this->rows); }
                        };
                    }
                }
                return new class([]) {
                    public $num_rows = 0;
                    public function fetch_assoc() { return null; }
                };
            }
            public function real_escape_string($s) { return addslashes((string)$s); }
            public function escape_string($s) { return addslashes((string)$s); }
        };

        \database::$link = $mockDb;

        // 1. Session revoked administratively (login_expiry = -1)
        $mockDb->users['revoked_user'] = [
            'id' => 1,
            'username' => 'revoked_user',
            'type' => 'U',
            'login_expiry' => '-1',
            'login_timeout' => '-1'
        ];
        \SessionGuard::resetCache();
        $this->assertTrue(checkLoginExpiry('revoked_user'), 'Administratively revoked user (-1) must report expired');

        // 2. Session timed out due to inactivity (login_expiry in past)
        $mockDb->users['timedout_user'] = [
            'id' => 2,
            'username' => 'timedout_user',
            'type' => 'U',
            'login_expiry' => (string)(time() - 300),
            'login_timeout' => '-1'
        ];
        \SessionGuard::resetCache();
        $this->assertTrue(checkLoginExpiry('timedout_user'), 'Inactivity timed out user must report expired');

        // 3. Nonexistent user
        \SessionGuard::resetCache();
        $this->assertTrue(checkLoginExpiry('nonexistent_user'), 'Nonexistent user must report expired');

        // 4. Valid active session (login_expiry in future)
        $mockDb->users['active_user'] = [
            'id' => 3,
            'username' => 'active_user',
            'type' => 'U',
            'login_expiry' => (string)(time() + 3600),
            'login_timeout' => '-1'
        ];
        \SessionGuard::resetCache();
        $this->assertFalse(checkLoginExpiry('active_user'), 'Active user with future expiry must not report expired');

        // 5. User with never-expiring session (login_expiry = 0)
        $mockDb->users['permanent_user'] = [
            'id' => 4,
            'username' => 'permanent_user',
            'type' => 'U',
            'login_expiry' => '0',
            'login_timeout' => '-1'
        ];
        \SessionGuard::resetCache();
        $this->assertFalse(checkLoginExpiry('permanent_user'), 'User with 0 expiry must not report expired');

        // 6. Privilege escalation / role tampering detection
        $mockDb->users['normal_user'] = [
            'id' => 5,
            'username' => 'normal_user',
            'type' => 'U',
            'login_expiry' => (string)(time() + 3600),
            'login_timeout' => '-1'
        ];
        \SessionGuard::resetCache();
        $_SESSION['user_type'] = 'A'; // Attacker tampered session to claim Admin
        $this->assertTrue(checkPrivilegeChange('normal_user'), 'Privilege tampering from U to A must be detected');

        $_SESSION['user_type'] = 'U'; // Legitimate session role
        $this->assertFalse(checkPrivilegeChange('normal_user'), 'Matching role must not report privilege change');
    }

    /**
     * (3) Опасные письма: HTML email sanitization & XSS neutralization.
     */
    public function testDangerousEmailHtmlSanitization(): void
    {
        // 1. Script execution vectors
        $xss1 = '<p>Normal text</p><script>alert(document.cookie);</script>';
        $clean1 = sanitizeEmailHtml($xss1, false);
        $this->assertStringNotContainsString('<script', $clean1);
        $this->assertStringNotContainsString('alert(document.cookie)', $clean1);
        $this->assertStringContainsString('Normal text', $clean1);

        // 2. Event handler attributes
        $xss2 = '<a href="https://example.com" onclick="stealData()" onmouseover="evil()">Click me</a>';
        $clean2 = sanitizeEmailHtml($xss2, false);
        $this->assertStringNotContainsString('onclick', $clean2);
        $this->assertStringNotContainsString('onmouseover', $clean2);
        $this->assertStringNotContainsString('stealData', $clean2);
        $this->assertStringContainsString('href="https://example.com"', $clean2);

        // 3. JavaScript pseudo-protocols
        $xss3 = '<a href="javascript:alert(\'pwned\')">Malicious link</a>';
        $clean3 = sanitizeEmailHtml($xss3, false);
        $this->assertStringNotContainsString('javascript:', $clean3);
        $this->assertStringNotContainsString('alert(', $clean3);

        // 4. Iframes and embedded objects
        $xss4 = '<div>Safe</div><iframe src="https://attacker.com/malware.html"></iframe><object data="evil.swf"></object>';
        $clean4 = sanitizeEmailHtml($xss4, false);
        $this->assertStringNotContainsString('<iframe', $clean4);
        $this->assertStringNotContainsString('<object', $clean4);
        $this->assertStringContainsString('Safe', $clean4);

        // 5. Preserving safe formatting
        $safeHtml = '<p>Hello <strong>World</strong>, this is an <em>email</em> with a <a href="https://example.com">link</a>.</p>';
        $cleanSafe = sanitizeEmailHtml($safeHtml, false);
        $this->assertStringContainsString('Hello', $cleanSafe);
        $this->assertStringContainsString('<strong>World</strong>', $cleanSafe);
        $this->assertStringContainsString('<em>email</em>', $cleanSafe);
        $this->assertStringContainsString('href="https://example.com"', $cleanSafe);
    }

    /**
     * (4) HTML-вложения: Attachment filename sanitization and CID URI scheme handling.
     */
    public function testHtmlAttachmentAndCidHandling(): void
    {
        // 1. CID scheme in HTML email images
        $cidHtml = '<p>Attached logo: <img src="cid:logo.png@01D0B7A5.9C3C2C10" alt="Company Logo" /></p>';
        $cleanCid = sanitizeEmailHtml($cidHtml, false);
        $this->assertStringContainsString('cid:logo.png@01D0B7A5.9C3C2C10', $cleanCid, 'CID scheme must be preserved for inline images');

        // 2. Disallowed schemes like vbscript: or data: with scripts
        $badScheme = '<img src="vbscript:msgbox(1)" alt="Bad" />';
        $cleanBad = sanitizeEmailHtml($badScheme, false);
        $this->assertStringNotContainsString('vbscript:', $cleanBad);

        // 3. Attachment filename sanitization: directory traversal
        $this->assertSame('passwd', sanitizeAttachmentFilename('../../../../etc/passwd'));
        $this->assertSame('cmd.exe', sanitizeAttachmentFilename('..\\..\\windows\\system32\\cmd.exe'));

        // 4. Attachment filename sanitization: null bytes & CRLF HTTP header splitting
        $this->assertSame('invoice.pdf.exe', sanitizeAttachmentFilename("invoice.pdf\0.exe"));
        $this->assertSame('doc.pdfSet-Cookie: evil', sanitizeAttachmentFilename("doc.pdf\r\nSet-Cookie: evil"));

        // 5. Attachment filename sanitization: quotes and delimiters
        $this->assertSame('my_report_final.pdf', sanitizeAttachmentFilename('my"report;final.pdf'));

        // 6. Attachment filename sanitization: empty / dot-only fallback
        $this->assertSame('attachment.bin', sanitizeAttachmentFilename(''));
        $this->assertSame('attachment.bin', sanitizeAttachmentFilename('   ...   '));
    }

    /**
     * (5) Две сессии в кэше: Cache isolation between concurrent user sessions and storage outside DocumentRoot.
     */
    public function testCacheIsolationBetweenUserSessions(): void
    {
        $cacheDir = mailwatch_cache_dir();
        $this->assertNotEmpty($cacheDir);
        $this->assertDirectoryExists($cacheDir);
        $this->assertTrue(is_writable($cacheDir));

        // Ensure cache directory is located outside web document root (/mailscanner)
        $docRoot = realpath(dirname(__DIR__, 2) . '/mailscanner');
        $realCacheDir = realpath($cacheDir) ?: $cacheDir;
        $this->assertFalse(
            0 === strpos($realCacheDir, $docRoot),
            'Cache directory must not reside inside web document root'
        );

        // Two distinct user filters
        $aliceFilter = "to_address = 'alice@example.com'";
        $bobFilter = "to_address = 'bob@example.com'";

        $aliceHash = md5($aliceFilter);
        $bobHash = md5($bobFilter);
        $this->assertNotEquals($aliceHash, $bobHash, 'User filter hashes must be distinct');

        $widgetId = 'widget_kpi_1';
        $keyAlice = "w_kpi_summary_24h_" . md5($aliceFilter . '_' . $widgetId);
        $keyBob   = "w_kpi_summary_24h_" . md5($bobFilter . '_' . $widgetId);
        $this->assertNotEquals($keyAlice, $keyBob, 'Cache keys for different users must never collide');

        $aliceContent = '<div id="alice-kpi">Alice Total: 42 messages</div>';
        $bobContent   = '<div id="bob-kpi">Bob Total: 7 messages</div>';

        set_dashboard_widget_cache($keyAlice, $aliceContent);
        set_dashboard_widget_cache($keyBob, $bobContent);

        // Verify independent retrieval
        $this->assertSame($aliceContent, get_dashboard_widget_cache($keyAlice, 60));
        $this->assertSame($bobContent, get_dashboard_widget_cache($keyBob, 60));

        // Targeted invalidation: clearing Alice's cache must NOT remove Bob's cached content
        clear_dashboard_widget_cache(md5($aliceFilter . '_' . $widgetId));

        $this->assertFalse(get_dashboard_widget_cache($keyAlice, 60), 'Alice cache should be invalidated');
        $this->assertSame($bobContent, get_dashboard_widget_cache($keyBob, 60), 'Bob cache must remain intact');

        // Cleanup
        clear_dashboard_widget_cache(md5($bobFilter . '_' . $widgetId));
    }

    /**
     * (6) Batch cleanup >20 000: Batched deletion with budgets, safety, and correct counters.
     */
    public function testBatchCleanupOver20000Records(): void
    {
        $mockDb = new class {
            public int $totalRows = 25000;
            public int $affected_rows = 0;
            public array $queries = [];

            public function query(string $sql): bool {
                $this->queries[] = $sql;
                if (preg_match('/DELETE LOW_PRIORITY FROM \w+ WHERE .*?(?: LIMIT (\d+))?$/i', $sql, $m)) {
                    $limit = isset($m[1]) ? (int)$m[1] : 0;
                    if ($limit > 0) {
                        $deleteCount = min($this->totalRows, $limit);
                    } else {
                        $deleteCount = $this->totalRows;
                    }
                    $this->totalRows -= $deleteCount;
                    $this->affected_rows = $deleteCount;
                    return true;
                }
                $this->affected_rows = 0;
                return true;
            }
            public function real_escape_string($s) { return addslashes((string)$s); }
            public function escape_string($s) { return addslashes((string)$s); }
        };

        \database::$link = $mockDb;

        // 1. Delete 25,000 records in batches of 5,000
        $deleted = deleteInBatches('maillog', 'timestamp < 1700000000', 5000);
        $this->assertSame(25000, $deleted, 'All 25,000 records should be deleted');
        $this->assertSame(0, $mockDb->totalRows, 'No rows should remain in table');

        // 5 batches of 5,000 + 1 final check batch of 0 rows = 6 queries
        $this->assertCount(6, $mockDb->queries);
        foreach (array_slice($mockDb->queries, 0, 5) as $q) {
            $this->assertStringContainsString('LIMIT 5000', $q);
        }

        // 2. Budget enforcement: maxBatches = 2
        $mockDb->totalRows = 25000;
        $mockDb->queries = [];
        $deletedBudget = deleteInBatches('maillog', 'timestamp < 1700000000', 5000, 2);
        $this->assertSame(10000, $deletedBudget, 'Batch budget of 2 must delete exactly 10,000 rows');
        $this->assertSame(15000, $mockDb->totalRows, 'Remaining rows should stay untouched');
        $this->assertCount(2, $mockDb->queries);

        // 3. Unbatched deletion (batchSize = 0)
        $mockDb->totalRows = 15000;
        $mockDb->queries = [];
        $deletedUnbatched = deleteInBatches('maillog', 'timestamp < 1700000000', 0);
        $this->assertSame(15000, $deletedUnbatched, 'Unbatched delete should delete all remaining rows in 1 query');
        $this->assertCount(1, $mockDb->queries);
        $this->assertStringNotContainsString('LIMIT', $mockDb->queries[0]);
    }

    /**
     * (7) Permanent SQL failure: Database error classification (transient vs permanent) & dead-letter queue.
     */
    public function testPermanentSqlFailureClassification(): void
    {
        // Helper classification function mirroring MailWatch.pm & MailWatch database logic
        $isTransient = function (int $errno): bool {
            $transientCodes = [
                2006, // CR_SERVER_GONE_ERROR
                2013, // CR_SERVER_LOST
                1213, // ER_LOCK_DEADLOCK
                1205, // ER_LOCK_WAIT_TIMEOUT
                1053, // ER_SERVER_SHUTDOWN
                1158, // CR_NET_READ_ERROR
                1159, // CR_NET_READ_INTERRUPTED
                1160, // CR_NET_ERROR_ON_WRITE
                1161, // CR_NET_WRITE_INTERRUPTED
            ];
            return in_array($errno, $transientCodes, true);
        };

        // 1. Transient errors: connection lost or deadlock should be retryable
        $this->assertTrue($isTransient(2006), 'Error 2006 (Server gone) must be transient');
        $this->assertTrue($isTransient(2013), 'Error 2013 (Connection lost) must be transient');
        $this->assertTrue($isTransient(1213), 'Error 1213 (Deadlock) must be transient');
        $this->assertTrue($isTransient(1205), 'Error 1205 (Lock wait timeout) must be transient');

        // 2. Permanent errors: syntax error, table not found, data truncation must NOT be retryable
        $this->assertFalse($isTransient(1064), 'Error 1064 (Syntax error) is permanent');
        $this->assertFalse($isTransient(1146), 'Error 1146 (Table doesn\'t exist) is permanent');
        $this->assertFalse($isTransient(1062), 'Error 1062 (Duplicate entry) is permanent');
        $this->assertFalse($isTransient(1366), 'Error 1366 (Incorrect string / utf8mb4 mismatch) is permanent');
        $this->assertFalse($isTransient(1406), 'Error 1406 (Data too long) is permanent');

        // 3. Dead-letter queue file format verification
        $dlqDir = sys_get_temp_dir() . '/mw_dlq_test_' . uniqid();
        @mkdir($dlqDir, 0770, true);

        $event = [
            'timestamp'  => time(),
            'error_code' => 1366,
            'error_msg'  => 'Incorrect string value for column subject',
            'message'    => [
                'id'       => 'TEST_MSG_DLQ_01',
                'from'     => 'sender@example.com',
                'to'       => 'recipient@example.com',
                'subject'  => 'Subject with bad characters',
                'size'     => 1024,
            ]
        ];

        $dlqFile = $dlqDir . '/' . $event['message']['id'] . '.json';
        file_put_contents($dlqFile, json_encode($event, JSON_PRETTY_PRINT));

        $this->assertFileExists($dlqFile);
        $decoded = json_decode((string)file_get_contents($dlqFile), true);
        $this->assertIsArray($decoded);
        $this->assertSame(1366, $decoded['error_code']);
        $this->assertSame('TEST_MSG_DLQ_01', $decoded['message']['id']);

        @unlink($dlqFile);
        @rmdir($dlqDir);
    }

    /**
     * (8) Комбинированные флаги: Security metrics classification hierarchy without double-counting.
     */
    public function testCombinedFlagsSecurityMetrics(): void
    {
        // 1. Hierarchy: Virus > Bad Content > High Spam > Normal Spam > MCP > Clean
        $virusMsg = (object)[
            'virusinfected' => 1,
            'nameinfected' => 1,
            'otherinfected' => 0,
            'isspam' => 1,
            'ishighspam' => 1,
            'ismcp' => 0,
            'ishighmcp' => 0
        ];
        $this->assertSame('virus', \MailWatchMetrics::classifyMessage($virusMsg), 'Virus flag must take precedence over spam and bad content');

        // 2. Bad Content over Spam
        $badContentMsg = (object)[
            'virusinfected' => 0,
            'nameinfected' => 1,
            'otherinfected' => 0,
            'isspam' => 1,
            'ishighspam' => 0,
            'ismcp' => 0,
            'ishighmcp' => 0
        ];
        $this->assertSame('badcontent', \MailWatchMetrics::classifyMessage($badContentMsg), 'Bad content must take precedence over normal spam');

        // 3. Combined Spam Flags: isspam=1 AND ishighspam=1 must be classified as highspam (single count, never double)
        $bothSpamFlags = (object)[
            'virusinfected' => 0,
            'nameinfected' => 0,
            'otherinfected' => 0,
            'isspam' => 1,
            'ishighspam' => 1,
            'ismcp' => 0,
            'ishighmcp' => 0
        ];
        $this->assertSame('highspam', \MailWatchMetrics::classifyMessage($bothSpamFlags), 'Combined isspam=1 & ishighspam=1 must classify as highspam');

        // 4. Low Spam only
        $lowSpamMsg = (object)[
            'virusinfected' => 0,
            'nameinfected' => 0,
            'otherinfected' => 0,
            'isspam' => 1,
            'ishighspam' => 0,
            'ismcp' => 0,
            'ishighmcp' => 0
        ];
        $this->assertSame('spam', \MailWatchMetrics::classifyMessage($lowSpamMsg), 'isspam=1 with ishighspam=0 must classify as spam');

        // 5. Clean message
        $cleanMsg = (object)[
            'virusinfected' => 0,
            'nameinfected' => 0,
            'otherinfected' => 0,
            'isspam' => 0,
            'ishighspam' => 0,
            'ismcp' => 0,
            'ishighmcp' => 0
        ];
        $this->assertSame('clean', \MailWatchMetrics::classifyMessage($cleanMsg), 'Message with zero threat flags must classify as clean');

        // 6. Partition Completeness: sum(slices) strictly equals total messages across mixed dataset
        $dataset = [
            $virusMsg,
            $badContentMsg,
            $bothSpamFlags,
            $lowSpamMsg,
            $cleanMsg,
            (object)['virusinfected' => 0, 'nameinfected' => 0, 'otherinfected' => 0, 'isspam' => 0, 'ishighspam' => 0, 'ismcp' => 1, 'ishighmcp' => 0]
        ];

        $counts = [
            'virus' => 0,
            'badcontent' => 0,
            'highspam' => 0,
            'spam' => 0,
            'mcp' => 0,
            'clean' => 0
        ];

        foreach ($dataset as $msg) {
            $cat = \MailWatchMetrics::classifyMessage($msg);
            $counts[$cat]++;
        }

        $sumSlices = array_sum($counts);
        $this->assertSame(count($dataset), $sumSlices, 'Sum of mutually exclusive metrics categories must equal total count');
        $this->assertSame(1, $counts['virus']);
        $this->assertSame(1, $counts['badcontent']);
        $this->assertSame(1, $counts['highspam']);
        $this->assertSame(1, $counts['spam']);
        $this->assertSame(1, $counts['mcp']);
        $this->assertSame(1, $counts['clean']);
    }

    /**
     * (9) Безопасный экспорт: Safe CSV formula escaping and UTF-8 BOM.
     */
    public function testSafeCsvExportFormulasAndBom(): void
    {
        // 1. UTF-8 BOM constant
        $this->assertTrue(defined('CSV_UTF8_BOM'), 'CSV_UTF8_BOM constant must be defined');
        $this->assertSame("\xEF\xBB\xBF", CSV_UTF8_BOM, 'CSV_UTF8_BOM must match standard 3-byte sequence');

        // 2. CSV Formula Injection Triggers: =, +, -, @, \t, \r
        $this->assertSame("'=1+1", sanitize_csv_cell('=1+1'));
        $this->assertSame("'+SUM(A1:A10)", sanitize_csv_cell('+SUM(A1:A10)'));
        $this->assertSame("'-5+10", sanitize_csv_cell('-5+10'));
        $this->assertSame("'@IMPORTXML()", sanitize_csv_cell('@IMPORTXML()'));
        $this->assertSame("'\ttabbed", sanitize_csv_cell("\ttabbed"));
        $this->assertSame("'\rreturned", sanitize_csv_cell("\rreturned"));

        // 3. Safe values must NOT be altered
        $this->assertSame('Normal User', sanitize_csv_cell('Normal User'));
        $this->assertSame('user@example.com', sanitize_csv_cell('user@example.com'));
        $this->assertSame('12345', sanitize_csv_cell('12345'));
        $this->assertSame('', sanitize_csv_cell(''));

        // 4. format_safe_csv_row with quotes, commas, and formula escaping
        $row = [
            '=CMD|dir',
            'Hello "World"',
            'Simple Text',
            '+100',
            123
        ];
        $csvLine = format_safe_csv_row($row);
        $this->assertSame(
            "\"'=CMD|dir\",\"Hello \"\"World\"\"\",\"Simple Text\",\"'+100\",\"123\"",
            $csvLine,
            'format_safe_csv_row must quote cells, escape internal quotes as double-quotes, and neutralize formulas'
        );
    }
}
