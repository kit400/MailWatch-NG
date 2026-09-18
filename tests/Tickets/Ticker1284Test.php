<?php

namespace App\Tests\Tickets;

use PHPUnit\Framework\TestCase;

class Ticker1284Test extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $conf = dirname(__DIR__, 2) . '/mailscanner/conf.php';
        $example = dirname(__DIR__, 2) . '/mailscanner/conf.php.example';
        if (!file_exists($conf) && file_exists($example)) {
            $content = file_get_contents($example);
            $content = str_replace(
                "define('MAILWATCH_HOME', '/var/www/html/mailscanner');",
                "define('MAILWATCH_HOME', __DIR__);",
                $content
            );
            file_put_contents($conf, $content);
        }
        require_once dirname(__DIR__, 2) . '/mailscanner/functions.php';
    }

    public function testItCanParseProcMounts()
    {
        foreach ($this->procmountsFixtureFiles() as $fixtureFile) {
            $mounted_fs = file($fixtureFile);
            $this->assertIsArray($mounted_fs);
            $disks = parse_proc_mounts($mounted_fs);

            // Assert that the result is as expected.
            $this->assertNotEmpty($disks);

            // Assert that no device matches /dev/loop* or snap
            $loopDevices = array_filter($disks, function ($disk) {
                return false !== stripos($disk['mountpoint'], 'snap');
            });
            $this->assertEmpty(
                $loopDevices,
                'There should be no snap devices in the disks array (' . $fixtureFile . ').'
                . PHP_EOL .
                'Found: ' . var_export($loopDevices, true)
            );
        }
    }

    public function testItCanParseMountCommand()
    {
        foreach ($this->mountFixtureFiles() as $fixtureFile) {
            $data = file_get_contents($fixtureFile);
            $this->assertIsString($data);
            $disks = parse_mount_output($data);

            // Assert that the result is as expected.
            $this->assertNotEmpty($disks);

            // Assert that no device matches snapd
            $loopDevices = array_filter($disks, function ($disk) {
                return false !== stripos($disk['mountpoint'], 'snapd');
            });
            $this->assertEmpty(
                $loopDevices,
                'There should be no snapd devices in the disks array (' . $fixtureFile . ').'
                . PHP_EOL .
                'Found: ' . var_export($loopDevices, true)
            );
        }
    }

    private function procmountsFixtureFiles()
    {
        return [
            __DIR__ . '/fixtures/1284/centos_7_proc_mounts.txt',
            __DIR__ . '/fixtures/1284/ubuntu_22.04_proc_mounts.txt',
        ];
    }

    private function mountFixtureFiles()
    {
        return [
            __DIR__ . '/fixtures/1284/centos_7_mount.txt',
            __DIR__ . '/fixtures/1284/ubuntu_22.04_mount.txt',
        ];
    }
}
