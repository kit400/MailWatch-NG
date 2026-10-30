<?php

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * MWFilterLogicTest - Tests for AND/OR Filter Logic selection, SQL grouping,
 * toggle badges, and switcher controls in Filter.
 */
class MWFilterLogicTest extends TestCase
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
        require_once dirname(__DIR__, 2) . '/mailscanner/filter.inc.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (class_exists('database', false)) {
            $this->originalDbLink = \database::$link;
        }

        // Mock database link for unit testing
        $mockDb = new class {
            public $savedFilters = [];
            public $queryLog = [];

            public function query($sql)
            {
                $this->queryLog[] = $sql;

                if (stripos($sql, 'SHOW COLUMNS FROM `saved_filters` LIKE \'logic\'') !== false) {
                    return new class {
                        public $num_rows = 1;
                        public function fetch_array() { return ['Field' => 'logic']; }
                        public function fetch_row() { return ['logic']; }
                    };
                }

                if (stripos($sql, 'SELECT DISTINCT `name` FROM `saved_filters`') !== false) {
                    $names = array_unique(array_column($this->savedFilters, 'name'));
                    $rows = array_map(function ($n) { return [$n]; }, $names);
                    return new class($rows) {
                        private $rows;
                        private $pos = 0;
                        public $num_rows;
                        public function __construct($rows) { $this->rows = $rows; $this->num_rows = count($rows); }
                        public function fetch_array() { return $this->rows[$this->pos++] ?? null; }
                        public function fetch_row() { return $this->rows[$this->pos++] ?? null; }
                    };
                }

                if (stripos($sql, 'SELECT `col`, `operator`, `value`, `logic` FROM `saved_filters`') !== false) {
                    $rows = [];
                    foreach ($this->savedFilters as $f) {
                        $rows[] = [$f['col'], $f['operator'], $f['value'], $f['logic']];
                    }
                    return new class($rows) {
                        private $rows;
                        private $pos = 0;
                        public $num_rows;
                        public function __construct($rows) { $this->rows = $rows; $this->num_rows = count($rows); }
                        public function fetch_row() { return $this->rows[$this->pos++] ?? null; }
                        public function fetch_array() { return $this->rows[$this->pos++] ?? null; }
                    };
                }

                if (preg_match("/REPLACE INTO `saved_filters`.*VALUES\s*\('([^']+)',\s*'([^']+)',\s*'([^']+)',\s*'([^']+)',\s*'([^']+)',\s*'([^']+)'\)/i", $sql, $m)) {
                    $this->savedFilters[] = [
                        'name' => $m[1],
                        'col' => $m[2],
                        'operator' => $m[3],
                        'value' => $m[4],
                        'logic' => $m[5],
                        'username' => $m[6],
                    ];
                    return true;
                }

                return true;
            }

            public function real_escape_string($s) { return addslashes((string)$s); }
            public function escape_string($s) { return addslashes((string)$s); }
        };

        \database::$link = $mockDb;

        $_SESSION = [
            'token' => 'test_token_123',
            'myusername' => 'testuser',
            'global_filter' => '(1=1)',
            'filter_history' => [],
        ];
    }

    protected function tearDown(): void
    {
        if (class_exists('database', false) && $this->originalDbLink !== null) {
            \database::$link = $this->originalDbLink;
        }
        $_SESSION = [];
        parent::tearDown();
    }

    public function testInitialState()
    {
        $filter = new \Filter();
        $this->assertIsArray($filter->item);
        $this->assertCount(0, $filter->item);
        $this->assertSame('AND', $filter->last_logic);

        $sql = $filter->CreateSQL();
        $this->assertSame("AND (1=1)\n", $sql);
    }

    public function testAddSingleFilter()
    {
        $filter = new \Filter();
        $filter->Add('to_address', 'LIKE', 'help');

        $this->assertCount(1, $filter->item);
        $this->assertSame('to_address', $filter->item[0][0]);
        $this->assertSame('LIKE', $filter->item[0][1]);
        $this->assertSame('help', $filter->item[0][2]);
        $this->assertSame('AND', $filter->item[0][3]);

        $sql = $filter->CreateSQL();
        $this->assertStringContainsString("AND (1=1)\n", $sql);
        $this->assertStringContainsString("AND (\n to_address LIKE '%help%'\n)\n", $sql);

        $this->assertNotEmpty($_SESSION['filter_history']);
        $this->assertStringContainsString('To contains "help"', $_SESSION['filter_history'][0]['summary']);
    }

    public function testAddMultipleFiltersWithDefaultAnd()
    {
        $filter = new \Filter();
        $filter->Add('to_address', 'LIKE', 'help');
        $filter->Add('to_address', 'LIKE', 'abuse');

        $this->assertCount(2, $filter->item);
        $this->assertSame('AND', $filter->item[0][3]);
        $this->assertSame('AND', $filter->item[1][3]);

        $sql = $filter->CreateSQL();
        $expectedGroup = "AND (\n to_address LIKE '%help%'\n AND\n to_address LIKE '%abuse%'\n)\n";
        $this->assertStringContainsString($expectedGroup, $sql);

        $summary = $_SESSION['filter_history'][0]['summary'];
        $this->assertSame('To contains "help" AND To contains "abuse"', $summary);
    }

    public function testAddMultipleFiltersWithOrLogic()
    {
        $filter = new \Filter();
        $filter->Add('to_address', 'LIKE', 'help');
        $filter->Add('to_address', 'LIKE', 'abuse', 'OR');

        $this->assertCount(2, $filter->item);
        $this->assertSame('AND', $filter->item[0][3]);
        $this->assertSame('OR', $filter->item[1][3]);
        $this->assertSame('OR', $filter->last_logic);

        $sql = $filter->CreateSQL();
        $expectedGroup = "AND (\n to_address LIKE '%help%'\n OR\n to_address LIKE '%abuse%'\n)\n";
        $this->assertStringContainsString($expectedGroup, $sql);
        // Ensure global_filter is strictly isolated
        $this->assertStringStartsWith("AND (1=1)\n", $sql);

        $summary = $_SESSION['filter_history'][0]['summary'];
        $this->assertSame('To contains "help" OR To contains "abuse"', $summary);
    }

    public function testToggleLogic()
    {
        $filter = new \Filter();
        $filter->Add('to_address', 'LIKE', 'help');
        $filter->Add('to_address', 'LIKE', 'abuse', 'AND');

        $this->assertSame('AND', $filter->item[1][3]);

        // Toggle index 1 to OR
        $filter->ToggleLogic(1);
        $this->assertSame('OR', $filter->item[1][3]);
        $this->assertStringContainsString(' OR ', $_SESSION['filter_history'][0]['summary']);

        // Toggle index 1 back to AND
        $filter->ToggleLogic(1);
        $this->assertSame('AND', $filter->item[1][3]);
        $this->assertStringContainsString(' AND ', $_SESSION['filter_history'][0]['summary']);

        // Toggle index 0 should do nothing (item 0 logic connective is fixed to AND)
        $filter->ToggleLogic(0);
        $this->assertSame('AND', $filter->item[0][3]);
    }

    public function testSetAllLogic()
    {
        $filter = new \Filter();
        $filter->Add('to_address', 'LIKE', 'help');
        $filter->Add('to_address', 'LIKE', 'abuse', 'AND');
        $filter->Add('from_address', 'LIKE', 'alert', 'AND');

        $this->assertSame('AND', $filter->item[1][3]);
        $this->assertSame('AND', $filter->item[2][3]);

        // Set all to OR
        $filter->SetAllLogic('OR');
        $this->assertSame('OR', $filter->last_logic);
        $this->assertSame('AND', $filter->item[0][3]);
        $this->assertSame('OR', $filter->item[1][3]);
        $this->assertSame('OR', $filter->item[2][3]);

        $sql = $filter->CreateSQL();
        $this->assertStringContainsString("AND (\n to_address LIKE '%help%'\n OR\n to_address LIKE '%abuse%'\n OR\n from_address LIKE '%alert%'\n)\n", $sql);

        // Set all to AND
        $filter->SetAllLogic('AND');
        $this->assertSame('AND', $filter->last_logic);
        $this->assertSame('AND', $filter->item[1][3]);
        $this->assertSame('AND', $filter->item[2][3]);
    }

    public function testRemoveFilterReindexingAndFirstLogic()
    {
        $filter = new \Filter();
        $filter->Add('to_address', 'LIKE', 'help');
        $filter->Add('to_address', 'LIKE', 'abuse', 'OR');
        $filter->Add('from_address', 'LIKE', 'alert', 'OR');

        // Remove the first filter item
        $filter->Remove(0);

        // Items should be reindexed 0 and 1
        $this->assertCount(2, $filter->item);
        $this->assertSame('abuse', $filter->item[0][2]);
        // New first item must have connective 'AND'
        $this->assertSame('AND', $filter->item[0][3]);
        $this->assertSame('alert', $filter->item[1][2]);
        $this->assertSame('OR', $filter->item[1][3]);

        $sql = $filter->CreateSQL();
        $this->assertStringContainsString("AND (\n to_address LIKE '%abuse%'\n OR\n from_address LIKE '%alert%'\n)\n", $sql);
    }

    public function testCreateMtalogSQLWithOrLogic()
    {
        $filter = new \Filter();
        $filter->Add('date', '>', '2026-01-01');
        $filter->Add('date', '<', '2026-02-01', 'OR');

        $sql = $filter->CreateMtalogSQL();
        $expected = "AND (\n DATE_FORMAT(timestamp,'%Y-%m-%d') > '2026-01-01'\n OR\n DATE_FORMAT(timestamp,'%Y-%m-%d') < '2026-02-01'\n)\n";
        $this->assertSame($expected, $sql);
    }

    public function testCompactBarHtmlRendersLogicBadgesAndSwitcher()
    {
        $filter = new \Filter();
        $filter->Add('to_address', 'LIKE', 'help');
        $filter->Add('to_address', 'LIKE', 'abuse', 'OR');

        $html = $filter->GetCompactBarHtml();

        // Check for clickable badge between filters
        $this->assertStringContainsString('class="filter-logic-badge filter-logic-or"', $html);
        $this->assertStringContainsString('action=toggle_logic&amp;column=1', $html);

        // Check for match-mode switcher
        $this->assertStringContainsString('class="filter-logic-switcher"', $html);
        $this->assertStringContainsString('action=set_all_logic&amp;logic=AND', $html);
        $this->assertStringContainsString('action=set_all_logic&amp;logic=OR', $html);
    }

    public function testDisplayFormRendersLogicSelect()
    {
        $filter = new \Filter();
        $filter->last_logic = 'OR';

        $html = $filter->DisplayForm();

        $this->assertStringContainsString('name="logic"', $html);
        $this->assertStringContainsString('<option value="AND"', $html);
        $this->assertStringContainsString('<option value="OR" SELECTED>', $html);
    }

    public function testSaveAndLoadFilterWithLogic()
    {
        $filter = new \Filter();
        $filter->Add('to_address', 'LIKE', 'help');
        $filter->Add('to_address', 'LIKE', 'abuse', 'OR');

        $filter->Save('test_saved_filter');

        $newFilter = new \Filter();
        $newFilter->Load('test_saved_filter');

        $this->assertCount(2, $newFilter->item);
        $this->assertSame('to_address', $newFilter->item[0][0]);
        $this->assertSame('help', $newFilter->item[0][2]);
        $this->assertSame('AND', $newFilter->item[0][3]);

        $this->assertSame('to_address', $newFilter->item[1][0]);
        $this->assertSame('abuse', $newFilter->item[1][2]);
        $this->assertSame('OR', $newFilter->item[1][3]);
    }
}
