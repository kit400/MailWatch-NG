<?php

/**
 * MailWatch for MailScanner
 * Interactive Customizable Dashboard
 *
 * Copyright (C) 2003-2026 MailWatch Team / EFA-NG Project
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/dashboard.inc.php';
require __DIR__ . '/login.function.php';

// Handle AJAX Endpoints
if (isset($_REQUEST['action'])) {
    $action = $_REQUEST['action'];
    $username = $_SESSION['myusername'] ?? 'default';

    if ($action === 'save_layout') {
        header('Content-Type: application/json');
        if (false === checkToken($_POST['token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Security token invalid']);
            exit;
        }
        $rawLayout = $_POST['layout'] ?? '[]';
        $layoutArray = json_decode($rawLayout, true);
        if (!is_array($layoutArray)) {
            echo json_encode(['success' => false, 'error' => 'Invalid layout data']);
            exit;
        }
        $res = save_user_dashboard_layout($username, $layoutArray);
        echo json_encode(['success' => (bool)$res]);
        exit;
    }

    if ($action === 'reset_layout') {
        header('Content-Type: application/json');
        if (false === checkToken($_POST['token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Security token invalid']);
            exit;
        }
        $res = reset_user_dashboard_layout($username);
        echo json_encode(['success' => (bool)$res]);
        exit;
    }

    if ($action === 'get_widget_body') {
        $type = $_GET['type'] ?? '';
        $timeRange = $_GET['range'] ?? '24h';
        $widgetId = $_GET['widget_id'] ?? ($type . '_widget');
        echo render_dashboard_widget_content($type, $timeRange, $widgetId);
        exit;
    }

    if ($action === 'get_widget_html') {
        $type = $_GET['type'] ?? '';
        $timeRange = $_GET['range'] ?? '24h';
        $widgetId = $_GET['widget_id'] ?? ($type . '_widget');
        $catalog = get_available_widgets_catalog();
        $widgetMeta = $catalog[$type] ?? ['title' => 'Widget', 'icon' => '📊'];

        echo render_single_widget_card([
            'id' => $widgetId,
            'type' => $type,
            'width' => $_GET['width'] ?? ($widgetMeta['default_width'] ?? 'col-6'),
            'title' => $widgetMeta['title']
        ], $timeRange);
        exit;
    }
}

// Full Dashboard Page
$timeRange = $_GET['range'] ?? '24h';
$validRanges = ['today', '24h', '7d', '30d'];
if (!in_array($timeRange, $validRanges, true)) {
    $timeRange = '24h';
}

html_start('Dashboard', 0, false, false);
dbconn();

$username = $_SESSION['myusername'] ?? 'default';
$userLayout = get_user_dashboard_layout($username);
$catalog = get_available_widgets_catalog();

/**
 * Helper to render an individual widget card inside wrapper
 */
function render_single_widget_card($w, $timeRange)
{
    $widgetId = htmlspecialchars($w['id'] ?? ($w['type'] . '_' . rand(100, 999)));
    $widgetType = htmlspecialchars($w['type']);
    $widgetWidth = htmlspecialchars($w['width'] ?? 'col-6');
    $widgetTitle = htmlspecialchars($w['title'] ?? 'Widget');

    $catalog = get_available_widgets_catalog();
    $icon = $catalog[$w['type']]['icon'] ?? '📊';

    $out = '
    <div class="dash-widget">
        <!-- Edit Controls Overlay (Visible in Edit Mode) -->
        <div class="dash-edit-overlay">
            <div class="dash-drag-handle" title="Drag to reorder widget">⠿ Drag</div>
            <div class="dash-width-selector">
                <button type="button" class="' . ($widgetWidth === 'col-3' ? 'active' : '') . '" onclick="setWidgetWidth(this, \'col-3\')">25%</button>
                <button type="button" class="' . ($widgetWidth === 'col-4' ? 'active' : '') . '" onclick="setWidgetWidth(this, \'col-4\')">33%</button>
                <button type="button" class="' . ($widgetWidth === 'col-6' ? 'active' : '') . '" onclick="setWidgetWidth(this, \'col-6\')">50%</button>
                <button type="button" class="' . ($widgetWidth === 'col-8' ? 'active' : '') . '" onclick="setWidgetWidth(this, \'col-8\')">66%</button>
                <button type="button" class="' . ($widgetWidth === 'col-12' ? 'active' : '') . '" onclick="setWidgetWidth(this, \'col-12\')">100%</button>
            </div>
            <div class="dash-order-actions">
                <button type="button" onclick="moveWidgetOrder(this, -1)" title="Move Left / Up">◀</button>
                <button type="button" onclick="moveWidgetOrder(this, 1)" title="Move Right / Down">▶</button>
                <button type="button" class="dash-btn-remove" onclick="removeWidget(this)" title="Remove widget">✕</button>
            </div>
        </div>

        <div class="dash-widget-header">
            <div class="dash-widget-title">
                <span class="dash-widget-icon">' . $icon . '</span>
                <span class="dash-widget-title-text">' . $widgetTitle . '</span>
            </div>
            <div class="dash-widget-tools">
                <button type="button" class="btn-dash-tool btn-dash-refresh" onclick="refreshWidget(this)" title="Refresh widget">🔄</button>
            </div>
        </div>
        <div class="dash-widget-body">
            ' . render_dashboard_widget_content($w['type'], $timeRange, $w['id']) . '
        </div>
    </div>';

    return $out;
}
?>

<!-- Scripts -->
<script src="js/echarts.min.js"></script>
<script src="js/dashboard.js"></script>

<input type="hidden" id="dashCsrfToken" value="<?php echo htmlspecialchars($_SESSION['token'] ?? ''); ?>">

<div class="dash-container">
    <!-- Top Dashboard Header & Controls -->
    <div class="dash-top-bar">
        <div class="dash-title-group">
            <h2 class="dash-page-title">📊 System &amp; Security Dashboard</h2>
            <div class="dash-time-selector">
                <button type="button" class="dash-range-btn <?php echo $timeRange === 'today' ? 'active' : ''; ?>" onclick="changeDashTimeRange('today')">Today</button>
                <button type="button" class="dash-range-btn <?php echo $timeRange === '24h' ? 'active' : ''; ?>" onclick="changeDashTimeRange('24h')">Last 24h</button>
                <button type="button" class="dash-range-btn <?php echo $timeRange === '7d' ? 'active' : ''; ?>" onclick="changeDashTimeRange('7d')">7 Days</button>
                <button type="button" class="dash-range-btn <?php echo $timeRange === '30d' ? 'active' : ''; ?>" onclick="changeDashTimeRange('30d')">30 Days</button>
            </div>
        </div>

        <div class="dash-actions-group">
            <!-- Auto Refresh Control -->
            <div class="dash-autorefresh-box">
                <span class="dash-autorefresh-label">Auto-Refresh:</span>
                <select id="dashAutoRefresh" class="dash-select">
                    <option value="0">Off</option>
                    <option value="15">15s</option>
                    <option value="30">30s</option>
                    <option value="60" selected>60s</option>
                    <option value="120">2m</option>
                    <option value="300">5m</option>
                </select>
                <span id="dashRefreshCountdown" class="dash-countdown-badge">60s</span>
            </div>

            <!-- Manual Refresh -->
            <button type="button" id="btnManualRefresh" class="dash-btn dash-btn-square" onclick="refreshDashboard()" title="Refresh all widgets" style="padding: 0; width: 30px; height: 30px; min-width: 30px; display: inline-flex; align-items: center; justify-content: center;">🔄</button>

            <!-- Add Widget -->
            <button type="button" class="dash-btn dash-btn-primary" onclick="openAddWidgetModal()">+ Add Widget</button>

            <!-- Customize / Edit Mode -->
            <button type="button" id="btnEditDashboard" class="dash-btn dash-btn-edit" onclick="toggleDashboardEditMode()">✏️ Customize Layout</button>
        </div>
    </div>

    <!-- Edit Mode Bar (Displayed when Customize is toggled) -->
    <div id="dashEditBar" class="dash-edit-banner" style="display: none;">
        <div class="dash-edit-banner-info">
            <strong>✏️ Layout Customization Mode:</strong> Drag widgets to reorder. Use width buttons (25% - 100%) to resize widgets, or remove unwanted items.
        </div>
        <div class="dash-edit-banner-actions">
            <button type="button" id="btnSaveLayout" class="dash-btn dash-btn-success" onclick="saveDashboardLayout()">💾 Save Layout</button>
            <button type="button" class="dash-btn dash-btn-danger" onclick="resetDashboardLayout()">↺ Reset to Default</button>
            <button type="button" class="dash-btn" onclick="toggleDashboardEditMode()">✕ Exit</button>
        </div>
    </div>

    <!-- Widgets Grid -->
    <div id="dashboardGrid" class="dashboard-grid">
        <?php foreach ($userLayout as $w): ?>
            <?php
                $wWidth = $w['width'] ?? 'col-6';
                $wId = $w['id'] ?? ($w['type'] . '_' . rand(100, 999));
                $wType = $w['type'];
            ?>
            <div class="dash-widget-wrapper <?php echo htmlspecialchars($wWidth); ?>"
                 data-widget-id="<?php echo htmlspecialchars($wId); ?>"
                 data-widget-type="<?php echo htmlspecialchars($wType); ?>">
                <?php echo render_single_widget_card($w, $timeRange); ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Add Widget Modal Catalog -->
<div id="addWidgetModal" class="dash-modal" style="display: none;">
    <div class="dash-modal-backdrop" onclick="closeAddWidgetModal()"></div>
    <div class="dash-modal-dialog">
        <div class="dash-modal-header">
            <h3 class="dash-modal-title">📦 Add Widget to Dashboard</h3>
            <button type="button" class="dash-modal-close" onclick="closeAddWidgetModal()">✕</button>
        </div>
        <div class="dash-modal-body">
            <div class="dash-catalog-grid">
                <?php foreach ($catalog as $type => $info): ?>
                    <div class="dash-catalog-item">
                        <div class="dash-catalog-icon"><?php echo $info['icon']; ?></div>
                        <div class="dash-catalog-info">
                            <h4 class="dash-catalog-title"><?php echo htmlspecialchars($info['title']); ?></h4>
                            <p class="dash-catalog-desc"><?php echo htmlspecialchars($info['description']); ?></p>
                            <div class="dash-catalog-meta">
                                <span class="dash-kpi-pill pill-slate">Category: <?php echo htmlspecialchars(ucfirst($info['category'])); ?></span>
                                <span class="dash-kpi-pill pill-blue">Default width: <?php echo htmlspecialchars($info['default_width']); ?></span>
                            </div>
                        </div>
                        <div class="dash-catalog-action">
                            <button type="button" class="dash-btn dash-btn-primary" onclick="addWidgetToGrid('<?php echo $type; ?>', '<?php echo addslashes($info['title']); ?>', '<?php echo $info['default_width']; ?>')">+ Add</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
document.addEventListener("DOMContentLoaded", function () {
    if (window.initDashboard) {
        window.initDashboard();
    }
});
</script>

<?php
html_end();
dbclose();
?>
