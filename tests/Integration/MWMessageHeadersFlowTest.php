<?php

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

class MWMessageHeadersFlowTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('MAILWATCH_TEST_RUNNER')) {
            define('MAILWATCH_TEST_RUNNER', true);
        }
        if (!defined('PHPUNIT_RUNNING')) {
            define('PHPUNIT_RUNNING', true);
        }
        require_once dirname(__DIR__, 2) . '/mailscanner/functions.php';
    }

    public function testParseRfcHeadersUnfoldsAndDecodes(): void
    {
        $raw = "From: =?UTF-8?B?VGVzdCBVc2Vy?= <test@example.com>\r\n"
             . "Subject: First line\r\n"
             . "  continued line with details\r\n"
             . "X-Mailer: Microsoft Outlook 16.0\r\n";

        $res = parse_rfc_headers($raw);
        $this->assertArrayHasKey('headers', $res);
        $this->assertArrayHasKey('map', $res);

        $this->assertCount(3, $res['headers']);
        $this->assertSame('Test User <test@example.com>', $res['map']['from'][0]);
        $this->assertSame('First line continued line with details', $res['map']['subject'][0]);
        $this->assertSame('Microsoft Outlook 16.0', $res['map']['x-mailer'][0]);
    }

    public function testParseReceivedHopsChronologicalAndDelays(): void
    {
        $received = [
            // Topmost (most recent / local gateway)
            "from mail-pj1-f53.google.com (mail-pj1-f53.google.com [209.85.216.53])\n"
            . " by mx1.efa-project.org (Postfix) with ESMTPS id 4Y12345678\n"
            . " (using TLSv1.3 with cipher TLS_AES_256_GCM_SHA384);\n"
            . " Wed, 26 Nov 2026 09:30:15 +0200",
            // Intermediate
            "by mail-pj1-f53.google.com with SMTP id 987654321;\n"
            . " Tue, 25 Nov 2026 23:30:12 -0800",
            // Bottommost (first hop / origin submission)
            "from [192.168.1.15] (client-host.net [198.51.100.42])\n"
            . " by mail-pj1-f53.google.com with ESMTPSA id 123456789;\n"
            . " (version=TLS1_3 cipher=TLS_AES_256_GCM_SHA384)\n"
            . " Tue, 25 Nov 2026 23:30:08 -0800",
        ];

        $hops = parse_received_hops($received);
        $this->assertCount(3, $hops);

        // Hop 1 should be the submission hop
        $this->assertSame(1, $hops[0]['hop_number']);
        $this->assertSame('198.51.100.42', $hops[0]['from_ip']);
        $this->assertSame('mail-pj1-f53.google.com', $hops[0]['by_host']);
        $this->assertSame('ESMTPSA', $hops[0]['protocol']);

        // Hop 2 should have delay (+4s)
        $this->assertSame(2, $hops[1]['hop_number']);
        $this->assertSame('+4s', $hops[1]['delay_formatted']);

        // Hop 3 should be the gateway hop (+3s)
        $this->assertSame(3, $hops[2]['hop_number']);
        $this->assertSame('209.85.216.53', $hops[2]['from_ip']);
        $this->assertSame('mx1.efa-project.org', $hops[2]['by_host']);
        $this->assertSame('Postfix', $hops[2]['by_mta']);
        $this->assertSame('TLS 1.3', $hops[2]['tls']);
        $this->assertSame('+3s', $hops[2]['delay_formatted']);
    }

    public function testDetectOriginatingClient(): void
    {
        $map = [
            'user-agent' => ['Mozilla Thunderbird 115.3.1 (Windows NT 10.0; Win64; x64)'],
            'x-originating-ip' => ['[198.51.100.55]'],
        ];

        $client = detect_originating_client($map, []);
        $this->assertSame('Mozilla Thunderbird', $client['name']);
        $this->assertSame('🦊', $client['icon']);
        $this->assertSame('198.51.100.55', $client['ip']);
        $this->assertSame('Desktop Client', $client['type']);
    }

    public function testRenderMessageHeadersViewHtmlStructure(): void
    {
        $raw = "Received: from mail-pj1-f53.google.com (mail-pj1-f53.google.com [209.85.216.53])\n"
             . " by mx1.efa-project.org (Postfix) with ESMTPS id 4Y12345678;\n"
             . " Wed, 26 Nov 2026 09:30:15 +0200\n"
             . "User-Agent: Roundcube Webmail/1.6.0\n"
             . "From: sender@example.com\n"
             . "To: rcpt@example.com\n"
             . "Subject: Hello World\n";

        $html = render_message_headers_view($raw, [
            'clientip' => '209.85.216.53',
            'id' => 'MSG123456',
        ]);

        $this->assertStringContainsString('class="msg-header-container"', $html);
        $this->assertStringContainsString('id="msg-header-view-diagram"', $html);
        $this->assertStringContainsString('id="msg-header-view-raw"', $html);
        $this->assertStringContainsString('mwSwitchHeaderTab', $html);
        $this->assertStringContainsString('mwCopyRawHeaders', $html);
        $this->assertStringContainsString('Roundcube Webmail', $html);
        $this->assertStringContainsString('209.85.216.53', $html);
        $this->assertStringContainsString('Delivery Flow &amp; Analysis', $html);
        $this->assertStringContainsString('Raw Headers', $html);
    }

    public function testRenderMessageHeadersViewWithEmptyHeaders(): void
    {
        $html = render_message_headers_view('', [
            'clientip' => '192.168.1.100',
            'receivedby' => 'mx1.local',
            'from' => 'admin@local',
            'to' => 'user@local',
            'subject' => 'Local alert',
            'date' => '2026-11-26 10:00:00',
            'id' => 'LOC123',
        ]);

        $this->assertStringContainsString('class="msg-header-container"', $html);
        $this->assertStringContainsString('192.168.1.100', $html);
        $this->assertStringContainsString('Local Delivery Gateway', $html);
    }
}
