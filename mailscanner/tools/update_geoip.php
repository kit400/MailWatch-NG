<?php

/**
 * CLI Tool to download and update the strato-do/ip-geo database
 *
 * Usage:
 *   php update_geoip.php
 */

require_once __DIR__ . '/../functions.php';

$sourceUrl = 'https://github.com/strato-do/ip-geo/releases/latest/download/ip-geo.mmdb';
$tempDir = __DIR__ . '/../temp';
$finalDest = $tempDir . '/ip-geo.mmdb';
$tempDest = $tempDir . '/ip-geo.mmdb.tmp';

if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

echo "Downloading strato-do/ip-geo database from $sourceUrl...\n";

$ch = curl_init($sourceUrl);
$fp = fopen($tempDest, 'wb');
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);
curl_setopt($ch, CURLOPT_USERAGENT, 'MailWatch-NG/' . mailwatch_version());
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$success = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);
fclose($fp);

if ($success && ($httpCode === 200 || $httpCode === 302) && filesize($tempDest) > 1000000) {
    rename($tempDest, $finalDest);
    @chmod($finalDest, 0644);

    if (is_dir('/usr/share/GeoIP') && is_writable('/usr/share/GeoIP')) {
        @unlink('/usr/share/GeoIP/ip-geo.mmdb');
        @symlink($finalDest, '/usr/share/GeoIP/ip-geo.mmdb');
    }

    echo "SUCCESS: Installed ip-geo.mmdb (" . formatSize(filesize($finalDest)) . ")\n";

    // Test with MaxMind reader
    require_once __DIR__ . '/../lib/maxmind-db/reader/autoload.php';
    try {
        $reader = new \MaxMind\Db\Reader($finalDest);
        $meta = $reader->metadata();
        echo "Database Type: " . ($meta->databaseType ?? 'ip-geo') . "\n";
        echo "Node Count:    " . number_format($meta->nodeCount ?? 0) . " nodes\n";
        echo "Build Epoch:   " . (isset($meta->buildEpoch) ? date('Y-m-d H:i:s', $meta->buildEpoch) : '-') . "\n";
        $reader->close();

        // Sample lookup test
        $test = return_geoip_data('8.8.8.8');
        if ($test) {
            echo "Verification:  8.8.8.8 -> {$test['country_name']} ({$test['city']}) - {$test['asn_full']}\n";
        }
    } catch (\Throwable $e) {
        echo "Warning: Error during verification: " . $e->getMessage() . "\n";
    }
} else {
    @unlink($tempDest);
    echo "ERROR: Failed to download database (HTTP $httpCode): $curlErr\n";
    exit(1);
}
