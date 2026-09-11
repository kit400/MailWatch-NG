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
 */

// Include necessary functions
require_once __DIR__ . '/filter.inc.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/graphgenerator.inc.php';

// Authentication checking
require __DIR__ . '/login.function.php';

$reportTitle = __('toptlds14', false) ?: 'Top TLDs (1st Level Domains)';
$filter = html_start($reportTitle, 0, false, true);

// Sorting order
$order = isset($_GET['order']) ? deepSanitizeInput($_GET['order'], 'url') : 'count';
$validOrders = ['count', 'size', 'spam', 'virus'];
if (!in_array($order, $validOrders, true)) {
    $order = 'count';
}

// Scope: from_domain (Senders) or to_domain (Recipients)
$scope = isset($_GET['scope']) ? deepSanitizeInput($_GET['scope'], 'url') : 'from';
if ($scope !== 'to') {
    $scope = 'from';
}

$domainField = ($scope === 'to') ? 'to_domain' : 'from_domain';
$scopeLabel = ($scope === 'to') ? (__('recipients14', false) ?: 'Recipients') : (__('senders14', false) ?: 'Senders');

$orderBySql = 'count DESC';
switch ($order) {
    case 'size':
        $orderBySql = 'size DESC';
        break;
    case 'spam':
        $orderBySql = 'total_spam DESC';
        break;
    case 'virus':
        $orderBySql = 'total_viruses DESC';
        break;
    case 'count':
    default:
        $orderBySql = 'count DESC';
        break;
}

$t0 = microtime(true);

$query = "
  SELECT
   LOWER(SUBSTRING_INDEX($domainField, '.', -1)) AS `tld`,
   COUNT(*) AS `count`,
   " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlVirus()) . " AS `total_viruses`,
   " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlSpam()) . " AS `total_spam`,
   SUM(size) AS `size`
 FROM
  maillog
 WHERE
  $domainField <> ''
 AND
  $domainField IS NOT NULL
 AND
  $domainField LIKE '%.%'
" . $filter->CreateSQL() . "
 GROUP BY
  `tld`
 ORDER BY
  $orderBySql
 LIMIT 50
";

$result = dbquery($query);
$tldList = [];
$totalMessages = 0;
$totalSize = 0;
$totalSpam = 0;
$totalViruses = 0;

// Known gTLD descriptions
$gtldDescriptions = [
    'com' => 'Commercial',
    'org' => 'Organization',
    'net' => 'Network Infrastructure',
    'edu' => 'Education',
    'gov' => 'Government',
    'mil' => 'Military',
    'int' => 'International Organization',
    'info' => 'Informational',
    'biz' => 'Business',
    'xyz' => 'Generic / New gTLD',
    'online' => 'Generic / New gTLD',
    'site' => 'Generic / New gTLD',
    'top' => 'Generic / New gTLD',
    'club' => 'Generic / Community',
    'vip' => 'Generic / New gTLD',
    'shop' => 'E-Commerce / Shopping',
    'store' => 'E-Commerce / Retail',
    'app' => 'Application / Tech',
    'dev' => 'Software Development',
    'cloud' => 'Cloud Services',
    'tech' => 'Technology',
    'pro' => 'Professional',
    'email' => 'Messaging / Communications',
];

while ($row = $result->fetch_assoc()) {
    $tld = trim($row['tld']);
    if (empty($tld)) continue;

    $cnt = (int)$row['count'];
    $sz = (int)$row['size'];
    $spm = (int)$row['total_spam'];
    $vir = (int)$row['total_viruses'];

    $totalMessages += $cnt;
    $totalSize += $sz;
    $totalSpam += $spm;
    $totalViruses += $vir;

    // Determine type / country for TLD
    $desc = '';
    $countryCode = '';
    if (strlen($tld) === 2) {
        $countryCode = ($tld === 'uk') ? 'GB' : strtoupper($tld);
        $desc = 'ccTLD (.' . $tld . ')';
    } elseif (isset($gtldDescriptions[$tld])) {
        $desc = $gtldDescriptions[$tld];
    } else {
        $desc = 'gTLD (.' . $tld . ')';
    }

    $tldList[] = [
        'tld' => $tld,
        'countryCode' => $countryCode,
        'desc' => $desc,
        'count' => $cnt,
        'size' => $sz,
        'total_spam' => $spm,
        'total_viruses' => $vir,
    ];
}

$elapsed = round(microtime(true) - $t0, 3);

// Prepare data for pie chart
$graphLimit = 10;
$topTldGraph = array_slice($tldList, 0, $graphLimit);

$chartLabels = [];
$chartNumericData = [];
$chartFormattedData = [];

$orderLabels = [
    'count' => __('messages39', false) ?: 'Messages',
    'spam' => __('spam39', false) ?: 'Spam',
    'virus' => __('viruses39', false) ?: 'Viruses',
    'size' => __('volume39', false) ?: 'Volume',
];
$activeOrderLabel = $orderLabels[$order] ?? 'Messages';

foreach ($topTldGraph as $item) {
    $chartLabels[] = addslashes('.' . $item['tld']);
    switch ($order) {
        case 'size':
            $chartNumericData[] = $item['size'];
            $chartFormattedData[] = addslashes(formatSize($item['size']));
            break;
        case 'spam':
            $chartNumericData[] = $item['total_spam'];
            $chartFormattedData[] = addslashes(number_format($item['total_spam']));
            break;
        case 'virus':
            $chartNumericData[] = $item['total_viruses'];
            $chartFormattedData[] = addslashes(number_format($item['total_viruses']));
            break;
        case 'count':
        default:
            $chartNumericData[] = $item['count'];
            $chartFormattedData[] = addslashes(number_format($item['count']));
            break;
    }
}

// Render Control Toolbar
?>
<div class="report-controls-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin:10px 0 16px 0; background:#fff; padding:10px 16px; border-radius:8px; border:1px solid #e2e8f0; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
  <div style="font-weight:600; color:#334155; font-size:13px; display:flex; align-items:center; gap:8px;">
    <span>🏷️ <?php echo htmlspecialchars($reportTitle); ?></span>
    <span style="font-size:11px; color:#64748b; font-weight:normal;">
      (<?php echo number_format($totalMessages); ?> <?php echo strtolower(__('messages39', false) ?: 'messages'); ?> across <?php echo count($tldList); ?> TLDs &bull; <?php echo $elapsed; ?>s)
    </span>
  </div>
  <div class="report-order-buttons" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
    <!-- Scope Selector -->
    <div style="display:flex; gap:4px; align-items:center; background:#f8fafc; padding:2px; border-radius:6px; border:1px solid #e2e8f0;">
      <a href="?scope=from&order=<?php echo htmlspecialchars($order); ?>" class="filter-badge" style="text-decoration:none; padding:3px 8px; border-radius:4px; font-size:10.5px; font-weight:500; <?php echo ($scope === 'from') ? 'background:#0f172a; color:#fff;' : 'color:#64748b;'; ?>"><?php echo __('senders14', false) ?: 'Senders'; ?></a>
      <a href="?scope=to&order=<?php echo htmlspecialchars($order); ?>" class="filter-badge" style="text-decoration:none; padding:3px 8px; border-radius:4px; font-size:10.5px; font-weight:500; <?php echo ($scope === 'to') ? 'background:#0f172a; color:#fff;' : 'color:#64748b;'; ?>"><?php echo __('recipients14', false) ?: 'Recipients'; ?></a>
    </div>

    <!-- Order Selector -->
    <div style="display:flex; gap:4px; align-items:center;">
      <span style="font-size:11px; color:#64748b; margin-right:2px;"><?php echo __('order03', false) ?: 'Order by'; ?>:</span>
      <a href="?scope=<?php echo htmlspecialchars($scope); ?>&order=count" class="filter-badge" style="text-decoration:none; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:500; <?php echo ($order === 'count') ? 'background:#2563eb; color:#fff;' : 'background:#f1f5f9; color:#475569;'; ?>"><?php echo __('messages39', false) ?: 'Messages'; ?></a>
      <a href="?scope=<?php echo htmlspecialchars($scope); ?>&order=spam" class="filter-badge" style="text-decoration:none; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:500; <?php echo ($order === 'spam') ? 'background:#2563eb; color:#fff;' : 'background:#f1f5f9; color:#475569;'; ?>"><?php echo __('spam39', false) ?: 'Spam'; ?></a>
      <a href="?scope=<?php echo htmlspecialchars($scope); ?>&order=virus" class="filter-badge" style="text-decoration:none; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:500; <?php echo ($order === 'virus') ? 'background:#2563eb; color:#fff;' : 'background:#f1f5f9; color:#475569;'; ?>"><?php echo __('viruses39', false) ?: 'Viruses'; ?></a>
      <a href="?scope=<?php echo htmlspecialchars($scope); ?>&order=size" class="filter-badge" style="text-decoration:none; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:500; <?php echo ($order === 'size') ? 'background:#2563eb; color:#fff;' : 'background:#f1f5f9; color:#475569;'; ?>"><?php echo __('volume39', false) ?: 'Volume'; ?></a>
    </div>
  </div>
</div>

<?php
if ($totalMessages > 0 && !empty($chartNumericData)) {
    $chartTitle = htmlspecialchars($reportTitle) . ' (' . $scopeLabel . ' - Top 10 by ' . $activeOrderLabel . ')';
    echo '<div id="tldReportGraph" class="reportGraph chart-echarts"></div>' . "\n";
    echo '<script src="js/echarts.min.js"></script>' . "\n";
    echo '<script src="js/pieConfig.js"></script>' . "\n";
    echo '<script>' . "\n";
    echo '  printPieGraph("tldReportGraph", {' . "\n";
    echo '    chartTitle : "' . addslashes($chartTitle) . '",' . "\n";
    echo '    chartId : "tldReportGraph",' . "\n";
    echo '    chartLabels : ["' . implode('", "', $chartLabels) . '"],' . "\n";
    echo '    chartNumericData : [' . implode(', ', $chartNumericData) . '],' . "\n";
    echo '    chartFormattedData : ["' . implode('", "', $chartFormattedData) . '"],' . "\n";
    echo '  });' . "\n";
    echo '</script>' . "\n";
    echo '<br>' . "\n";
} else {
    echo '<p class="center">' . (__('nodata64', false) ?: 'No data found.') . '</p>' . "\n";
}

// Table of top TLDs
if (!empty($tldList)) {
    $exportFileName = 'top_tlds_' . $scope . '_' . date('Ymd') . '.csv';
    ?>
    <div class="report-table-wrapper">
      <div class="report-table-toolbar">
        <div class="report-table-toolbar-title">
          <span>📋</span> <?php echo htmlspecialchars($reportTitle); ?>
          <span class="report-table-toolbar-count">(<?php echo count($tldList); ?> <?php echo __('records', false) ?: 'records'; ?>)</span>
        </div>
        <div>
          <button type="button" class="btn-report-export" onclick="exportTableToCSV(this.closest('.report-table-wrapper').querySelector('table'), '<?php echo $exportFileName; ?>')" title="<?php echo __('export_csv', false) ?: 'Export CSV'; ?>">
            <span>⬇</span> <?php echo __('export_csv', false) ?: 'Export CSV'; ?>
          </button>
        </div>
      </div>
      <table class="reportTable">
      <thead>
        <tr>

          <th style="width:50px; text-align:center;">#</th>
          <th style="width:140px;">TLD (Top-Level Domain)</th>
          <th>Type / Description</th>
          <th style="text-align:right;"><?php echo __('messages39', false) ?: 'Messages'; ?></th>
          <th style="width:120px; text-align:center;"><?php echo __('share', false) ?: 'Share'; ?></th>
          <th style="text-align:right;"><?php echo __('spam39', false) ?: 'Spam'; ?></th>
          <th style="text-align:right;"><?php echo __('viruses39', false) ?: 'Viruses'; ?></th>
          <th style="text-align:right;"><?php echo __('volume39', false) ?: 'Volume'; ?></th>
        </tr>
      </thead>
      <tbody>
      <?php
      $rank = 1;
      foreach ($tldList as $item) {
          $pctShare = ($totalMessages > 0) ? round(($item['count'] / $totalMessages) * 100, 1) : 0;
          $spamPct = ($item['count'] > 0) ? round(($item['total_spam'] / $item['count']) * 100, 1) : 0;
          ?>
          <tr>
            <td style="text-align:center; font-weight:600; color:#64748b;"><?php echo $rank++; ?></td>
            <td style="font-weight:600;">
              <span style="display:inline-block; background:#f1f5f9; color:#1e293b; padding:2px 8px; border-radius:4px; font-family:monospace; font-size:12px; border:1px solid #e2e8f0;">
                .<?php echo htmlspecialchars($item['tld']); ?>
              </span>
            </td>
            <td>
              <?php
              if (!empty($item['countryCode'])) {
                  echo format_country_flag($item['countryCode']);
                  echo ' <span style="font-size:11px; color:#64748b;">(ccTLD)</span>';
              } else {
                  echo '<span style="font-size:11.5px; color:#475569;">' . htmlspecialchars($item['desc']) . '</span>';
              }
              ?>
            </td>
            <td style="text-align:right; font-weight:600; color:#1e293b;"><?php echo number_format($item['count']); ?></td>
            <td style="text-align:center;">
              <div style="background:#f1f5f9; border-radius:4px; height:14px; width:100%; position:relative; overflow:hidden;">
                <div style="background:#0284c7; height:100%; width:<?php echo min(100, $pctShare); ?>%; border-radius:4px;"></div>
                <span style="position:absolute; top:0; left:0; right:0; font-size:9.5px; font-weight:600; color:#334155; line-height:14px;"><?php echo $pctShare; ?>%</span>
              </div>
            </td>
            <td style="text-align:right; color:#dc2626;">
              <?php echo number_format($item['total_spam']); ?>
              <span style="font-size:10px; color:#94a3b8;">(<?php echo $spamPct; ?>%)</span>
            </td>
            <td style="text-align:right; color:#7c3aed;">
              <?php echo number_format($item['total_viruses']); ?>
            </td>
            <td style="text-align:right; color:#475569; font-size:11px;">
              <?php echo formatSize($item['size']); ?>
            </td>
          </tr>
          <?php
      }
      ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:bold; background:#f8fafc;">
          <td colspan="3" style="text-align:right;"><?php echo __('totals', false) ?: 'Totals'; ?>:</td>
          <td style="text-align:right;"><?php echo number_format($totalMessages); ?></td>
          <td style="text-align:center;">100%</td>
          <td style="text-align:right; color:#dc2626;"><?php echo number_format($totalSpam); ?></td>
          <td style="text-align:right; color:#7c3aed;"><?php echo number_format($totalViruses); ?></td>
          <td style="text-align:right;"><?php echo formatSize($totalSize); ?></td>
        </tr>
      </tfoot>
    </table>
    </div>
    <?php
}

// Add footer
html_end();
// Close db connection
dbclose();
