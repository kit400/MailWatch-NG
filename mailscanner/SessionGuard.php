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

/**
 * SessionGuard - Unified Application Session & Privilege Security Guard
 *
 * Enforces session authentication, expiration, administrative revocation,
 * and role consistency across all entry points (HTML pages, AJAX endpoints,
 * direct download/viewpart handlers, and quarantine operations) BEFORE any
 * output or side effects occur (MW-04 fix).
 */
class SessionGuard
{
    /**
     * Cache for user rows within a single request execution, keyed by username.
     *
     * @var array
     */
    private static $cachedUsers = [];

    /**
     * Enforce authentication, active session expiry, and privilege consistency.
     *
     * @param bool $keepAlive Whether to update login_expiry (default false; true on non-refreshing navigation)
     * @return bool Returns true if valid, terminates request otherwise.
     */
    public static function enforce($keepAlive = false)
    {
        // 1. Session must have an authenticated username
        if (!isset($_SESSION['myusername']) || '' === (string)$_SESSION['myusername']) {
            self::terminate('baduser', 'Unauthenticated request');
        }

        $username = (string)$_SESSION['myusername'];

        // 2. Fetch active user status from database
        $user = self::getUserRecord($username);
        if (!$user) {
            self::terminate('baduser', 'User account does not exist or has been removed');
        }

        // 3. Check login expiry / administrative revocation
        $loginExpiry = $user['login_expiry'] ?? null;
        if ('-1' === (string)$loginExpiry) {
            self::terminate('timeout', 'User session was administratively revoked');
        }

        if ('0' !== (string)$loginExpiry && null !== $loginExpiry) {
            $expiryTime = (int)$loginExpiry;
            if ($expiryTime > 0 && $expiryTime <= time()) {
                self::terminate('timeout', 'User session has expired due to inactivity');
            }
        }

        // 4. Check privilege change
        $currentRole = (string)($user['type'] ?? '');
        $sessionRole = (string)($_SESSION['user_type'] ?? '');
        if ($sessionRole !== $currentRole) {
            self::terminate('privilege_changed', sprintf(
                'Privilege change detected (session: %s, db: %s)',
                $sessionRole,
                $currentRole
            ));
        }

        // 5. Optional keep-alive for non-refreshing interactive requests
        if ($keepAlive) {
            self::updateExpiry($username, $user);
        }

        return true;
    }

    /**
     * Fetch user record from database with caching for the current request.
     *
     * @param string $username
     * @return array|null
     */
    public static function getUserRecord($username)
    {
        $key = (string)$username;
        if (array_key_exists($key, self::$cachedUsers)) {
            return self::$cachedUsers[$key];
        }

        if (!class_exists('database', false)) {
            return null;
        }

        $safeUser = safe_value($username);
        $sql = "SELECT id, username, type, login_expiry, login_timeout FROM users WHERE username='$safeUser'";

        try {
            $res = dbquery($sql, false);
            if ($res && $res->num_rows > 0) {
                self::$cachedUsers[$key] = $res->fetch_assoc();
                return self::$cachedUsers[$key];
            }
        } catch (\Throwable $e) {
            // Fall through to null on error
        }

        self::$cachedUsers[$key] = null;
        return null;
    }

    /**
     * Reset cached user record (useful for testing and state transitions).
     */
    public static function resetCache()
    {
        self::$cachedUsers = [];
    }

    /**
     * Determine whether the incoming request is an AJAX, JSON API, or direct non-HTML request.
     *
     * @return bool
     */
    public static function isApiOrAjaxRequest()
    {
        // 1. HTTP header: X-Requested-With: XMLHttpRequest
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }

        // 2. HTTP header: Accept: application/json
        if (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            return true;
        }

        // 3. Known AJAX action parameters
        if (isset($_REQUEST['action']) && in_array($_REQUEST['action'], [
            'save_layout', 'reset_layout', 'get_widget_body', 'get_widget_html',
            'get_notifications', 'mark_read', 'mark_all_read', 'check_updates'
        ], true)) {
            return true;
        }

        // 4. Non-HTML endpoint scripts
        $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (in_array($scriptName, ['viewpart.php', 'notification_action.php', 'graph.php'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Terminate the session and abort execution with appropriate response code.
     *
     * @param string $reason Error code for login page or API response
     * @param string $message Detailed description for audit log / response
     */
    public static function terminate($reason = 'timeout', $message = 'Session expired or unauthorized')
    {
        $username = isset($_SESSION['myusername']) ? (string)$_SESSION['myusername'] : 'unknown';

        if (function_exists('audit_log')) {
            audit_log(sprintf('SessionGuard: Terminated session for user %s: %s', $username, $message));
        }

        // Administratively invalidate the session in DB if username is known
        if (isset($_SESSION['myusername']) && class_exists('database', false)) {
            try {
                $safeUser = safe_value($_SESSION['myusername']);
                @dbquery("UPDATE users SET login_expiry='-1' WHERE username='$safeUser'", false);
            } catch (\Throwable $e) {
            }
        }

        // Clear session data
        $_SESSION = [];

        // Delete session cookie
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                true,
                true
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }

        // If AJAX, API, or direct non-HTML endpoint (e.g. viewpart.php)
        if (self::isApiOrAjaxRequest()) {
            if (!headers_sent()) {
                header('HTTP/1.1 401 Unauthorized');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
            }

            if ((isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                || (isset($_REQUEST['action']) && stripos($_SERVER['SCRIPT_NAME'] ?? '', 'dashboard.php') !== false)
                || (stripos($_SERVER['SCRIPT_NAME'] ?? '', 'notification_action.php') !== false)) {
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=UTF-8');
                }
                echo json_encode([
                    'success' => false,
                    'error' => $reason,
                    'message' => $message
                ]);
            } else {
                if (!headers_sent()) {
                    header('Content-Type: text/plain; charset=UTF-8');
                }
                echo "Unauthorized: " . $message . "\n";
            }
            exit;
        }

        // Regular browser request: redirect to login page
        $safeReason = preg_replace('/[^a-zA-Z0-9_-]/', '', $reason);
        if (!headers_sent()) {
            header('Location: login.php?error=' . urlencode($safeReason));
        } else {
            echo '<meta http-equiv="refresh" content="0;url=login.php?error=' . htmlspecialchars($safeReason, ENT_QUOTES) . '">';
        }
        exit;
    }

    /**
     * Update login expiry for active interactive session.
     *
     * @param string $username
     * @param array  $user
     */
    public static function updateExpiry($username, array $user)
    {
        $loginTimeout = $user['login_timeout'] ?? '-1';
        if ('-1' === (string)$loginTimeout) {
            $timeoutVal = (defined('SESSION_TIMEOUT') && SESSION_TIMEOUT > 0) ? (int)SESSION_TIMEOUT : 600;
        } elseif ('0' === (string)$loginTimeout) {
            $timeoutVal = 0;
        } else {
            $timeoutVal = (int)$loginTimeout;
        }

        $expiryVal = ($timeoutVal === 0) ? 0 : (time() + $timeoutVal);
        $safeUser = safe_value($username);
        $now = time();
        @dbquery("UPDATE users SET login_expiry='$expiryVal', last_login='$now' WHERE username='$safeUser'", false);

        $key = (string)$username;
        if (isset(self::$cachedUsers[$key]) && is_array(self::$cachedUsers[$key])) {
            self::$cachedUsers[$key]['login_expiry'] = $expiryVal;
        }
    }
}
