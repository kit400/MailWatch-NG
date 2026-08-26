<?php

/**
 * Generate synthetic test messages evenly distributed over a time period (e.g., past 60 minutes)
 * for visual testing of tables, legends, and Apache ECharts traffic graphs.
 *
 * Usage:
 *   php generate_test_traffic.php [--minutes=60] [--count=60]
 */

require_once __DIR__ . '/../functions.php';

// Parse CLI options
$options = getopt('', ['minutes::', 'count::', 'help']);

if (isset($options['help'])) {
    echo "Usage: php generate_test_traffic.php [--minutes=60] [--count=60]\n";
    exit(0);
}

$minutes = isset($options['minutes']) ? max(1, (int)$options['minutes']) : 60;
$totalCount = isset($options['count']) ? max(1, (int)$options['count']) : 60;

dbconn();

$senders = [
    ['client@partner-corp.com', 'partner-corp.com'],
    ['alerts@security-monitoring.net', 'security-monitoring.net'],
    ['billing@cloud-services.io', 'cloud-services.io'],
    ['newsletter@tech-insider.com', 'tech-insider.com'],
    ['support@github.com', 'github.com'],
    ['noreply@google.com', 'google.com'],
    ['invoices@global-logistics.de', 'global-logistics.de'],
    ['sales@phishing-target.xyz', 'phishing-target.xyz'],
    ['promo@mass-marketing.biz', 'mass-marketing.biz'],
    ['trojan-drop@malicious-domain.cc', 'malicious-domain.cc'],
    ['bad-actor@darkweb-relay.ru', 'darkweb-relay.ru'],
    ['internal-user@efa-project.org', 'efa-project.org'],
    ['manager@efa-project.org', 'efa-project.org'],
];

$recipients = [
    ['admin@efa-project.org', 'efa-project.org'],
    ['it-support@efa-project.org', 'efa-project.org'],
    ['security@efa-project.org', 'efa-project.org'],
    ['accounting@efa-project.org', 'efa-project.org'],
    ['staff@efa-project.org', 'efa-project.org'],
];

$subjects = [
    'Clean' => [
        'Monthly Server Performance Summary',
        'Weekly Team Sync Meeting Notes',
        'Invoice #INV-2026-8941 for Cloud Services',
        'Project Delivery Timeline Update',
        'New Pull Request #482 opened in EFA-NG',
        'Documentation Review & Architecture Changes',
        'System Maintenance Schedule for September',
    ],
    'LowSpam' => [
        'Special Discount: Boost your website traffic by 300%',
        'Exclusive Investment Offer for Tech Leaders',
        'Unclaimed rewards waiting in your customer account',
        'Last Chance: 50% Off Business Database Leads',
    ],
    'HighSpam' => [
        'URGENT: Your bank account will be suspended in 24 hours',
        'You have won $1,500,000 in the International Lottery',
        'CONFIRM YOUR CRYPTO WALLET PASSCODE IMMEDIATELY',
        'Inheritance Funds Transfer Confirmation from Barrister',
    ],
    'Virus' => [
        'Incoming Payment Receipt (Encrypted Archive attached)',
        'FedEx Delivery Notice: Package Tracking Document',
        'Urgent Notice: Unpaid Invoice Scan attached',
    ],
    'BlockedFile' => [
        'Purchase Order Details (Setup.exe)',
        'Updated Employee Salary Sheet (vba_macro.xlsm)',
        'Software License Patch (keygen.bat)',
    ],
    'BadHeader' => [
        'Notification with malformed RFC2822 header format',
        'Message with invalid MIME boundary characters',
    ],
    'Whitelisted' => [
        'Trusted Partner Secure Document Exchange',
        'Official Government Portal Notification',
    ],
    'Blacklisted' => [
        'Known Spam Network Broadcast #9012',
        'Spam Relay Test Message',
    ],
    'MCP' => [
        'Internal Financial Audit Draft (CONFIDENTIAL)',
        'Employee Passport Numbers for Visa Application',
    ],
    'HighMCP' => [
        'Customer Credit Card Database Export (TOP SECRET)',
        'High Risk Policy Violation: Unencrypted Banking Credentials',
    ],
    'Released' => [
        'False Positive: Released Legitimate Vendor Quote',
    ],
];

$baseTypes = [
    'Clean', 'Clean', 'Clean',
    'LowSpam', 'HighSpam',
    'Virus', 'BlockedFile', 'BadHeader',
    'Whitelisted', 'Blacklisted',
    'MCP', 'HighMCP', 'Released',
];

$now = time();
$stepSeconds = ($minutes * 60) / max(1, $totalCount);
$inserted = 0;

// Build pool ensuring even coverage of all types
$typePool = [];
while (count($typePool) < $totalCount) {
    $shuffled = $baseTypes;
    shuffle($shuffled);
    $typePool = array_merge($typePool, $shuffled);
}
$typePool = array_slice($typePool, 0, $totalCount);
shuffle($typePool);

for ($i = 0; $i < $totalCount; $i++) {
    $msgTime = $now - (int)(($totalCount - $i) * $stepSeconds) + mt_rand(-3, 3);
    if ($msgTime > $now) $msgTime = $now;

    $type = $typePool[$i];
    $senderPair = $senders[array_rand($senders)];
    $recipientPair = $recipients[array_rand($recipients)];
    $subjectList = $subjects[$type] ?? $subjects['Clean'];
    $subject = $subjectList[array_rand($subjectList)];

    $dateStr = date('Y-m-d', $msgTime);
    $timeStr = date('H:i:s', $msgTime);
    $timestampStr = date('Y-m-d H:i:s', $msgTime);

    // Unique message ID
    $msgId = strtoupper(dechex($msgTime)) . '.' . substr(md5(uniqid((string)mt_rand(), true)), 0, 10);
    $clientIp = mt_rand(11, 220) . '.' . mt_rand(1, 254) . '.' . mt_rand(1, 254) . '.' . mt_rand(1, 254);
    $size = mt_rand(1024, 1500000);

    // Flags initialization
    $isspam = 0;
    $ishighspam = 0;
    $issaspam = 0;
    $isrblspam = 0;
    $spamwhitelisted = 0;
    $spamblacklisted = 0;
    $sascore = sprintf('%.2f', mt_rand(1, 30) / 10.0);
    $spamreport = 'SpamAssassin (score=' . $sascore . ', required=5.0)';
    $virusinfected = 0;
    $nameinfected = 0;
    $otherinfected = 0;
    $report = '';
    $ismcp = 0;
    $ishighmcp = 0;
    $issamcp = 0;
    $mcpsascore = '0.00';
    $mcpreport = '';
    $quarantined = 0;
    $released = 0;
    $salearn = 0;

    switch ($type) {
        case 'Clean':
            $sascore = sprintf('%.2f', mt_rand(1, 25) / 10.0);
            $spamreport = 'SpamAssassin (score=' . $sascore . ', required=5.0, autolearn=ham)';
            break;

        case 'LowSpam':
            $isspam = 1;
            $issaspam = 1;
            $sascore = sprintf('%.2f', mt_rand(55, 95) / 10.0);
            $spamreport = "SpamAssassin (score=$sascore, required=5.0, BAYES_50=0.8, HTML_MESSAGE=0.1, RDNS_NONE=0.7)";
            $quarantined = 1;
            break;

        case 'HighSpam':
            $isspam = 1;
            $ishighspam = 1;
            $issaspam = 1;
            $isrblspam = 1;
            $sascore = sprintf('%.2f', mt_rand(150, 290) / 10.0);
            $spamreport = "SpamAssassin (score=$sascore, required=5.0, BAYES_99=3.5, RCVD_IN_ZEN=3.0, PHOENIX_SPAM=4.5)";
            $quarantined = 1;
            break;

        case 'Virus':
            $virusinfected = 1;
            $report = 'ClamAV (Eicar-Test-Signature / Win32.Trojan.Gen-8912)';
            $quarantined = 1;
            break;

        case 'BlockedFile':
            $nameinfected = 1;
            $report = 'MailScanner: Blocked dangerous executable attachment (setup.exe)';
            $quarantined = 1;
            break;

        case 'BadHeader':
            $otherinfected = 1;
            $report = 'MailScanner: Found disallowed characters or invalid RFC header syntax';
            break;

        case 'Whitelisted':
            $spamwhitelisted = 1;
            $sascore = '-100.00';
            $spamreport = 'SpamAssassin (score=-100.0, required=5.0, USER_IN_WHITELIST=-100)';
            break;

        case 'Blacklisted':
            $spamblacklisted = 1;
            $isspam = 1;
            $sascore = '100.00';
            $spamreport = 'SpamAssassin (score=100.0, required=5.0, USER_IN_BLACKLIST=100)';
            $quarantined = 1;
            break;

        case 'MCP':
            $ismcp = 1;
            $issamcp = 1;
            $mcpsascore = '5.50';
            $mcpreport = 'MCP: Internal confidential keyword match (CONFIDENTIAL)';
            break;

        case 'HighMCP':
            $ismcp = 1;
            $ishighmcp = 1;
            $issamcp = 1;
            $mcpsascore = '25.00';
            $mcpreport = 'MCP: High-risk Credit Card and PII pattern detected in message body';
            $quarantined = 1;
            break;

        case 'Released':
            $isspam = 1;
            $quarantined = 1;
            $released = 1;
            $sascore = '6.20';
            $spamreport = 'SpamAssassin (score=6.2, required=5.0)';
            break;
    }

    $fromAddr = safe_value($senderPair[0]);
    $fromDomain = safe_value($senderPair[1]);
    $toAddr = safe_value($recipientPair[0]);
    $toDomain = safe_value($recipientPair[1]);
    $subjSafe = safe_value($subject);
    $reportSafe = safe_value($report);
    $spamReportSafe = safe_value($spamreport);
    $mcpReportSafe = safe_value($mcpreport);
    $sysHostname = safe_value(rtrim(gethostname()));

    // Generate realistic RFC822 headers
    $rawHeaders = "Received: from mail.$fromDomain ([$clientIp]) by $sysHostname with ESMTP id $msgId;\n\t" . date('r', $ts) . "\n" .
                  "From: <$fromAddr>\n" .
                  "To: <$toAddr>\n" .
                  "Subject: $subject\n" .
                  "Date: " . date('r', $ts) . "\n" .
                  "Message-ID: <$msgId@$fromDomain>\n" .
                  "X-Mailer: EFA-NG Mail Agent\n" .
                  "X-MailScanner-ID: $msgId\n" .
                  "X-MailScanner-SpamCheck: $spamreport";
    $headersSafe = safe_value($rawHeaders);

    $sql = "INSERT INTO maillog 
            (timestamp, id, size, from_address, from_domain, to_address, to_domain, subject, clientip,
             isspam, ishighspam, issaspam, isrblspam, spamwhitelisted, spamblacklisted, sascore, spamreport,
             virusinfected, nameinfected, otherinfected, report, ismcp, ishighmcp, issamcp, mcpsascore, mcpreport,
             hostname, date, time, headers, quarantined, released, salearn, messageid)
            VALUES
            ('$timestampStr', '$msgId', $size, '$fromAddr', '$fromDomain', '$toAddr', '$toDomain', '$subjSafe', '$clientIp',
             $isspam, $ishighspam, $issaspam, $isrblspam, $spamwhitelisted, $spamblacklisted, $sascore, '$spamReportSafe',
             $virusinfected, $nameinfected, $otherinfected, '$reportSafe', $ismcp, $ishighmcp, $issamcp, $mcpsascore, '$mcpReportSafe',
             '$sysHostname', '$dateStr', '$timeStr', '$headersSafe', $quarantined, $released, $salearn, '<$msgId@$fromDomain>')";

    dbquery($sql);
    $inserted++;
}

echo "Successfully generated $inserted synthetic test messages across the past $minutes minutes.\n";
