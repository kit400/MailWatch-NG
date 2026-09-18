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
 * MailWatchQueueParser - Parser for MTA queue entries (Exim & Sendmail)
 *
 * Extracts recipient addresses and status lines from queue files
 * for both Exim and Sendmail spool layouts.
 */
class MailWatchQueueParser
{
    const EXIM_RECIPIENT_REGEX = '/^([-=+.\w]+@[-.\w]+)$/';
    const SENDMAIL_RECIPIENT_REGEX = '/^R[^:]*:(.+)$/';

    /**
     * Parse an Exim queue spool line to extract the recipient email address.
     *
     * @param string $line
     * @return string|null
     */
    public static function parseEximRecipient($line)
    {
        if (preg_match(self::EXIM_RECIPIENT_REGEX, trim((string)$line), $match)) {
            return $match[1];
        }
        return null;
    }

    /**
     * Parse a Sendmail queue spool line (qf*) to extract the recipient email address.
     *
     * @param string $line
     * @return string|null
     */
    public static function parseSendmailRecipient($line)
    {
        if (preg_match(self::SENDMAIL_RECIPIENT_REGEX, trim((string)$line), $match)) {
            return $match[1];
        }
        return null;
    }
}
