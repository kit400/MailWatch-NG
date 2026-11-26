<?php

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

class MWGeoIPTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('MAILWATCH_TEST_RUNNER')) {
            define('MAILWATCH_TEST_RUNNER', true);
        }
        if (!defined('PHPUNIT_RUNNING')) {
            define('PHPUNIT_RUNNING', true);
        }
        require_once dirname(__DIR__, 2) . '/mailscanner/functions.php';
    }

    public function testMaxMindAutoloaderExistsAndLoadsReaderClass(): void
    {
        $autoload = dirname(__DIR__, 2) . '/mailscanner/lib/maxmind-db/reader/autoload.php';
        $this->assertFileExists($autoload);
        $this->assertFileIsReadable($autoload);

        require_once $autoload;
        $this->assertTrue(class_exists('\MaxMind\Db\Reader'));
    }

    public function testReturnGeoipDataPrivateAndLocalIps(): void
    {
        $this->assertFalse(return_geoip_data(''));
        $this->assertFalse(return_geoip_data('127.0.0.1'));
        $this->assertFalse(return_geoip_data('10.0.1.5'));
        $this->assertFalse(return_geoip_data('192.168.1.100'));
        $this->assertFalse(return_geoip_data('::1'));
    }

    public function testReturnGeoipDataSafeWhenNoDb(): void
    {
        if (!get_geoip_database_file()) {
            $this->assertFalse(return_geoip_data('8.8.8.8'));
        } else {
            $data = return_geoip_data('8.8.8.8');
            $this->assertIsArray($data);
        }
    }
}
