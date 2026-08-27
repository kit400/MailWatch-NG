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

class SystemNotifications
{
    /**
     * Get unread notifications for a specific user
     *
     * @param string $username
     * @param string $userType
     * @return array
     */
    public static function getUnreadNotifications($username, $userType)
    {
        dbconn();
        $usernameSafe = safe_value($username);
        $roleFilter = "AND (target_role = 'ALL' OR target_role = '" . safe_value($userType) . "')";

        $sql = "SELECT n.* FROM system_notifications n
                LEFT JOIN user_notifications_read r ON n.id = r.notification_id AND r.username = '$usernameSafe'
                WHERE n.is_active = 1
                  AND (n.expires_at IS NULL OR n.expires_at > NOW())
                  AND r.id IS NULL
                  $roleFilter
                ORDER BY n.created_at DESC";

        $res = dbquery($sql);
        $notifications = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $notifications[] = $row;
            }
        }
        return $notifications;
    }

    /**
     * Get all active notifications with read status for current user
     *
     * @param string $username
     * @param string $userType
     * @param int $limit
     * @return array
     */
    public static function getAllNotifications($username, $userType, $limit = 50)
    {
        dbconn();
        $usernameSafe = safe_value($username);
        $roleFilter = "AND (target_role = 'ALL' OR target_role = '" . safe_value($userType) . "')";

        $sql = "SELECT n.*, (CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END) AS is_read
                FROM system_notifications n
                LEFT JOIN user_notifications_read r ON n.id = r.notification_id AND r.username = '$usernameSafe'
                WHERE n.is_active = 1
                  AND (n.expires_at IS NULL OR n.expires_at > NOW())
                  $roleFilter
                ORDER BY n.created_at DESC
                LIMIT " . (int)$limit;

        $res = dbquery($sql);
        $notifications = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $notifications[] = $row;
            }
        }
        return $notifications;
    }

    /**
     * Mark a notification as read for a user
     *
     * @param int $notificationId
     * @param string $username
     * @return bool
     */
    public static function markAsRead($notificationId, $username)
    {
        dbconn();
        $nid = (int)$notificationId;
        $uname = safe_value($username);
        $sql = "REPLACE INTO user_notifications_read (notification_id, username, read_at) VALUES ($nid, '$uname', NOW())";
        return (bool)dbquery($sql);
    }

    /**
     * Mark all active notifications as read for a user
     *
     * @param string $username
     * @param string $userType
     * @return bool
     */
    public static function markAllAsRead($username, $userType)
    {
        $unread = self::getUnreadNotifications($username, $userType);
        foreach ($unread as $n) {
            self::markAsRead($n['id'], $username);
        }
        return true;
    }

    /**
     * Create a notification
     *
    /**
     * Create a new notification
     * Supports either an associative array of options, or positional arguments:
     * createNotification($title, $desc, $type, $targetRole, $version, $changelogUrl)
     *
     * @param array|string $data
     * @param string $desc
     * @param string $type
     * @param string $targetRole
     * @param string|null $version
     * @param string|null $changelogUrl
     * @return int Inserted ID
     */
    public static function createNotification($data, $desc = '', $type = 'info', $targetRole = 'ALL', $version = null, $changelogUrl = null)
    {
        dbconn();
        if (!is_array($data)) {
            $data = [
                'title' => (string)$data,
                'short_description' => (string)$desc,
                'type' => (string)$type,
                'target_role' => (string)$targetRole,
                'version' => $version,
                'changelog_url' => $changelogUrl,
                'is_banner' => 1,
                'is_active' => 1,
            ];
        }

        $type = in_array($data['type'] ?? '', ['release', 'danger', 'warning', 'info', 'tip']) ? $data['type'] : 'info';
        $title = safe_value($data['title'] ?? 'System Notice');
        $version = !empty($data['version']) ? "'" . safe_value($data['version']) . "'" : "NULL";
        $shortDesc = safe_value($data['short_description'] ?? '');
        $changelogUrl = !empty($data['changelog_url']) ? "'" . safe_value($data['changelog_url']) . "'" : "NULL";
        $fullContent = !empty($data['full_content']) ? "'" . safe_value($data['full_content']) . "'" : "NULL";
        $targetRole = in_array($data['target_role'] ?? '', ['ALL', 'A', 'D', 'U']) ? $data['target_role'] : 'ALL';
        $isBanner = (!isset($data['is_banner']) || $data['is_banner']) ? 1 : 0;
        $isActive = (!isset($data['is_active']) || $data['is_active']) ? 1 : 0;
        $expiresAt = !empty($data['expires_at']) ? "'" . safe_value($data['expires_at']) . "'" : "NULL";

        // De-duplicate: If active notification with same title & type exists, update it
        $dupCheck = "SELECT id FROM system_notifications WHERE title = '$title' AND type = '$type' AND is_active = 1 LIMIT 1";
        $dupRes = dbquery($dupCheck);
        if ($dupRes && $dupRes->num_rows > 0) {
            $dupRow = $dupRes->fetch_assoc();
            $existingId = (int)$dupRow['id'];
            $updateSql = "UPDATE system_notifications SET 
                          short_description = '$shortDesc',
                          version = $version,
                          changelog_url = $changelogUrl,
                          full_content = $fullContent,
                          target_role = '$targetRole',
                          is_banner = $isBanner,
                          created_at = NOW(),
                          expires_at = $expiresAt
                          WHERE id = $existingId";
            dbquery($updateSql);
            return $existingId;
        }

        $sql = "INSERT INTO system_notifications
                (type, title, version, short_description, changelog_url, full_content, target_role, is_banner, is_active, created_at, expires_at)
                VALUES
                ('$type', '$title', $version, '$shortDesc', $changelogUrl, $fullContent, '$targetRole', $isBanner, $isActive, NOW(), $expiresAt)";

        dbquery($sql);
        if (isset(database::$link) && database::$link instanceof mysqli) {
            return (int)database::$link->insert_id;
        }
        return 0;
    }

    /**
     * Broadcast notification via email to targeted users
     *
     * @param int $notificationId
     * @return int Number of sent emails
     */
    public static function broadcastNotificationEmail($notificationId)
    {
        dbconn();
        $nid = (int)$notificationId;
        $res = dbquery("SELECT * FROM system_notifications WHERE id = $nid");
        if (!$res || $res->num_rows === 0) {
            return 0;
        }
        $notif = $res->fetch_assoc();

        // Get recipients
        $roleFilter = "1=1";
        if ($notif['target_role'] !== 'ALL') {
            $roleFilter = "type = '" . safe_value($notif['target_role']) . "'";
        }
        $userRes = dbquery("SELECT username, fullname, type FROM users WHERE $roleFilter AND username LIKE '%@%'");
        $recipients = [];
        if ($userRes) {
            while ($u = $userRes->fetch_assoc()) {
                $recipients[] = $u['username'];
            }
        }

        // Also add root / system admin from aliases if applicable
        $aliases = @file_get_contents('/etc/aliases');
        if ($aliases && preg_match('/^root:\s*([^\s#]+)/m', $aliases, $m)) {
            if (filter_var($m[1], FILTER_VALIDATE_EMAIL)) {
                $recipients[] = trim($m[1]);
            }
        }
        $recipients = array_unique($recipients);

        if (empty($recipients)) {
            return 0;
        }

        $typeLabels = [
            'release' => '🚀 System Update',
            'danger' => '🚨 Critical Alert',
            'warning' => '⚠️ System Warning',
            'info' => 'ℹ️ System Announcement',
            'tip' => '💡 Tip & Recommendation',
        ];
        $typeLabel = $typeLabels[$notif['type']] ?? '📢 Notification';

        $subject = "[EFA-NG] " . $typeLabel . ": " . $notif['title'];
        if (!empty($notif['version'])) {
            $subject .= " (v" . $notif['version'] . ")";
        }

        $headers = "From: EFA-NG System <postmaster@efa-test.localdomain>\r\n" .
                   "Reply-To: postmaster@efa-test.localdomain\r\n" .
                   "MIME-Version: 1.0\r\n" .
                   "Content-Type: text/html; charset=UTF-8\r\n" .
                   "X-Mailer: PHP/" . phpversion();

        $changelogButton = '';
        if (!empty($notif['changelog_url'])) {
            $changelogButton = '<p style="margin: 20px 0;"><a href="' . htmlspecialchars($notif['changelog_url']) . '" style="background-color: #1f6cb0; color: #ffffff; padding: 10px 18px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;">📖 View Full Changelog</a></p>';
        }

        $body = '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f8fafc; color: #1e293b; padding: 20px; line-height: 1.5; }
.card { max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 6px rgba(0,0,0,0.06); }
.header { background: #1f6cb0; color: #ffffff; padding: 18px 24px; }
.header h2 { margin: 0; font-size: 18px; }
.content { padding: 24px; }
.desc { font-size: 14px; color: #334155; margin-bottom: 16px; }
.footer { padding: 14px 24px; background: #f1f5f9; font-size: 12px; color: #64748b; text-align: center; border-top: 1px solid #e2e8f0; }
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <h2>' . htmlspecialchars($typeLabel) . '</h2>
  </div>
  <div class="content">
    <h3 style="margin-top: 0; color: #0f172a;">' . htmlspecialchars($notif['title']) . (!empty($notif['version']) ? ' <span style="font-size: 13px; color: #1f6cb0; font-weight: normal;">(v' . htmlspecialchars($notif['version']) . ')</span>' : '') . '</h3>
    <div class="desc">' . nl2br(htmlspecialchars($notif['short_description'])) . '</div>
    ' . $changelogButton . '
  </div>
  <div class="footer">
    Sent by EFA-NG Notification System &bull; ' . date('Y-m-d H:i:s T') . '
  </div>
</div>
</body>
</html>';

        $sentCount = 0;
        foreach ($recipients as $to) {
            if (mail($to, $subject, $body, $headers)) {
                $sentCount++;
            }
        }
        return $sentCount;
    }

    /**
     * Render the top announcement banner for active unread release/danger/warning notices
     *
     * @param string $username
     * @param string $userType
     * @return string HTML
     */
    public static function renderTopAnnouncementBanner($username, $userType)
    {
        $unread = self::getUnreadNotifications($username, $userType);
        $bannerNotifs = array_filter($unread, function ($n) {
            return (int)$n['is_banner'] === 1;
        });

        if (empty($bannerNotifs)) {
            return '';
        }

        $html = '<div class="announcements-container" id="announcementsContainer">' . "\n";
        foreach ($bannerNotifs as $n) {
            $typeClass = 'banner-' . htmlspecialchars($n['type']);
            $icon = '📢';
            $typeBadge = 'Notice';
            if ($n['type'] === 'release') {
                $icon = '🚀';
                $typeBadge = 'System Update';
            } elseif ($n['type'] === 'danger') {
                $icon = '🚨';
                $typeBadge = 'Critical Alert';
            } elseif ($n['type'] === 'warning') {
                $icon = '⚠️';
                $typeBadge = 'Warning';
            } elseif ($n['type'] === 'tip') {
                $icon = '💡';
                $typeBadge = 'Tip';
            }

            $html .= '  <div class="announcement-banner ' . $typeClass . '" id="banner-notif-' . (int)$n['id'] . '">' . "\n";
            $html .= '    <div class="banner-left">' . "\n";
            $html .= '      <span class="banner-icon">' . $icon . '</span>' . "\n";
            $html .= '      <span class="banner-badge">' . $typeBadge . '</span>' . "\n";
            if (!empty($n['version'])) {
                $html .= '      <span class="banner-version">v' . htmlspecialchars($n['version']) . '</span>' . "\n";
            }
            $html .= '      <strong class="banner-title">' . htmlspecialchars($n['title']) . ':</strong>' . "\n";
            $html .= '      <span class="banner-desc">' . htmlspecialchars($n['short_description']) . '</span>' . "\n";
            $html .= '    </div>' . "\n";

            $html .= '    <div class="banner-right">' . "\n";
            if (!empty($n['changelog_url'])) {
                $html .= '      <a href="' . htmlspecialchars($n['changelog_url']) . '" target="_blank" class="banner-action-btn banner-btn-changelog" rel="noopener noreferrer">📖 ' . (__('changelog', false) ?: 'Changelog') . '</a>' . "\n";
            }
            $html .= '      <button type="button" class="banner-close-btn" onclick="dismissNotification(' . (int)$n['id'] . ')" title="Dismiss">✕</button>' . "\n";
            $html .= '    </div>' . "\n";
            $html .= '  </div>' . "\n";
        }
        $html .= '</div>' . "\n";

        return $html;
    }

    /**
     * Render the bell icon button for User Cabinet
     *
     * @param string $username
     * @param string $userType
     * @return string HTML
     */
    public static function renderBellButtonHtml($username, $userType)
    {
        $unread = self::getUnreadNotifications($username, $userType);
        $count = count($unread);
        $hasUnread = $count > 0;

        $bellIcon = function_exists('mw_icon') ? mw_icon('bell', '', 16) : '🔔';
        $html = '<button type="button" class="notif-bell-btn' . ($hasUnread ? ' has-unread' : '') . '" id="notifBellBtn" onclick="toggleNotificationsModal()" title="Notifications">' . "\n";
        $html .= '  <span class="bell-icon">' . $bellIcon . '</span>' . "\n";
        if ($hasUnread) {
            $html .= '  <span class="notif-badge" id="notifBadgeCount">' . $count . '</span>' . "\n";
        }
        $html .= '</button>' . "\n";

        return $html;
    }

    /**
     * Render the Notifications Slide-over / Modal Center
     *
     * @param string $username
     * @param string $userType
     * @param string $token
     * @return string HTML
     */
    public static function renderNotificationModalHtml($username, $userType, $token)
    {
        $all = self::getAllNotifications($username, $userType, 30);
        $unreadCount = count(array_filter($all, function ($n) {
            return (int)$n['is_read'] === 0;
        }));

        $html = '<div class="notif-modal-overlay" id="notifModalOverlay" onclick="handleNotifOverlayClick(event)">' . "\n";
        $html .= '  <div class="notif-modal-card" id="notifModalCard">' . "\n";

        // Modal Header
        $html .= '    <div class="notif-modal-header">' . "\n";
        $html .= '      <div class="notif-modal-title">' . "\n";
        $html .= '        <span class="notif-header-icon">🔔</span>' . "\n";
        $html .= '        <span>Notifications &amp; Updates</span>' . "\n";
        if ($unreadCount > 0) {
            $html .= '        <span class="notif-modal-count-pill" id="notifModalPill">' . $unreadCount . ' new</span>' . "\n";
        }
        $html .= '      </div>' . "\n";
        $html .= '      <div class="notif-header-actions">' . "\n";
        if ($unreadCount > 0) {
            $html .= '        <button type="button" class="notif-mark-all-btn" id="notifMarkAllBtn" onclick="markAllNotificationsRead()">✓ Mark all read</button>' . "\n";
        }
        $html .= '        <button type="button" class="notif-modal-close-btn" onclick="toggleNotificationsModal()">✕</button>' . "\n";
        $html .= '      </div>' . "\n";
        $html .= '    </div>' . "\n";

        // Modal Content / List
        $html .= '    <div class="notif-modal-body">' . "\n";
        if (empty($all)) {
            $html .= '      <div class="notif-empty-state">' . "\n";
            $html .= '        <span class="empty-icon">✨</span>' . "\n";
            $html .= '        <p>No notifications at this time.</p>' . "\n";
            $html .= '      </div>' . "\n";
        } else {
            $html .= '      <div class="notif-items-list">' . "\n";
            foreach ($all as $n) {
                $isRead = (int)$n['is_read'] === 1;
                $type = htmlspecialchars($n['type']);
                $icon = '📢';
                $badgeText = 'Notice';
                if ($type === 'release') {
                    $icon = '🚀';
                    $badgeText = 'System Update';
                } elseif ($type === 'danger') {
                    $icon = '🚨';
                    $badgeText = 'Critical Alert';
                } elseif ($type === 'warning') {
                    $icon = '⚠️';
                    $badgeText = 'Warning';
                } elseif ($type === 'tip') {
                    $icon = '💡';
                    $badgeText = 'Tip';
                }

                $html .= '        <div class="notif-item notif-type-' . $type . ($isRead ? ' is-read' : ' is-unread') . '" id="notif-card-' . (int)$n['id'] . '">' . "\n";
                $html .= '          <div class="notif-item-top">' . "\n";
                $html .= '            <div class="notif-meta">' . "\n";
                $html .= '              <span class="notif-type-badge">' . $icon . ' ' . $badgeText . '</span>' . "\n";
                if (!empty($n['version'])) {
                    $html .= '              <span class="notif-ver-tag">v' . htmlspecialchars($n['version']) . '</span>' . "\n";
                }
                $html .= '              <span class="notif-date">' . date('M j, Y H:i', strtotime($n['created_at'])) . '</span>' . "\n";
                $html .= '            </div>' . "\n";
                if (!$isRead) {
                    $html .= '            <button type="button" class="notif-item-read-btn" onclick="dismissNotification(' . (int)$n['id'] . ')" title="Mark as read">✓</button>' . "\n";
                }
                $html .= '          </div>' . "\n";

                $html .= '          <div class="notif-item-title">' . htmlspecialchars($n['title']) . '</div>' . "\n";
                $html .= '          <div class="notif-item-desc">' . nl2br(htmlspecialchars($n['short_description'])) . '</div>' . "\n";

                if (!empty($n['changelog_url'])) {
                    $html .= '          <div class="notif-item-bottom">' . "\n";
                    $html .= '            <a href="' . htmlspecialchars($n['changelog_url']) . '" target="_blank" rel="noopener noreferrer" class="notif-btn-changelog">📖 ' . (__('changelog', false) ?: 'View Full Changelog') . ' &rarr;</a>' . "\n";
                    $html .= '          </div>' . "\n";
                }
                $html .= '        </div>' . "\n";
            }
            $html .= '      </div>' . "\n";
        }
        $html .= '    </div>' . "\n";

        // Modal Footer (Admin management link)
        if ('A' === $userType) {
            $html .= '    <div class="notif-modal-footer">' . "\n";
            $html .= '      <a href="system_notifications.php" class="notif-admin-link">⚙️ Manage Announcements &amp; Broadcasts</a>' . "\n";
            $html .= '    </div>' . "\n";
        }

        $html .= '  </div>' . "\n";
        $html .= '</div>' . "\n";

        // JavaScript for modal toggle and AJAX dismiss/read
        $html .= '<script type="text/javascript">
function toggleNotificationsModal() {
    var overlay = document.getElementById("notifModalOverlay");
    if (!overlay) return;
    if (overlay.classList.contains("is-open")) {
        overlay.classList.remove("is-open");
    } else {
        overlay.classList.add("is-open");
    }
}
function handleNotifOverlayClick(e) {
    if (e.target && e.target.id === "notifModalOverlay") {
        toggleNotificationsModal();
    }
}
function dismissNotification(id) {
    var banner = document.getElementById("banner-notif-" + id);
    if (banner) {
        banner.style.opacity = "0";
        setTimeout(function() { banner.remove(); }, 250);
    }
    var card = document.getElementById("notif-card-" + id);
    if (card) {
        card.classList.remove("is-unread");
        card.classList.add("is-read");
        var btn = card.querySelector(".notif-item-read-btn");
        if (btn) btn.remove();
    }
    // Send AJAX
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "notification_action.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var res = JSON.parse(xhr.responseText);
                var badge = document.getElementById("notifBadgeCount");
                var bell = document.getElementById("notifBellBtn");
                if (badge) {
                    if (res.unreadCount > 0) {
                        badge.textContent = res.unreadCount;
                    } else {
                        badge.remove();
                        if (bell) bell.classList.remove("has-unread");
                    }
                }
            } catch(e) {}
        }
    };
    xhr.send("action=mark_read&id=" + encodeURIComponent(id) + "&token=' . $token . '");
}
function markAllNotificationsRead() {
    var items = document.querySelectorAll(".notif-item.is-unread");
    items.forEach(function(item) {
        item.classList.remove("is-unread");
        item.classList.add("is-read");
        var btn = item.querySelector(".notif-item-read-btn");
        if (btn) btn.remove();
    });
    var banners = document.getElementById("announcementsContainer");
    if (banners) banners.remove();
    var badge = document.getElementById("notifBadgeCount");
    if (badge) badge.remove();
    var bell = document.getElementById("notifBellBtn");
    if (bell) bell.classList.remove("has-unread");
    var markBtn = document.getElementById("notifMarkAllBtn");
    if (markBtn) markBtn.remove();
    var pill = document.getElementById("notifModalPill");
    if (pill) pill.remove();

    var xhr = new XMLHttpRequest();
    xhr.open("POST", "notification_action.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.send("action=mark_all_read&token=' . $token . '");
}
</script>' . "\n";

        return $html;
    }
}
