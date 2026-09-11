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

$reportTitle = __('topcountries14', false) ?: 'Top Countries';
$filter = html_start($reportTitle, 0, false, true);

// Sorting order
$order = isset($_GET['order']) ? deepSanitizeInput($_GET['order'], 'url') : 'count';
$validOrders = ['count', 'size', 'spam', 'virus'];
if (!in_array($order, $validOrders, true)) {
    $order = 'count';
}

$dbFile = get_geoip_database_file();
if ($dbFile) {
    require_once __DIR__ . '/lib/maxmind-db/reader/autoload.php';
}

$t0 = microtime(true);

// Query distinct client IPs with counts and stats
$query = "
  SELECT
   clientip,
   COUNT(*) AS count,
   " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlVirus()) . " AS total_viruses,
   " . MailWatchMetrics::sqlCountIf(MailWatchMetrics::sqlSpam()) . " AS total_spam,
   SUM(size) AS size
 FROM
  maillog
 WHERE
  clientip <> ''
 AND
  clientip IS NOT NULL
" . $filter->CreateSQL() . "
 GROUP BY
  clientip
";

$result = dbquery($query);
$countries = [];
$totalMessages = 0;
$totalSize = 0;
$totalSpam = 0;
$totalViruses = 0;

$reader = null;
if ($dbFile && file_exists($dbFile)) {
    try {
        $reader = new \MaxMind\Db\Reader($dbFile);
    } catch (\Exception $e) {
        $reader = null;
    }
}

$ipCountryCache = [];

while ($row = $result->fetch_assoc()) {
    $rawIp = trim($row['clientip']);
    $ip = stripPortFromIp($rawIp);
    $cnt = (int)$row['count'];
    $sz = (int)$row['size'];
    $spm = (int)$row['total_spam'];
    $vir = (int)$row['total_viruses'];

    $totalMessages += $cnt;
    $totalSize += $sz;
    $totalSpam += $spm;
    $totalViruses += $vir;

    if (isset($ipCountryCache[$ip])) {
        $code = $ipCountryCache[$ip]['code'];
        $name = $ipCountryCache[$ip]['name'];
    } else {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $code = 'XX';
            $name = __('unknown13', false) ?: 'Unknown';
        } elseif (ip_in_range($ip, false, 'private') || ip_in_range($ip, false, 'local')) {
            $code = 'LO';
            $name = 'Local / Private Network';
        } elseif ($reader) {
            try {
                $rec = $reader->get($ip);
                $code = $rec['country_code'] ?? ($rec['country']['iso_code'] ?? ($rec['registered_country_code'] ?? ''));
                $name = $rec['country_name'] ?? ($rec['country']['names'][LANG] ?? ($rec['country']['names']['en'] ?? ($rec['registered_country_name'] ?? '')));
                if (empty($code)) {
                    $code = 'XX';
                    $name = __('unknown13', false) ?: 'Unknown';
                }
            } catch (\Exception $e) {
                $code = 'XX';
                $name = __('unknown13', false) ?: 'Unknown';
            }
        } else {
            $code = 'XX';
            $name = __('unknown13', false) ?: 'Unknown';
        }
        $ipCountryCache[$ip] = ['code' => $code, 'name' => $name];
    }

    if (!isset($countries[$code])) {
        $countries[$code] = [
            'code' => $code,
            'name' => $name,
            'count' => 0,
            'size' => 0,
            'total_spam' => 0,
            'total_viruses' => 0,
        ];
    }

    $countries[$code]['count'] += $cnt;
    $countries[$code]['size'] += $sz;
    $countries[$code]['total_spam'] += $spm;
    $countries[$code]['total_viruses'] += $vir;
}

if ($reader) {
    try {
        $reader->close();
    } catch (\Exception $e) {}
}

$elapsed = round(microtime(true) - $t0, 3);

// Sort by selected metric
uasort($countries, function ($a, $b) use ($order) {
    switch ($order) {
        case 'size':
            return $b['size'] <=> $a['size'];
        case 'spam':
            return $b['total_spam'] <=> $a['total_spam'];
        case 'virus':
            return $b['total_viruses'] <=> $a['total_viruses'];
        case 'count':
        default:
            return $b['count'] <=> $a['count'];
    }
});

// Prepare data for pie chart
$graphLimit = 10;
$topCountriesGraph = array_slice($countries, 0, $graphLimit);

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

foreach ($topCountriesGraph as $c) {
    $chartLabels[] = addslashes($c['name'] . ' (' . $c['code'] . ')');
    switch ($order) {
        case 'size':
            $chartNumericData[] = $c['size'];
            $chartFormattedData[] = addslashes(formatSize($c['size']));
            break;
        case 'spam':
            $chartNumericData[] = $c['total_spam'];
            $chartFormattedData[] = addslashes(number_format($c['total_spam']));
            break;
        case 'virus':
            $chartNumericData[] = $c['total_viruses'];
            $chartFormattedData[] = addslashes(number_format($c['total_viruses']));
            break;
        case 'count':
        default:
            $chartNumericData[] = $c['count'];
            $chartFormattedData[] = addslashes(number_format($c['count']));
            break;
    }
}

// Render Control Toolbar
?>
<div class="report-controls-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin:10px 0 16px 0; background:#fff; padding:10px 16px; border-radius:8px; border:1px solid #e2e8f0; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
  <div style="font-weight:600; color:#334155; font-size:13px; display:flex; align-items:center; gap:8px;">
    <span>🌍 <?php echo htmlspecialchars($reportTitle); ?></span>
    <span style="font-size:11px; color:#64748b; font-weight:normal;">
      (<?php echo number_format($totalMessages); ?> <?php echo strtolower(__('messages39', false) ?: 'messages'); ?> from <?php echo count($countries); ?> <?php echo strtolower(__('country39', false) ?: 'countries'); ?> &bull; <?php echo $elapsed; ?>s)
    </span>
  </div>
  <div class="report-order-buttons" style="display:flex; gap:6px; align-items:center;">
    <span style="font-size:11px; color:#64748b; margin-right:4px;"><?php echo __('order03', false) ?: 'Order by'; ?>:</span>
    <a href="?order=count" class="filter-badge" style="text-decoration:none; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:500; <?php echo ($order === 'count') ? 'background:#2563eb; color:#fff;' : 'background:#f1f5f9; color:#475569;'; ?>"><?php echo __('messages39', false) ?: 'Messages'; ?></a>
    <a href="?order=spam" class="filter-badge" style="text-decoration:none; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:500; <?php echo ($order === 'spam') ? 'background:#2563eb; color:#fff;' : 'background:#f1f5f9; color:#475569;'; ?>"><?php echo __('spam39', false) ?: 'Spam'; ?></a>
    <a href="?order=virus" class="filter-badge" style="text-decoration:none; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:500; <?php echo ($order === 'virus') ? 'background:#2563eb; color:#fff;' : 'background:#f1f5f9; color:#475569;'; ?>"><?php echo __('viruses39', false) ?: 'Viruses'; ?></a>
    <a href="?order=size" class="filter-badge" style="text-decoration:none; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:500; <?php echo ($order === 'size') ? 'background:#2563eb; color:#fff;' : 'background:#f1f5f9; color:#475569;'; ?>"><?php echo __('volume39', false) ?: 'Volume'; ?></a>
  </div>
</div>

<?php
if ($totalMessages > 0 && !empty($chartNumericData)) {
    $chartTitle = htmlspecialchars($reportTitle) . ' (Top 10 by ' . $activeOrderLabel . ')';
    echo '<div id="countryReportGraph" class="reportGraph chart-echarts"></div>' . "\n";
    echo '<script src="js/echarts.min.js"></script>' . "\n";
    echo '<script src="js/pieConfig.js"></script>' . "\n";
    echo '<script>' . "\n";
    echo '  printPieGraph("countryReportGraph", {' . "\n";
    echo '    chartTitle : "' . addslashes($chartTitle) . '",' . "\n";
    echo '    chartId : "countryReportGraph",' . "\n";
    echo '    chartLabels : ["' . implode('", "', $chartLabels) . '"],' . "\n";
    echo '    chartNumericData : [' . implode(', ', $chartNumericData) . '],' . "\n";
    echo '    chartFormattedData : ["' . implode('", "', $chartFormattedData) . '"],' . "\n";
    echo '  });' . "\n";
    echo '</script>' . "\n";
    echo '<br>' . "\n";
} else {
    echo '<p class="center">' . (__('nodata64', false) ?: 'No data found.') . '</p>' . "\n";
}

// Table of top countries
if (!empty($countries)) {
    $tableLimit = 50;
    $topCountriesTable = array_slice($countries, 0, $tableLimit);
    $exportFileName = 'top_countries_' . date('Ymd') . '.csv';
    ?>
    <div class="report-table-wrapper">
      <div class="report-table-toolbar">
        <div class="report-table-toolbar-title">
          <span>📋</span> <?php echo htmlspecialchars($reportTitle); ?>
          <span class="report-table-toolbar-count">(<?php echo count($topCountriesTable); ?> <?php echo __('records', false) ?: 'records'; ?>)</span>
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
          <th><?php echo __('country39', false) ?: 'Country'; ?></th>
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
      foreach ($topCountriesTable as $c) {
          $pctShare = ($totalMessages > 0) ? round(($c['count'] / $totalMessages) * 100, 1) : 0;
          $spamPct = ($c['count'] > 0) ? round(($c['total_spam'] / $c['count']) * 100, 1) : 0;
          $flagHtml = format_country_flag($c['code'], $c['name']);
          ?>
          <tr>
            <td style="text-align:center; font-weight:600; color:#64748b;"><?php echo $rank++; ?></td>
            <td style="font-weight:500;">
              <?php echo $flagHtml; ?>
              <span style="font-size:10px; color:#94a3b8; margin-left:4px;">(<?php echo htmlspecialchars($c['code']); ?>)</span>
            </td>
            <td style="text-align:right; font-weight:600; color:#1e293b;"><?php echo number_format($c['count']); ?></td>
            <td style="text-align:center;">
              <div style="background:#f1f5f9; border-radius:4px; height:14px; width:100%; position:relative; overflow:hidden;">
                <div style="background:#3b82f6; height:100%; width:<?php echo min(100, $pctShare); ?>%; border-radius:4px;"></div>
                <span style="position:absolute; top:0; left:0; right:0; font-size:9.5px; font-weight:600; color:#334155; line-height:14px;"><?php echo $pctShare; ?>%</span>
              </div>
            </td>
            <td style="text-align:right; color:#dc2626;">
              <?php echo number_format($c['total_spam']); ?>
              <span style="font-size:10px; color:#94a3b8;">(<?php echo $spamPct; ?>%)</span>
            </td>
            <td style="text-align:right; color:#7c3aed;">
              <?php echo number_format($c['total_viruses']); ?>
            </td>
            <td style="text-align:right; color:#475569; font-size:11px;">
              <?php echo formatSize($c['size']); ?>
            </td>
          </tr>
          <?php
      }
      ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:bold; background:#f8fafc;">
          <td colspan="2" style="text-align:right;"><?php echo __('totals', false) ?: 'Totals'; ?>:</td>
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
