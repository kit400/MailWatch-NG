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

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../notifications.inc.php';

$options = getopt('', [
    'type::',
    'title:',
    'version::',
    'desc:',
    'changelog::',
    'audience::',
    'email-all',
    'no-banner',
]);

if (empty($options['title']) || empty($options['desc'])) {
    echo "Usage: php send_update_notification.php --title=\"...\" --desc=\"...\" [options]\n";
    echo "Options:\n";
    echo "  --type=release|danger|warning|info|tip  (default: release)\n";
    echo "  --title=\"...\"                           Title of announcement\n";
    echo "  --version=\"...\"                         Version number (e.g. 6.0.0)\n";
    echo "  --desc=\"...\"                            Short description of changes\n";
    echo "  --changelog=\"...\"                       URL to full changelog\n";
    echo "  --audience=ALL|A|D|U                    Target audience (default: ALL)\n";
    echo "  --email-all                             Send email broadcast to all target users\n";
    echo "  --no-banner                             Do not display top banner\n";
    exit(1);
}

$type = $options['type'] ?? 'release';
$title = $options['title'];
$version = $options['version'] ?? null;
$desc = $options['desc'];
$changelog = $options['changelog'] ?? null;
$audience = $options['audience'] ?? 'ALL';
$isBanner = !isset($options['no-banner']);

$id = SystemNotifications::createNotification([
    'type' => $type,
    'title' => $title,
    'version' => $version,
    'short_description' => $desc,
    'changelog_url' => $changelog,
    'target_role' => $audience,
    'is_banner' => $isBanner ? 1 : 0,
    'is_active' => 1,
]);

echo "Created notification #$id successfully.\n";

if (isset($options['email-all'])) {
    $sent = SystemNotifications::broadcastNotificationEmail($id);
    echo "Broadcast email sent to $sent recipients.\n";
}
