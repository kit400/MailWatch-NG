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

require_once __DIR__ . '/functions.php';
require __DIR__ . '/login.function.php';

html_start(__('qviewer08'), 0, false, false);

$token = $_SESSION['token'] ?? '';
$dir = isset($_GET['dir']) ? deepSanitizeInput($_GET['dir'], 'url') : null;
$selectedDateSql = '';

if ($dir !== null) {
    if (false === checkToken($_GET['token'] ?? '')) {
        header('Location: login.php?error=pagetimeout');
        exit;
    }
    if (!validateInput($dir, 'quardir')) {
        exit(__('dievalidate99'));
    }
    $selectedDateSql = translateQuarantineDate($dir, 'sql');
}

// Determine target month for calendar view (YYYY-MM)
if (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) {
    $targetMonth = sanitizeInput($_GET['month']);
} elseif ($selectedDateSql !== '') {
    $targetMonth = substr($selectedDateSql, 0, 7);
} else {
    $targetMonth = date('Y-m');
}

/**
 * Render interactive Monthly Quarantine Calendar with KPI statistics
 *
 * @param string $targetMonth (YYYY-MM)
 * @param string $selectedDateSql (YYYY-MM-DD)
 * @param string $token
 * @return string HTML
 */
function renderQuarantineCalendar($targetMonth, $selectedDateSql = '', $token = '')
{
    if (!preg_match('/^\d{4}-\d{2}$/', $targetMonth)) {
        $targetMonth = date('Y-m');
    }

    $year = (int)substr($targetMonth, 0, 4);
    $month = (int)substr($targetMonth, 5, 2);

    $firstDayOfMonth = new DateTime("$year-$month-01");
    $daysInMonth = (int)$firstDayOfMonth->format('t');
    $monthStartSql = "$year-" . sprintf('%02d', $month) . "-01";
    $monthEndSql = "$year-" . sprintf('%02d', $month) . "-$daysInMonth";

    $prevMonthDt = (clone $firstDayOfMonth)->sub(new DateInterval('P1M'));
    $nextMonthDt = (clone $firstDayOfMonth)->add(new DateInterval('P1M'));
    $prevMonthStr = $prevMonthDt->format('Y-m');
    $nextMonthStr = $nextMonthDt->format('Y-m');
    $currentMonthStr = date('Y-m');
    $todaySql = date('Y-m-d');

    $quarantineData = [];
    $monthTotalCount = 0;
    $monthTotalViruses = 0;
    $monthTotalSpam = 0;
    $monthTotalMcp = 0;

    if (QUARANTINE_USE_FLAG) {
        $sql = "
            SELECT 
                date, 
                COUNT(*) as cnt,
                SUM(CASE WHEN virusinfected > 0 OR nameinfected > 0 OR otherinfected > 0 THEN 1 ELSE 0 END) as virus_cnt,
                SUM(CASE WHEN isspam > 0 OR ishighspam > 0 OR isrblspam > 0 THEN 1 ELSE 0 END) as spam_cnt,
                SUM(CASE WHEN ismcp > 0 OR ishighmcp > 0 THEN 1 ELSE 0 END) as mcp_cnt
            FROM maillog
            WHERE " . $_SESSION['global_filter'] . "
              AND quarantined = 1
              AND date BETWEEN '$monthStartSql' AND '$monthEndSql'
        ";

        if (defined('HIDE_HIGH_SPAM') && HIDE_HIGH_SPAM === true && defined('HIDE_APPLY_QUARANTINE') && HIDE_APPLY_QUARANTINE === true && 'U' === $_SESSION['user_type']) {
            $sql .= " AND ishighspam=0 AND COALESCE(ishighmcp,0)=0";
        }
        if (defined('HIDE_NON_SPAM') && HIDE_NON_SPAM === true && defined('HIDE_APPLY_QUARANTINE') && HIDE_APPLY_QUARANTINE === true && 'U' === $_SESSION['user_type']) {
            $sql .= " AND isspam>0";
        }
        if (defined('HIDE_UNKNOWN') && HIDE_UNKNOWN === true && defined('HIDE_APPLY_QUARANTINE') && HIDE_APPLY_QUARANTINE === true) {
            $sql .= " AND (virusinfected>0 OR nameinfected>0 OR otherinfected>0 OR ishighspam>0 OR isrblspam>0 OR spamblacklisted>0 OR ismcp>0 OR ishighmcp>0 OR issamcp>0 OR isspam>0)";
        }

        $sql .= " GROUP BY date";
        $res = dbquery($sql);
        while ($row = $res->fetch_assoc()) {
            $d = $row['date'];
            $quarantineData[$d] = [
                'cnt' => (int)$row['cnt'],
                'virus' => (int)$row['virus_cnt'],
                'spam' => (int)$row['spam_cnt'],
                'mcp' => (int)$row['mcp_cnt'],
            ];
            $monthTotalCount += (int)$row['cnt'];
            $monthTotalViruses += (int)$row['virus_cnt'];
            $monthTotalSpam += (int)$row['spam_cnt'];
            $monthTotalMcp += (int)$row['mcp_cnt'];
        }
    } else {
        $items = quarantine_list('/');
        foreach ($items as $f) {
            if (is_numeric($f) && strlen($f) === 8) {
                $dSql = translateQuarantineDate($f, 'sql');
                if (substr($dSql, 0, 7) === $targetMonth) {
                    $cnt = count(quarantine_list($f));
                    $quarantineData[$dSql] = [
                        'cnt' => $cnt,
                        'virus' => 0,
                        'spam' => 0,
                        'mcp' => 0,
                    ];
                    $monthTotalCount += $cnt;
                }
            }
        }
    }

    $html = '<div class="quarantine-calendar-container">' . "\n";

    // 1. KPI Statistics Cards
    $html .= '  <div class="quarantine-kpi-bar">' . "\n";
    $html .= '    <div class="quarantine-kpi-card"><span class="quarantine-kpi-icon">🔒</span><div class="quarantine-kpi-info"><span class="quarantine-kpi-label">' . __('qviewer08') . '</span><span class="quarantine-kpi-value">' . number_format($monthTotalCount) . '</span></div></div>' . "\n";
    $html .= '    <div class="quarantine-kpi-card"><span class="quarantine-kpi-icon">🦠</span><div class="quarantine-kpi-info"><span class="quarantine-kpi-label">' . __('topvirus14') . '</span><span class="quarantine-kpi-value">' . number_format($monthTotalViruses) . '</span></div></div>' . "\n";
    $html .= '    <div class="quarantine-kpi-card"><span class="quarantine-kpi-icon">⚡</span><div class="quarantine-kpi-info"><span class="quarantine-kpi-label">Spam</span><span class="quarantine-kpi-value">' . number_format($monthTotalSpam) . '</span></div></div>' . "\n";
    $html .= '    <div class="quarantine-kpi-card"><span class="quarantine-kpi-icon">🛡️</span><div class="quarantine-kpi-info"><span class="quarantine-kpi-label">Policy / MCP</span><span class="quarantine-kpi-value">' . number_format($monthTotalMcp) . '</span></div></div>' . "\n";
    $html .= '  </div>' . "\n";

    // 2. Calendar Header & Controls
    $html .= '  <div class="quarantine-calendar-card">' . "\n";
    $html .= '    <div class="quarantine-calendar-header">' . "\n";
    $html .= '      <div class="quarantine-month-title"><span class="cal-icon">📅</span> ' . $firstDayOfMonth->format('F Y') . '</div>' . "\n";
    $html .= '      <div class="quarantine-nav-controls">' . "\n";
    $html .= '        <a href="quarantine.php?token=' . $token . '&amp;month=' . $prevMonthStr . ($selectedDateSql !== '' ? '&amp;dir=' . str_replace('-', '', $selectedDateSql) : '') . '" class="quarantine-nav-btn" title="Previous Month">◀ ' . $prevMonthDt->format('M') . '</a>' . "\n";
    if ($targetMonth !== $currentMonthStr) {
        $html .= '        <a href="quarantine.php?token=' . $token . '&amp;month=' . $currentMonthStr . '" class="quarantine-nav-btn today-btn" title="Current Month">Today</a>' . "\n";
    }
    $html .= '        <a href="quarantine.php?token=' . $token . '&amp;month=' . $nextMonthStr . ($selectedDateSql !== '' ? '&amp;dir=' . str_replace('-', '', $selectedDateSql) : '') . '" class="quarantine-nav-btn" title="Next Month">' . $nextMonthDt->format('M') . ' ▶</a>' . "\n";
    $html .= '      </div>' . "\n";
    $html .= '    </div>' . "\n";

    // 3. Calendar Grid
    $html .= '    <div class="quarantine-calendar-grid">' . "\n";

    // Day Headers (Mon - Sun)
    $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    foreach ($dayNames as $idx => $dName) {
        $isWeekend = ($idx >= 5);
        $html .= '      <div class="quarantine-day-header' . ($isWeekend ? ' weekend' : '') . '">' . $dName . '</div>' . "\n";
    }

    $startDayOfWeek = (int)$firstDayOfMonth->format('N'); // 1 (Mon) - 7 (Sun)
    $prevMonthDays = (int)$prevMonthDt->format('t');

    // Leading days from previous month
    for ($p = 1; $p < $startDayOfWeek; ++$p) {
        $pDayNum = $prevMonthDays - ($startDayOfWeek - $p - 1);
        $html .= '      <div class="quarantine-day-cell other-month"><div class="quarantine-cell-top"><span class="quarantine-day-num">' . $pDayNum . '</span></div></div>' . "\n";
    }

    // Days of current month
    for ($day = 1; $day <= $daysInMonth; ++$day) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $dirCode = sprintf('%04d%02d%02d', $year, $month, $day);
        $isToday = ($dateStr === $todaySql);
        $isSelected = ($dateStr === $selectedDateSql);
        $hasData = isset($quarantineData[$dateStr]) && $quarantineData[$dateStr]['cnt'] > 0;

        $classes = ['quarantine-day-cell'];
        if ($isToday) $classes[] = 'today';
        if ($isSelected) $classes[] = 'selected';

        $html .= '      <div class="' . implode(' ', $classes) . '">' . "\n";
        $html .= '        <div class="quarantine-cell-top">' . "\n";
        $html .= '          <span class="quarantine-day-num">' . $day . '</span>' . "\n";
        if ($isToday) {
            $html .= '          <span class="today-tag" style="font-size:8.5px;font-weight:700;color:#0284c7;">TODAY</span>' . "\n";
        }
        $html .= '        </div>' . "\n";

        $html .= '        <div class="quarantine-cell-body">' . "\n";
        if ($hasData) {
            $info = $quarantineData[$dateStr];
            $url = 'quarantine.php?token=' . $token . '&amp;dir=' . $dirCode . '&amp;month=' . $targetMonth;
            $html .= '          <a href="' . $url . '" class="quarantine-count-badge" title="View ' . $info['cnt'] . ' quarantined items">' . "\n";
            $html .= '            <span>🔒 ' . $info['cnt'] . '</span>' . "\n";
            $html .= '            <span>›</span>' . "\n";
            $html .= '          </a>' . "\n";

            $subTags = [];
            if ($info['virus'] > 0) $subTags[] = '<span class="quarantine-sub-tag virus" title="' . $info['virus'] . ' viruses">🦠 ' . $info['virus'] . '</span>';
            if ($info['spam'] > 0) $subTags[] = '<span class="quarantine-sub-tag spam" title="' . $info['spam'] . ' spam">⚡ ' . $info['spam'] . '</span>';
            if ($info['mcp'] > 0) $subTags[] = '<span class="quarantine-sub-tag mcp" title="' . $info['mcp'] . ' MCP">🛡️ ' . $info['mcp'] . '</span>';

            if (count($subTags) > 0) {
                $html .= '          <div class="quarantine-sub-badges">' . implode('', $subTags) . '</div>' . "\n";
            }
        }
        $html .= '        </div>' . "\n";
        $html .= '      </div>' . "\n";
    }

    // Trailing days to fill last week
    $endDayOfWeek = (int)(new DateTime("$year-$month-$daysInMonth"))->format('N');
    $trailingDays = 7 - $endDayOfWeek;
    if ($trailingDays > 0 && $trailingDays < 7) {
        for ($t = 1; $t <= $trailingDays; ++$t) {
            $html .= '      <div class="quarantine-day-cell other-month"><div class="quarantine-cell-top"><span class="quarantine-day-num">' . $t . '</span></div></div>' . "\n";
        }
    }

    $html .= '    </div>' . "\n"; // End grid
    $html .= '  </div>' . "\n"; // End card
    $html .= '</div>' . "\n"; // End container

    return $html;
}

// 1. Output the interactive Quarantine Calendar
echo renderQuarantineCalendar($targetMonth, $selectedDateSql, $token);

// 2. If a specific date is selected, output the Quarantine Message Listing Table below the calendar
if ($selectedDateSql !== '') {
    if (isset($_GET['pageID']) && !validateInput(deepSanitizeInput($_GET['pageID'], 'num'), 'num')) {
        exit(__('dievalidate99'));
    }

    $formattedDate = translateQuarantineDate($dir, DATE_FORMAT);
    echo '<div class="quarantine-date-view-header">' . "\n";
    echo '  <div style="display:flex;align-items:center;gap:8px;">' . "\n";
    echo '    <span style="font-size:18px;">🔒</span>' . "\n";
    echo '    <div>' . "\n";
    echo '      <div style="font-weight:700;font-size:13px;color:#0f172a;">' . __('folder08') . ' ' . $formattedDate . '</div>' . "\n";
    echo '      <div style="font-size:11px;color:#64748b;">Quarantined messages intercepted on this date</div>' . "\n";
    echo '    </div>' . "\n";
    echo '  </div>' . "\n";
    echo '  <a href="quarantine.php?token=' . $token . '&amp;month=' . $targetMonth . '" class="quarantine-nav-btn">✕ Close / All Dates</a>' . "\n";
    echo '</div>' . "\n";

    if (QUARANTINE_USE_FLAG) {
        dbconn();
        $date = $selectedDateSql;
        $sql = "
SELECT
 id AS id2,
 DATE_FORMAT(timestamp, '" . DATE_FORMAT . ' ' . TIME_FORMAT . "') AS datetime,
 from_address,";
        if (defined('DISPLAY_IP') && DISPLAY_IP) {
            $sql .= 'clientip,';
        }
        $sql .= "
 to_address,
 subject,
 size,
 sascore,
 isspam,
 ishighspam,
 spamwhitelisted,
 spamblacklisted,
 virusinfected,
 nameinfected,
 otherinfected,
 report,
 ismcp,
 ishighmcp,
 issamcp,
 mcpwhitelisted,
 mcpblacklisted,
 mcpsascore,
 released,
 '' as status
FROM
 maillog
WHERE
 " . $_SESSION['global_filter'] . "
AND
 date = '$date'
AND
 quarantined = 1";

        if (defined('HIDE_HIGH_SPAM') && HIDE_HIGH_SPAM === true && defined('HIDE_APPLY_QUARANTINE') && HIDE_APPLY_QUARANTINE === true && 'U' === $_SESSION['user_type']) {
            $sql .= ' AND ishighspam=0 AND COALESCE(ishighmcp,0)=0';
        }
        if (defined('HIDE_NON_SPAM') && HIDE_NON_SPAM === true && defined('HIDE_APPLY_QUARANTINE') && HIDE_APPLY_QUARANTINE === true && 'U' === $_SESSION['user_type']) {
            $sql .= ' AND isspam>0';
        }
        if (defined('HIDE_UNKNOWN') && HIDE_UNKNOWN === true && defined('HIDE_APPLY_QUARANTINE') && HIDE_APPLY_QUARANTINE === true) {
            $sql .= ' AND (virusinfected>0 OR nameinfected>0 OR otherinfected>0 OR ishighspam>0 OR isrblspam>0 OR spamblacklisted>0 OR ismcp>0 OR ishighmcp>0 OR issamcp>0 OR isspam>0)';
        }

        $sql .= ' ORDER BY date DESC, time DESC';
        db_colorised_table($sql, __('folder08') . ' ' . $formattedDate, true, true);
    } else {
        $cleanDir = preg_replace('[\.|\.\.|\/]', '', $dir);
        $items = quarantine_list($cleanDir);
        if (count($items) > 0) {
            $msg_ids = implode(',', $items);
            $date = safe_value($selectedDateSql);
            $sql = "
  SELECT
   id AS id2,
   DATE_FORMAT(timestamp, '" . DATE_FORMAT . ' ' . TIME_FORMAT . "') AS datetime,
   from_address,";
            if (defined('DISPLAY_IP') && DISPLAY_IP) {
                $sql .= 'clientip,';
            }
            $sql .= "
   to_address,
   subject,
   size,
   sascore,
   isspam,
   ishighspam,
   spamwhitelisted,
   spamblacklisted,
   virusinfected,
   nameinfected,
   otherinfected,
   report,
   ismcp,
   ishighmcp,
   issamcp,
   mcpwhitelisted,
   mcpblacklisted,
   mcpsascore,
   released,
   '' as status
  FROM
   maillog
  WHERE
   " . $_SESSION['global_filter'] . "
  AND
   date = '$date'
  AND
   BINARY id IN ($msg_ids)";

            if (defined('HIDE_HIGH_SPAM') && HIDE_HIGH_SPAM === true && defined('HIDE_APPLY_QUARANTINE') && HIDE_APPLY_QUARANTINE === true && 'U' === $_SESSION['user_type']) {
                $sql .= ' AND ishighspam=0 AND COALESCE(ishighmcp,0)=0';
            }
            if (defined('HIDE_NON_SPAM') && HIDE_NON_SPAM === true && defined('HIDE_APPLY_QUARANTINE') && HIDE_APPLY_QUARANTINE === true && 'U' === $_SESSION['user_type']) {
                $sql .= ' AND isspam>0';
            }
            if (defined('HIDE_UNKNOWN') && HIDE_UNKNOWN === true && defined('HIDE_APPLY_QUARANTINE') && HIDE_APPLY_QUARANTINE === true) {
                $sql .= ' AND (virusinfected>0 OR nameinfected>0 OR otherinfected>0 OR ishighspam>0 OR isrblspam>0 OR spamblacklisted>0 OR ismcp>0 OR ishighmcp>0 OR issamcp>0 OR isspam>0)';
            }

            $sql .= ' ORDER BY date DESC, time DESC';
            db_colorised_table($sql, __('folder_0208') . __('colon99') . ' ' . translateQuarantineDate($dir), true, true);
        } else {
            echo '<div class="alert alert-info" style="margin-top:10px;">' . __('dienodir08') . '</div>' . "\n";
        }
    }
}

html_end();
dbclose();
