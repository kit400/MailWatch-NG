<?php

/*
 * MailWatch for MailScanner
 * Copyright (C) 2003-2011  Steve Freegard (steve@freegard.name)
 * Copyright (C) 2011  Garrod Alwood (garrod.alwood@lorodoes.com)
 * Copyright (C) 2014-2021  MailWatch Team (https://github.com/mailwatch/1.2.0/graphs/contributors)
 * Copyright (C) 2026       EFA-NG Project (https://efa-ng.space.ua)
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later
 * version.
 */

require_once __DIR__ . '/functions.php';
require __DIR__ . '/login.function.php';

// Flash feedback messages
$flash_success = '';
$flash_error = '';
$errors = [];

// Sanitize GET / POST parameters
$url_type = isset($_GET['type']) ? deepSanitizeInput($_GET['type'], 'url') : '';
if (!validateInput($url_type, 'urltype')) {
    $url_type = '';
}

$url_to = '';
if (isset($_POST['to'])) {
    $url_to = deepSanitizeInput($_POST['to'], 'string');
    if (!empty($url_to) && !validateInput($url_to, 'user')) {
        $url_to = '';
    }
} elseif (isset($_GET['to'])) {
    $url_to = deepSanitizeInput($_GET['to'], 'string');
    if (!validateInput($url_to, 'user')) {
        $url_to = '';
    }
}

$url_host = isset($_GET['host']) ? deepSanitizeInput($_GET['host'], 'url') : '';
if (!validateInput($url_host, 'host')) {
    $url_host = '';
}

$url_from = '';
if (isset($_POST['from'])) {
    $url_from = deepSanitizeInput($_POST['from'], 'string');
    if (!validateInput($url_from, 'user')) {
        $url_from = '';
    }
} elseif (isset($_GET['from'])) {
    $url_from = deepSanitizeInput($_GET['from'], 'string');
    if (!validateInput($url_from, 'user')) {
        $url_from = '';
    }
}

$url_submit = '';
if (isset($_POST['submit'])) {
    $url_submit = deepSanitizeInput($_POST['submit'], 'listsubmit');
    if (!validateInput($url_submit, 'listsubmit')) {
        $url_submit = '';
    }
} elseif (isset($_GET['submit'])) {
    $url_submit = deepSanitizeInput($_GET['submit'], 'listsubmit');
    if (!validateInput($url_submit, 'listsubmit')) {
        $url_submit = '';
    }
}

$url_list = '';
if (isset($_POST['list'])) {
    $url_list = deepSanitizeInput($_POST['list'], 'url');
    if (!validateInput($url_list, 'list')) {
        $url_list = '';
    }
} elseif (isset($_GET['list'])) {
    $url_list = deepSanitizeInput($_GET['list'], 'url');
    if (!validateInput($url_list, 'list')) {
        $url_list = '';
    }
}

$url_domain = '';
if (isset($_POST['domain'])) {
    $url_domain = deepSanitizeInput($_POST['domain'], 'url');
    if (!empty($url_domain) && !validateInput($url_domain, 'host')) {
        $url_domain = '';
    }
}

$url_id = isset($_GET['listid']) ? deepSanitizeInput($_GET['listid'], 'num') : '';
if (!validateInput($url_id, 'num')) {
    $url_id = '';
}

// Split user/domain if necessary (from detail.php)
$touser = '';
$to_domain = '';
if (preg_match('/(\S+)@(\S+)/', $url_to, $split)) {
    $touser = $split[1];
    $to_domain = $split[2];
} else {
    $to_domain = $url_to;
}

// Determine $from address
switch ($url_type) {
    case 'h':
        $from = $url_host;
        break;
    case 'f':
    default:
        $from = $url_from;
        break;
}

$myusername = safe_value(stripslashes($_SESSION['myusername']));

// Validate user permissions and build domain/user filters
$to_user_filter = [];
$to_domain_filter = [];
$to_address = '';

switch ($_SESSION['user_type']) {
    case 'U': // User
        $sql1 = "SELECT filter FROM user_filters WHERE username='$myusername' AND active='Y'";
        $result1 = dbquery($sql1);
        $filter = [];
        while ($row = $result1->fetch_assoc()) {
            $filter[] = $row['filter'];
        }
        $user_filter = [];
        foreach ($filter as $user_filter_check) {
            if (preg_match('/^[^@]{1,64}@[^@]{1,255}$/', $user_filter_check)) {
                $user_filter[] = $user_filter_check;
            }
        }
        $user_filter[] = $myusername;
        foreach ($user_filter as $tempvar) {
            if (strpos($tempvar, '@')) {
                $ar = explode('@', $tempvar);
                $to_user_filter[] = $ar[0];
                $to_domain_filter[] = $ar[1];
            }
        }
        $to_user_filter = array_unique($to_user_filter);
        $to_domain_filter = array_unique($to_domain_filter);
        break;

    case 'D': // Domain Admin
        $sql1 = "SELECT filter FROM user_filters WHERE username='$myusername' AND active='Y'";
        $result1 = dbquery($sql1);
        while ($row = $result1->fetch_assoc()) {
            $to_domain_filter[] = $row['filter'];
        }
        if (strpos($_SESSION['myusername'], '@')) {
            $ar = explode('@', $_SESSION['myusername']);
            $to_domain_filter[] = $ar[1];
        } else {
            $to_domain_filter[] = $_SESSION['myusername'];
        }
        $to_domain_filter = array_unique($to_domain_filter);
        break;

    case 'A': // Administrator
        $to_address = 'default';
        break;
}

// Scope handling from new modern form
$scope_type = isset($_POST['scope_type']) ? trim($_POST['scope_type']) : '';
if ('A' === $_SESSION['user_type']) {
    if ('global' === $scope_type || empty($scope_type)) {
        if (!empty($url_to) || !empty($url_domain)) {
            if (!empty($url_to) && !empty($url_domain)) {
                $to_address = $url_to . '@' . $url_domain;
            } elseif (!empty($url_domain)) {
                $to_address = $url_domain;
            } else {
                $to_address = $url_to;
            }
        } else {
            $to_address = 'default';
            $url_domain = '';
        }
    } elseif ('domain' === $scope_type) {
        $to_address = $url_domain;
    } elseif ('user' === $scope_type) {
        $to_address = !empty($url_domain) ? ($url_to . '@' . $url_domain) : $url_to;
    }
} else {
    switch (true) {
        case !empty($url_to):
            $to_address = $url_to;
            if (!empty($url_domain)) {
                $to_address .= '@' . $url_domain;
            }
            break;
        case !empty($url_domain):
            $to_address = $url_domain;
            break;
    }
}

// -----------------------------------------------------------------------------
// POST: Add Entry
// -----------------------------------------------------------------------------
if ('add' === $url_submit) {
    if (false === checkToken($_POST['token'] ?? '')) {
        header('Location: login.php?error=pagetimeout');
        exit;
    }
    if (false === checkFormToken('/lists.php list token', $_POST['formtoken'] ?? '')) {
        header('Location: login.php?error=pagetimeout');
        exit;
    }

    if (empty($url_list)) {
        $errors[] = __('error071');
    }
    if (empty($from)) {
        $errors[] = __('error072');
    }

    $to_domain = strtolower($url_domain);
    if (empty($errors)) {
        $list = ('w' === $url_list) ? 'whitelist' : 'blacklist';
        $listi18 = ('w' === $url_list) ? __('wl07') : __('bl07');

        $sql = 'REPLACE INTO ' . $list . ' (to_address, to_domain, from_address) VALUES '
            . "('" . safe_value(stripslashes($to_address)) . "',"
            . "'" . safe_value($to_domain) . "',"
            . "'" . safe_value(stripslashes($from)) . "')";
        dbquery($sql);
        audit_log(sprintf(__('auditlogadded07', true), $from, $to_address, $listi18));
        $flash_success = sprintf(__('auditlogadded07', true), htmlspecialchars($from), htmlspecialchars($to_address), $listi18);

        $to_domain = '';
        $touser = '';
        $from = '';
        $url_list = '';
    } else {
        $flash_error = implode('<br>', $errors);
    }
}

// -----------------------------------------------------------------------------
// GET: Delete Single Entry
// -----------------------------------------------------------------------------
if ('delete' === $url_submit) {
    if (false === checkToken($_GET['token'] ?? '')) {
        header('Location: login.php?error=pagetimeout');
        exit;
    }
    $id = intval($url_id);
    $list = ('w' === $url_list) ? 'whitelist' : 'blacklist';
    $listi18 = ('w' === $url_list) ? __('wl07') : __('bl07');

    $sqlfrom = "SELECT from_address, to_address, to_domain FROM $list WHERE id='$id'";
    $result = dbquery($sqlfrom);
    if ($result && $row = $result->fetch_assoc()) {
        $from_address = $row['from_address'];
        $del_to = $row['to_address'];

        switch ($_SESSION['user_type']) {
            case 'U':
                $sql = "DELETE FROM $list WHERE id='$id' AND to_address='$del_to'";
                break;
            case 'D':
                $del_domain = $row['to_domain'];
                $sql = "DELETE FROM $list WHERE id='$id' AND to_domain='$del_domain'";
                break;
            case 'A':
            default:
                $sql = "DELETE FROM $list WHERE id='$id'";
                break;
        }

        dbquery($sql);
        audit_log(sprintf(__('auditlogremoved07', true), $from_address, $del_to, $listi18));
        $flash_success = sprintf(__('auditlogremoved07', true), htmlspecialchars($from_address), htmlspecialchars($del_to), $listi18);
    }
    $to_domain = '';
    $touser = '';
    $from = '';
    $url_list = '';
}

// -----------------------------------------------------------------------------
// POST: Bulk Delete Entries
// -----------------------------------------------------------------------------
if ('bulk_delete' === $url_submit) {
    if (false === checkToken($_POST['token'] ?? '')) {
        header('Location: login.php?error=pagetimeout');
        exit;
    }
    if (false === checkFormToken('/lists.php list token', $_POST['formtoken'] ?? '')) {
        header('Location: login.php?error=pagetimeout');
        exit;
    }

    $raw_items = $_POST['selected_rules'] ?? [];
    $del_count = 0;
    if (is_array($raw_items) && !empty($raw_items)) {
        foreach ($raw_items as $item_val) {
            $parts = explode(':', $item_val);
            if (count($parts) === 2) {
                $target_table = ($parts[0] === 'w') ? 'whitelist' : (($parts[0] === 'b') ? 'blacklist' : '');
                $target_id = intval($parts[1]);
                if ($target_table && $target_id > 0) {
                    switch ($_SESSION['user_type']) {
                        case 'A':
                            $del_sql = "DELETE FROM $target_table WHERE id = $target_id";
                            break;
                        case 'D':
                        case 'U':
                            $del_sql = "DELETE FROM $target_table WHERE id = $target_id AND (" . $_SESSION['global_list'] . ")";
                            break;
                    }
                    if (isset($del_sql)) {
                        dbquery($del_sql);
                        $del_count++;
                    }
                }
            }
        }
        if ($del_count > 0) {
            audit_log("Bulk removed $del_count entries from Allow/Block lists");
            $flash_success = "Successfully removed $del_count selected rule(s).";
        }
    }
}

// -----------------------------------------------------------------------------
// Load Entries from DB
// -----------------------------------------------------------------------------
$sql_w = 'SELECT id, from_address, to_address, to_domain FROM whitelist WHERE ' . $_SESSION['global_list'] . ' ORDER BY from_address ASC';
$res_w = dbquery($sql_w);
$whitelists = [];
while ($row = $res_w->fetch_assoc()) {
    $row['list_type'] = 'w';
    $whitelists[] = $row;
}

$sql_b = 'SELECT id, from_address, to_address, to_domain FROM blacklist WHERE ' . $_SESSION['global_list'] . ' ORDER BY from_address ASC';
$res_b = dbquery($sql_b);
$blacklists = [];
while ($row = $res_b->fetch_assoc()) {
    $row['list_type'] = 'b';
    $blacklists[] = $row;
}

$count_w = count($whitelists);
$count_b = count($blacklists);
$count_total = $count_w + $count_b;

html_start(__('wblists07'), 0, false, false);
?>

<style>
/* Modern Allowlist/Blocklist Interface Styles */
.lists-container {
    max-width: 1380px;
    margin: 10px auto 40px auto;
    padding: 0 12px;
    box-sizing: border-box;
}

/* Hero Stats Bar */
.lists-hero-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 22px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    gap: 16px;
    flex-wrap: wrap;
}

.lists-hero-title-group {
    display: flex;
    align-items: center;
    gap: 12px;
}

.lists-hero-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: linear-gradient(135deg, #1f6cb0 0%, #174e82 100%);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    box-shadow: 0 2px 5px rgba(31,108,176,0.25);
}

.lists-hero-title {
    font-size: 19px;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    line-height: 1.2;
}

.lists-hero-subtitle {
    font-size: 12px;
    color: #64748b;
    margin: 3px 0 0 0;
}

/* KPI Badges */
.lists-kpi-group {
    display: flex;
    gap: 10px;
    align-items: center;
}

.lists-kpi-card {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
    color: inherit;
}

.lists-kpi-card:hover {
    border-color: #cbd5e1;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.04);
}

.lists-kpi-card.kpi-allow {
    background: #f0fdf4;
    border-color: #bbf7d0;
}
.lists-kpi-card.kpi-allow .kpi-number {
    color: #16a34a;
}

.lists-kpi-card.kpi-block {
    background: #fef2f2;
    border-color: #fecaca;
}
.lists-kpi-card.kpi-block .kpi-number {
    color: #dc2626;
}

.lists-kpi-card.kpi-total {
    background: #eff6ff;
    border-color: #bfdbfe;
}
.lists-kpi-card.kpi-total .kpi-number {
    color: #1f6cb0;
}

.kpi-icon {
    font-size: 16px;
    line-height: 1;
}

.kpi-label {
    font-size: 11px;
    font-weight: 600;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.kpi-number {
    font-size: 15px;
    font-weight: 800;
    line-height: 1;
}

/* Alert Notification */
.lists-alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 18px;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.lists-alert-success {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}
.lists-alert-error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.lists-alert-close {
    cursor: pointer;
    background: none;
    border: none;
    font-size: 18px;
    font-weight: bold;
    color: inherit;
    line-height: 1;
    opacity: 0.6;
}
.lists-alert-close:hover { opacity: 1; }

/* Add Rule Card */
.add-rule-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    margin-bottom: 22px;
    overflow: hidden;
}

.add-rule-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    cursor: pointer;
    user-select: none;
}

.add-rule-card-title {
    font-size: 14.5px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

.add-rule-card-body {
    padding: 20px;
}

/* Segmented Control for List Type */
.segmented-picker {
    display: inline-flex;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    gap: 4px;
}

.segmented-option {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
    color: #64748b;
}

.segmented-option input[type="radio"] {
    display: none;
}

.segmented-option.active-allow {
    background: #ffffff;
    color: #16a34a;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #bbf7d0;
}

.segmented-option.active-block {
    background: #ffffff;
    color: #dc2626;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #fecaca;
}

/* Form Layout */
.form-grid-3 {
    display: grid;
    grid-template-columns: 240px 1fr 1fr;
    gap: 16px;
    align-items: end;
    margin-top: 14px;
}

@media (max-width: 900px) {
    .form-grid-3 {
        grid-template-columns: 1fr;
    }
}

.form-group-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.form-group-item label {
    font-size: 12px;
    font-weight: 600;
    color: #334155;
}

.form-input-modern {
    height: 38px;
    padding: 0 12px;
    font-size: 13px;
    color: #1e293b;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    box-sizing: border-box;
    width: 100%;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.form-input-modern:focus {
    border-color: #1f6cb0;
    outline: none;
    box-shadow: 0 0 0 3px rgba(31,108,176,0.15);
}

.form-help-hint {
    font-size: 11px;
    color: #64748b;
    margin-top: 2px;
}

/* Form Buttons */
.form-actions-row {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid #f1f5f9;
}

.btn-modern-primary {
    background: #1f6cb0;
    color: #ffffff;
    border: none;
    border-radius: 6px;
    padding: 8px 18px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.15s ease;
}
.btn-modern-primary:hover { background: #185890; }

.btn-modern-secondary {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 8px 14px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
}
.btn-modern-secondary:hover { background: #e2e8f0; color: #1e293b; }

/* Table Container & Filter Tabs */
.rules-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    overflow: hidden;
}

.rules-tabs-nav {
    display: flex;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 0 12px;
    gap: 4px;
}

.rules-tab {
    padding: 12px 18px;
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 7px;
    transition: all 0.15s ease;
}

.rules-tab:hover {
    color: #1e293b;
    background: #f1f5f9;
}

.rules-tab.active {
    color: #1f6cb0;
    border-bottom-color: #1f6cb0;
    background: #ffffff;
}

.tab-badge {
    padding: 2px 7px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
    background: #e2e8f0;
    color: #475569;
}
.rules-tab.active .tab-badge {
    background: #dbeafe;
    color: #1d4ed8;
}

/* Toolbar: Search, Filters & Bulk Actions */
.rules-toolbar {
    padding: 14px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
    flex-wrap: wrap;
}

.toolbar-left-group {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1 1 650px;
    flex-wrap: wrap;
}

.search-input-wrapper {
    position: relative;
    flex: 2 1 360px;
    min-width: 320px;
    display: flex;
    align-items: center;
}

.search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    pointer-events: none;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    line-height: 1;
    z-index: 2;
}

input[type="text"].search-input,
.search-input {
    width: 100% !important;
    min-width: 320px !important;
    height: 38px !important;
    padding-left: 40px !important;
    padding-right: 34px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    font-size: 13px !important;
    box-sizing: border-box !important;
    background: #ffffff !important;
    color: #1e293b !important;
}

input[type="text"].search-input:focus,
.search-input:focus {
    border-color: #1f6cb0 !important;
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(31,108,176,0.15) !important;
}

.search-clear-btn {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    background: none;
    border: none;
    color: #94a3b8;
    font-size: 18px;
    line-height: 1;
    padding: 2px 6px;
    display: none;
    z-index: 2;
}
.search-clear-btn:hover { color: #475569; }

select.filter-select,
.filter-select {
    height: 38px !important;
    padding: 0 12px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    font-size: 12.5px !important;
    color: #334155 !important;
    background-color: #ffffff !important;
    cursor: pointer;
    flex: 1 1 180px;
    min-width: 160px;
}

.toolbar-right-group {
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Bulk Toolbar (shows when rows checked) */
.bulk-toolbar-bar {
    display: none;
    align-items: center;
    justify-content: space-between;
    padding: 10px 18px;
    background: #eff6ff;
    border-bottom: 1px solid #bfdbfe;
    font-size: 13px;
    color: #1e3a8a;
    font-weight: 600;
}

.btn-danger-sm {
    background: #dc2626;
    color: #ffffff;
    border: none;
    border-radius: 5px;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: background 0.15s ease;
}
.btn-danger-sm:hover { background: #b91c1c; }

/* Table Styling */
.table-responsive-wrapper {
    overflow-x: auto;
}

.lists-modern-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
    text-align: left;
}

.lists-modern-table th {
    background: #f8fafc;
    padding: 10px 14px;
    font-size: 11.5px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #e2e8f0;
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
}

.lists-modern-table th:hover {
    background: #f1f5f9;
    color: #0f172a;
}

.lists-modern-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    vertical-align: middle;
}

.lists-modern-table tr:hover td {
    background: #f8fafc;
}

/* Badges */
.badge-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.3px;
    line-height: 1;
}

.badge-pill.pill-allow {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
}

.badge-pill.pill-block {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.badge-scope-global {
    background: #e0f2fe;
    color: #0369a1;
    border: 1px solid #bae6fd;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 600;
}

.badge-scope-domain {
    background: #f3e8ff;
    color: #7e22ce;
    border: 1px solid #e9d5ff;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 600;
}

.badge-scope-user {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #cbd5e1;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 600;
}

.sender-text {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 12px;
    font-weight: 600;
    color: #0f172a;
}

.btn-row-delete {
    color: #94a3b8;
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 13px;
    text-decoration: none;
    transition: all 0.15s ease;
}
.btn-row-delete:hover {
    color: #dc2626;
    background: #fee2e2;
}

.btn-row-copy {
    color: #94a3b8;
    background: none;
    border: none;
    cursor: pointer;
    padding: 2px 4px;
    margin-left: 4px;
    font-size: 12px;
    opacity: 0.5;
}
.btn-row-copy:hover {
    opacity: 1;
    color: #1f6cb0;
}

/* Pagination Footer */
.rules-pagination-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 18px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    font-size: 12px;
    color: #64748b;
    flex-wrap: wrap;
    gap: 10px;
}

.pagination-controls {
    display: flex;
    gap: 4px;
}

.page-btn {
    padding: 5px 10px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    border-radius: 5px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    color: #334155;
    transition: all 0.15s ease;
}

.page-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #94a3b8;
}

.page-btn.active {
    background: #1f6cb0;
    color: #ffffff;
    border-color: #1f6cb0;
}

.page-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* Empty State */
.empty-table-state {
    text-align: center;
    padding: 45px 20px;
    color: #64748b;
}
.empty-icon {
    font-size: 36px;
    color: #cbd5e1;
    margin-bottom: 10px;
}
.empty-title {
    font-size: 15px;
    font-weight: 700;
    color: #334155;
    margin: 0 0 5px 0;
}
.empty-desc {
    font-size: 12px;
    margin: 0;
}
</style>

<div class="lists-container">

    <!-- Hero Header with KPIs -->
    <div class="lists-hero-bar">
        <div class="lists-hero-title-group">
            <div class="lists-hero-icon">🛡️</div>
            <div>
                <h1 class="lists-hero-title"><?= __('wblists07') ?></h1>
                <p class="lists-hero-subtitle">Manage sender bypass rules (Allowlist) and sender blocking rules (Blocklist).</p>
            </div>
        </div>

        <div class="lists-kpi-group">
            <div class="lists-kpi-card kpi-allow" onclick="switchListTab('w')" title="Show Allowlist rules">
                <span class="kpi-icon">✔</span>
                <div>
                    <div class="kpi-label"><?= __('wl07') ?></div>
                    <div class="kpi-number" id="kpiAllowCount"><?= number_format($count_w) ?></div>
                </div>
            </div>

            <div class="lists-kpi-card kpi-block" onclick="switchListTab('b')" title="Show Blocklist rules">
                <span class="kpi-icon">✖</span>
                <div>
                    <div class="kpi-label"><?= __('bl07') ?></div>
                    <div class="kpi-number" id="kpiBlockCount"><?= number_format($count_b) ?></div>
                </div>
            </div>

            <div class="lists-kpi-card kpi-total" onclick="switchListTab('all')" title="Show All active rules">
                <span class="kpi-icon">📊</span>
                <div>
                    <div class="kpi-label">Total</div>
                    <div class="kpi-number" id="kpiTotalCount"><?= number_format($count_total) ?></div>
                </div>
            </div>

            <button type="button" class="btn-modern-primary" onclick="toggleAddForm()" style="margin-left: 6px;">
                <span>+</span> Add Rule
            </button>
        </div>
    </div>

    <!-- Feedback Alerts -->
    <?php if (!empty($flash_success)): ?>
    <div class="lists-alert lists-alert-success" id="flashAlert">
        <div><strong>✔ Success:</strong> <?= $flash_success ?></div>
        <button type="button" class="lists-alert-close" onclick="document.getElementById('flashAlert').style.display='none'">&times;</button>
    </div>
    <?php endif; ?>

    <?php if (!empty($flash_error)): ?>
    <div class="lists-alert lists-alert-error" id="flashAlertErr">
        <div><strong>✖ Error:</strong> <?= $flash_error ?></div>
        <button type="button" class="lists-alert-close" onclick="document.getElementById('flashAlertErr').style.display='none'">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Collapsible Add Rule Form -->
    <div class="add-rule-card" id="addRuleCard" style="<?= (!empty($from) || !empty($url_to) || !empty($flash_error)) ? '' : 'display: none;' ?>">
        <div class="add-rule-card-header" onclick="toggleAddForm()">
            <div class="add-rule-card-title">
                <span>➕</span> <?= __('addwlbl07') ?>
            </div>
            <span id="addFormToggleIcon" style="font-size: 13px; color: #64748b;">▲ Collapse</span>
        </div>

        <div class="add-rule-card-body">
            <form action="lists.php" method="post" id="addRuleForm">
                <input type="hidden" name="token" value="<?= $_SESSION['token'] ?>">
                <input type="hidden" name="formtoken" value="<?= generateFormToken('/lists.php list token') ?>">
                <input type="hidden" name="submit" value="add">

                <!-- List Type Selector -->
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;"><?= __('list07') ?></label>
                    <div class="segmented-picker">
                        <label class="segmented-option <?= ('b' !== $url_list) ? 'active-allow' : '' ?>" id="labelListW">
                            <input type="radio" name="list" value="w" <?= ('b' !== $url_list) ? 'checked' : '' ?> onchange="updateListTypeRadio()">
                            <span>✔</span> <?= __('wl07') ?> (Pass / Bypass Spam Checks)
                        </label>
                        <label class="segmented-option <?= ('b' === $url_list) ? 'active-block' : '' ?>" id="labelListB">
                            <input type="radio" name="list" value="b" <?= ('b' === $url_list) ? 'checked' : '' ?> onchange="updateListTypeRadio()">
                            <span>✖</span> <?= __('bl07') ?> (Block / Reject Delivery)
                        </label>
                    </div>
                </div>

                <!-- Fields Grid -->
                <div class="form-grid-3">
                    <!-- Sender (From) -->
                    <div class="form-group-item">
                        <label for="fromInput"><?= __('from07') ?> (Sender Address / Domain / IP)</label>
                        <input type="text" id="fromInput" name="from" class="form-input-modern" placeholder="e.g. user@example.com, @domain.com, or IP" value="<?= htmlspecialchars($from) ?>" required>
                        <span class="form-help-hint">Supports full email, domain (@example.com), hostname, or IP address.</span>
                    </div>

                    <!-- Scope selection for Admin -->
                    <?php if ('A' === $_SESSION['user_type']): ?>
                    <div class="form-group-item">
                        <label for="scopeTypeSelect">Recipient Scope</label>
                        <select id="scopeTypeSelect" name="scope_type" class="form-input-modern" onchange="updateAdminScopeFields()">
                            <option value="global" selected>Global Rule (All Recipients / Default)</option>
                            <option value="domain" <?= (!empty($url_domain) && empty($url_to)) ? 'selected' : '' ?>>Specific Domain (@domain.com)</option>
                            <option value="user" <?= (!empty($url_to) && !empty($url_domain)) ? 'selected' : '' ?>>Specific Mailbox (user@domain.com)</option>
                        </select>
                        <span class="form-help-hint">Choose whether rule applies system-wide or to a specific target.</span>
                    </div>

                    <div class="form-group-item" id="adminSpecificTargetGroup" style="display: <?= (!empty($url_domain) || !empty($url_to)) ? 'flex' : 'none' ?>;">
                        <label id="targetLabel"><?= __('to07') ?> (Target Details)</label>
                        <div style="display: flex; gap: 6px; align-items: center;">
                            <input type="text" id="targetUserInput" name="to" class="form-input-modern" placeholder="user" value="<?= htmlspecialchars($touser) ?>" style="display: <?= (!empty($url_to)) ? 'block' : 'none' ?>;">
                            <span id="targetAtSign" style="color: #64748b; font-weight: bold; display: <?= (!empty($url_to)) ? 'inline' : 'none' ?>;">@</span>
                            <input type="text" id="targetDomainInput" name="domain" class="form-input-modern" placeholder="domain.com" value="<?= htmlspecialchars($to_domain) ?>">
                        </div>
                        <span class="form-help-hint" id="targetHint">Specify destination domain or user.</span>
                    </div>
                    <?php elseif ('D' === $_SESSION['user_type']): ?>
                    <!-- Domain Admin Form -->
                    <div class="form-group-item">
                        <label for="domainAdminTo"><?= __('to07') ?> (User Mailbox)</label>
                        <input type="text" id="domainAdminTo" name="to" class="form-input-modern" placeholder="Leave empty for whole domain or enter user" value="<?= htmlspecialchars($touser) ?>">
                        <span class="form-help-hint">Optional mailbox username (or blank for entire domain).</span>
                    </div>
                    <div class="form-group-item">
                        <label for="domainSelect">Target Domain</label>
                        <select id="domainSelect" name="domain" class="form-input-modern">
                            <?php foreach ($to_domain_filter as $dom_opt): ?>
                            <option value="<?= htmlspecialchars($dom_opt) ?>" <?= ($to_domain === $dom_opt) ? 'selected' : '' ?>><?= htmlspecialchars($dom_opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php elseif ('U' === $_SESSION['user_type']): ?>
                    <!-- User Form -->
                    <div class="form-group-item">
                        <label for="userSelect"><?= __('to07') ?> (Your Mailbox)</label>
                        <select id="userSelect" name="to" class="form-input-modern">
                            <?php foreach ($to_user_filter as $user_opt): ?>
                            <option value="<?= htmlspecialchars($user_opt) ?>" <?= ($touser === $user_opt) ? 'selected' : '' ?>><?= htmlspecialchars($user_opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-item">
                        <label for="domainSelect">Domain</label>
                        <select id="domainSelect" name="domain" class="form-input-modern">
                            <?php foreach ($to_domain_filter as $dom_opt): ?>
                            <option value="<?= htmlspecialchars($dom_opt) ?>" <?= ($to_domain === $dom_opt) ? 'selected' : '' ?>><?= htmlspecialchars($dom_opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Form Action Buttons -->
                <div class="form-actions-row">
                    <button type="reset" class="btn-modern-secondary" onclick="resetAddForm()"><?= __('reset07') ?></button>
                    <button type="submit" class="btn-modern-primary">
                        <span>➕</span> <?= __('add07') ?> Rule
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Rules Management Card -->
    <div class="rules-card">
        <!-- Tabs Header -->
        <div class="rules-tabs-nav">
            <div class="rules-tab active" id="tabAll" onclick="switchListTab('all')">
                <span>📋</span> All Active Rules <span class="tab-badge" id="tabAllBadge"><?= number_format($count_total) ?></span>
            </div>
            <div class="rules-tab" id="tabAllow" onclick="switchListTab('w')">
                <span>✔</span> <?= __('wl07') ?> <span class="tab-badge" id="tabAllowBadge" style="background:#dcfce7; color:#15803d;"><?= number_format($count_w) ?></span>
            </div>
            <div class="rules-tab" id="tabBlock" onclick="switchListTab('b')">
                <span>✖</span> <?= __('bl07') ?> <span class="tab-badge" id="tabBlockBadge" style="background:#fee2e2; color:#b91c1c;"><?= number_format($count_b) ?></span>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="rules-toolbar">
            <div class="toolbar-left-group">
                <!-- Live Search Box -->
                <div class="search-input-wrapper">
                    <span class="search-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </span>
                    <input type="text" id="filterSearchInput" class="search-input" placeholder="Search by sender, domain, recipient, or IP address..." oninput="handleSearchChange(this.value)">
                    <button type="button" class="search-clear-btn" id="searchClearBtn" onclick="clearSearchInput()" title="Clear search">&times;</button>
                </div>

                <!-- Scope Filter -->
                <select id="scopeFilter" class="filter-select" onchange="handleFilterChange()">
                    <option value="">All Scopes</option>
                    <option value="global">Global Rules (default)</option>
                    <option value="domain">Domain Rules (@...)</option>
                    <option value="user">User Rules (user@...)</option>
                </select>

                <!-- Pattern Type Filter -->
                <select id="patternFilter" class="filter-select" onchange="handleFilterChange()">
                    <option value="">All Senders</option>
                    <option value="email">Email Addresses</option>
                    <option value="domain">Domains (@...)</option>
                    <option value="ip">IP Addresses</option>
                </select>
            </div>

            <div class="toolbar-right-group">
                <!-- Page Size -->
                <select id="pageSizeSelect" class="filter-select" onchange="handlePageSizeChange(this.value)">
                    <option value="25" selected>25 / page</option>
                    <option value="50">50 / page</option>
                    <option value="100">100 / page</option>
                    <option value="250">250 / page</option>
                    <option value="all">All (no pagination)</option>
                </select>

                <!-- CSV Export Button -->
                <button type="button" class="btn-modern-secondary" onclick="exportFilteredCsv()" title="Export currently filtered list to CSV">
                    <span>⬇</span> Export CSV
                </button>
            </div>
        </div>

        <!-- Bulk Action Floating Bar -->
        <div class="bulk-toolbar-bar" id="bulkActionBar">
            <div>
                Selected: <span id="selectedCountText">0</span> rule(s)
            </div>
            <div>
                <form action="lists.php" method="post" id="bulkDeleteForm" onsubmit="return confirmBulkDelete();" style="display:inline;">
                    <input type="hidden" name="token" value="<?= $_SESSION['token'] ?>">
                    <input type="hidden" name="formtoken" value="<?= generateFormToken('/lists.php list token') ?>">
                    <input type="hidden" name="submit" value="bulk_delete">
                    <div id="bulkSelectedInputsContainer" style="display:none;"></div>
                    <button type="submit" class="btn-danger-sm">
                        <span>🗑</span> Delete Selected
                    </button>
                </form>
            </div>
        </div>

        <!-- Table Container -->
        <div class="table-responsive-wrapper">
            <table class="lists-modern-table" id="rulesDataTable">
                <thead>
                    <tr>
                        <th style="width: 38px; text-align: center;">
                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this.checked)" title="Select all on current page">
                        </th>
                        <th style="width: 110px;" onclick="handleSortColumn('type')">
                            Type <span id="sort_icon_type">↕</span>
                        </th>
                        <th onclick="handleSortColumn('from')">
                            <?= __('from07') ?> Sender Address / Pattern <span id="sort_icon_from">▲</span>
                        </th>
                        <th onclick="handleSortColumn('to')">
                            <?= __('to07') ?> Recipient Scope <span id="sort_icon_to">↕</span>
                        </th>
                        <th style="width: 85px; text-align: right; cursor: default;">
                            Action
                        </th>
                    </tr>
                </thead>
                <tbody id="rulesTableBody">
                </tbody>
            </table>

            <!-- Empty State Display -->
            <div id="emptyTableState" class="empty-table-state" style="display: none;">
                <div class="empty-icon">🔍</div>
                <h4 class="empty-title">No matching rules found</h4>
                <p class="empty-desc">Try clearing or adjusting your search term and filters.</p>
                <button type="button" class="btn-modern-secondary" onclick="resetAllFilters()" style="margin-top: 12px;">
                    Reset Filters
                </button>
            </div>
        </div>

        <!-- Pagination Controls Footer -->
        <div class="rules-pagination-footer" id="rulesPaginationFooter">
            <div id="paginationInfoText">
                Showing 0 to 0 of 0 entries
            </div>

            <div class="pagination-controls" id="paginationButtons">
            </div>
        </div>
    </div>

</div>

<!-- Data Store & Interactive Client Logic -->
<script>
(function() {
    'use strict';

    var CSRF_TOKEN = <?= json_encode($_SESSION['token']) ?>;
    var rawWhitelist = <?= json_encode($whitelists) ?>;
    var rawBlacklist = <?= json_encode($blacklists) ?>;

    function classifyRule(item) {
        var from = item.from_address || '';
        var to = item.to_address || '';
        var domain = item.to_domain || '';

        var pType = 'email';
        if (/^[\d\.\:\/]+$/.test(from)) {
            pType = 'ip';
        } else if (from.startsWith('@') || (!from.includes('@') && from.includes('.'))) {
            pType = 'domain';
        }

        var sType = 'user';
        if (to === 'default' || (to === '' && domain === '')) {
            sType = 'global';
        } else if (!to.includes('@') && (to === domain || to.startsWith('@'))) {
            sType = 'domain';
        }

        return {
            id: parseInt(item.id, 10),
            type: item.list_type,
            from: from,
            to: to,
            domain: domain,
            patternType: pType,
            scopeType: sType,
            key: item.list_type + ':' + item.id
        };
    }

    var allRules = [];
    rawWhitelist.forEach(function(r) { allRules.push(classifyRule(r)); });
    rawBlacklist.forEach(function(r) { allRules.push(classifyRule(r)); });

    var currentTab = 'all';
    var searchQuery = '';
    var scopeFilterVal = '';
    var patternFilterVal = '';
    var sortColumn = 'from';
    var sortDirection = 'asc';
    var currentPage = 1;
    var pageSize = 25;
    var selectedKeys = {};

    function getFilteredData() {
        return allRules.filter(function(r) {
            if (currentTab !== 'all' && r.type !== currentTab) {
                return false;
            }
            if (scopeFilterVal && r.scopeType !== scopeFilterVal) {
                return false;
            }
            if (patternFilterVal && r.patternType !== patternFilterVal) {
                return false;
            }
            if (searchQuery) {
                var q = searchQuery.toLowerCase();
                var matchFrom = r.from.toLowerCase().includes(q);
                var matchTo = r.to.toLowerCase().includes(q);
                var matchDomain = r.domain.toLowerCase().includes(q);
                var matchId = r.id.toString().includes(q);
                if (!matchFrom && !matchTo && !matchDomain && !matchId) {
                    return false;
                }
            }
            return true;
        }).sort(function(a, b) {
            var valA = '';
            var valB = '';
            if (sortColumn === 'type') {
                valA = a.type;
                valB = b.type;
            } else if (sortColumn === 'from') {
                valA = a.from.toLowerCase();
                valB = b.from.toLowerCase();
            } else if (sortColumn === 'to') {
                valA = a.to.toLowerCase();
                valB = b.to.toLowerCase();
            } else if (sortColumn === 'id') {
                return sortDirection === 'asc' ? (a.id - b.id) : (b.id - a.id);
            }

            if (valA < valB) return sortDirection === 'asc' ? -1 : 1;
            if (valA > valB) return sortDirection === 'asc' ? 1 : -1;
            return 0;
        });
    }

    function renderTable() {
        var filtered = getFilteredData();
        var tbody = document.getElementById('rulesTableBody');
        var emptyState = document.getElementById('emptyTableState');
        var paginationFooter = document.getElementById('rulesPaginationFooter');
        var selectAllCheckbox = document.getElementById('selectAllCheckbox');

        ['type', 'from', 'to'].forEach(function(col) {
            var iconEl = document.getElementById('sort_icon_' + col);
            if (iconEl) {
                if (sortColumn === col) {
                    iconEl.textContent = sortDirection === 'asc' ? '▲' : '▼';
                    iconEl.style.color = '#1f6cb0';
                } else {
                    iconEl.textContent = '↕';
                    iconEl.style.color = '#94a3b8';
                }
            }
        });

        if (filtered.length === 0) {
            tbody.innerHTML = '';
            emptyState.style.display = 'block';
            paginationFooter.style.display = 'none';
            selectAllCheckbox.checked = false;
            selectAllCheckbox.disabled = true;
            return;
        }

        emptyState.style.display = 'none';
        paginationFooter.style.display = 'flex';
        selectAllCheckbox.disabled = false;

        var total = filtered.length;
        var limit = (pageSize === 'all') ? total : parseInt(pageSize, 10);
        var totalPages = Math.ceil(total / limit) || 1;
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        var startIdx = (currentPage - 1) * limit;
        var endIdx = Math.min(startIdx + limit, total);
        var pageItems = filtered.slice(startIdx, endIdx);

        var rowsHtml = '';
        var pageSelectedCount = 0;

        pageItems.forEach(function(r) {
            var isChecked = !!selectedKeys[r.key];
            if (isChecked) pageSelectedCount++;

            var typeBadge = (r.type === 'w')
                ? '<span class="badge-pill pill-allow">✔ ALLOW</span>'
                : '<span class="badge-pill pill-block">✖ BLOCK</span>';

            var scopeBadge = '';
            if (r.scopeType === 'global') {
                scopeBadge = '<span class="badge-scope-global">Global (Default)</span>';
            } else if (r.scopeType === 'domain') {
                scopeBadge = '<span class="badge-scope-domain">@' + escapeHtml(r.to.replace(/^@/, '')) + '</span>';
            } else {
                scopeBadge = '<span class="badge-scope-user">' + escapeHtml(r.to) + '</span>';
            }

            var patternIcon = '📧';
            if (r.patternType === 'domain') patternIcon = '🌐';
            if (r.patternType === 'ip') patternIcon = '🖥️';

            var delLink = 'lists.php?token=' + encodeURIComponent(CSRF_TOKEN)
                + '&amp;submit=delete&amp;list=' + r.type
                + '&amp;listid=' + r.id
                + '&amp;to=' + encodeURIComponent(r.to);

            rowsHtml += '<tr data-key="' + r.key + '">'
                + '<td style="text-align: center;"><input type="checkbox" class="row-checkbox" value="' + r.key + '" ' + (isChecked ? 'checked' : '') + ' onchange="window.handleRowCheck(\'' + r.key + '\', this.checked)"></td>'
                + '<td>' + typeBadge + '</td>'
                + '<td>'
                    + '<span style="margin-right: 5px;" title="' + r.patternType + '">' + patternIcon + '</span>'
                    + '<span class="sender-text">' + escapeHtml(r.from) + '</span>'
                    + '<button type="button" class="btn-row-copy" onclick="window.copyToClipboard(\'' + escapeJs(r.from) + '\')" title="Copy sender to clipboard">📋</button>'
                + '</td>'
                + '<td>' + scopeBadge + '</td>'
                + '<td style="text-align: right;">'
                    + '<a href="' + delLink + '" class="btn-row-delete" onclick="return confirm(\'Delete rule for ' + escapeJs(r.from) + '?\');" title="Delete rule">🗑 Delete</a>'
                + '</td>'
                + '</tr>';
        });

        tbody.innerHTML = rowsHtml;
        selectAllCheckbox.checked = (pageItems.length > 0 && pageSelectedCount === pageItems.length);

        var infoText = 'Showing ' + (startIdx + 1) + ' to ' + endIdx + ' of ' + total + ' entries';
        if (total < allRules.length) {
            infoText += ' (filtered from ' + allRules.length + ' total)';
        }
        document.getElementById('paginationInfoText').textContent = infoText;

        renderPaginationButtons(totalPages);
        updateBulkBar();
    }

    function renderPaginationButtons(totalPages) {
        var container = document.getElementById('paginationButtons');
        if (pageSize === 'all' || totalPages <= 1) {
            container.innerHTML = '';
            return;
        }

        var html = '';
        html += '<button type="button" class="page-btn" ' + (currentPage === 1 ? 'disabled' : '') + ' onclick="window.goToPage(1)">&laquo;</button>';
        html += '<button type="button" class="page-btn" ' + (currentPage === 1 ? 'disabled' : '') + ' onclick="window.goToPage(' + (currentPage - 1) + ')">&lsaquo;</button>';

        var startP = Math.max(1, currentPage - 2);
        var endP = Math.min(totalPages, currentPage + 2);

        for (var p = startP; p <= endP; p++) {
            html += '<button type="button" class="page-btn ' + (p === currentPage ? 'active' : '') + '" onclick="window.goToPage(' + p + ')">' + p + '</button>';
        }

        html += '<button type="button" class="page-btn" ' + (currentPage === totalPages ? 'disabled' : '') + ' onclick="window.goToPage(' + (currentPage + 1) + ')">&rsaquo;</button>';
        html += '<button type="button" class="page-btn" ' + (currentPage === totalPages ? 'disabled' : '') + ' onclick="window.goToPage(' + totalPages + ')">&raquo;</button>';

        container.innerHTML = html;
    }

    function updateBulkBar() {
        var bulkBar = document.getElementById('bulkActionBar');
        var keys = Object.keys(selectedKeys);
        var count = keys.length;
        document.getElementById('selectedCountText').textContent = count;

        if (count > 0) {
            bulkBar.style.display = 'flex';
            var inputsHtml = '';
            keys.forEach(function(k) {
                inputsHtml += '<input type="hidden" name="selected_rules[]" value="' + escapeHtml(k) + '">';
            });
            document.getElementById('bulkSelectedInputsContainer').innerHTML = inputsHtml;
        } else {
            bulkBar.style.display = 'none';
            document.getElementById('bulkSelectedInputsContainer').innerHTML = '';
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeJs(str) {
        if (!str) return '';
        return String(str).replace(/'/g, "\\'").replace(/"/g, '\\"');
    }

    window.switchListTab = function(tab) {
        currentTab = tab;
        currentPage = 1;
        document.getElementById('tabAll').classList.toggle('active', tab === 'all');
        document.getElementById('tabAllow').classList.toggle('active', tab === 'w');
        document.getElementById('tabBlock').classList.toggle('active', tab === 'b');
        renderTable();
    };

    window.handleSearchChange = function(val) {
        searchQuery = val.trim();
        currentPage = 1;
        document.getElementById('searchClearBtn').style.display = searchQuery ? 'block' : 'none';
        renderTable();
    };

    window.clearSearchInput = function() {
        var input = document.getElementById('filterSearchInput');
        input.value = '';
        window.handleSearchChange('');
        input.focus();
    };

    window.handleFilterChange = function() {
        scopeFilterVal = document.getElementById('scopeFilter').value;
        patternFilterVal = document.getElementById('patternFilter').value;
        currentPage = 1;
        renderTable();
    };

    window.handlePageSizeChange = function(size) {
        pageSize = size;
        currentPage = 1;
        renderTable();
    };

    window.handleSortColumn = function(col) {
        if (sortColumn === col) {
            sortDirection = (sortDirection === 'asc') ? 'desc' : 'asc';
        } else {
            sortColumn = col;
            sortDirection = 'asc';
        }
        renderTable();
    };

    window.goToPage = function(page) {
        currentPage = page;
        renderTable();
        var tableEl = document.getElementById('rulesDataTable');
        if (tableEl) tableEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    window.handleRowCheck = function(key, isChecked) {
        if (isChecked) {
            selectedKeys[key] = true;
        } else {
            delete selectedKeys[key];
        }
        renderTable();
    };

    window.toggleSelectAll = function(isChecked) {
        var filtered = getFilteredData();
        var limit = (pageSize === 'all') ? filtered.length : parseInt(pageSize, 10);
        var startIdx = (currentPage - 1) * limit;
        var endIdx = Math.min(startIdx + limit, filtered.length);
        var pageItems = filtered.slice(startIdx, endIdx);

        pageItems.forEach(function(r) {
            if (isChecked) {
                selectedKeys[r.key] = true;
            } else {
                delete selectedKeys[r.key];
            }
        });
        renderTable();
    };

    window.confirmBulkDelete = function() {
        var count = Object.keys(selectedKeys).length;
        if (count === 0) return false;
        return confirm('Are you sure you want to permanently delete the ' + count + ' selected rule(s)?');
    };

    window.resetAllFilters = function() {
        searchQuery = '';
        scopeFilterVal = '';
        patternFilterVal = '';
        document.getElementById('filterSearchInput').value = '';
        document.getElementById('searchClearBtn').style.display = 'none';
        document.getElementById('scopeFilter').value = '';
        document.getElementById('patternFilter').value = '';
        window.switchListTab('all');
    };

    window.copyToClipboard = function(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text);
        } else {
            var el = document.createElement('textarea');
            el.value = text;
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
        }
    };

    window.exportFilteredCsv = function() {
        var filtered = getFilteredData();
        if (filtered.length === 0) {
            alert('No entries to export.');
            return;
        }
        var csv = 'Type,Sender (From),Recipient (To),Domain,ID\n';
        filtered.forEach(function(r) {
            var typeName = (r.type === 'w') ? 'Allowlist' : 'Blocklist';
            csv += '"' + typeName + '","' + r.from.replace(/"/g, '""') + '","' + r.to.replace(/"/g, '""') + '","' + r.domain.replace(/"/g, '""') + '",' + r.id + '\n';
        });

        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        var url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', 'efa_rules_' + currentTab + '_' + (new Date().toISOString().slice(0,10)) + '.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    window.toggleAddForm = function() {
        var formCard = document.getElementById('addRuleCard');
        var icon = document.getElementById('addFormToggleIcon');
        if (formCard.style.display === 'none') {
            formCard.style.display = 'block';
            if (icon) icon.textContent = '▲ Collapse';
            document.getElementById('fromInput').focus();
            formCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            formCard.style.display = 'none';
            if (icon) icon.textContent = '▼ Expand';
        }
    };

    window.updateListTypeRadio = function() {
        var w = document.querySelector('input[name="list"][value="w"]').checked;
        var labelW = document.getElementById('labelListW');
        var labelB = document.getElementById('labelListB');
        labelW.classList.toggle('active-allow', w);
        labelB.classList.toggle('active-block', !w);
    };

    window.updateAdminScopeFields = function() {
        var sel = document.getElementById('scopeTypeSelect');
        if (!sel) return;
        var val = sel.value;
        var group = document.getElementById('adminSpecificTargetGroup');
        var userInput = document.getElementById('targetUserInput');
        var atSign = document.getElementById('targetAtSign');
        var domainInput = document.getElementById('targetDomainInput');
        var hint = document.getElementById('targetHint');

        if (val === 'global') {
            group.style.display = 'none';
        } else if (val === 'domain') {
            group.style.display = 'flex';
            userInput.style.display = 'none';
            atSign.style.display = 'none';
            domainInput.style.display = 'block';
            domainInput.placeholder = 'e.g. domain.com';
            hint.textContent = 'Rule will apply to all recipients under @domain.com';
        } else if (val === 'user') {
            group.style.display = 'flex';
            userInput.style.display = 'block';
            atSign.style.display = 'inline';
            domainInput.style.display = 'block';
            domainInput.placeholder = 'domain.com';
            hint.textContent = 'Rule will apply only to this specific user mailbox.';
        }
    };

    window.resetAddForm = function() {
        document.getElementById('addRuleForm').reset();
        window.updateListTypeRadio();
        window.updateAdminScopeFields();
    };

    document.addEventListener('DOMContentLoaded', function() {
        renderTable();
    });

    if (document.readyState !== 'loading') {
        renderTable();
    }
})();
</script>

<?php
html_end();
dbclose();
