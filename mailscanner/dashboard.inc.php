<?php

/**
 * MailWatch for MailScanner
 * Dashboard Engine & Widget Library
 *
 * Copyright (C) 2003-2026 MailWatch Team / EFA-NG Project
 */

require_once __DIR__ . '/functions.php';

/**
 * Initialize user_dashboards table if not exists
 */
function init_user_dashboards_table()
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $sql = "CREATE TABLE IF NOT EXISTS `user_dashboards` (
        `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `username` varchar(191) COLLATE utf8_unicode_ci NOT NULL,
        `dashboard_name` varchar(100) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'default',
        `layout_json` longtext COLLATE utf8_unicode_ci NOT NULL,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `user_dash_uniq` (`username`, `dashboard_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
    dbquery($sql, false);
    $initialized = true;
}

/**
 * Get Available Widgets Catalog
 */
function get_available_widgets_catalog()
{
    return [
        'kpi_summary' => [
            'type' => 'kpi_summary',
            'title' => 'System & Mail Overview (KPIs)',
            'description' => 'Summary metrics: total processed volume, clean rate %, spam blocked, virus & bad content, mail queues, system load & memory.',
            'icon' => '📈',
            'default_width' => 'col-12',
            'category' => 'overview'
        ],
        'traffic_chart' => [
            'type' => 'traffic_chart',
            'title' => 'Mail Flow & Traffic Trends',
            'description' => 'Interactive time-series area chart of total, clean, spam, and threat traffic over the selected time range.',
            'icon' => '📊',
            'default_width' => 'col-8',
            'category' => 'charts'
        ],
        'threat_donut' => [
            'type' => 'threat_donut',
            'title' => 'Threat Distribution Breakdown',
            'description' => 'Donut chart visualizing distribution of Clean, Spam, High Spam, Viruses, Bad Content, and Policy/MCP.',
            'icon' => '🍩',
            'default_width' => 'col-4',
            'category' => 'charts'
        ],
        'top_relays_asn' => [
            'type' => 'top_relays_asn',
            'title' => 'Top Sending Countries & AS/ASN',
            'description' => 'Top relay IP addresses with country, city, and Autonomous System (AS/ASN) details powered by strato-do/ip-geo.',
            'icon' => '🌐',
            'default_width' => 'col-6',
            'category' => 'security'
        ],
        'top_senders_recipients' => [
            'type' => 'top_senders_recipients',
            'title' => 'Top Senders, Recipients & Domains',
            'description' => 'Top email senders, recipient targets, and sending domain names with volume and spam counts.',
            'icon' => '👥',
            'default_width' => 'col-6',
            'category' => 'security'
        ],
        'recent_threats' => [
            'type' => 'recent_threats',
            'title' => 'Recent Intercepted Threats',
            'description' => 'Realtime stream of intercepted threats (Viruses, Bad Content, High Spam, MCP) with direct drill-down links.',
            'icon' => '🚨',
            'default_width' => 'col-8',
            'category' => 'security'
        ],
        'recent_messages' => [
            'type' => 'recent_messages',
            'title' => 'Recent Processed Messages',
            'description' => 'Live feed of latest processed mail messages with status badges and quick view links.',
            'icon' => '📬',
            'default_width' => 'col-6',
            'category' => 'overview'
        ],
        'system_services' => [
            'type' => 'system_services',
            'title' => 'Core Services & Health Monitor',
            'description' => 'Real-time daemon status (MailScanner, Postfix, MSMilter, MariaDB, ClamAV, SpamAssassin, PHP-FPM) and resource usage.',
            'icon' => '🖥️',
            'default_width' => 'col-4',
            'category' => 'system'
        ],
        'spam_rules_top' => [
            'type' => 'spam_rules_top',
            'title' => 'Top SpamAssassin Rules',
            'description' => 'Most frequently triggered SpamAssassin rules with rule descriptions and hit counts.',
            'icon' => '🛡️',
            'default_width' => 'col-6',
            'category' => 'security'
        ],
        'quarantine_stats' => [
            'type' => 'quarantine_stats',
            'title' => 'Quarantine Overview',
            'description' => 'Summary of items in quarantine, categorized by type with shortcut to the Quarantine Calendar.',
            'icon' => '🔒',
            'default_width' => 'col-4',
            'category' => 'overview'
        ],
        'quick_actions' => [
            'type' => 'quick_actions',
            'title' => 'Quick Tools & Message Lookup',
            'description' => 'Fast search box, queue check triggers, and administrative shortcuts.',
            'icon' => '⚡',
            'default_width' => 'col-4',
            'category' => 'system'
        ]
    ];
}

/**
 * Get Default Dashboard Layout
 */
function get_default_dashboard_layout()
{
    return [
        ['id' => 'kpi_summary_1', 'type' => 'kpi_summary', 'width' => 'col-12', 'title' => 'System & Mail Overview (KPIs)'],
        ['id' => 'traffic_chart_1', 'type' => 'traffic_chart', 'width' => 'col-8', 'title' => 'Mail Flow & Traffic Trends'],
        ['id' => 'threat_donut_1', 'type' => 'threat_donut', 'width' => 'col-4', 'title' => 'Threat Distribution Breakdown'],
        ['id' => 'top_relays_asn_1', 'type' => 'top_relays_asn', 'width' => 'col-6', 'title' => 'Top Sending Countries & AS/ASN'],
        ['id' => 'top_senders_recipients_1', 'type' => 'top_senders_recipients', 'width' => 'col-6', 'title' => 'Top Senders, Recipients & Domains'],
        ['id' => 'recent_threats_1', 'type' => 'recent_threats', 'width' => 'col-8', 'title' => 'Recent Intercepted Threats'],
        ['id' => 'system_services_1', 'type' => 'system_services', 'width' => 'col-4', 'title' => 'Core Services & Health Monitor']
    ];
}

/**
 * Load user dashboard layout
 */
function get_user_dashboard_layout($username)
{
    init_user_dashboards_table();
    $sql = "SELECT layout_json FROM user_dashboards WHERE username = '" . safe_value($username) . "' AND dashboard_name = 'default' LIMIT 1";
    $res = dbquery($sql);
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $decoded = json_decode($row['layout_json'], true);
        if (is_array($decoded) && !empty($decoded)) {
            return $decoded;
        }
    }
    return get_default_dashboard_layout();
}

/**
 * Save user dashboard layout
 */
function save_user_dashboard_layout($username, $layoutArray)
{
    init_user_dashboards_table();
    $json = json_encode($layoutArray);
    $sql = "INSERT INTO user_dashboards (username, dashboard_name, layout_json) 
            VALUES ('" . safe_value($username) . "', 'default', '" . safe_value($json) . "')
            ON DUPLICATE KEY UPDATE layout_json = '" . safe_value($json) . "', updated_at = NOW()";
    return dbquery($sql);
}

/**
 * Reset user dashboard layout to default
 */
function reset_user_dashboard_layout($username)
{
    init_user_dashboards_table();
    $sql = "DELETE FROM user_dashboards WHERE username = '" . safe_value($username) . "' AND dashboard_name = 'default'";
    return dbquery($sql);
}

/**
 * Get SQL WHERE condition for specified time range
 */
function get_dashboard_time_filter($timeRange = '24h')
{
    switch ($timeRange) {
        case 'today':
            return "timestamp >= CURDATE()";
        case '7d':
            return "timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        case '30d':
            return "timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        case '24h':
        default:
            return "timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    }
}

/**
 * Master dispatcher to render a widget body
 */
function render_dashboard_widget_content($type, $timeRange = '24h', $widgetId = '')
{
    switch ($type) {
        case 'kpi_summary':
            return render_widget_kpi_summary($timeRange);
        case 'traffic_chart':
            return render_widget_traffic_chart($timeRange, $widgetId);
        case 'threat_donut':
            return render_widget_threat_donut($timeRange, $widgetId);
        case 'top_relays_asn':
            return render_widget_top_relays_asn($timeRange);
        case 'top_senders_recipients':
            return render_widget_top_senders_recipients($timeRange);
        case 'recent_threats':
            return render_widget_recent_threats($timeRange);
        case 'recent_messages':
            return render_widget_recent_messages($timeRange);
        case 'system_services':
            return render_widget_system_services();
        case 'spam_rules_top':
            return render_widget_spam_rules_top($timeRange);
        case 'quarantine_stats':
            return render_widget_quarantine_stats();
        case 'quick_actions':
            return render_widget_quick_actions();
        default:
            return '<div class="p-3 text-muted">Unknown widget type: ' . htmlspecialchars($type) . '</div>';
    }
}

// ----------------------------------------------------------------------------
// Widget Renderers
// ----------------------------------------------------------------------------

/**
 * 1. KPI Summary Cards
 */
function render_widget_kpi_summary($timeRange)
{
    $timeFilter = get_dashboard_time_filter($timeRange);
    $globalFilter = $_SESSION['global_filter'] ?? '1=1';

    $sql = "SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN isspam=0 AND ishighspam=0 AND virusinfected=0 AND nameinfected=0 AND otherinfected=0 AND ismcp=0 AND ishighmcp=0 THEN 1 ELSE 0 END) AS clean,
        SUM(isspam) AS spam,
        SUM(ishighspam) AS highspam,
        SUM(virusinfected) AS virus,
        SUM(CASE WHEN (nameinfected=1 OR otherinfected=1) AND virusinfected=0 THEN 1 ELSE 0 END) AS badcontent,
        SUM(CASE WHEN ismcp=1 OR ishighmcp=1 THEN 1 ELSE 0 END) AS mcp,
        SUM(size) AS total_size
    FROM maillog 
    WHERE $timeFilter AND ($globalFilter)";

    $res = dbquery($sql);
    $d = $res ? $res->fetch_assoc() : [];

    $total = (int)($d['total'] ?? 0);
    $clean = (int)($d['clean'] ?? 0);
    $spam = (int)($d['spam'] ?? 0);
    $highspam = (int)($d['highspam'] ?? 0);
    $virus = (int)($d['virus'] ?? 0);
    $badcontent = (int)($d['badcontent'] ?? 0);
    $mcp = (int)($d['mcp'] ?? 0);
    $totalSize = formatSize((float)($d['total_size'] ?? 0));

    $cleanPct = $total > 0 ? round(($clean / $total) * 100, 1) : 100;
    $spamPct = $total > 0 ? round((($spam + $highspam) / $total) * 100, 1) : 0;
    $threats = $virus + $badcontent + $mcp;
    $threatPct = $total > 0 ? round(($threats / $total) * 100, 1) : 0;

    // Load Average
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
    $loadStr = number_format($load[0], 2) . ', ' . number_format($load[1], 2);

    // RAM & Swap
    $ramPct = 0;
    $swapPct = 0;
    if (file_exists('/proc/meminfo')) {
        $meminfo = @file_get_contents('/proc/meminfo');
        if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $mt) && preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $ma)) {
            $tot = (float)$mt[1];
            $avail = (float)$ma[1];
            if ($tot > 0) {
                $ramPct = round((($tot - $avail) / $tot) * 100);
            }
        }
        if (preg_match('/SwapTotal:\s+(\d+)/', $meminfo, $st) && preg_match('/SwapFree:\s+(\d+)/', $meminfo, $sf)) {
            $stot = (float)$st[1];
            $sfree = (float)$sf[1];
            if ($stot > 0) {
                $swapPct = round((($stot - $sfree) / $stot) * 100);
            }
        }
    }

    // Queues
    $inqCount = 0;
    $qRes = dbquery("SELECT COUNT(*) AS c FROM inq");
    if ($qRes) {
        $r = $qRes->fetch_assoc();
        $inqCount = (int)($r['c'] ?? 0);
    }

    $out = '<div class="dash-kpi-grid">';
    
    // Card 1: Total Processed
    $out .= '
    <div class="dash-kpi-card kpi-blue">
        <div class="dash-kpi-header">
            <span class="dash-kpi-title">TOTAL PROCESSED</span>
            <span class="dash-kpi-icon">📨</span>
        </div>
        <div class="dash-kpi-val">' . number_format($total) . '</div>
        <div class="dash-kpi-sub">
            <span class="dash-kpi-pill pill-blue">Volume ' . $totalSize . '</span>
        </div>
    </div>';

    // Card 2: Clean Rate
    $out .= '
    <div class="dash-kpi-card kpi-green">
        <div class="dash-kpi-header">
            <span class="dash-kpi-title">CLEAN MESSAGES</span>
            <span class="dash-kpi-icon">🛡️</span>
        </div>
        <div class="dash-kpi-val">' . number_format($clean) . '</div>
        <div class="dash-kpi-sub">
            <span class="dash-kpi-pill pill-green">' . $cleanPct . '% Clean Rate</span>
        </div>
    </div>';

    // Card 3: Spam Blocked
    $out .= '
    <div class="dash-kpi-card kpi-yellow">
        <div class="dash-kpi-header">
            <span class="dash-kpi-title">SPAM INTERCEPTED</span>
            <span class="dash-kpi-icon">⚡</span>
        </div>
        <div class="dash-kpi-val">' . number_format($spam + $highspam) . '</div>
        <div class="dash-kpi-sub">
            <span class="dash-kpi-pill pill-yellow">' . number_format($spam) . ' Spam</span>
            <span class="dash-kpi-pill pill-purple">' . number_format($highspam) . ' High</span>
        </div>
    </div>';

    // Card 4: Viruses & Bad Content
    $out .= '
    <div class="dash-kpi-card kpi-red">
        <div class="dash-kpi-header">
            <span class="dash-kpi-title">VIRUSES &amp; THREATS</span>
            <span class="dash-kpi-icon">🦠</span>
        </div>
        <div class="dash-kpi-val">' . number_format($threats) . '</div>
        <div class="dash-kpi-sub">
            <span class="dash-kpi-pill pill-red">' . number_format($virus) . ' Virus</span>
            <span class="dash-kpi-pill pill-orange">' . number_format($badcontent) . ' Bad Content</span>
        </div>
    </div>';

    // Card 5: Mail Queues
    $out .= '
    <div class="dash-kpi-card kpi-slate">
        <div class="dash-kpi-header">
            <span class="dash-kpi-title">MAIL QUEUES</span>
            <span class="dash-kpi-icon">📬</span>
        </div>
        <div class="dash-kpi-val">' . number_format($inqCount) . '</div>
        <div class="dash-kpi-sub">
            <span class="dash-kpi-pill ' . ($inqCount > 50 ? 'pill-red' : 'pill-slate') . '">' . ($inqCount === 0 ? 'Queues Clear' : 'Pending in Spool') . '</span>
        </div>
    </div>';

    // Card 6: System Health
    $out .= '
    <div class="dash-kpi-card kpi-teal">
        <div class="dash-kpi-header">
            <span class="dash-kpi-title">SYSTEM HEALTH</span>
            <span class="dash-kpi-icon">⚡</span>
        </div>
        <div class="dash-kpi-val">Load ' . $loadStr . '</div>
        <div class="dash-kpi-sub">
            <span class="dash-kpi-pill pill-teal">RAM ' . $ramPct . '%</span>
            <span class="dash-kpi-pill ' . ($swapPct > 50 ? 'pill-yellow' : 'pill-teal') . '">Swap ' . $swapPct . '%</span>
        </div>
    </div>';

    $out .= '</div>';
    return $out;
}

/**
 * 2. Traffic Flow Trends Chart (ECharts)
 */
function render_widget_traffic_chart($timeRange, $widgetId)
{
    $chartDomId = 'traffic_chart_' . preg_replace('/[^a-zA-Z0-9_]/', '', $widgetId);
    $timeFilter = get_dashboard_time_filter($timeRange);
    $globalFilter = $_SESSION['global_filter'] ?? '1=1';

    $slotFormat = ($timeRange === '7d' || $timeRange === '30d') ? "%Y-%m-%d" : "%Y-%m-%d %H:00";
    $labelFormat = ($timeRange === '7d' || $timeRange === '30d') ? "%b %d" : "%H:00";

    $sql = "SELECT 
        DATE_FORMAT(timestamp, '$slotFormat') AS slot,
        DATE_FORMAT(timestamp, '$labelFormat') AS label,
        COUNT(*) AS total,
        SUM(CASE WHEN isspam=0 AND ishighspam=0 AND virusinfected=0 AND nameinfected=0 AND otherinfected=0 AND ismcp=0 AND ishighmcp=0 THEN 1 ELSE 0 END) AS clean,
        SUM(isspam + ishighspam) AS spam,
        SUM(virusinfected + CASE WHEN (nameinfected=1 OR otherinfected=1) AND virusinfected=0 THEN 1 ELSE 0 END) AS threats
    FROM maillog 
    WHERE $timeFilter AND ($globalFilter)
    GROUP BY slot, label
    ORDER BY slot ASC";

    $res = dbquery($sql);
    $labels = [];
    $dataTotal = [];
    $dataClean = [];
    $dataSpam = [];
    $dataThreats = [];

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $labels[] = $r['label'];
            $dataTotal[] = (int)$r['total'];
            $dataClean[] = (int)$r['clean'];
            $dataSpam[] = (int)$r['spam'];
            $dataThreats[] = (int)$r['threats'];
        }
    }

    if (empty($labels)) {
        $labels = ['00:00', '06:00', '12:00', '18:00'];
        $dataTotal = [0, 0, 0, 0];
        $dataClean = [0, 0, 0, 0];
        $dataSpam = [0, 0, 0, 0];
        $dataThreats = [0, 0, 0, 0];
    }

    $jsonLabels = json_encode($labels);
    $jsonTotal = json_encode($dataTotal);
    $jsonClean = json_encode($dataClean);
    $jsonSpam = json_encode($dataSpam);
    $jsonThreats = json_encode($dataThreats);

    $out = '<div id="' . $chartDomId . '" style="width: 100%; height: 260px;"></div>';
    $out .= '<script type="text/javascript">
    (function() {
        var el = document.getElementById("' . $chartDomId . '");
        if (!el || !window.echarts) return;
        var myChart = echarts.init(el);
        var option = {
            animationDuration: 600,
            tooltip: { trigger: "axis", axisPointer: { type: "cross", label: { backgroundColor: "#6a7985" } } },
            legend: { data: ["Total Messages", "Clean", "Spam", "Viruses & Threats"], top: 0, textStyle: { fontSize: 11 } },
            grid: { left: "3%", right: "4%", bottom: "3%", top: "35px", containLabel: true },
            xAxis: [{ type: "category", boundaryGap: false, data: ' . $jsonLabels . ', axisLine: { lineStyle: { color: "#cbd5e1" } }, axisLabel: { color: "#64748b", fontSize: 10 } }],
            yAxis: [{ type: "value", splitLine: { lineStyle: { color: "#f1f5f9" } }, axisLabel: { color: "#64748b", fontSize: 10 } }],
            series: [
                { name: "Total Messages", type: "line", smooth: true, lineStyle: { width: 2, color: "#0284c7" }, itemStyle: { color: "#0284c7" }, data: ' . $jsonTotal . ' },
                { name: "Clean", type: "line", smooth: true, areaStyle: { opacity: 0.15, color: "#10b981" }, lineStyle: { width: 2, color: "#10b981" }, itemStyle: { color: "#10b981" }, data: ' . $jsonClean . ' },
                { name: "Spam", type: "line", smooth: true, areaStyle: { opacity: 0.15, color: "#eab308" }, lineStyle: { width: 2, color: "#eab308" }, itemStyle: { color: "#eab308" }, data: ' . $jsonSpam . ' },
                { name: "Viruses & Threats", type: "line", smooth: true, areaStyle: { opacity: 0.2, color: "#ef4444" }, lineStyle: { width: 2, color: "#ef4444" }, itemStyle: { color: "#ef4444" }, data: ' . $jsonThreats . ' }
            ]
        };
        myChart.setOption(option);
        window.addEventListener("resize", function() { myChart.resize(); });
    })();
    </script>';

    return $out;
}

/**
 * 3. Threat Breakdown Donut Chart
 */
function render_widget_threat_donut($timeRange, $widgetId)
{
    $chartDomId = 'threat_donut_' . preg_replace('/[^a-zA-Z0-9_]/', '', $widgetId);
    $timeFilter = get_dashboard_time_filter($timeRange);
    $globalFilter = $_SESSION['global_filter'] ?? '1=1';

    $sql = "SELECT 
        SUM(CASE WHEN isspam=0 AND ishighspam=0 AND virusinfected=0 AND nameinfected=0 AND otherinfected=0 AND ismcp=0 AND ishighmcp=0 THEN 1 ELSE 0 END) AS clean,
        SUM(CASE WHEN isspam=1 AND ishighspam=0 THEN 1 ELSE 0 END) AS spam,
        SUM(ishighspam) AS highspam,
        SUM(virusinfected) AS virus,
        SUM(CASE WHEN (nameinfected=1 OR otherinfected=1) AND virusinfected=0 THEN 1 ELSE 0 END) AS badcontent,
        SUM(CASE WHEN ismcp=1 OR ishighmcp=1 THEN 1 ELSE 0 END) AS mcp
    FROM maillog 
    WHERE $timeFilter AND ($globalFilter)";

    $res = dbquery($sql);
    $d = $res ? $res->fetch_assoc() : [];

    $chartData = [
        ['value' => (int)($d['clean'] ?? 0), 'name' => 'Clean', 'itemStyle' => ['color' => '#10b981']],
        ['value' => (int)($d['spam'] ?? 0), 'name' => 'Spam', 'itemStyle' => ['color' => '#eab308']],
        ['value' => (int)($d['highspam'] ?? 0), 'name' => 'High Spam', 'itemStyle' => ['color' => '#c026d3']],
        ['value' => (int)($d['virus'] ?? 0), 'name' => 'Virus', 'itemStyle' => ['color' => '#ef4444']],
        ['value' => (int)($d['badcontent'] ?? 0), 'name' => 'Bad Content', 'itemStyle' => ['color' => '#f97316']],
        ['value' => (int)($d['mcp'] ?? 0), 'name' => 'Policy / MCP', 'itemStyle' => ['color' => '#8b5cf6']]
    ];

    $jsonData = json_encode($chartData);

    $out = '<div id="' . $chartDomId . '" style="width: 100%; height: 260px;"></div>';
    $out .= '<script type="text/javascript">
    (function() {
        var el = document.getElementById("' . $chartDomId . '");
        if (!el || !window.echarts) return;
        var myChart = echarts.init(el);
        var option = {
            tooltip: { trigger: "item", formatter: "{b}: {c} ({d}%)" },
            legend: { orient: "horizontal", bottom: 0, itemWidth: 10, itemHeight: 10, textStyle: { fontSize: 10 } },
            series: [{
                name: "Threat Distribution",
                type: "pie",
                radius: ["42%", "72%"],
                center: ["50%", "45%"],
                avoidLabelOverlap: false,
                itemStyle: { borderRadius: 4, borderColor: "#fff", borderWidth: 2 },
                label: { show: false, position: "center" },
                emphasis: { label: { show: true, fontSize: 13, fontWeight: "bold" } },
                data: ' . $jsonData . '
            }]
        };
        myChart.setOption(option);
        window.addEventListener("resize", function() { myChart.resize(); });
    })();
    </script>';

    return $out;
}

/**
 * 4. Top Relays with GeoIP & Autonomous System (AS/ASN)
 */
function render_widget_top_relays_asn($timeRange)
{
    $timeFilter = get_dashboard_time_filter($timeRange);
    $globalFilter = $_SESSION['global_filter'] ?? '1=1';

    $sql = "SELECT 
        clientip,
        COUNT(*) AS count,
        SUM(isspam + ishighspam) AS spam,
        SUM(virusinfected + CASE WHEN (nameinfected=1 OR otherinfected=1) AND virusinfected=0 THEN 1 ELSE 0 END) AS threats
    FROM maillog 
    WHERE $timeFilter AND clientip != '' AND clientip IS NOT NULL AND ($globalFilter)
    GROUP BY clientip 
    ORDER BY count DESC 
    LIMIT 7";

    $res = dbquery($sql);
    if (!$res || $res->num_rows === 0) {
        return '<div style="padding:20px;text-align:center;color:#64748b;">No relay activity found for this period.</div>';
    }

    $out = '<div class="table-responsive"><table class="boxtable" width="100%">
    <thead>
        <tr>
            <th style="padding:6px 10px;text-align:left;">Relay IP &amp; Hostname</th>
            <th style="padding:6px 10px;text-align:left;">Location</th>
            <th style="padding:6px 10px;text-align:left;">Autonomous System (AS)</th>
            <th style="padding:6px 10px;text-align:right;">Msgs</th>
            <th style="padding:6px 10px;text-align:right;">Threats</th>
        </tr>
    </thead>
    <tbody>';

    while ($row = $res->fetch_assoc()) {
        $ip = stripPortFromIp(trim($row['clientip']));
        $host = '-';
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $resolved = @gethostbyaddr($ip);
            $host = ($resolved && $resolved !== $ip) ? $resolved : '-';
        }

        $locStr = '-';
        $asnStr = '-';
        $geo = return_geoip_data($ip);
        if ($geo) {
            $locStr = htmlspecialchars($geo['country_name']);
            if (!empty($geo['city'])) {
                $locStr .= ' <span style="font-size:10px;color:#64748b;">(' . htmlspecialchars($geo['city']) . ')</span>';
            }
            if (!empty($geo['asn_number'])) {
                $asnBadge = '<a href="https://ipinfo.io/AS' . $geo['asn_number'] . '" target="_blank" rel="noopener noreferrer" class="badge-asn" title="ipinfo.io AS' . $geo['asn_number'] . '">AS' . $geo['asn_number'] . '</a>';
                $asnStr = $asnBadge . ' <span style="font-size:10.5px;color:#334155;">' . htmlspecialchars(mb_strimwidth($geo['asn_name'], 0, 24, '...')) . '</span>';
            }
        }

        $threatCount = (int)$row['threats'] + (int)$row['spam'];
        $tokenParam = isset($_SESSION['token']) ? '&amp;token=' . urlencode($_SESSION['token']) : '';

        $out .= '<tr>
            <td style="padding:6px 10px;">
                <strong><a href="rep_message_listing.php?relay=' . urlencode($ip) . $tokenParam . '" style="color:#0284c7;text-decoration:none;">' . htmlspecialchars($ip) . '</a></strong><br>
                <span style="font-size:10px;color:#64748b;">' . htmlspecialchars(mb_strimwidth($host, 0, 28, '...')) . '</span>
            </td>
            <td style="padding:6px 10px;">' . $locStr . '</td>
            <td style="padding:6px 10px;">' . $asnStr . '</td>
            <td style="padding:6px 10px;text-align:right;font-weight:600;">' . number_format($row['count']) . '</td>
            <td style="padding:6px 10px;text-align:right;">
                <span class="' . ($threatCount > 0 ? 'pill-red' : 'pill-green') . ' dash-kpi-pill" style="font-size:10px;">' . number_format($threatCount) . '</span>
            </td>
        </tr>';
    }

    $out .= '</tbody></table></div>';
    return $out;
}

/**
 * 5. Top Senders, Recipients & Domains (Tabbed)
 */
function render_widget_top_senders_recipients($timeRange)
{
    $timeFilter = get_dashboard_time_filter($timeRange);
    $globalFilter = $_SESSION['global_filter'] ?? '1=1';
    $tokenParam = isset($_SESSION['token']) ? '&amp;token=' . urlencode($_SESSION['token']) : '';

    // Top Senders
    $sqlSenders = "SELECT from_address, COUNT(*) AS count, SUM(isspam + ishighspam + virusinfected) AS threats 
                   FROM maillog 
                   WHERE $timeFilter AND from_address != '' AND ($globalFilter)
                   GROUP BY from_address ORDER BY count DESC LIMIT 6";
    $resSenders = dbquery($sqlSenders);

    // Top Recipients
    $sqlRecipients = "SELECT to_address, COUNT(*) AS count, SUM(isspam + ishighspam + virusinfected) AS threats 
                      FROM maillog 
                      WHERE $timeFilter AND to_address != '' AND ($globalFilter)
                      GROUP BY to_address ORDER BY count DESC LIMIT 6";
    $resRecipients = dbquery($sqlRecipients);

    $out = '
    <div class="dash-tab-container">
        <div class="dash-tab-headers">
            <button class="dash-tab-btn active" onclick="switchDashTab(this, \'tab-senders\')">Top Senders</button>
            <button class="dash-tab-btn" onclick="switchDashTab(this, \'tab-recipients\')">Top Recipients</button>
        </div>
        <div class="dash-tab-content active" id="tab-senders">
            <table class="boxtable" width="100%">
                <thead>
                    <tr>
                        <th style="padding:6px 10px;text-align:left;">Sender Address</th>
                        <th style="padding:6px 10px;text-align:right;">Messages</th>
                        <th style="padding:6px 10px;text-align:right;">Threats</th>
                    </tr>
                </thead>
                <tbody>';

    if ($resSenders && $resSenders->num_rows > 0) {
        while ($r = $resSenders->fetch_assoc()) {
            $addr = htmlspecialchars($r['from_address']);
            $out .= '<tr>
                <td style="padding:6px 10px;"><a href="rep_message_listing.php?from=' . urlencode($r['from_address']) . $tokenParam . '" style="color:#0284c7;text-decoration:none;font-weight:500;">' . mb_strimwidth($addr, 0, 38, '...') . '</a></td>
                <td style="padding:6px 10px;text-align:right;font-weight:600;">' . number_format($r['count']) . '</td>
                <td style="padding:6px 10px;text-align:right;"><span class="' . ((int)$r['threats'] > 0 ? 'pill-red' : 'pill-green') . ' dash-kpi-pill" style="font-size:10px;">' . number_format($r['threats']) . '</span></td>
            </tr>';
        }
    } else {
        $out .= '<tr><td colspan="3" style="text-align:center;padding:12px;color:#64748b;">No data recorded</td></tr>';
    }

    $out .= '</tbody></table></div>';

    // Recipients Tab
    $out .= '<div class="dash-tab-content" id="tab-recipients">
            <table class="boxtable" width="100%">
                <thead>
                    <tr>
                        <th style="padding:6px 10px;text-align:left;">Recipient Address</th>
                        <th style="padding:6px 10px;text-align:right;">Messages</th>
                        <th style="padding:6px 10px;text-align:right;">Threats</th>
                    </tr>
                </thead>
                <tbody>';

    if ($resRecipients && $resRecipients->num_rows > 0) {
        while ($r = $resRecipients->fetch_assoc()) {
            $addr = htmlspecialchars($r['to_address']);
            $out .= '<tr>
                <td style="padding:6px 10px;"><a href="rep_message_listing.php?to=' . urlencode($r['to_address']) . $tokenParam . '" style="color:#0284c7;text-decoration:none;font-weight:500;">' . mb_strimwidth($addr, 0, 38, '...') . '</a></td>
                <td style="padding:6px 10px;text-align:right;font-weight:600;">' . number_format($r['count']) . '</td>
                <td style="padding:6px 10px;text-align:right;"><span class="' . ((int)$r['threats'] > 0 ? 'pill-red' : 'pill-green') . ' dash-kpi-pill" style="font-size:10px;">' . number_format($r['threats']) . '</span></td>
            </tr>';
        }
    } else {
        $out .= '<tr><td colspan="3" style="text-align:center;padding:12px;color:#64748b;">No data recorded</td></tr>';
    }

    $out .= '</tbody></table></div></div>';

    return $out;
}

/**
 * 6. Recent Intercepted Threats
 */
function render_widget_recent_threats($timeRange)
{
    $timeFilter = get_dashboard_time_filter($timeRange);
    $globalFilter = $_SESSION['global_filter'] ?? '1=1';

    $sql = "SELECT 
        id,
        timestamp,
        from_address,
        to_address,
        subject,
        sascore,
        virusinfected,
        nameinfected,
        otherinfected,
        isspam,
        ishighspam,
        ismcp,
        ishighmcp
    FROM maillog 
    WHERE (isspam=1 OR virusinfected=1 OR nameinfected=1 OR otherinfected=1 OR ismcp=1 OR ishighmcp=1)
      AND $timeFilter AND ($globalFilter)
    ORDER BY timestamp DESC 
    LIMIT 6";

    $res = dbquery($sql);
    if (!$res || $res->num_rows === 0) {
        return '<div style="padding:24px;text-align:center;color:#166534;font-weight:600;">🛡️ No security threats detected in this time frame. All clear!</div>';
    }

    $out = '<div class="table-responsive"><table class="boxtable" width="100%">
    <thead>
        <tr>
            <th style="padding:6px 10px;text-align:left;">Type</th>
            <th style="padding:6px 10px;text-align:left;">From / To</th>
            <th style="padding:6px 10px;text-align:left;">Subject</th>
            <th style="padding:6px 10px;text-align:center;">Score</th>
            <th style="padding:6px 10px;text-align:right;">Time</th>
            <th style="padding:6px 10px;text-align:center;">Action</th>
        </tr>
    </thead>
    <tbody>';

    while ($r = $res->fetch_assoc()) {
        $typeBadge = '';
        if ((int)$r['virusinfected'] === 1) {
            $typeBadge = '<span class="dash-kpi-pill pill-red">🔴 Virus</span>';
        } elseif ((int)$r['nameinfected'] === 1 || (int)$r['otherinfected'] === 1) {
            $typeBadge = '<span class="dash-kpi-pill pill-orange">🟠 Bad Content</span>';
        } elseif ((int)$r['ishighspam'] === 1) {
            $typeBadge = '<span class="dash-kpi-pill pill-purple">🟣 High Spam</span>';
        } elseif ((int)$r['isspam'] === 1) {
            $typeBadge = '<span class="dash-kpi-pill pill-yellow">🟡 Spam</span>';
        } elseif ((int)$r['ismcp'] === 1 || (int)$r['ishighmcp'] === 1) {
            $typeBadge = '<span class="dash-kpi-pill pill-purple">🛡️ Policy</span>';
        }

        $timeStr = date('H:i:s', strtotime($r['timestamp']));
        $subject = !empty($r['subject']) ? htmlspecialchars(mb_strimwidth($r['subject'], 0, 32, '...')) : '<em>(no subject)</em>';

        $tokenParam = isset($_SESSION['token']) ? '&amp;token=' . urlencode($_SESSION['token']) : '';

        $out .= '<tr>
            <td style="padding:6px 10px;">' . $typeBadge . '</td>
            <td style="padding:6px 10px;font-size:11px;">
                <span style="color:#1e293b;font-weight:600;">' . htmlspecialchars(mb_strimwidth($r['from_address'], 0, 24, '...')) . '</span><br>
                <span style="color:#64748b;">→ ' . htmlspecialchars(mb_strimwidth($r['to_address'], 0, 24, '...')) . '</span>
            </td>
            <td style="padding:6px 10px;font-size:11px;">' . $subject . '</td>
            <td style="padding:6px 10px;text-align:center;font-weight:700;font-size:11px;">' . number_format((float)$r['sascore'], 1) . '</td>
            <td style="padding:6px 10px;text-align:right;font-size:10.5px;color:#64748b;">' . $timeStr . '</td>
            <td style="padding:6px 10px;text-align:center;">
                <a href="detail.php?id=' . urlencode($r['id']) . $tokenParam . '" class="btn-micro" title="View details">🔍</a>
            </td>
        </tr>';
    }

    $out .= '</tbody></table></div>';
    return $out;
}

/**
 * 7. Live Recent Processed Messages
 */
function render_widget_recent_messages($timeRange)
{
    $globalFilter = $_SESSION['global_filter'] ?? '1=1';
    $sql = "SELECT id, timestamp, from_address, to_address, subject, size, isspam, ishighspam, virusinfected, nameinfected, otherinfected, sascore
            FROM maillog 
            WHERE $globalFilter 
            ORDER BY timestamp DESC LIMIT 6";

    $res = dbquery($sql);
    if (!$res || $res->num_rows === 0) {
        return '<div style="padding:16px;text-align:center;color:#64748b;">No recent messages found.</div>';
    }

    $tokenParam = isset($_SESSION['token']) ? '&amp;token=' . urlencode($_SESSION['token']) : '';

    $out = '<div class="table-responsive"><table class="boxtable" width="100%">
    <thead>
        <tr>
            <th style="padding:6px 10px;text-align:left;">Status</th>
            <th style="padding:6px 10px;text-align:left;">Sender / Recipient</th>
            <th style="padding:6px 10px;text-align:left;">Subject</th>
            <th style="padding:6px 10px;text-align:right;">Size</th>
            <th style="padding:6px 10px;text-align:center;">Action</th>
        </tr>
    </thead>
    <tbody>';

    while ($r = $res->fetch_assoc()) {
        $status = '<span class="dash-kpi-pill pill-green">Clean</span>';
        if ((int)$r['virusinfected'] === 1) {
            $status = '<span class="dash-kpi-pill pill-red">Virus</span>';
        } elseif ((int)$r['nameinfected'] === 1 || (int)$r['otherinfected'] === 1) {
            $status = '<span class="dash-kpi-pill pill-orange">Bad Content</span>';
        } elseif ((int)$r['ishighspam'] === 1) {
            $status = '<span class="dash-kpi-pill pill-purple">High Spam</span>';
        } elseif ((int)$r['isspam'] === 1) {
            $status = '<span class="dash-kpi-pill pill-yellow">Spam</span>';
        }

        $subject = !empty($r['subject']) ? htmlspecialchars(mb_strimwidth($r['subject'], 0, 28, '...')) : '<em>(no subject)</em>';

        $out .= '<tr>
            <td style="padding:6px 10px;">' . $status . '</td>
            <td style="padding:6px 10px;font-size:11px;">
                <span style="font-weight:600;color:#0f172a;">' . htmlspecialchars(mb_strimwidth($r['from_address'], 0, 22, '...')) . '</span><br>
                <span style="color:#64748b;">' . htmlspecialchars(mb_strimwidth($r['to_address'], 0, 22, '...')) . '</span>
            </td>
            <td style="padding:6px 10px;font-size:11px;">' . $subject . '</td>
            <td style="padding:6px 10px;text-align:right;font-size:10.5px;">' . formatSize((float)$r['size']) . '</td>
            <td style="padding:6px 10px;text-align:center;">
                <a href="detail.php?id=' . urlencode($r['id']) . $tokenParam . '" class="btn-micro">🔍</a>
            </td>
        </tr>';
    }

    $out .= '</tbody></table></div>';
    return $out;
}

/**
 * 8. Core Services & System Health
 */
function render_widget_system_services()
{
    $services = [
        'mailscanner' => 'MailScanner',
        'postfix' => 'Postfix MTA',
        'msmilter' => 'MSMilter',
        'mariadb' => 'MariaDB',
        'clamd@scan' => 'ClamAV Scan',
        'spamassassin' => 'SpamAssassin',
        'php-fpm' => 'PHP-FPM',
        'unbound' => 'Unbound DNS'
    ];

    $out = '<div style="padding:10px 14px;">';
    
    // Services Grid
    $out .= '<div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:6px;text-transform:uppercase;">Core Daemons Status</div>';
    $out .= '<div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:6px;margin-bottom:14px;">';

    foreach ($services as $svc => $label) {
        $isRunning = false;
        exec("systemctl is-active " . escapeshellarg($svc) . " 2>/dev/null", $outArr, $ret);
        if ($ret === 0 && isset($outArr[0]) && trim($outArr[0]) === 'active') {
            $isRunning = true;
        }
        $outArr = [];

        $badgeClass = $isRunning ? 'pill-green' : 'pill-slate';
        $badgeText = $isRunning ? '● RUNNING' : '○ STOPPED';

        if ($svc === 'spamassassin') {
            if ($isRunning) {
                $badgeClass = 'pill-green';
                $badgeText = '● ACTIVE';
            } elseif (true === get_conf_truefalse('UseSpamAssassin')) {
                $badgeClass = 'pill-green';
                $badgeText = '● ACTIVE';
            } else {
                $badgeClass = 'pill-slate';
                $badgeText = '○ DISABLED';
            }
        }

        $out .= '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:5px 8px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-size:11px;font-weight:600;color:#1e293b;">' . $label . '</span>
            <span class="dash-kpi-pill ' . $badgeClass . '" style="font-size:9.5px;padding:1px 5px;">' . $badgeText . '</span>
        </div>';
    }
    $out .= '</div>';

    // Resource Bars
    $out .= '<div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:6px;text-transform:uppercase;">Resource Consumption</div>';

    // RAM & Swap
    $ramTotal = 1;
    $ramUsed = 0;
    $ramPct = 0;
    $swapTotal = 0;
    $swapUsed = 0;
    $swapPct = 0;
    if (file_exists('/proc/meminfo')) {
        $meminfo = @file_get_contents('/proc/meminfo');
        if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $mt) && preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $ma)) {
            $ramTotal = (float)$mt[1] * 1024;
            $avail = (float)$ma[1] * 1024;
            $ramUsed = $ramTotal - $avail;
            $ramPct = round(($ramUsed / $ramTotal) * 100, 1);
        }
        if (preg_match('/SwapTotal:\s+(\d+)/', $meminfo, $st) && preg_match('/SwapFree:\s+(\d+)/', $meminfo, $sf)) {
            $swapTotal = (float)$st[1] * 1024;
            $swapFree = (float)$sf[1] * 1024;
            $swapUsed = $swapTotal - $swapFree;
            $swapPct = $swapTotal > 0 ? round(($swapUsed / $swapTotal) * 100, 1) : 0;
        }
    }

    $out .= '
    <div style="margin-bottom:8px;">
        <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:2px;">
            <span>Memory (RAM)</span>
            <span style="font-weight:600;">' . formatSize($ramUsed) . ' / ' . formatSize($ramTotal) . ' (' . $ramPct . '%)</span>
        </div>
        <div class="dash-progress-track">
            <div class="dash-progress-bar ' . ($ramPct > 85 ? 'bar-red' : ($ramPct > 70 ? 'bar-yellow' : 'bar-blue')) . '" style="width:' . min($ramPct, 100) . '%;"></div>
        </div>
    </div>';

    if ($swapTotal > 0) {
        $out .= '
        <div style="margin-bottom:8px;">
            <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:2px;">
                <span>Swap Memory</span>
                <span style="font-weight:600;">' . formatSize($swapUsed) . ' / ' . formatSize($swapTotal) . ' (' . $swapPct . '%)</span>
            </div>
            <div class="dash-progress-track">
                <div class="dash-progress-bar ' . ($swapPct > 70 ? 'bar-red' : ($swapPct > 40 ? 'bar-yellow' : 'bar-purple')) . '" style="width:' . min($swapPct, 100) . '%;"></div>
            </div>
        </div>';
    }

    // Disk
    $diskTotal = @disk_total_space('/') ?: 1;
    $diskFree = @disk_free_space('/') ?: 0;
    $diskUsed = $diskTotal - $diskFree;
    $diskPct = round(($diskUsed / $diskTotal) * 100, 1);

    $out .= '
    <div>
        <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:2px;">
            <span>Disk Space (/)</span>
            <span style="font-weight:600;">' . formatSize($diskUsed) . ' / ' . formatSize($diskTotal) . ' (' . $diskPct . '%)</span>
        </div>
        <div class="dash-progress-track">
            <div class="dash-progress-bar ' . ($diskPct > 90 ? 'bar-red' : ($diskPct > 75 ? 'bar-yellow' : 'bar-green')) . '" style="width:' . min($diskPct, 100) . '%;"></div>
        </div>
    </div>';

    $out .= '</div>';
    return $out;
}

/**
 * 9. Top Triggered SpamAssassin Rules
 */
function render_widget_spam_rules_top($timeRange)
{
    $timeFilter = get_dashboard_time_filter($timeRange);
    $globalFilter = $_SESSION['global_filter'] ?? '1=1';

    // Query recent spam reports and parse rules
    $sql = "SELECT report FROM maillog WHERE isspam=1 AND $timeFilter AND ($globalFilter) AND report != '' LIMIT 150";
    $res = dbquery($sql);

    $ruleHits = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $report = $row['report'];
            // Extract rules like RULE_NAME
            if (preg_match_all('/([A-Z0-9_]{3,35})\s*[,=]/', $report, $matches)) {
                foreach ($matches[1] as $rule) {
                    if (in_array($rule, ['TOTAL', 'SCORE', 'AUTO_LEARN', 'REQUIRED', 'BAYES'])) {
                        continue;
                    }
                    $ruleHits[$rule] = ($ruleHits[$rule] ?? 0) + 1;
                }
            }
        }
    }

    arsort($ruleHits);
    $topRules = array_slice($ruleHits, 0, 7, true);

    if (empty($topRules)) {
        return '<div style="padding:20px;text-align:center;color:#64748b;">No rule triggers found for this time window.</div>';
    }

    $out = '<div class="table-responsive"><table class="boxtable" width="100%">
    <thead>
        <tr>
            <th style="padding:6px 10px;text-align:left;">Rule Identifier</th>
            <th style="padding:6px 10px;text-align:right;">Hits</th>
            <th style="padding:6px 10px;text-align:left;width:35%;">Distribution</th>
        </tr>
    </thead>
    <tbody>';

    $maxHits = max($topRules) ?: 1;
    foreach ($topRules as $rule => $hits) {
        $pct = round(($hits / $maxHits) * 100);
        $out .= '<tr>
            <td style="padding:6px 10px;font-family:monospace;font-size:11px;font-weight:600;color:#0f172a;">' . htmlspecialchars($rule) . '</td>
            <td style="padding:6px 10px;text-align:right;font-weight:700;">' . number_format($hits) . '</td>
            <td style="padding:6px 10px;">
                <div class="dash-progress-track" style="height:6px;">
                    <div class="dash-progress-bar bar-purple" style="width:' . $pct . '%;"></div>
                </div>
            </td>
        </tr>';
    }

    $out .= '</tbody></table></div>';
    return $out;
}

/**
 * 10. Quarantine Stats Overview
 */
function render_widget_quarantine_stats()
{
    $todayCount = 0;
    $todayViruses = 0;
    $todaySpam = 0;

    $sql = "SELECT 
        COUNT(*) AS total,
        SUM(virusinfected) AS virus,
        SUM(isspam) AS spam
    FROM maillog 
    WHERE (quarantined=1 OR virusinfected=1 OR nameinfected=1 OR otherinfected=1 OR ishighspam=1)
      AND timestamp >= CURDATE()";

    $res = dbquery($sql);
    if ($res) {
        $r = $res->fetch_assoc();
        $todayCount = (int)($r['total'] ?? 0);
        $todayViruses = (int)($r['virus'] ?? 0);
        $todaySpam = (int)($r['spam'] ?? 0);
    }

    $out = '<div style="padding:14px;text-align:center;">
        <div style="font-size:32px;font-weight:800;color:#dc2626;line-height:1;margin-bottom:4px;">' . number_format($todayCount) . '</div>
        <div style="font-size:12px;color:#64748b;font-weight:600;margin-bottom:14px;">Quarantined Today</div>
        <div style="display:flex;justify-content:center;gap:10px;margin-bottom:16px;">
            <span class="dash-kpi-pill pill-red">🦠 ' . number_format($todayViruses) . ' Viruses</span>
            <span class="dash-kpi-pill pill-yellow">⚡ ' . number_format($todaySpam) . ' Spam</span>
        </div>
        <a href="quarantine.php" class="btn" style="text-decoration:none;padding:6px 14px;font-size:11.5px;">📅 Open Quarantine Calendar »</a>
    </div>';

    return $out;
}

/**
 * 11. Quick Actions & Message Lookup
 */
function render_widget_quick_actions()
{
    $out = '<div style="padding:12px;">
        <form method="GET" action="rep_message_listing.php" style="margin-bottom:14px;">
            <label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:4px;">FAST MESSAGE LOOKUP</label>
            <div style="display:flex;gap:4px;">
                <input type="text" name="from" placeholder="Sender, recipient, or subject..." style="flex:1;padding:6px 8px;font-size:11.5px;border:1px solid #cbd5e1;border-radius:4px;">
                <input type="hidden" name="token" value="' . htmlspecialchars($_SESSION['token'] ?? '') . '">
                <input type="submit" value="Search" class="btn" style="padding:6px 12px;font-size:11px;">
            </div>
        </form>
        <div style="display:flex;flex-direction:column;gap:6px;">
            <a href="status.php" class="dash-action-link">📬 View Live Recent Messages Feed</a>
            <a href="rep_top_mail_relays.php" class="dash-action-link">🌐 Top Mail Relays &amp; AS Report</a>
            <a href="geoip_update.php" class="dash-action-link">🔄 Update GeoIP &amp; AS Database</a>
        </div>
    </div>';

    return $out;
}
