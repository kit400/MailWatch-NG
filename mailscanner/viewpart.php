<?php

/*
 * MailWatch for MailScanner
 * Copyright (C) 2003-2011  Steve Freegard (steve@freegard.name)
 * Copyright (C) 2011  Garrod Alwood (garrod.alwood@lorodoes.com)
 * Copyright (C) 2014-2021  MailWatch Team (https://github.com/mailwatch/1.2.0/graphs/contributors)
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.
 *
 * In addition, as a special exception, the copyright holder gives permission to link the code of this program with
 * those files in the PEAR library that are licensed under the PHP License (or with modified versions of those files
 * that use the same license as those files), and distribute linked combinations including the two.
 * You must obey the GNU General Public License in all respects for all of the code used other than those files in the
 * PEAR library that are licensed under the PHP License. If you modify this program, you may extend this exception to
 * your version of the program, but you are not obligated to do so.
 * If you do not wish to do so, delete this exception statement from your version.
 *
 * You should have received a copy of the GNU General Public License along with this program; if not, write to the Free
 * Software Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/pear/Mail/mimeDecode.php';

require __DIR__ . '/login.function.php';

ini_set('memory_limit', (string) MEMORY_LIMIT);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

if (!isset($_GET['id'])) {
    exit(__('nomessid58'));
}

if (false === checkToken($_GET['token'])) {
    header('Location: login.php?error=pagetimeout');
    exit;
}

$message_id = deepSanitizeInput($_GET['id'], 'url');
if (!validateInput($message_id, 'msgid')) {
    exit(__('dievalidate99'));
}
// See if message is local
dbconn(); // required db link for mysql_real_escape_string
$result = dbquery(
    "SELECT hostname, DATE_FORMAT(date,'%Y%m%d') AS date FROM maillog WHERE id='" .
    $message_id . "' AND "
    . $_SESSION['global_filter']
);
$message_data = $result->fetch_object();

if (!$message_data) {
    exit(__('mess58') . " '" . $message_id . "' " . __('notfound58') . "\n");
}

if (RPC_ONLY || !is_local($message_data->hostname)) {
    // Host is remote - use XML-RPC
    // $client = new xmlrpc_client(constant('RPC_RELATIVE_PATH').'/rpcserver.php', $host, 80);
    $input = new xmlrpcval($message_id);
    $parameters = [$input];
    $msg = new xmlrpcmsg('return_quarantined_file', $parameters);
    // $rsp = $client->send($msg);
    $rsp = xmlrpc_wrapper($message_data->hostname, $msg);
    if (0 === $rsp->faultCode()) {
        $response = php_xmlrpc_decode($rsp->value());
    } else {
        exit(__('error58') . ' ' . $rsp->faultString());
    }
    $file = base64_decode($response);
} else {
    // build filename path
    $quarantine_dir = get_conf_var('QuarantineDir');
    $filename = '';
    switch (true) {
        case file_exists($quarantine_dir . '/' . $message_data->date . '/nonspam/' . $message_id):
            $filename = $message_data->date . '/nonspam/' . $message_id;
            break;
        case file_exists($quarantine_dir . '/' . $message_data->date . '/spam/' . $message_id):
            $filename = $message_data->date . '/spam/' . $message_id;
            break;
        case file_exists($quarantine_dir . '/' . $message_data->date . '/mcp/' . $message_id):
            $filename = $message_data->date . '/mcp/' . $message_id;
            break;
        case file_exists($quarantine_dir . '/' . $message_data->date . '/' . $message_id . '/message'):
            $filename = $message_data->date . '/' . $message_id . '/message';
            break;
    }

    if (!@file_exists($quarantine_dir . '/' . $filename)) {
        exit(__('errornfd58') . "\n");
    }
    $file = file_get_contents($quarantine_dir . '/' . $filename);
}

$params['include_bodies'] = true;
$params['decode_bodies'] = 'UTF8//TRANSLIT/IGNORE';
$params['decode_headers'] = true;
$params['input'] = $file;

$Mail_mimeDecode = new Mail_mimeDecode($file);
$structure = $Mail_mimeDecode->decode($params);
$mime_struct = $Mail_mimeDecode->getMimeNumbers($structure);

if (isset($_GET['part'])) {
    $part = deepSanitizeInput($_GET['part'], 'url');
    if (!validateInput($part, 'mimepart')) {
        exit(__('dievalidate99'));
    }

    // Make sure that part being requested actually exists
    if (!isset($mime_struct[$part])) {
        exit(__('part58') . ' ' . $part . ' ' . __('notfound58') . "\n");
    }
} else {
    exit(__('part58') . __('notfound58') . "\n");
}

/**
 * @param stdClass $structure a Mail_mimeDecode structure object
 */
function decode_structure($structure)
{
    $type = $structure->ctype_primary . '/' . $structure->ctype_secondary;
    switch ($type) {
        case 'text/plain':
            header('Content-Type: text/html; charset=UTF-8');
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline';");
            /*
            if (isset ($structure->ctype_parameters['charset']) &&
                strtolower($structure->ctype_parameters['charset']) == 'utf-8'
            ) {
                $structure->body = utf8_decode($structure->body);
            }
            */
            if (isset($structure->ctype_parameters['charset'])) {
                $charset = strtoupper($structure->ctype_parameters['charset']);
                if ('WINDOWS-1255' === $charset ) {
                    $structure->body = iconv('ISO-8859-8//TRANSLIT', 'UTF-8', $structure->body);
                } elseif ( preg_match('/^ISO-8859-([1-9]|10|1[3-6])$/',$charset)) {
                    $structure->body = iconv(sprintf('%s//TRANSLIT',$charset), 'UTF-8', $structure->body);
                } elseif ('UTF-8' !== $charset) {
                    $structure->body = getUTF8String($structure->body);
                }
            }
            echo '<!DOCTYPE html>
 <html>
 <head>
 <meta charset="utf-8">
 <title>' . __('title58') . '</title>
 </head>
 <body>
 <pre>' . htmlspecialchars(wordwrap($structure->body), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>
 </body>
 </html>' . "\n";
            break;
        case 'text/html':
            header('Content-Type: text/html; charset=UTF-8');
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src http: https: data: cid:; font-src 'none'; media-src 'none'; object-src 'none'; frame-src 'none'; base-uri 'none'; form-action 'none';");
            echo '<!DOCTYPE html>' . "\n";
            if (isset($structure->ctype_parameters['charset'])) {
                $charset = strtoupper($structure->ctype_parameters['charset']);
                if ('WINDOWS-1255' === $charset ) {
                    $structure->body = iconv('ISO-8859-8//TRANSLIT', 'UTF-8', $structure->body);
                } elseif ( preg_match('/^ISO-8859-([1-9]|10|1[3-6])$/',$charset)) {
                    $structure->body = iconv(sprintf('%s//TRANSLIT',$charset), 'UTF-8', $structure->body);
                } elseif ('UTF-8' !== $charset) {
                    $structure->body = getUTF8String($structure->body);
                }
            }
            $stripHtml = defined('STRIP_HTML') ? (bool) STRIP_HTML : true;
            $allowedTags = defined('ALLOWED_TAGS') ? ALLOWED_TAGS : null;
            echo sanitizeEmailHtml($structure->body, $stripHtml, $allowedTags);
            break;
        case 'multipart/alternative':
            break;
        case 'message/partial':
            // @link https://tools.ietf.org/html/rfc2046#section-5.2.2
            header('Content-Type: application/octet-stream');
            header("Content-Security-Policy: default-src 'none'; sandbox;");
            // get message id
            preg_match('/.*id="?([^";]*)"?.*/', $structure->headers['content-type'], $identifier);
            // get part number
            preg_match("/.*number=([\d]*).*/", $structure->headers['content-type'], $partNumber);
            // get total parts
            preg_match("/.*total=([\d]*).*/", $structure->headers['content-type'], $totalParts);

            // build filename
            $filename = isset($identifier[1]) ? $identifier[1] : 'partialMessage';
            if (isset($partNumber[1])) {
                $filename .= ' - Part ' . $partNumber[1];
            }
            if (isset($totalParts[1])) {
                $filename .= ' of ' . $totalParts[1];
            }
            $filename .= '.bin';
            $filename = sanitizeAttachmentFilename($filename);

            header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
            echo $structure->body;
            break;
        default:
            $filename = 'attachment.bin';
            if (isset($structure->d_parameters['filename'])) {
                $filename = $structure->d_parameters['filename'];
            } elseif (isset($structure->ctype_parameters['name'])) {
                $filename = $structure->ctype_parameters['name'];
            } elseif (isset($structure->headers['content-disposition'])) {
                if (preg_match('/filename\*?=(?:UTF-8\'\')?["\']?([^";\r\n]+)["\']?/i', $structure->headers['content-disposition'], $fnMatch)) {
                    $filename = $fnMatch[1];
                }
            }
            $safeFilename = sanitizeAttachmentFilename($filename);

            // Determine content type safely
            $contentType = 'application/octet-stream';
            if (isset($structure->headers['content-type'])) {
                $ctParts = explode(';', $structure->headers['content-type'], 2);
                $contentType = trim($ctParts[0]);
            }

            // Dangerous or active MIME types that should never be rendered inline in browser
            $dangerousMimeTypes = [
                'text/html', 'text/javascript', 'application/javascript', 'application/x-javascript',
                'image/svg+xml', 'text/xml', 'application/xml', 'application/xhtml+xml',
                'application/pdf', 'text/x-php', 'application/x-httpd-php'
            ];

            // Always force Content-Disposition: attachment for downloaded parts to prevent browser execution
            header('Content-Type: ' . (in_array(strtolower($contentType), $dangerousMimeTypes, true) ? 'application/octet-stream' : $contentType));
            header('Content-Disposition: attachment; filename="' . $safeFilename . '"; filename*=UTF-8\'\'' . rawurlencode($safeFilename));
            header("Content-Security-Policy: default-src 'none'; sandbox;");
            echo $structure->body;
            break;
    }
}

decode_structure($mime_struct[$part]);

// Close any open db connections
dbclose();
