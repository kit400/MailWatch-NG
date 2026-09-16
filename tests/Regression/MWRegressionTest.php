<?php

namespace App\Tests\Regression;

use PHPUnit\Framework\TestCase;

class MWRegressionTest extends TestCase
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
    }

    /**
     * @dataProvider regressionScriptsProvider
     */
    public function testRegressionScript(string $name, string $file)
    {
        $this->assertFileExists($file, "Regression test script $name not found");
        $phpBinary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $command = escapeshellcmd($phpBinary) . ' ' . escapeshellarg($file);
        exec($command, $output, $returnVar);
        $outputStr = implode("\n", $output);
        $this->assertSame(0, $returnVar, "Regression test $name failed with return code $returnVar:\n$outputStr");
        $this->assertStringContainsString('PASS', $outputStr, "Regression test $name did not output PASS:\n$outputStr");
    }

    public function regressionScriptsProvider(): array
    {
        $baseDir = dirname(__DIR__);
        return [
            'MW-03 (Address LIKE Wildcards)' => ['MW-03', $baseDir . '/MW03RegressionTest.php'],
            'MW-04 (SessionGuard Decoupling)' => ['MW-04', $baseDir . '/MW04RegressionTest.php'],
            'MW-05 (Private HTML Cache)' => ['MW-05', $baseDir . '/MW05RegressionTest.php'],
            'MW-08 (Batched Delete & Budgets)' => ['MW-08', $baseDir . '/MW08RegressionTest.php'],
            'MW-09 (Mtalog Cleanup Safety)' => ['MW-09', $baseDir . '/MW09RegressionTest.php'],
            'MW-10 (Failed Events & DLQ)' => ['MW-10', $baseDir . '/MW10RegressionTest.php'],
            'MW-11 (Mutual Exclusivity & Metrics)' => ['MW-11', $baseDir . '/MW11RegressionTest.php'],
            'MW-13 (MySQLi Options & SQL Mode)' => ['MW-13', $baseDir . '/MW13RegressionTest.php'],
            'MW-14 (LDAP Filter vs DN Escaping)' => ['MW-14', $baseDir . '/MW14RegressionTest.php'],
        ];
    }
}
