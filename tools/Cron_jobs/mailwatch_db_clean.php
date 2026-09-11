#!/usr/bin/php -q
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

// Auto-detect functions.php relative to this script, with fallback to default path
$pathToFunctions = dirname(dirname(__DIR__)) . '/mailscanner/functions.php';
if (!@is_file($pathToFunctions)) {
    // Edit if you changed webapp directory from default
    $pathToFunctions = '/var/www/html/mailscanner/functions.php';
}
if (!@is_file($pathToFunctions)) {
    exit('Error: Cannot find functions.php file in "' . $pathToFunctions . '": edit ' . __FILE__ . ' and set the right path' . PHP_EOL);
}
require_once $pathToFunctions;

ini_set('error_log', 'syslog');
ini_set('html_errors', 'off');
ini_set('display_errors', 'on');
ini_set('implicit_flush', 'false');

if (!defined('RECORD_DAYS_TO_KEEP') || RECORD_DAYS_TO_KEEP < 1) {
    exit('The variable RECORD_DAYS_TO_KEEP is empty, please set a value in conf.php.');
}

if (!defined('AUDIT_DAYS_TO_KEEP') || AUDIT_DAYS_TO_KEEP < 1) {
    exit('The variable AUDIT_DAYS_TO_KEEP is empty, please set a value in conf.php.');
}

// Batch size for DELETE operations (reduces table locking on large databases)
$batchSize = defined('DB_CLEAN_BATCH_SIZE') ? (int) DB_CLEAN_BATCH_SIZE : 10000;

// Whether to run OPTIMIZE TABLE after cleanup
$runOptimize = !defined('DB_CLEAN_OPTIMIZE') || DB_CLEAN_OPTIMIZE === true;

// Maximum execution time in seconds per table cleanup (0 = unlimited)
$maxExecutionTime = defined('DB_CLEAN_MAX_EXECUTION_TIME') ? (int) DB_CLEAN_MAX_EXECUTION_TIME : 0;

// Maximum batches per table (0 = unlimited)
$maxBatches = defined('DB_CLEAN_MAX_BATCHES') ? (int) DB_CLEAN_MAX_BATCHES : 0;

// Pause in milliseconds between batches to reduce server load
$sleepMs = defined('DB_CLEAN_SLEEP_MS') ? (int) DB_CLEAN_SLEEP_MS : 0;

/**
 * Delete rows in batches to avoid long table locks
 *
 * @param string $table            Table name
 * @param string $whereClause      WHERE clause (without WHERE keyword)
 * @param int    $batchSize        Number of rows to delete per batch (0 = no batching)
 * @param int    $maxBatches       Maximum batches to process (0 = unlimited / use config)
 * @param int    $maxExecutionTime Maximum execution time in seconds (0 = unlimited / use config)
 * @param int    $sleepMs          Pause in milliseconds between batches (0 = use config)
 *
 * @return int Total number of deleted rows
 */
function deleteInBatches($table, $whereClause, $batchSize, $maxBatches = 0, $maxExecutionTime = 0, $sleepMs = 0)
{
    $totalDeleted = 0;
    $batchCount = 0;
    $startTime = microtime(true);

    if ($maxBatches <= 0 && defined('DB_CLEAN_MAX_BATCHES')) {
        $maxBatches = (int) DB_CLEAN_MAX_BATCHES;
    }
    if ($maxExecutionTime <= 0 && defined('DB_CLEAN_MAX_EXECUTION_TIME')) {
        $maxExecutionTime = (int) DB_CLEAN_MAX_EXECUTION_TIME;
    }
    if ($sleepMs <= 0 && defined('DB_CLEAN_SLEEP_MS')) {
        $sleepMs = (int) DB_CLEAN_SLEEP_MS;
    }

    if ($batchSize <= 0) {
        // No batching - delete all in one query
        $sql = "DELETE LOW_PRIORITY FROM {$table} WHERE {$whereClause}";
        try {
            $deleted = dbexecute($sql, false);
            if ($deleted < 0) {
                $link = dbconn();
                $err = isset($link->error) ? $link->error : 'unknown error';
                error_log("mailwatch_db_clean: error deleting from {$table}: {$err}");
                return 0;
            }
            return $deleted;
        } catch (\Throwable $e) {
            error_log("mailwatch_db_clean: exception deleting from {$table}: " . $e->getMessage());
            return 0;
        }
    }

    // Delete in batches
    do {
        // Check execution budgets before executing next batch
        if ($maxExecutionTime > 0 && (microtime(true) - $startTime) >= $maxExecutionTime) {
            error_log("mailwatch_db_clean: time budget exceeded ({$maxExecutionTime}s) for {$table}, stopping batch delete. Total deleted: {$totalDeleted} rows in {$batchCount} batches.");
            break;
        }
        if ($maxBatches > 0 && $batchCount >= $maxBatches) {
            error_log("mailwatch_db_clean: batch budget reached ({$maxBatches} batches) for {$table}, stopping batch delete. Total deleted: {$totalDeleted} rows.");
            break;
        }

        $sql = "DELETE LOW_PRIORITY FROM {$table} WHERE {$whereClause} LIMIT {$batchSize}";
        try {
            $deleted = dbexecute($sql, false);
            if ($deleted < 0) {
                $link = dbconn();
                $err = isset($link->error) ? $link->error : 'unknown error';
                error_log("mailwatch_db_clean: error deleting from {$table} in batch " . ($batchCount + 1) . ": {$err}");
                break;
            }
        } catch (\Throwable $e) {
            error_log("mailwatch_db_clean: exception deleting from {$table} in batch " . ($batchCount + 1) . ": " . $e->getMessage());
            break;
        }

        $batchCount++;
        $totalDeleted += $deleted;

        // Brief pause between batches if configured (reduces server load & replication lag)
        if ($deleted > 0 && $sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    } while ($deleted > 0);

    return $totalDeleted;
}

// Only execute cleanup when invoked directly as a script (not when included in tests)
if (!defined('PHPUNIT_RUNNING') && !defined('MAILWATCH_TEST_RUNNER')) {
    // Cleaning the maillog table
    $deletedMaillog = deleteInBatches('maillog', 'timestamp < (NOW() - INTERVAL ' . RECORD_DAYS_TO_KEEP . ' DAY)', $batchSize);
    if ($deletedMaillog > 0) {
        error_log("mailwatch_db_clean: cleaned {$deletedMaillog} rows from maillog");
    }

    // Cleaning the mta_log and optionally the mta_log_id table
    $sqlcheck = "SHOW TABLES LIKE 'mtalog_ids'";
    $tablecheck = dbquery($sqlcheck, false);
    $mta = get_conf_var('mta');
    $optimize_mtalog_id = '';
    if (('postfix' === $mta || 'msmail' === $mta) && $tablecheck && $tablecheck->num_rows > 0) {
        // version for postfix with mtalog_ids enabled
        // Delete mtalog_ids entries that reference old mtalog records, then delete from mtalog
        if ($batchSize <= 0) {
            // No batching - delete all in one query using subquery
            dbexecute(
                'DELETE FROM mtalog_ids WHERE smtp_id IN (
                    SELECT msg_id FROM mtalog WHERE timestamp < (NOW() - INTERVAL ' . RECORD_DAYS_TO_KEEP . ' DAY)
                )',
                false
            );
            dbexecute('DELETE LOW_PRIORITY FROM mtalog WHERE timestamp < (NOW() - INTERVAL ' . RECORD_DAYS_TO_KEEP . ' DAY)', false);
        } else {
            // With batching: first collect msg_ids to delete in batches, then delete from mtalog_ids
            $mtaStartTime = microtime(true);
            $mtaBatchCount = 0;
            do {
                if ($maxExecutionTime > 0 && (microtime(true) - $mtaStartTime) >= $maxExecutionTime) {
                    error_log("mailwatch_db_clean: time budget exceeded ({$maxExecutionTime}s) for mtalog cleanup, stopping.");
                    break;
                }
                if ($maxBatches > 0 && $mtaBatchCount >= $maxBatches) {
                    error_log("mailwatch_db_clean: batch budget reached ({$maxBatches} batches) for mtalog cleanup, stopping.");
                    break;
                }

                // Get a batch of msg_ids to delete
                $result = dbquery(
                    'SELECT msg_id FROM mtalog WHERE timestamp < (NOW() - INTERVAL ' . RECORD_DAYS_TO_KEEP . ' DAY) LIMIT ' . $batchSize,
                    false
                );

                if (!$result || 0 === $result->num_rows) {
                    break;
                }

                $msgIds = array();
                while ($row = $result->fetch_assoc()) {
                    if (null !== $row['msg_id']) {
                        $msgIds[] = "'" . addslashes($row['msg_id']) . "'";
                    }
                }

                if (!empty($msgIds)) {
                    $msgIdList = implode(',', $msgIds);
                    // Delete from mtalog_ids first
                    dbexecute('DELETE FROM mtalog_ids WHERE smtp_id IN (' . $msgIdList . ')', false);
                    // Then delete from mtalog
                    $mtaDeleted = dbexecute('DELETE LOW_PRIORITY FROM mtalog WHERE msg_id IN (' . $msgIdList . ')', false);
                    if ($mtaDeleted < 0) {
                        error_log("mailwatch_db_clean: error deleting from mtalog in batch " . ($mtaBatchCount + 1));
                        break;
                    }
                }
                $mtaBatchCount++;

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            } while ($result && $result->num_rows > 0);
        }
        $optimize_mtalog_id = ', mtalog_ids';
    } else {
        $deletedMtalog = deleteInBatches('mtalog', 'timestamp < (NOW() - INTERVAL ' . RECORD_DAYS_TO_KEEP . ' DAY)', $batchSize);
        if ($deletedMtalog > 0) {
            error_log("mailwatch_db_clean: cleaned {$deletedMtalog} rows from mtalog");
        }
    }

    // Clean the audit log
    $deletedAudit = deleteInBatches('audit_log', 'timestamp < (NOW() - INTERVAL ' . AUDIT_DAYS_TO_KEEP . ' DAY)', $batchSize);
    if ($deletedAudit > 0) {
        error_log("mailwatch_db_clean: cleaned {$deletedAudit} rows from audit_log");
    }

    // Optimize tables (optional - can be slow on large InnoDB tables)
    if ($runOptimize) {
        dbexecute('OPTIMIZE TABLE maillog, mtalog, audit_log' . $optimize_mtalog_id, false);
    }
}
