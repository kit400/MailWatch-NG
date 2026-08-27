<?php

/*
 * MailWatch for MailScanner
 * Copyright (C) 2003-2026 MailWatch Team
 *
 * My Settings - User profile, avatar, password change, language, default dashboard, theme
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/password.php';

// Authentication check
if (!isset($_SESSION['myusername'])) {
    header('Location: login.php');
    exit;
}

$username = $_SESSION['myusername'];
$userType = $_SESSION['user_type'] ?? 'U';

ensure_user_preferences_table();
dbconn();

$msgSuccess = '';
$msgError = '';

// Process POST form submission
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    if (false === checkFormToken('/user_settings.php token', $_POST['formtoken'] ?? '')) {
        $msgError = 'Invalid or expired security form token. Please reload and try again.';
    } else {
        $action = $_POST['action'] ?? 'save_profile';

        if ('save_profile' === $action) {
            $fullname = trim(sanitizeInput($_POST['fullname'] ?? ''));
            $email = trim(sanitizeInput($_POST['email'] ?? ''));
            $language = trim(sanitizeInput($_POST['language'] ?? 'en'));
            $avatar = trim(sanitizeInput($_POST['avatar'] ?? 'default'));
            $customAvatarUrl = trim(sanitizeInput($_POST['custom_avatar_url'] ?? ''));
            $defaultDashboard = trim(sanitizeInput($_POST['default_dashboard'] ?? 'dashboard.php'));
            $theme = trim(sanitizeInput($_POST['theme'] ?? 'default'));

            // If custom avatar selected and URL provided
            if ('custom' === $avatar && !empty($customAvatarUrl)) {
                if (filter_var($customAvatarUrl, FILTER_VALIDATE_URL)) {
                    $avatar = $customAvatarUrl;
                } else {
                    $avatar = 'default';
                }
            } elseif ('gravatar' === $avatar && !empty($email)) {
                $avatar = 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($email))) . '?s=128&d=mp';
            }

            // Validate email if provided
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $msgError = 'The provided email address is invalid.';
            } else {
                // Validate language
                if (defined('USER_SELECTABLE_LANG')) {
                    $allowedLangs = explode(',', USER_SELECTABLE_LANG);
                    if (!in_array($language, $allowedLangs, true)) {
                        $language = 'en';
                    }
                }

                // Whitelist default dashboard
                $allowedDashboards = ['dashboard.php', 'status.php', 'quarantine.php', 'reports.php'];
                if (!in_array($defaultDashboard, $allowedDashboards, true)) {
                    $defaultDashboard = 'dashboard.php';
                }

                // Whitelist theme
                $allowedThemes = ['default', 'dark'];
                if (!in_array($theme, $allowedThemes, true)) {
                    $theme = 'default';
                }

                // Save preferences
                save_user_preferences($username, [
                    'email' => $email,
                    'language' => $language,
                    'avatar' => $avatar,
                    'default_dashboard' => $defaultDashboard,
                    'theme' => $theme,
                ]);

                // Update users table for fullname
                if (!empty($fullname)) {
                    $safeFullname = safe_value($fullname);
                    $safeUser = safe_value($username);
                    dbquery("UPDATE users SET fullname = '$safeFullname' WHERE username = '$safeUser'");
                    $_SESSION['fullname'] = $fullname;
                }

                // Set language cookie if changed
                $cookieParams = session_get_cookie_params();
                $sessionCookieSecure = (isset($_SERVER['HTTPS']) && 'on' === $_SERVER['HTTPS']) || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO']);
                setcookie('MW_LANG', $language, time() + 31536000, $cookieParams['path'], $cookieParams['domain'], $sessionCookieSecure, false);
                $_COOKIE['MW_LANG'] = $language;

                audit_log(sprintf('User %s updated their profile and preferences via My Settings', $username));
                $msgSuccess = 'Your profile and settings have been saved successfully.';
            }
        } elseif ('change_password' === $action) {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($currentPassword)) {
                $msgError = 'Please enter your current password.';
            } elseif (empty($newPassword)) {
                $msgError = 'Please enter a new password.';
            } elseif (strlen($newPassword) < 6) {
                $msgError = 'The new password must be at least 6 characters long.';
            } elseif ($newPassword !== $confirmPassword) {
                $msgError = 'The new passwords do not match.';
            } else {
                // Verify current password against database
                $safeUser = safe_value($username);
                $uRes = dbquery("SELECT password FROM users WHERE username = '$safeUser' LIMIT 1");
                if ($uRes && $uRes->num_rows > 0) {
                    $uRow = $uRes->fetch_assoc();
                    $dbPass = $uRow['password'] ?? '';

                    $verified = false;
                    if (password_verify($currentPassword, $dbPass)) {
                        $verified = true;
                    } elseif (hash_equals(md5($currentPassword), $dbPass)) {
                        $verified = true;
                    }

                    if (!$verified) {
                        $msgError = 'Your current password is incorrect.';
                    } else {
                        $newHash = safe_value(password_hash($newPassword, PASSWORD_DEFAULT));
                        dbquery("UPDATE users SET password = '$newHash' WHERE username = '$safeUser'");
                        audit_log(sprintf('User %s changed their password via My Settings', $username));
                        $msgSuccess = 'Password updated successfully!';
                    }
                } else {
                    $msgError = 'User account not found in database.';
                }
            }
        }
    }
}

// Fetch current user details
$safeUser = safe_value($username);
$userRow = [];
$uRes = dbquery("SELECT * FROM users WHERE username = '$safeUser' LIMIT 1");
if ($uRes && $uRes->num_rows > 0) {
    $userRow = $uRes->fetch_assoc();
}

$prefs = get_user_preferences($username);

// Available languages
$availableLangs = [
    'en'     => 'English (US/UK)',
    'de'     => 'Deutsch (German)',
    'fr'     => 'Français (French)',
    'it'     => 'Italiano (Italian)',
    'es-419' => 'Español (Latin America)',
    'nl'     => 'Nederlands (Dutch)',
    'pt_br'  => 'Português (Brasil)',
    'ja'     => '日本語 (Japanese)',
    'tr'     => 'Türkçe (Turkish)',
];

if (defined('USER_SELECTABLE_LANG')) {
    $allowed = explode(',', USER_SELECTABLE_LANG);
    $availableLangs = array_intersect_key($availableLangs, array_flip($allowed));
}

// Available preset avatars
$presetAvatars = [
    'default' => ['label' => 'Standard User',    'emoji' => '👤'],
    'admin'   => ['label' => 'Administrator',    'emoji' => '👨‍💼'],
    'tech'    => ['label' => 'Engineer',         'emoji' => '👩‍💻'],
    'shield'  => ['label' => 'Security Guard',   'emoji' => '🛡️'],
    'pilot'   => ['label' => 'Space Pilot',      'emoji' => '🚀'],
    'owl'     => ['label' => 'Night Owl',        'emoji' => '🦉'],
    'fox'     => ['label' => 'Clever Fox',       'emoji' => '🦊'],
    'tux'     => ['label' => 'Linux Penguin',    'emoji' => '🐧'],
    'cyber'   => ['label' => 'Cyber Specialist', 'emoji' => '🧑‍💻'],
    'star'    => ['label' => 'Star Guardian',    'emoji' => '⭐'],
];

// Current avatar value
$currentAvatar = $prefs['avatar'] ?? 'default';
$isCustomAvatar = (0 === strpos($currentAvatar, 'http://') || 0 === strpos($currentAvatar, 'https://'));

// Available dashboards
$availableDashboards = [
    'dashboard.php'  => ['name' => 'Interactive Dashboard', 'desc' => 'Modern 12-column widget grid with metrics and charts', 'icon' => '📊'],
    'status.php'     => ['name' => 'Recent Messages',        'desc' => 'Real-time message listing with active filters',       'icon' => '📬'],
    'quarantine.php' => ['name' => 'Quarantine Management',  'desc' => 'Quarantine viewer, releasing and message learning',  'icon' => '🛡️'],
    'reports.php'    => ['name' => 'Reports & Analytics',    'desc' => 'Reports sidebar and statistical breakdowns',         'icon' => '📈'],
];

// Available themes
$availableThemes = [
    'default' => ['name' => 'Default Light Theme', 'desc' => 'Standard EFA-NG high-contrast clean theme', 'badge' => 'Active'],
    'dark'    => ['name' => 'Dark Theme (Preview)', 'desc' => 'Dark mode interface (in active development)', 'badge' => 'Coming Soon'],
];

// Page Header
html_start('My Settings &amp; Profile');

?>
<div class="user-settings-wrapper">

  <div class="user-settings-header">
    <div class="header-title-box">
      <h1 class="settings-main-title">👤 My Settings &amp; Profile</h1>
      <p class="settings-sub-title">Personal preferences, avatar, landing page, theme, and password management for <b><?php echo htmlspecialchars($username); ?></b>.</p>
    </div>
    <div class="header-badge-box">
      <span class="user-role-pill role-<?php echo strtolower($userType); ?>">
        <?php
        echo 'A' === $userType ? '🛡️ Administrator' : ('D' === $userType ? '🏢 Domain Admin' : '👤 User');
        ?>
      </span>
    </div>
  </div>

  <?php if (!empty($msgSuccess)): ?>
    <div class="settings-alert alert-success">
      <span class="alert-icon">✅</span>
      <span class="alert-text"><?php echo htmlspecialchars($msgSuccess); ?></span>
    </div>
  <?php endif; ?>

  <?php if (!empty($msgError)): ?>
    <div class="settings-alert alert-danger">
      <span class="alert-icon">⚠️</span>
      <span class="alert-text"><?php echo htmlspecialchars($msgError); ?></span>
    </div>
  <?php endif; ?>

  <div class="user-settings-grid">

    <!-- Column 1: Profile & Avatar & Preferences -->
    <div class="settings-col-main">
      <form method="POST" action="user_settings.php" class="settings-card">
        <input type="hidden" name="action" value="save_profile">
        <input type="hidden" name="formtoken" value="<?php echo generateFormToken('/user_settings.php token'); ?>">

        <div class="card-section-header">
          <span class="card-icon">👤</span>
          <div class="card-header-titles">
            <h2 class="card-title">Profile &amp; Identity</h2>
            <p class="card-desc">Your personal information, notification email, and avatar icon.</p>
          </div>
        </div>

        <div class="form-row-split">
          <div class="form-group">
            <label class="form-label">Username</label>
            <input type="text" class="form-control" value="<?php echo htmlspecialchars($username); ?>" disabled>
            <span class="form-hint">System username cannot be changed.</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="fullname">Full Name</label>
            <input type="text" id="fullname" name="fullname" class="form-control" value="<?php echo htmlspecialchars($userRow['fullname'] ?? $username); ?>" required>
            <span class="form-hint">Displayed in the navigation bar and audit logs.</span>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="email">Email Address</label>
          <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($prefs['email'] ?? $userRow['quarantine_rcpt'] ?? ''); ?>" placeholder="name@domain.com">
          <span class="form-hint">Used for personal quarantine digest reports and password resets.</span>
        </div>

        <!-- Avatar Selection -->
        <div class="form-group">
          <label class="form-label">Avatar Selection</label>
          <div class="avatar-picker-container">
            <div class="avatar-current-preview" id="avatarCurrentPreview">
              <?php echo get_user_avatar_badge_html($username, 54); ?>
              <span class="preview-caption">Current Avatar</span>
            </div>

            <div class="avatar-presets-grid">
              <?php foreach ($presetAvatars as $key => $p): ?>
                <?php $isSelected = (!$isCustomAvatar && $currentAvatar === $key); ?>
                <label class="avatar-preset-label <?php echo $isSelected ? 'is-selected' : ''; ?>" title="<?php echo htmlspecialchars($p['label']); ?>">
                  <input type="radio" name="avatar" value="<?php echo $key; ?>" <?php echo $isSelected ? 'checked' : ''; ?> onchange="selectPresetAvatar('<?php echo $p['emoji']; ?>')">
                  <span class="preset-emoji"><?php echo $p['emoji']; ?></span>
                  <span class="preset-name"><?php echo htmlspecialchars($p['label']); ?></span>
                </label>
              <?php endforeach; ?>

              <!-- Gravatar option -->
              <label class="avatar-preset-label <?php echo (false !== strpos($currentAvatar, 'gravatar.com')) ? 'is-selected' : ''; ?>" title="Use Gravatar based on your email">
                <input type="radio" name="avatar" value="gravatar" <?php echo (false !== strpos($currentAvatar, 'gravatar.com')) ? 'checked' : ''; ?> onchange="selectGravatarAvatar()">
                <span class="preset-emoji">🌐</span>
                <span class="preset-name">Gravatar</span>
              </label>

              <!-- Custom URL option -->
              <label class="avatar-preset-label <?php echo ($isCustomAvatar && false === strpos($currentAvatar, 'gravatar.com')) ? 'is-selected' : ''; ?>" title="Custom image URL">
                <input type="radio" name="avatar" value="custom" <?php echo ($isCustomAvatar && false === strpos($currentAvatar, 'gravatar.com')) ? 'checked' : ''; ?> onchange="selectCustomAvatar()">
                <span class="preset-emoji">🖼️</span>
                <span class="preset-name">Custom URL</span>
              </label>
            </div>
          </div>

          <div class="custom-avatar-url-box" id="customAvatarUrlBox" style="<?php echo ($isCustomAvatar && false === strpos($currentAvatar, 'gravatar.com')) ? '' : 'display:none;'; ?>">
            <label class="form-label" for="custom_avatar_url" style="margin-top: 10px;">Custom Image URL</label>
            <input type="url" id="custom_avatar_url" name="custom_avatar_url" class="form-control" value="<?php echo $isCustomAvatar ? htmlspecialchars($currentAvatar) : ''; ?>" placeholder="https://example.com/my-photo.png" oninput="previewCustomUrl(this.value)">
            <span class="form-hint">Enter direct URL to PNG, JPG, or SVG image.</span>
          </div>
        </div>

        <div class="dropdown-divider" style="margin: 24px 0;"></div>

        <!-- Preferences section -->
        <div class="card-section-header">
          <span class="card-icon">⚙️</span>
          <div class="card-header-titles">
            <h2 class="card-title">Interface &amp; Preferences</h2>
            <p class="card-desc">Choose your default landing dashboard, interface language, and theme.</p>
          </div>
        </div>

        <div class="form-row-split">
          <!-- Language -->
          <div class="form-group">
            <label class="form-label" for="language">🌐 Interface Language</label>
            <select id="language" name="language" class="form-control form-select">
              <?php foreach ($availableLangs as $code => $label): ?>
                <option value="<?php echo $code; ?>" <?php echo ($prefs['language'] ?? 'en') === $code ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($label); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="form-hint">Interface labels, reports, and menu language.</span>
          </div>

          <!-- Default Dashboard -->
          <div class="form-group">
            <label class="form-label" for="default_dashboard">📊 Default Landing Page</label>
            <select id="default_dashboard" name="default_dashboard" class="form-control form-select">
              <?php foreach ($availableDashboards as $url => $d): ?>
                <option value="<?php echo $url; ?>" <?php echo ($prefs['default_dashboard'] ?? 'dashboard.php') === $url ? 'selected' : ''; ?>>
                  <?php echo $d['icon'] . ' ' . htmlspecialchars($d['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="form-hint">First screen displayed after logging in.</span>
          </div>
        </div>

        <!-- Theme Selection -->
        <div class="form-group">
          <label class="form-label" for="theme">🎨 Color Theme</label>
          <select id="theme" name="theme" class="form-control form-select">
            <?php foreach ($availableThemes as $tKey => $t): ?>
              <option value="<?php echo $tKey; ?>" <?php echo ($prefs['theme'] ?? 'default') === $tKey ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($t['name']) . ' (' . htmlspecialchars($t['badge']) . ')'; ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="form-hint">Visual styling and color scheme for the application.</span>
        </div>

        <div class="card-footer-actions">
          <button type="submit" class="btn btn-primary">💾 Save Settings</button>
        </div>
      </form>
    </div>

    <!-- Column 2: Password Change & Account Info -->
    <div class="settings-col-side">

      <!-- Change Password Card -->
      <form method="POST" action="user_settings.php" class="settings-card">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="formtoken" value="<?php echo generateFormToken('/user_settings.php token'); ?>">

        <div class="card-section-header">
          <span class="card-icon">🔒</span>
          <div class="card-header-titles">
            <h2 class="card-title">Change Password</h2>
            <p class="card-desc">Update your account login password.</p>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="current_password">Current Password</label>
          <input type="password" id="current_password" name="current_password" class="form-control" autocomplete="current-password" required>
        </div>

        <div class="form-group">
          <label class="form-label" for="new_password">New Password</label>
          <input type="password" id="new_password" name="new_password" class="form-control" autocomplete="new-password" minlength="6" required>
          <span class="form-hint">Minimum 6 characters with mixed letters and numbers.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="confirm_password">Confirm New Password</label>
          <input type="password" id="confirm_password" name="confirm_password" class="form-control" autocomplete="new-password" minlength="6" required>
        </div>

        <div class="card-footer-actions">
          <button type="submit" class="btn btn-warning">🔑 Update Password</button>
        </div>
      </form>

      <!-- Account Summary Card -->
      <div class="settings-card info-card">
        <div class="card-section-header">
          <span class="card-icon">ℹ️</span>
          <div class="card-header-titles">
            <h2 class="card-title">Account Summary</h2>
            <p class="card-desc">Active account parameters.</p>
          </div>
        </div>

        <div class="summary-list">
          <div class="summary-item">
            <span class="summary-label">Account Role:</span>
            <span class="summary-value"><?php echo 'A' === $userType ? 'Administrator' : ('D' === $userType ? 'Domain Admin' : 'Standard User'); ?></span>
          </div>
          <div class="summary-item">
            <span class="summary-label">Spam Check Score:</span>
            <span class="summary-value"><?php echo htmlspecialchars($userRow['spamscore'] ?? 'Default'); ?></span>
          </div>
          <div class="summary-item">
            <span class="summary-label">High Spam Score:</span>
            <span class="summary-value"><?php echo htmlspecialchars($userRow['highspamscore'] ?? 'Default'); ?></span>
          </div>
          <div class="summary-item">
            <span class="summary-label">Quarantine Report:</span>
            <span class="summary-value"><?php echo (!empty($userRow['quarantine_report']) ? '✅ Enabled' : '❌ Disabled'); ?></span>
          </div>
          <?php if (!empty($userRow['last_login']) && $userRow['last_login'] > 0): ?>
          <div class="summary-item">
            <span class="summary-label">Last Login:</span>
            <span class="summary-value"><?php echo date('Y-m-d H:i:s', $userRow['last_login']); ?></span>
          </div>
          <?php endif; ?>
        </div>

        <?php if ('A' === $userType): ?>
        <div class="summary-actions">
          <a href="settings.php" class="btn-link">⚙️ Go to Global System Settings &rarr;</a>
        </div>
        <?php endif; ?>
      </div>

    </div>

  </div>

</div>

<script>
function selectPresetAvatar(emoji) {
    document.querySelectorAll('.avatar-preset-label').forEach(function(el) {
        el.classList.remove('is-selected');
    });
    if (window.event && window.event.currentTarget) {
        window.event.currentTarget.classList.add('is-selected');
    }
    document.getElementById('customAvatarUrlBox').style.display = 'none';

    var prev = document.getElementById('avatarCurrentPreview');
    if (prev) {
        prev.innerHTML = '<span class="user-avatar-badge" style="width:54px;height:54px;line-height:54px;font-size:35px;">' + emoji + '</span><span class="preview-caption">Current Avatar</span>';
    }
}

function selectGravatarAvatar() {
    document.querySelectorAll('.avatar-preset-label').forEach(function(el) {
        el.classList.remove('is-selected');
    });
    if (window.event && window.event.currentTarget) {
        window.event.currentTarget.classList.add('is-selected');
    }
    document.getElementById('customAvatarUrlBox').style.display = 'none';

    var prev = document.getElementById('avatarCurrentPreview');
    if (prev) {
        prev.innerHTML = '<span class="user-avatar-badge" style="width:54px;height:54px;line-height:54px;font-size:35px;">🌐</span><span class="preview-caption">Gravatar (Active)</span>';
    }
}

function selectCustomAvatar() {
    document.querySelectorAll('.avatar-preset-label').forEach(function(el) {
        el.classList.remove('is-selected');
    });
    if (window.event && window.event.currentTarget) {
        window.event.currentTarget.classList.add('is-selected');
    }
    document.getElementById('customAvatarUrlBox').style.display = 'block';

    var urlInput = document.getElementById('custom_avatar_url');
    if (urlInput && urlInput.value) {
        previewCustomUrl(urlInput.value);
    }
}

function previewCustomUrl(url) {
    if (!url) return;
    var prev = document.getElementById('avatarCurrentPreview');
    if (prev) {
        prev.innerHTML = '<img src="' + url + '" class="user-avatar-img" style="width:54px;height:54px;border-radius:50%;object-fit:cover;" alt="Preview" onerror="this.onerror=null;this.src=\'images/favicon.png\';"><span class="preview-caption">Custom Preview</span>';
    }
}
</script>

<?php
html_end();
