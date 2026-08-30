<?php

/*
 * MailWatch for MailScanner
 * Copyright (C) 2003-2011  Steve Freegard (steve@freegard.name)
 * Copyright (C) 2011  Garrod Alwood (garrod.alwood@lorodoes.com)
 * Copyright (C) 2014-2021  MailWatch Team (https://github.com/mailwatch/1.2.0/graphs/contributors)
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.
 *
 * In addition, as a special exception, the copyright holder gives permission to link the code of this program with
 * those files in the PEAR library that are licensed under the PHP License (or with modified versions of those files
 * that use the same license as those files), and distribute linked combinations including the two.
 * You must obey the GNU General Public License in all respects for all of the code used other than those files in the
 * PEAR library that are licensed under the PHP License. If you modify this program, you may extend this exception to
 * your version of the program, but you are not obligated to do so.
 * If you do not wish to do so, delete this exception statement from your version.
 *
 * You should have received a copy of the GNU General Public License along with this program; if not, write to the Free
 * Software Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

// Include of necessary functions
require_once __DIR__ . '/functions.php';

// Authentication checking
require __DIR__ . '/login.function.php';

if ('A' !== $_SESSION['user_type']) {
    header('Location: index.php');
    audit_log(__('auditlog11', true));
    exit;
}

html_start(__('mwandmsversion11'), 0, false, false);
dbconn();

$components = [];

// MailWatch-NG
$components[] = [
    'name' => 'MailWatch-NG',
    'url' => mailwatch_project_url(),
    'version' => mailwatch_version(),
];

// EFA-NG Project
$efa_ver = efa_version();
if (!empty($efa_ver)) {
    $components[] = [
        'name' => 'EFA-NG',
        'url' => 'https://efa-ng.space.ua',
        'version' => $efa_ver,
    ];
}

// Operating System
$systemos = PHP_OS;
$os_url = 'https://www.kernel.org';
if (0 === stripos(PHP_OS, 'linux')) {
    $vars = [];
    $files = glob('/etc/*-release');
    if (is_array($files)) {
        foreach ($files as $file) {
            $lines = file($file);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    if (strpos($line, '=') !== false) {
                        list($k, $v) = explode('=', trim($line), 2);
                        $vars[$k] = trim($v, "\"'");
                    }
                }
            }
        }
    }
    if (!empty($vars['PRETTY_NAME'])) {
        $systemos = $vars['PRETTY_NAME'];
    } elseif (!empty($vars['NAME'])) {
        $systemos = $vars['NAME'] . (!empty($vars['VERSION']) ? ' ' . $vars['VERSION'] : '');
    } elseif (!empty($vars['ID'])) {
        $systemos = $vars['ID'];
    }
    if (!empty($vars['HOME_URL'])) {
        $os_url = $vars['HOME_URL'];
    }
    $systemos .= ' (' . php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m') . ')';
} elseif ('freebsd' === strtolower(PHP_OS)) {
    $systemos = 'FreeBSD ' . php_uname('r') . ' (' . php_uname('m') . ')';
    $os_url = 'https://www.freebsd.org';
}
$components[] = [
    'name' => __('systemos11'),
    'url' => $os_url,
    'version' => $systemos,
];

// MailScanner
$mailscanner_version = get_conf_var('MailScannerVersionNumber');
$components[] = [
    'name' => 'MailScanner',
    'url' => 'https://www.mailscanner.info',
    'version' => !empty($mailscanner_version) ? $mailscanner_version : 'Unknown',
];

// MTA
$mta = strtolower((string) get_conf_var('MTA', true));
if ('postfix' === $mta || 'msmail' === $mta || empty($mta)) {
    $mta_version = 'Unknown';
    exec('which postconf 2>/dev/null', $postconf);
    if (!empty($postconf[0])) {
        exec($postconf[0] . " -d 2>/dev/null | grep 'mail_version =' | cut -d' ' -f3", $out);
        if (!empty($out[0])) {
            $mta_version = trim($out[0]);
        }
    }
    $components[] = [
        'name' => 'Postfix (MTA)',
        'url' => 'https://www.postfix.org',
        'version' => $mta_version,
    ];
} elseif ('exim' === $mta) {
    $mta_version = 'Unknown';
    exec('which exim 2>/dev/null', $exim);
    if (!empty($exim[0])) {
        exec($exim[0] . " -bV 2>/dev/null | grep 'Exim version' | cut -d' ' -f3", $out);
        if (!empty($out[0])) {
            $mta_version = trim($out[0]);
        }
    }
    $components[] = [
        'name' => 'Exim (MTA)',
        'url' => 'https://www.exim.org',
        'version' => $mta_version,
    ];
} elseif ('sendmail' === $mta) {
    $mta_version = 'Unknown';
    exec('which sendmail 2>/dev/null', $sendmail);
    if (!empty($sendmail[0])) {
        exec($sendmail[0] . " -d0.4 -bv root 2>/dev/null | grep 'Version' | cut -d' ' -f2", $out);
        if (!empty($out[0])) {
            $mta_version = trim($out[0]);
        }
    }
    $components[] = [
        'name' => 'Sendmail (MTA)',
        'url' => 'https://www.sendmail.com',
        'version' => $mta_version,
    ];
}

// Anti-Virus
$virusScanner = get_conf_var('VirusScanners');
if (false !== stripos($virusScanner, 'clam')) {
    $clam_ver = 'Unknown';
    exec('which clamscan 2>/dev/null', $clamscan);
    if (!empty($clamscan[0])) {
        exec($clamscan[0] . " -V 2>/dev/null | cut -d/ -f1 | cut -d' ' -f2", $out);
        if (!empty($out[0])) {
            $clam_ver = trim($out[0]);
        }
    }
    $components[] = [
        'name' => 'ClamAV',
        'url' => 'https://www.clamav.net',
        'version' => $clam_ver,
    ];
}
if (false !== stripos($virusScanner, 'sophos')) {
    $sophos_ver = 'Unknown';
    exec('which sweep 2>/dev/null', $sweep);
    if (!empty($sweep[0])) {
        exec($sweep[0] . " -v 2>/dev/null | grep 'Product version' | cut -d: -f2", $out);
        if (!empty($out[0])) {
            $sophos_ver = trim($out[0]);
        }
    }
    $components[] = [
        'name' => 'Sophos',
        'url' => 'https://www.sophos.com',
        'version' => $sophos_ver,
    ];
}

// SpamAssassin
$sa_ver = 'Unknown';
exec(SA_DIR . "spamassassin -V 2>/dev/null | tr '\\n' ' ' | cut -d' ' -f3", $sa_out);
if (!empty($sa_out[0])) {
    $sa_ver = trim($sa_out[0]);
}
$components[] = [
    'name' => 'SpamAssassin',
    'url' => 'https://spamassassin.apache.org',
    'version' => $sa_ver,
];

// PHP
$components[] = [
    'name' => 'PHP',
    'url' => 'https://www.php.net',
    'version' => PHP_VERSION,
];

// Database (MySQL / MariaDB)
$db_version = database::getDatabaseVersion();
$is_mariadb = (false !== stripos($db_version, 'mariadb'));
$components[] = [
    'name' => $is_mariadb ? 'MariaDB Database' : 'MySQL Database',
    'url' => $is_mariadb ? 'https://mariadb.org' : 'https://www.mysql.com',
    'version' => !empty($db_version) ? $db_version : 'Unknown',
];

// GeoIP Database (strato-do / ip-geo)
$geoip_version = false;
$geoip_database_file = get_geoip_database_file();
if ($geoip_database_file && filesize($geoip_database_file) > 0) {
    require_once __DIR__ . '/lib/maxmind-db/reader/autoload.php';
    try {
        $geoIpDbReader = new \MaxMind\Db\Reader($geoip_database_file);
        $GeoIPDbMetadata = $geoIpDbReader->metadata();

        $desc = 'Fused IP Lookup (IPv4/IPv6 + AS/ASN)';
        if (isset($GeoIPDbMetadata->description) && is_array($GeoIPDbMetadata->description)) {
            $desc = $GeoIPDbMetadata->description['en'] ?? (reset($GeoIPDbMetadata->description) ?: $desc);
        } elseif (isset($GeoIPDbMetadata->description) && is_string($GeoIPDbMetadata->description)) {
            $desc = $GeoIPDbMetadata->description;
        }

        $epoch = (int)($GeoIPDbMetadata->buildEpoch ?? 0);
        $epochDate = $epoch > 0 ? date('Y-m-d H:i:s', $epoch) : '';
        $nodeCount = isset($GeoIPDbMetadata->nodeCount) ? ' [' . number_format($GeoIPDbMetadata->nodeCount) . ' nodes]' : '';

        $geoip_version = trim($desc . $nodeCount . ' ' . $epochDate);
        $geoIpDbReader->close();
    } catch (\Throwable $e) {
        $geoip_version = false;
    }
}
$components[] = [
    'name' => 'GeoIP & ASN Database (strato-do / ip-geo)',
    'url' => 'https://github.com/strato-do/ip-geo',
    'version' => !empty($geoip_version) ? $geoip_version : __('nodbdown11'),
];

// System Update Status Check (cached)
$currentVer = mailwatch_version();
$updateCheck = class_exists('SystemNotifications') ? SystemNotifications::checkForUpdates(false) : null;
$hasUpdate = $updateCheck && !empty($updateCheck['has_update']);
$latestVer = $updateCheck['latest_version'] ?? $currentVer;
$checkedAt = !empty($updateCheck['checked_at']) ? date('M j, Y H:i', $updateCheck['checked_at']) : 'Never';
$upgradeCmd = $updateCheck['upgrade_command'] ?? 'dnf clean all && dnf -y update eFa MailWatch && systemctl reload php-fpm httpd';
$changelogUrl = $updateCheck['release_data']['changelog_url'] ?? 'https://github.com/kit400/EFA-NG/releases';
$releaseDesc = $updateCheck['release_data']['short_description'] ?? 'New release available.';

// Fallback sync: if cached status is not updated but database has active release notification for newer version
if (!$hasUpdate && class_exists('SystemNotifications')) {
    dbconn();
    $dRes = dbquery("SELECT * FROM system_notifications WHERE type = 'release' AND is_active = 1 ORDER BY id DESC LIMIT 1");
    if ($dRes && $dRes->num_rows > 0) {
        $rRow = $dRes->fetch_assoc();
        if (!empty($rRow['version']) && version_compare($currentVer, $rRow['version'], '<')) {
            $hasUpdate = true;
            $latestVer = $rRow['version'];
            $releaseDesc = !empty($rRow['short_description']) ? $rRow['short_description'] : 'New release available.';
            $changelogUrl = !empty($rRow['changelog_url']) ? $rRow['changelog_url'] : $changelogUrl;
        }
    }
}

echo '<div class="version-update-card" id="versionUpdateCard" style="margin-bottom: 20px; background: #ffffff; border: 1px solid ' . ($hasUpdate ? '#38bdf8' : '#e2e8f0') . '; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); padding: 18px 20px;">' . "\n";
echo '  <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap;">' . "\n";
echo '    <div style="display: flex; gap: 14px; align-items: flex-start;">' . "\n";
echo '      <div style="font-size: 26px; line-height: 1; margin-top: 2px;">' . ($hasUpdate ? '🚀' : '✅') . '</div>' . "\n";
echo '      <div>' . "\n";
if ($hasUpdate) {
    echo '        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">' . "\n";
    echo '          <span style="background: #0284c7; color: #ffffff; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700; text-transform: uppercase;">Update Available</span>' . "\n";
    echo '          <strong style="font-size: 15px; color: #0f172a;">New version EFA-NG v' . htmlspecialchars($latestVer) . ' is available!</strong>' . "\n";
    echo '        </div>' . "\n";
    echo '        <div style="color: #475569; font-size: 13px; margin-bottom: 12px;">' . htmlspecialchars($releaseDesc) . ' (Current: <code>v' . htmlspecialchars($currentVer) . '</code>)</div>' . "\n";
    echo '        <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 14px; max-width: 760px;">
          <div style="font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 10px;">🚀 Quick Upgrade Instructions (SSH as root):</div>

          <div style="margin-bottom: 12px;">
            <div style="font-weight: 600; font-size: 11px; color: #0284c7; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Option 1: Interactive CLI Console (Recommended)</div>
            <div style="font-size: 12px; color: #475569; margin-bottom: 6px;">Launch console and choose <strong>13) Update System & Packages</strong>:</div>
            <div style="display: flex; align-items: center; background: #0f172a; border-radius: 4px; padding: 8px 12px; gap: 10px;">
              <code style="color: #38bdf8; font-family: monospace; font-size: 13px; flex: 1;">eFa-Configure</code>
              <button type="button" onclick="copyUpdateCommand(this, \'eFa-Configure\')" style="background: #334155; color: #f8fafc; border: 1px solid #475569; border-radius: 4px; padding: 4px 12px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap;">Copy</button>
            </div>
          </div>

          <div>
            <div style="font-weight: 600; font-size: 11px; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Option 2: Direct Shell Command</div>
            <div style="font-size: 12px; color: #475569; margin-bottom: 6px;">Execute package upgrade and reload daemons:</div>
            <div style="display: flex; align-items: center; background: #0f172a; border-radius: 4px; padding: 8px 12px; gap: 10px;">
              <code style="color: #38bdf8; font-family: monospace; font-size: 13px; flex: 1; word-break: break-all;">' . htmlspecialchars($upgradeCmd) . '</code>
              <button type="button" onclick="copyUpdateCommand(this, \'' . htmlspecialchars(addslashes($upgradeCmd)) . '\')" style="background: #334155; color: #f8fafc; border: 1px solid #475569; border-radius: 4px; padding: 4px 12px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap;">Copy</button>
            </div>
          </div>
        </div>' . "\n";
} else {
    echo '        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">' . "\n";
    echo '          <span style="background: #16a34a; color: #ffffff; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700; text-transform: uppercase;">Up to Date</span>' . "\n";
    echo '          <strong style="font-size: 15px; color: #0f172a;">You are running the latest version of EFA-NG (v' . htmlspecialchars($currentVer) . ')</strong>' . "\n";
    echo '        </div>' . "\n";
    echo '        <div style="color: #64748b; font-size: 13px;">No new updates found. Last checked: ' . $checkedAt . '</div>' . "\n";
}
echo '      </div>' . "\n";
echo '    </div>' . "\n";
echo '    <div style="display: flex; gap: 8px; align-items: center; align-self: flex-start;">' . "\n";
if ($hasUpdate) {
    echo '      <a href="' . htmlspecialchars($changelogUrl) . '" target="_blank" rel="noopener noreferrer" style="display: inline-block; padding: 6px 12px; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none;">📖 Changelog</a>' . "\n";
}
echo '      <button type="button" id="btnCheckUpdates" onclick="triggerUpdateCheck(this)" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background: #f1f5f9; color: #1e293b; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">🔄 Check for Updates</button>' . "\n";
echo '    </div>' . "\n";
echo '  </div>' . "\n";
echo '</div>' . "\n";

echo '<script type="text/javascript">
function triggerUpdateCheck(btn) {
    var orig = btn.innerHTML;
    btn.innerHTML = "🔄 Checking...";
    btn.disabled = true;
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "notification_action.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            btn.innerHTML = orig;
            btn.disabled = false;
            if (xhr.status === 200) {
                location.reload();
            } else {
                alert("Failed to check for updates. Please try again.");
            }
        }
    };
    xhr.send("action=check_updates&token=' . ($_SESSION['token'] ?? '') . '");
}
</script>' . "\n";

echo '<table width="100%" class="boxtable">' . "\n";
echo '  <thead>' . "\n";
echo '    <tr>' . "\n";
echo '      <th style="width: 35%; text-align: left; padding: 8px 12px;">' . __('softver11') . '</th>' . "\n";
echo '      <th style="width: 65%; text-align: left; padding: 8px 12px;">' . __('version11') . '</th>' . "\n";
echo '    </tr>' . "\n";
echo '  </thead>' . "\n";
echo '  <tbody>' . "\n";

foreach ($components as $c) {
    echo '    <tr>' . "\n";
    echo '      <td class="textdata" style="font-weight: 600; padding: 7px 12px;"><a href="' . htmlspecialchars($c['url']) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($c['name']) . '</a></td>' . "\n";
    echo '      <td style="padding: 7px 12px; color: #1e293b;">' . htmlspecialchars($c['version']) . '</td>' . "\n";
    echo '    </tr>' . "\n";
}

echo '  </tbody>' . "\n";
echo '</table>' . "\n";

// Add footer
html_end();
// Close any open db connections
dbclose();
