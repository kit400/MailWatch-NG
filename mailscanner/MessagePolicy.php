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
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program; if not, write to the Free
 * Software Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

/**
 * MessagePolicy - Centralized Authorization & Security Policy for Email & Quarantine Operations
 *
 * Enforces server-side permissions for:
 * - Viewing email contents and MIME parts (quarantine/archive)
 * - Releasing quarantined messages and dangerous objects
 * - Changing recipient address (alt_recpt)
 * - Training SpamAssassin (sa-learn)
 * - Deleting quarantined items
 *
 * Roles:
 * - 'A' (Administrator): Full access.
 * - 'D' (Domain Administrator): Governed by configuration flags:
 *       DOMAINADMIN_CAN_SEE_DANGEROUS_CONTENTS (default: false)
 *       DOMAINADMIN_CAN_RELEASE_DANGEROUS_CONTENTS (default: false)
 * - 'U' (User): Restricted to own non-dangerous emails; can never release dangerous content
 *       or redirect email to alternate recipients.
 */
class MessagePolicy
{
    /**
     * Resolve the active user role from parameter or session.
     *
     * @param string|null $userType Role ('A', 'D', 'U') or null to use current session
     * @return string
     */
    public static function resolveRole($userType = null)
    {
        if ($userType !== null && '' !== $userType) {
            return (string) $userType;
        }
        return isset($_SESSION['user_type']) ? (string) $_SESSION['user_type'] : '';
    }

    /**
     * Check if user is an Administrator
     *
     * @param string|null $userType
     * @return bool
     */
    public static function isAdmin($userType = null)
    {
        return 'A' === self::resolveRole($userType);
    }

    /**
     * Check if user is a Domain Administrator
     *
     * @param string|null $userType
     * @return bool
     */
    public static function isDomainAdmin($userType = null)
    {
        return 'D' === self::resolveRole($userType);
    }

    /**
     * Check if user is a regular User
     *
     * @param string|null $userType
     * @return bool
     */
    public static function isUser($userType = null)
    {
        return 'U' === self::resolveRole($userType);
    }

    /**
     * Can the user view/see dangerous contents (virus, malware, bad headers, active infections)?
     *
     * @param string|null $userType
     * @return bool
     */
    public static function canSeeDangerousContents($userType = null)
    {
        $role = self::resolveRole($userType);
        if ('A' === $role) {
            return true;
        }
        if ('D' === $role) {
            return defined('DOMAINADMIN_CAN_SEE_DANGEROUS_CONTENTS') && true === constant('DOMAINADMIN_CAN_SEE_DANGEROUS_CONTENTS');
        }
        return false;
    }

    /**
     * Can the user release dangerous contents (virus, malware, bad headers, active infections)?
     *
     * @param string|null $userType
     * @return bool
     */
    public static function canReleaseDangerousContents($userType = null)
    {
        $role = self::resolveRole($userType);
        if ('A' === $role) {
            return true;
        }
        if ('D' === $role) {
            return defined('DOMAINADMIN_CAN_RELEASE_DANGEROUS_CONTENTS') && true === constant('DOMAINADMIN_CAN_RELEASE_DANGEROUS_CONTENTS');
        }
        return false;
    }

    /**
     * Check if a single quarantine item array is dangerous.
     *
     * @param array $item
     * @return bool
     */
    public static function isDangerousItem(array $item)
    {
        if (isset($item['dangerous']) && ('Y' === (string) $item['dangerous'] || true === $item['dangerous'] || '1' === (string) $item['dangerous'])) {
            return true;
        }
        foreach (['virusinfected', 'nameinfected', 'otherinfected'] as $k) {
            if (isset($item[$k])) {
                $val = (string) $item[$k];
                if ('0' !== $val && '' !== $val && 'N' !== $val && 'false' !== strtolower($val)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Determine if a message or quarantine item/list is marked as dangerous.
     *
     * @param mixed $target Message object, quarantine item array, array of items, or message ID string
     * @return bool
     */
    public static function isDangerous($target)
    {
        if (empty($target)) {
            return false;
        }

        // Case 1: Array of items (e.g. $quarantined list) or single item array
        if (is_array($target)) {
            if (isset($target[0]) && is_array($target[0])) {
                foreach ($target as $item) {
                    if (self::isDangerousItem($item)) {
                        return true;
                    }
                }
                return false;
            }
            return self::isDangerousItem($target);
        }

        // Case 2: Object from database (e.g. $message from maillog)
        if (is_object($target)) {
            $v = isset($target->virusinfected) ? (string) $target->virusinfected : '0';
            $n = isset($target->nameinfected) ? (string) $target->nameinfected : '0';
            $o = isset($target->otherinfected) ? (string) $target->otherinfected : '0';

            if (('0' !== $v && '' !== $v && 'N' !== $v && 'false' !== strtolower($v))
                || ('0' !== $n && '' !== $n && 'N' !== $n && 'false' !== strtolower($n))
                || ('0' !== $o && '' !== $o && 'N' !== $o && 'false' !== strtolower($o))) {
                return true;
            }

            if (isset($target->dangerous) && ('Y' === (string) $target->dangerous || true === $target->dangerous || '1' === (string) $target->dangerous)) {
                return true;
            }

            return false;
        }

        // Case 3: Message ID string
        if (is_string($target) && function_exists('dbquery')) {
            $msgId = safe_value($target);
            $sql = "SELECT virusinfected, nameinfected, otherinfected FROM maillog WHERE id = '$msgId'";
            $res = dbquery($sql);
            if ($res && $row = $res->fetch_object()) {
                return self::isDangerous($row);
            }
        }

        return false;
    }

    /**
     * Can user view the message / item content?
     *
     * @param mixed $target Message object, quarantine item/list, or message ID
     * @param string|null $userType
     * @return bool
     */
    public static function canView($target, $userType = null)
    {
        if (!self::isDangerous($target)) {
            return true;
        }
        return self::canSeeDangerousContents($userType);
    }

    /**
     * Can user release a specific quarantine item?
     *
     * @param array $item Single quarantine item array
     * @param string|null $userType
     * @return bool
     */
    public static function canReleaseItem(array $item, $userType = null)
    {
        if (!self::isDangerousItem($item)) {
            return true;
        }
        return self::canReleaseDangerousContents($userType);
    }

    /**
     * Can user release this message or specific items?
     *
     * @param mixed $target Quarantine items list, item, message object, or message ID
     * @param string|null $userType
     * @param array|null $selectedIndices Optional array of item indices/IDs to check
     * @return bool
     */
    public static function canRelease($target, $userType = null, $selectedIndices = null)
    {
        $role = self::resolveRole($userType);

        // Admins can always release
        if ('A' === $role) {
            return true;
        }

        // Check if any relevant item is dangerous
        $hasDangerous = false;

        if (is_array($target) && isset($target[0]) && is_array($target[0])) {
            if ($selectedIndices !== null && is_array($selectedIndices)) {
                // Check only selected items (look up by item 'id' or array key)
                $selectedMap = array_flip($selectedIndices);
                foreach ($target as $idx => $item) {
                    $itemId = isset($item['id']) ? $item['id'] : $idx;
                    if (isset($selectedMap[$idx]) || isset($selectedMap[$itemId])) {
                        if (self::isDangerousItem($item)) {
                            $hasDangerous = true;
                            break;
                        }
                    }
                }
            } else {
                // Check all items
                $hasDangerous = self::isDangerous($target);
            }
        } else {
            $hasDangerous = self::isDangerous($target);
        }

        if (!$hasDangerous) {
            // Clean quarantine message can be released by authorized users
            return true;
        }

        // Dangerous message requires explicit permission
        return self::canReleaseDangerousContents($role);
    }

    /**
     * Can user redirect the message to an alternate recipient (alt_recpt)?
     *
     * @param mixed $target
     * @param string|null $userType
     * @return bool
     */
    public static function canChangeRecipient($target = null, $userType = null)
    {
        $role = self::resolveRole($userType);

        // Regular users can NEVER redirect to an alternate recipient
        if ('U' === $role) {
            return false;
        }

        // Administrators can always redirect
        if ('A' === $role) {
            return true;
        }

        // Domain Administrators: allowed for clean messages, or if dangerous, only if allowed to release dangerous
        if ('D' === $role) {
            if (empty($target)) {
                return true;
            }
            if (!self::isDangerous($target)) {
                return true;
            }
            return self::canReleaseDangerousContents($role);
        }

        return false;
    }

    /**
     * Can user delete quarantined items?
     *
     * @param mixed $target
     * @param string|null $userType
     * @return bool
     */
    public static function canDelete($target = null, $userType = null)
    {
        $role = self::resolveRole($userType);
        // All authenticated roles (A, D, U) can delete their quarantined items
        return in_array($role, ['A', 'D', 'U'], true);
    }

    /**
     * Can user train SpamAssassin (sa-learn)?
     *
     * @param mixed $target
     * @param string|null $userType
     * @return bool
     */
    public static function canLearn($target = null, $userType = null)
    {
        if (defined('UseSpamAssassin') && 'NO' === strtoupper(constant('UseSpamAssassin'))) {
            return false;
        }
        $role = self::resolveRole($userType);
        return in_array($role, ['A', 'D', 'U'], true);
    }
}
