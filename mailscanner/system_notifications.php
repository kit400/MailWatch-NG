<?php

/*
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
require_once __DIR__ . '/notifications.inc.php';
require __DIR__ . '/login.function.php';

// Admin only
if ('A' !== $_SESSION['user_type']) {
    header('Location: index.php');
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$messageType = 'success';

// Handle Actions
if ('create' === $action && 'POST' === $_SERVER['REQUEST_METHOD']) {
    if (false === checkFormToken('/system_notifications.php create token', $_POST['formtoken'] ?? '')) {
        header('Location: login.php?error=pagetimeout');
        exit;
    }

    $title = sanitizeInput($_POST['title'] ?? '');
    $shortDesc = sanitizeInput($_POST['short_description'] ?? '');
    $type = sanitizeInput($_POST['type'] ?? 'info');
    $version = sanitizeInput($_POST['version'] ?? '');
    $changelogUrl = sanitizeInput($_POST['changelog_url'] ?? '');
    $targetRole = sanitizeInput($_POST['target_role'] ?? 'ALL');
    $isBanner = isset($_POST['is_banner']) ? 1 : 0;
    $isActive = 1;

    if (!empty($title) && !empty($shortDesc)) {
        $newId = SystemNotifications::createNotification([
            'type' => $type,
            'title' => $title,
            'version' => $version,
            'short_description' => $shortDesc,
            'changelog_url' => $changelogUrl,
            'target_role' => $targetRole,
            'is_banner' => $isBanner,
            'is_active' => $isActive,
        ]);

        $message = "Notification #" . $newId . " created successfully.";

        // Check if broadcast email requested
        if (isset($_POST['send_email']) && $_POST['send_email'] == '1') {
            $sent = SystemNotifications::broadcastNotificationEmail($newId);
            $message .= " Email broadcast sent to " . $sent . " user(s).";
        }
    } else {
        $message = "Please provide both title and short description.";
        $messageType = 'error';
    }
} elseif ('delete' === $action) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        dbconn();
        dbquery("DELETE FROM system_notifications WHERE id = $id");
        dbquery("DELETE FROM user_notifications_read WHERE notification_id = $id");
        $message = "Notification #$id deleted successfully.";
    }
} elseif ('toggle' === $action) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        dbconn();
        dbquery("UPDATE system_notifications SET is_active = IF(is_active=1, 0, 1) WHERE id = $id");
        $message = "Notification status updated.";
    }
} elseif ('broadcast' === $action) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $sent = SystemNotifications::broadcastNotificationEmail($id);
        $message = "Email broadcast sent to " . $sent . " recipient(s).";
    }
}

html_start('System Notifications & Announcements', 0, false, false);
?>

<div class="notif-admin-container">
  <div class="notif-admin-header">
    <div class="notif-admin-title-area">
      <h2>📢 System Notifications &amp; Broadcast Center</h2>
      <p class="notif-admin-sub">Publish release updates, security alerts, notices, and send email notifications to users.</p>
    </div>
  </div>

  <?php if (!empty($message)): ?>
    <div class="notif-alert-box notif-alert-<?php echo $messageType; ?>">
      <?php echo htmlspecialchars($message); ?>
    </div>
  <?php endif; ?>

  <div class="notif-admin-grid">
    <!-- Left Column: Create Form -->
    <div class="notif-admin-card notif-form-card">
      <div class="admin-card-title">➕ Publish New Notification / Announcement</div>
      <form method="POST" action="system_notifications.php" class="notif-create-form">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>">
        <input type="hidden" name="formtoken" value="<?php echo generateFormToken('/system_notifications.php create token'); ?>">

        <div class="notif-form-group">
          <label class="notif-form-label">Notification Type:</label>
          <select name="type" class="notif-form-select" id="notifTypeSelect" onchange="handleTypeChange()">
            <option value="release">🚀 System Update / Release (Релиз / Обновление)</option>
            <option value="danger">🚨 Critical Alert / Security (Критическое / Безопасность)</option>
            <option value="warning">⚠️ Warning / Maintenance (Предупреждение / Техработы)</option>
            <option value="info" selected>ℹ️ Information / Announcement (Информация / Объявление)</option>
            <option value="tip">💡 Tip &amp; Recommendation (Совет / Рекомендация)</option>
          </select>
        </div>

        <div class="notif-form-row">
          <div class="notif-form-group col-grow">
            <label class="notif-form-label">Title (Заголовок):</label>
            <input type="text" name="title" class="notif-form-input" placeholder="e.g. EFA-NG v6.0.0 Released" required>
          </div>
          <div class="notif-form-group col-ver">
            <label class="notif-form-label">Version (Версия):</label>
            <input type="text" name="version" class="notif-form-input" placeholder="e.g. 6.0.0">
          </div>
        </div>

        <div class="notif-form-group">
          <label class="notif-form-label">Short Description / Summary (Краткое описание изменений):</label>
          <textarea name="short_description" class="notif-form-textarea" rows="4" placeholder="Brief summary of changes or details of the alert..." required></textarea>
        </div>

        <div class="notif-form-group">
          <label class="notif-form-label">Changelog URL (Ссылка на полный ченжлог):</label>
          <input type="url" name="changelog_url" class="notif-form-input" placeholder="https://github.com/kit400/EFA-NG/releases/tag/v6.0.0">
        </div>

        <div class="notif-form-group">
          <label class="notif-form-label">Target Audience (Аудитория):</label>
          <select name="target_role" class="notif-form-select">
            <option value="ALL">All Users (Все пользователи)</option>
            <option value="A">Administrators Only (Только администраторы)</option>
            <option value="D">Domain Admins (Администраторы доменов)</option>
            <option value="U">Standard Users (Обычные пользователи)</option>
          </select>
        </div>

        <div class="notif-form-checkboxes">
          <label class="notif-checkbox-label">
            <input type="checkbox" name="is_banner" value="1" checked>
            <span>📌 Show as Top Announcement Banner (Отображать верхний баннер)</span>
          </label>
          <label class="notif-checkbox-label highlight">
            <input type="checkbox" name="send_email" value="1" checked>
            <span>✉️ Send Email Broadcast to Target Users immediately (Отправить email всем пользователям)</span>
          </label>
        </div>

        <div class="notif-form-actions">
          <button type="submit" class="notif-submit-btn">🚀 Publish Announcement</button>
        </div>
      </form>
    </div>

    <!-- Right Column: Existing Announcements List -->
    <div class="notif-admin-card notif-list-card">
      <div class="admin-card-title">📋 Active &amp; Past Announcements</div>

      <?php
      dbconn();
      $res = dbquery("SELECT n.*,
                             (SELECT COUNT(*) FROM user_notifications_read r WHERE r.notification_id = n.id) AS read_count
                      FROM system_notifications n
                      ORDER BY n.created_at DESC");
      ?>

      <div class="notif-table-wrapper">
        <table class="notif-admin-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Title &amp; Summary</th>
              <th>Audience</th>
              <th>Status</th>
              <th>Reads</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$res || $res->num_rows === 0): ?>
              <tr>
                <td colspan="6" class="text-center text-muted" style="padding: 24px;">No announcements published yet.</td>
              </tr>
            <?php else: ?>
              <?php while ($row = $res->fetch_assoc()): ?>
                <tr class="<?php echo $row['is_active'] ? '' : 'is-inactive'; ?>">
                  <td>
                    <?php
                    $badgeMap = [
                        'release' => ['🚀', 'Release', 'badge-release'],
                        'danger' => ['🚨', 'Danger', 'badge-danger'],
                        'warning' => ['⚠️', 'Warning', 'badge-warning'],
                        'info' => ['ℹ️', 'Info', 'badge-info'],
                        'tip' => ['💡', 'Tip', 'badge-tip'],
                    ];
                    $b = $badgeMap[$row['type']] ?? ['📢', 'Notice', 'badge-info'];
                    ?>
                    <span class="type-pill <?php echo $b[2]; ?>"><?php echo $b[0] . ' ' . $b[1]; ?></span>
                    <?php if (!empty($row['version'])): ?>
                      <div class="ver-sub">v<?php echo htmlspecialchars($row['version']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <strong><?php echo htmlspecialchars($row['title']); ?></strong>
                    <div class="table-desc"><?php echo htmlspecialchars($row['short_description']); ?></div>
                    <?php if (!empty($row['changelog_url'])): ?>
                      <a href="<?php echo htmlspecialchars($row['changelog_url']); ?>" target="_blank" class="table-changelog-link" rel="noopener noreferrer">📖 Changelog &rarr;</a>
                    <?php endif; ?>
                    <div class="table-date"><?php echo date('Y-m-d H:i', strtotime($row['created_at'])); ?></div>
                  </td>
                  <td><span class="audience-badge"><?php echo htmlspecialchars($row['target_role']); ?></span></td>
                  <td>
                    <?php if ($row['is_active']): ?>
                      <a href="system_notifications.php?action=toggle&amp;id=<?php echo $row['id']; ?>" class="status-btn active" title="Click to disable">Active</a>
                    <?php else: ?>
                      <a href="system_notifications.php?action=toggle&amp;id=<?php echo $row['id']; ?>" class="status-btn inactive" title="Click to enable">Disabled</a>
                    <?php endif; ?>
                  </td>
                  <td><span class="read-count-pill"><?php echo (int)$row['read_count']; ?></span></td>
                  <td>
                    <div class="action-btn-group">
                      <a href="system_notifications.php?action=broadcast&amp;id=<?php echo $row['id']; ?>" class="row-btn btn-broadcast" onclick="return confirm('Send email broadcast to target users for this announcement?');" title="Send email broadcast">✉️ Email</a>
                      <a href="system_notifications.php?action=delete&amp;id=<?php echo $row['id']; ?>" class="row-btn btn-delete" onclick="return confirm('Delete this notification?');" title="Delete">🗑️</a>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script type="text/javascript">
function handleTypeChange() {
    var select = document.getElementById("notifTypeSelect");
    // Can customize placeholders based on type
}
</script>

<?php
html_end();
