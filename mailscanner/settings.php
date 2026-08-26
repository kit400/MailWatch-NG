<?php

/*
 * MailWatch for MailScanner
 * Copyright (C) 2003-2026 MailWatch Team
 *
 * System Settings & Security Management Web Interface
 */

require_once __DIR__ . '/functions.php';
require __DIR__ . '/login.function.php';

// Only Administrators can access system settings
if ('A' !== $_SESSION['user_type']) {
    header('Location: index.php');
    exit;
}

ensure_system_settings_table();
ensure_login_failures_table();

$message = '';
$messageType = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        if (false === checkFormToken('/settings.php save token', $_POST['formtoken'] ?? '')) {
            $message = 'Security token invalid or expired. Please try again.';
            $messageType = 'error';
        } else {
            // Process Security settings
            $loginProtection = isset($_POST['LOGIN_PROTECTION_ENABLED']) ? '1' : '0';
            set_system_setting('LOGIN_PROTECTION_ENABLED', $loginProtection);

            $captchaAttempts = max(1, (int)($_POST['LOGIN_MAX_FAILURES_BEFORE_CAPTCHA'] ?? 2));
            set_system_setting('LOGIN_MAX_FAILURES_BEFORE_CAPTCHA', $captchaAttempts);

            $banAttempts = max(1, (int)($_POST['LOGIN_MAX_FAILURES_BEFORE_BAN'] ?? 3));
            set_system_setting('LOGIN_MAX_FAILURES_BEFORE_BAN', $banAttempts);

            $banDuration = max(1, (int)($_POST['LOGIN_BAN_DURATION_MINUTES'] ?? 30));
            set_system_setting('LOGIN_BAN_DURATION_MINUTES', $banDuration);

            $windowMinutes = max(1, (int)($_POST['LOGIN_FAILURES_WINDOW_MINUTES'] ?? 15));
            set_system_setting('LOGIN_FAILURES_WINDOW_MINUTES', $windowMinutes);

            // Clean & normalize whitelist
            $rawWhitelist = trim($_POST['LOGIN_WHITELIST_IPS'] ?? '');
            $cleanWhitelist = preg_replace('/[\r\n]+/', ', ', $rawWhitelist);
            $cleanWhitelist = preg_replace('/\s*,\s*/', ', ', $cleanWhitelist);
            $cleanWhitelist = trim($cleanWhitelist, ', ');
            set_system_setting('LOGIN_WHITELIST_IPS', $cleanWhitelist);

            // Process General settings
            $sessionTimeout = max(60, (int)($_POST['SESSION_TIMEOUT'] ?? 259200));
            set_system_setting('SESSION_TIMEOUT', $sessionTimeout);

            $maxResults = max(10, (int)($_POST['MAX_RESULTS'] ?? 50));
            set_system_setting('MAX_RESULTS', $maxResults);

            $statusRefresh = max(5, (int)($_POST['STATUS_REFRESH'] ?? 30));
            set_system_setting('STATUS_REFRESH', $statusRefresh);

            audit_log('Updated system settings', $_SESSION['myusername']);
            $message = 'Settings have been updated and saved to database successfully.';
            $messageType = 'success';
        }
    } elseif ($action === 'unban_ip') {
        if (false === checkFormToken('/settings.php unban token', $_POST['formtoken'] ?? '')) {
            $message = 'Security token invalid or expired.';
            $messageType = 'error';
        } else {
            $unbanIp = trim($_POST['ip_address'] ?? '');
            if (!empty($unbanIp)) {
                unban_login_ip($unbanIp);
                audit_log("Unbanned IP address [{$unbanIp}]", $_SESSION['myusername']);
                $message = "IP address <strong>" . htmlspecialchars($unbanIp) . "</strong> has been unbanned.";
                $messageType = 'success';
            }
        }
    } elseif ($action === 'clear_failures') {
        if (false === checkFormToken('/settings.php clear token', $_POST['formtoken'] ?? '')) {
            $message = 'Security token invalid or expired.';
            $messageType = 'error';
        } else {
            @dbquery("TRUNCATE TABLE `login_failures`");
            audit_log('Cleared all login failure records', $_SESSION['myusername']);
            $message = 'All login failure records and temporary bans have been cleared.';
            $messageType = 'success';
        }
    }
}

// Fetch current values
$allSettings = get_all_system_settings();
$activeBans = get_active_ip_bans();
$recentFailures = get_recent_failed_logins(20);

// Active client IP
$clientIp = getHTTPClientIP();
$isClientWhitelisted = is_client_ip_whitelisted($clientIp);

html_start('System Settings & Security', 0, false, false);
?>

<div class="settings-container">
    <div class="settings-header">
        <div class="settings-header-icon">🛡️</div>
        <div>
            <h2>System Settings &amp; Security</h2>
            <p class="settings-subtitle">Manage database-backed configuration, login security policies, brute-force protection, and IP whitelists.</p>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="settings-alert settings-alert-<?php echo $messageType; ?>">
            <?php echo ($messageType === 'success' ? '✅ ' : '⚠️ ') . $message; ?>
        </div>
    <?php endif; ?>

    <div class="settings-nav-tabs">
        <button type="button" class="tab-btn active" onclick="switchSettingsTab('tab-security', this)">🛡️ Login &amp; Brute-Force Security</button>
        <button type="button" class="tab-btn" onclick="switchSettingsTab('tab-general', this)">⚙️ General &amp; Interface</button>
        <button type="button" class="tab-btn" onclick="switchSettingsTab('tab-bans', this)">
            🚫 Active Bans &amp; History
            <?php if (count($activeBans) > 0): ?>
                <span class="tab-badge"><?php echo count($activeBans); ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- TAB 1: SECURITY -->
    <div id="tab-security" class="settings-tab-content active">
        <form method="POST" action="settings.php" class="settings-form">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="formtoken" value="<?php echo generateFormToken('/settings.php save token'); ?>">

            <div class="settings-card">
                <div class="settings-card-header">
                    <h3>🛡️ Brute-Force Password Protection</h3>
                    <span class="settings-badge-status <?php echo (get_system_setting('LOGIN_PROTECTION_ENABLED', true) ? 'badge-active' : 'badge-disabled'); ?>">
                        <?php echo (get_system_setting('LOGIN_PROTECTION_ENABLED', true) ? '● ACTIVE' : '○ DISABLED'); ?>
                    </span>
                </div>
                <div class="settings-card-body">
                    <div class="setting-row">
                        <div class="setting-info">
                            <label class="setting-label" for="login_prot">Enable Login Brute-Force Protection</label>
                            <p class="setting-desc">Enforce automatic CAPTCHA challenges and temporary IP bans upon repeated authentication failures.</p>
                        </div>
                        <div class="setting-control">
                            <label class="switch">
                                <input type="checkbox" id="login_prot" name="LOGIN_PROTECTION_ENABLED" value="1" <?php echo (get_system_setting('LOGIN_PROTECTION_ENABLED', true) ? 'checked' : ''); ?>>
                                <span class="slider round"></span>
                            </label>
                        </div>
                    </div>

                    <div class="setting-row">
                        <div class="setting-info">
                            <label class="setting-label" for="captcha_limit">CAPTCHA Trigger Threshold (Attempts)</label>
                            <p class="setting-desc">Number of consecutive failed login attempts from an IP before displaying security CAPTCHA verification (default: 2).</p>
                        </div>
                        <div class="setting-control">
                            <input type="number" id="captcha_limit" name="LOGIN_MAX_FAILURES_BEFORE_CAPTCHA" min="1" max="20" class="form-input-number" value="<?php echo htmlspecialchars((string)get_system_setting('LOGIN_MAX_FAILURES_BEFORE_CAPTCHA', 2)); ?>">
                        </div>
                    </div>

                    <div class="setting-row">
                        <div class="setting-info">
                            <label class="setting-label" for="ban_limit">IP Ban Trigger Threshold (Attempts)</label>
                            <p class="setting-desc">Number of consecutive failed login attempts before temporarily banning the client IP address (default: 3).</p>
                        </div>
                        <div class="setting-control">
                            <input type="number" id="ban_limit" name="LOGIN_MAX_FAILURES_BEFORE_BAN" min="1" max="50" class="form-input-number" value="<?php echo htmlspecialchars((string)get_system_setting('LOGIN_MAX_FAILURES_BEFORE_BAN', 3)); ?>">
                        </div>
                    </div>

                    <div class="setting-row">
                        <div class="setting-info">
                            <label class="setting-label" for="ban_duration">IP Ban Duration (Minutes)</label>
                            <p class="setting-desc">How long a banned IP address is blocked from accessing the login page (default: 30 minutes).</p>
                        </div>
                        <div class="setting-control">
                            <div class="input-with-unit">
                                <input type="number" id="ban_duration" name="LOGIN_BAN_DURATION_MINUTES" min="1" max="1440" class="form-input-number" value="<?php echo htmlspecialchars((string)get_system_setting('LOGIN_BAN_DURATION_MINUTES', 30)); ?>">
                                <span class="unit-label">min</span>
                            </div>
                        </div>
                    </div>

                    <div class="setting-row">
                        <div class="setting-info">
                            <label class="setting-label" for="window_minutes">Failure Tracking Window (Minutes)</label>
                            <p class="setting-desc">Time window in minutes to evaluate consecutive failures for an IP (default: 15 minutes).</p>
                        </div>
                        <div class="setting-control">
                            <div class="input-with-unit">
                                <input type="number" id="window_minutes" name="LOGIN_FAILURES_WINDOW_MINUTES" min="1" max="1440" class="form-input-number" value="<?php echo htmlspecialchars((string)get_system_setting('LOGIN_FAILURES_WINDOW_MINUTES', 15)); ?>">
                                <span class="unit-label">min</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-card">
                <div class="settings-card-header">
                    <h3>🌐 IP &amp; CIDR Subnet Whitelist</h3>
                    <span class="settings-badge-info">Never Challenged / Never Banned</span>
                </div>
                <div class="settings-card-body">
                    <p class="setting-desc">
                        Specify trusted IP addresses or CIDR subnets. Clients connecting from these addresses are <strong>never prompted for CAPTCHA</strong> and will <strong>never be banned</strong>.
                    </p>
                    <div class="your-ip-badge">
                        Your Current IP: <strong><?php echo htmlspecialchars($clientIp); ?></strong>
                        <?php if ($isClientWhitelisted): ?>
                            <span class="badge-tag-pass">✅ Whitelisted</span>
                        <?php else: ?>
                            <span class="badge-tag-warn">⚠️ Not in Whitelist</span>
                        <?php endif; ?>
                    </div>

                    <div class="whitelist-input-box">
                        <label for="whitelist_ips" class="setting-label">Whitelisted IPs and Subnets (Comma-separated or one per line):</label>
                        <textarea id="whitelist_ips" name="LOGIN_WHITELIST_IPS" rows="4" class="form-textarea-code" placeholder="127.0.0.1, ::1, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 195.230.150.0/27"><?php echo htmlspecialchars(get_system_setting('LOGIN_WHITELIST_IPS', '127.0.0.1, ::1, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16')); ?></textarea>
                        <div class="whitelist-quick-examples">
                            <span>Supported Formats:</span>
                            <code>127.0.0.1</code> <code>::1</code> <code>10.0.0.0/8</code> <code>172.16.0.0/12</code> <code>192.168.0.0/16</code> <code>195.230.150.68/32</code>
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-submit-bar">
                <button type="submit" class="btn-save-settings">💾 Save Security Settings</button>
            </div>
        </form>
    </div>

    <!-- TAB 2: GENERAL -->
    <div id="tab-general" class="settings-tab-content">
        <form method="POST" action="settings.php" class="settings-form">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="formtoken" value="<?php echo generateFormToken('/settings.php save token'); ?>">

            <div class="settings-card">
                <div class="settings-card-header">
                    <h3>⚙️ System &amp; Session Preferences</h3>
                </div>
                <div class="settings-card-body">
                    <div class="setting-row">
                        <div class="setting-info">
                            <label class="setting-label" for="sess_timeout">Session Inactivity Timeout</label>
                            <p class="setting-desc">Duration in seconds of inactivity before a user session expires and requires re-login (default: 259200 / 3 days).</p>
                        </div>
                        <div class="setting-control">
                            <div class="input-with-unit">
                                <input type="number" id="sess_timeout" name="SESSION_TIMEOUT" min="60" max="2592000" class="form-input-number" value="<?php echo htmlspecialchars((string)get_system_setting('SESSION_TIMEOUT', 259200)); ?>">
                                <span class="unit-label">sec</span>
                            </div>
                        </div>
                    </div>

                    <div class="setting-row">
                        <div class="setting-info">
                            <label class="setting-label" for="max_results">Default Results Per Page</label>
                            <p class="setting-desc">Default number of records to display per page on Recent Messages and listing reports.</p>
                        </div>
                        <div class="setting-control">
                            <input type="number" id="max_results" name="MAX_RESULTS" min="10" max="500" class="form-input-number" value="<?php echo htmlspecialchars((string)get_system_setting('MAX_RESULTS', 50)); ?>">
                        </div>
                    </div>

                    <div class="setting-row">
                        <div class="setting-info">
                            <label class="setting-label" for="status_refresh">Recent Messages Auto-Refresh</label>
                            <p class="setting-desc">Auto-refresh interval in seconds for the Recent Messages monitoring view.</p>
                        </div>
                        <div class="setting-control">
                            <div class="input-with-unit">
                                <input type="number" id="status_refresh" name="STATUS_REFRESH" min="5" max="600" class="form-input-number" value="<?php echo htmlspecialchars((string)get_system_setting('STATUS_REFRESH', 30)); ?>">
                                <span class="unit-label">sec</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-submit-bar">
                <button type="submit" class="btn-save-settings">💾 Save General Settings</button>
            </div>
        </form>
    </div>

    <!-- TAB 3: BANS & AUDIT -->
    <div id="tab-bans" class="settings-tab-content">
        <div class="settings-card">
            <div class="settings-card-header">
                <h3>🚫 Currently Banned IP Addresses</h3>
                <span class="settings-badge-info"><?php echo count($activeBans); ?> Active Ban(s)</span>
            </div>
            <div class="settings-card-body">
                <?php if (count($activeBans) > 0): ?>
                    <table class="settings-data-table">
                        <thead>
                            <tr>
                                <th>IP Address</th>
                                <th>Target User</th>
                                <th>Ban Until</th>
                                <th>Remaining</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeBans as $ban): ?>
                                <tr>
                                    <td><strong class="mono-ip"><?php echo htmlspecialchars($ban['ip_address']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($ban['username'] ?: '—'); ?></td>
                                    <td><?php echo htmlspecialchars($ban['ban_until']); ?></td>
                                    <td><span class="badge-ban-timer">⏱️ <?php echo htmlspecialchars((string)$ban['remaining_minutes']); ?> min</span></td>
                                    <td>
                                        <form method="POST" action="settings.php" style="display:inline;" onsubmit="return confirm('Unban IP <?php echo htmlspecialchars($ban['ip_address']); ?>?');">
                                            <input type="hidden" name="action" value="unban_ip">
                                            <input type="hidden" name="ip_address" value="<?php echo htmlspecialchars($ban['ip_address']); ?>">
                                            <input type="hidden" name="formtoken" value="<?php echo generateFormToken('/settings.php unban token'); ?>">
                                            <button type="submit" class="btn-unban-action">🔓 Unban</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-bans-state">
                        <div class="empty-icon">✅</div>
                        <p>No active IP bans at this time. All clients are in good standing.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="settings-card" style="margin-top: 20px;">
            <div class="settings-card-header">
                <h3>📜 Recent Failed Login History</h3>
                <form method="POST" action="settings.php" style="margin: 0;" onsubmit="return confirm('Are you sure you want to clear all login failure history and reset active bans?');">
                    <input type="hidden" name="action" value="clear_failures">
                    <input type="hidden" name="formtoken" value="<?php echo generateFormToken('/settings.php clear token'); ?>">
                    <button type="submit" class="btn-clear-history">🗑️ Clear History</button>
                </form>
            </div>
            <div class="settings-card-body">
                <?php if (count($recentFailures) > 0): ?>
                    <table class="settings-data-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>IP Address</th>
                                <th>Username Attempted</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentFailures as $fail): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($fail['attempt_time']); ?></td>
                                    <td><span class="mono-ip"><?php echo htmlspecialchars($fail['ip_address']); ?></span></td>
                                    <td><?php echo htmlspecialchars($fail['username'] ?: '—'); ?></td>
                                    <td>
                                        <?php if (!empty($fail['is_banned'])): ?>
                                            <span class="status-pill status-banned">⛔ BANNED</span>
                                        <?php else: ?>
                                            <span class="status-pill status-failed">⚠️ Failed Attempt</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-bans-state">
                        <p>No recorded login failures.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function switchSettingsTab(tabId, btn) {
    document.querySelectorAll('.settings-tab-content').forEach(function(el) {
        el.classList.remove('active');
    });
    document.querySelectorAll('.settings-nav-tabs .tab-btn').forEach(function(el) {
        el.classList.remove('active');
    });
    var target = document.getElementById(tabId);
    if (target) {
        target.classList.add('active');
    }
    if (btn) {
        btn.classList.add('active');
    }
}
</script>

<?php
html_end();
