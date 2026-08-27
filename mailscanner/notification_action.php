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

header('Content-Type: application/json; charset=UTF-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$username = $_SESSION['myusername'] ?? '';
$userType = $_SESSION['user_type'] ?? 'U';

if (empty($username)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ('mark_read' === $action) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        SystemNotifications::markAsRead($id, $username);
    }
    $unread = SystemNotifications::getUnreadNotifications($username, $userType);
    echo json_encode(['success' => true, 'unreadCount' => count($unread)]);
    exit;
}

if ('mark_all_read' === $action) {
    SystemNotifications::markAllAsRead($username, $userType);
    echo json_encode(['success' => true, 'unreadCount' => 0]);
    exit;
}

if ('check_updates' === $action) {
    if ('A' !== $userType) {
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
    $res = SystemNotifications::checkForUpdates(true);
    echo json_encode(['success' => true, 'result' => $res]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
exit;
