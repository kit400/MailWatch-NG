<?php

/**
 * MailWatch for MailScanner
 * Copyright (C) 2003-2011  Steve Freegard (steve@freegard.name)
 * Copyright (C) 2011  Garrod Alwood (garrod.alwood@lorodoes.com)
 * Copyright (C) 2014-2026  MailWatch Team (https://github.com/mailwatch/1.2.0/graphs/contributors)
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later
 * version.
 */

require_once __DIR__ . '/functions.php';
require __DIR__ . '/login.function.php';

if ('A' !== $_SESSION['user_type']) {
    header('Location: login.php');
    exit;
}

html_start(__('geoipupdate15'), 0, false, false);

$dbFile = __DIR__ . '/temp/ip-geo.mmdb';
$sourceUrl = 'https://github.com/strato-do/ip-geo/releases/latest/download/ip-geo.mmdb';

if (!isset($_POST['run'])) {
    $currentStatus = 'Not installed';
    $nodeCount = '-';
    $buildDate = '-';
    $fileSize = '-';

    $activeFile = get_geoip_database_file();
    if ($activeFile && file_exists($activeFile)) {
        $fileSize = formatSize(filesize($activeFile));
        require_once __DIR__ . '/lib/maxmind-db/reader/autoload.php';
        try {
            $reader = new \MaxMind\Db\Reader($activeFile);
            $meta = $reader->metadata();
            $currentStatus = 'Active (' . basename($activeFile) . ')';
            $nodeCount = number_format($meta->nodeCount ?? 0) . ' nodes';
            $buildDate = isset($meta->buildEpoch) ? date('Y-m-d H:i:s', $meta->buildEpoch) : date('Y-m-d H:i:s', filemtime($activeFile));
            $reader->close();
        } catch (\Throwable $e) {
            $currentStatus = 'Error reading database: ' . htmlspecialchars($e->getMessage());
        }
    }

    echo '<form method="POST" action="geoip_update.php">
            <input type="hidden" name="run" value="true">
            <input type="hidden" name="token" value="' . htmlspecialchars($_SESSION['token'] ?? '') . '">
            <table class="boxtable" width="100%">
            <thead>
                <tr><th colspan="2">' . __('updategeoip15') . ' — strato-do/ip-geo</th></tr>
            </thead>
            <tbody>
               <tr>
                   <td colspan="2" style="padding:12px;line-height:1.5;">
                       <strong>IP Geolocation & Autonomous System (AS/ASN) Database</strong><br>
                       High-accuracy IPv4 / IPv6 geolocation and AS/ASN database powered by <a href="https://github.com/strato-do/ip-geo" target="_blank" rel="noopener noreferrer"><strong>strato-do/ip-geo</strong></a> (MaxMind DB format, rebuilt weekly with city-level precision and BGP Autonomous System mappings).<br>
                       <span style="color:#64748b;font-size:11px;">Direct download from official releases — no MaxMind license key required.</span>
                   </td>
               </tr>
               <tr>
                   <td style="width:30%;font-weight:600;padding:8px 12px;background:#f8fafc;">Database Status</td>
                   <td style="padding:8px 12px;">' . htmlspecialchars($currentStatus) . '</td>
               </tr>
               <tr>
                   <td style="font-weight:600;padding:8px 12px;background:#f8fafc;">File Size</td>
                   <td style="padding:8px 12px;">' . $fileSize . '</td>
               </tr>
               <tr>
                   <td style="font-weight:600;padding:8px 12px;background:#f8fafc;">Nodes / IP Ranges</td>
                   <td style="padding:8px 12px;">' . $nodeCount . '</td>
               </tr>
               <tr>
                   <td style="font-weight:600;padding:8px 12px;background:#f8fafc;">Build Date</td>
                   <td style="padding:8px 12px;">' . $buildDate . '</td>
               </tr>
               <tr>
                   <td colspan="2" align="center" style="padding:16px;">
                       <input type="submit" value="⬇ Download &amp; Update GeoIP Database Now" class="btn" style="padding:8px 18px;font-weight:700;cursor:pointer;">
                   </td>
               </tr>
            </tbody>
            </table>
            </form>' . "\n";
} else {
    if (false === checkToken($_POST['token'] ?? '')) {
        header('Location: login.php?error=pagetimeout');
        exit;
    }

    echo '<div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;padding:16px;margin-top:12px;line-height:1.6;">';
    echo '<h3 style="margin-top:0;">Downloading strato-do/ip-geo Database...</h3>';
    ob_flush();
    flush();

    $tempDest = __DIR__ . '/temp/ip-geo.mmdb.tmp';
    $finalDest = __DIR__ . '/temp/ip-geo.mmdb';

    if (!is_dir(__DIR__ . '/temp')) {
        mkdir(__DIR__ . '/temp', 0755, true);
    }

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

        // Update symlink in /usr/share/GeoIP if directory exists
        if (is_dir('/usr/share/GeoIP') && is_writable('/usr/share/GeoIP')) {
            @unlink('/usr/share/GeoIP/ip-geo.mmdb');
            @symlink($finalDest, '/usr/share/GeoIP/ip-geo.mmdb');
        }

        echo '<div style="color:#166534;font-weight:700;margin-bottom:10px;">✔ Successfully downloaded and installed ip-geo.mmdb (' . formatSize(filesize($finalDest)) . ')</div>';

        // Verification & Test Lookups
        require_once __DIR__ . '/lib/maxmind-db/reader/autoload.php';
        try {
            $reader = new \MaxMind\Db\Reader($finalDest);
            $meta = $reader->metadata();
            echo '<table class="boxtable" style="width:100%;margin-top:12px;">';
            echo '<tr><th colspan="2">Verified Database Metadata</th></tr>';
            echo '<tr><td style="width:25%;font-weight:600;">Database Type</td><td>' . htmlspecialchars($meta->databaseType ?? 'ip-geo') . '</td></tr>';
            echo '<tr><td style="font-weight:600;">Node Count</td><td>' . number_format($meta->nodeCount ?? 0) . ' nodes</td></tr>';
            echo '<tr><td style="font-weight:600;">Build Date</td><td>' . (isset($meta->buildEpoch) ? date('Y-m-d H:i:s', $meta->buildEpoch) : '-') . '</td></tr>';
            echo '</table>';

            // Sample Lookups
            echo '<h4 style="margin-top:16px;">Sample Geolocation &amp; AS Verification Lookups:</h4>';
            echo '<ul>';
            $sampleIps = ['8.8.8.8', '1.1.1.1', '195.230.150.68'];
            foreach ($sampleIps as $testIp) {
                $rec = return_geoip_data($testIp);
                if ($rec) {
                    echo '<li><strong>' . htmlspecialchars($testIp) . '</strong>: ' . htmlspecialchars($rec['country_name']) . (!empty($rec['city']) ? ' (' . htmlspecialchars($rec['city']) . ')' : '') . ' &mdash; <span class="badge-asn">' . htmlspecialchars($rec['asn_full']) . '</span></li>';
                }
            }
            echo '</ul>';

            $reader->close();
            audit_log('Updated GeoIP & ASN database to strato-do/ip-geo');
        } catch (\Throwable $e) {
            echo '<div style="color:#dc2626;">Error verifying database: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } else {
        @unlink($tempDest);
        echo '<div style="color:#dc2626;font-weight:700;">✖ Failed to download ip-geo.mmdb: HTTP ' . $httpCode . ' ' . htmlspecialchars($curlErr) . '</div>';
    }

    echo '<div style="margin-top:16px;"><a href="geoip_update.php" class="btn" style="text-decoration:none;padding:6px 14px;">« Back to GeoIP Management</a></div>';
    echo '</div>';
}

html_end();
dbclose();
