#!/usr/bin/env perl
use strict;
use warnings;
use utf8;
use File::Temp qw(tempdir);
use File::Path qw(rmtree);

binmode(STDOUT, ":utf8");
binmode(STDERR, ":utf8");

my $test_count = 0;
my $pass_count = 0;

sub ok {
    my ($cond, $name) = @_;
    $name //= '';
    $test_count++;
    if ($cond) {
        $pass_count++;
        print "  [PASS] $name\n";
    } else {
        print "  [FAIL] $name\n";
    }
}

sub is {
    my ($actual, $expected, $name) = @_;
    my $cond = (defined $actual && defined $expected && $actual eq $expected) || (!defined $actual && !defined $expected);
    ok($cond, $name . ($cond ? "" : " (Expected: '$expected', Got: '$actual')"));
}

print "=== MW-10 Regression Test Suite (Perl) ===\n\n";

# Load functions from MailWatch.pm logic
# We define isolated standalone versions for unit testing
sub test_IsTransientDBError {
    my ($err_code, $err_str, $dbh_ping) = @_;
    $err_code //= 0;
    $err_str  //= '';

    if (defined $dbh_ping && !$dbh_ping) {
        return 1;
    }

    my %transient_codes = map { $_ => 1 } (
        1040, 1041, 1053, 1158, 1159, 1160, 1161, 1205, 1213,
        2002, 2003, 2006, 2013, 2055
    );

    return 1 if $transient_codes{int($err_code)};

    if ($err_str =~ /server has gone away/i ||
        $err_str =~ /lost connection/i ||
        $err_str =~ /can't connect/i ||
        $err_str =~ /connection refused/i ||
        $err_str =~ /broken pipe/i ||
        $err_str =~ /lock wait timeout/i ||
        $err_str =~ /deadlock/i ||
        $err_str =~ /too many connections/i ||
        $err_str =~ /shutdown in progress/i) {
        return 1;
    }

    return 0;
}

sub test_StripNulBytes {
    my ($msg) = @_;
    for my $f (qw(id from from_domain to to_domain subject clientip archiveplaces spamreport reports mcpreport hostname headers rblspamreport token messageid)) {
        if (defined $msg->{$f}) {
            $msg->{$f} =~ s/\0//g;
        }
    }
}

sub test_SanitizeMessageFields {
    my ($msg, $strip_4byte) = @_;
    for my $f (qw(id from from_domain to to_domain subject clientip archiveplaces spamreport reports mcpreport hostname headers rblspamreport token messageid)) {
        if (defined $msg->{$f}) {
            $msg->{$f} =~ s/\0//g;
            if ($strip_4byte) {
                if (utf8::is_utf8($msg->{$f})) {
                    $msg->{$f} =~ s/[^\x{0000}-\x{FFFF}]/\x{FFFD}/g;
                } else {
                    $msg->{$f} =~ s/[\xF0-\xF4][\x80-\xBF]{3}/\x{EF}\x{BF}\x{BD}/g;
                }
            }
        }
    }
    if (defined $msg->{token} && length($msg->{token}) > 64) {
        $msg->{token} = substr($msg->{token}, 0, 64);
    }
    if (defined $msg->{sascore} && $msg->{sascore} !~ /^-?\d+(?:\.\d+)?$/) {
        $msg->{sascore} = 0.00;
    }
    if (defined $msg->{mcpsascore} && $msg->{mcpsascore} !~ /^-?\d+(?:\.\d+)?$/) {
        $msg->{mcpsascore} = 0.00;
    }
}

sub test_escape_json_str {
    my ($s) = @_;
    $s //= '';
    $s =~ s/\\/\\\\/g;
    $s =~ s/"/\\"/g;
    $s =~ s/\n/\\n/g;
    $s =~ s/\r/\\r/g;
    $s =~ s/\t/\\t/g;
    $s =~ s/([\x00-\x1f])/sprintf("\\u%04x", ord($1))/ge;
    return $s;
}

sub test_json_encode_value {
    my ($val, $depth, $key) = @_;
    $depth //= 0;
    my $indent = '  ' x $depth;
    my $sub_indent = '  ' x ($depth + 1);

    if (!defined $val) {
        return 'null';
    } elsif (ref($val) eq 'HASH') {
        my @items;
        for my $k (sort keys %$val) {
            push @items, sprintf('%s"%s": %s', $sub_indent, test_escape_json_str($k), test_json_encode_value($val->{$k}, $depth + 1, $k));
        }
        return "{\n" . join(",\n", @items) . "\n" . $indent . "}";
    } elsif (ref($val) eq 'ARRAY') {
        my @items;
        for my $item (@$val) {
            push @items, sprintf('%s%s', $sub_indent, test_json_encode_value($item, $depth + 1, undef));
        }
        return "[\n" . join(",\n", @items) . "\n" . $indent . "]";
    } elsif (defined $key && $key =~ /^(?:size|is[a-z]+|spamwhitelisted|spamblacklisted|mcpwhitelisted|mcpblacklisted|quarantined|released|salearn)$/ && $val =~ /^-?\d+$/) {
        return "$val";
    } elsif (defined $key && $key =~ /^(?:sascore|mcpsascore)$/ && $val =~ /^-?\d+(?:\.\d+)?$/) {
        return "$val";
    } else {
        return '"' . test_escape_json_str($val) . '"';
    }
}

print "1. Error Classification (IsTransientDBError):\n";
ok(test_IsTransientDBError(2006, "MySQL server has gone away", 1), "Code 2006 is recognized as transient");
ok(test_IsTransientDBError(2013, "Lost connection to server during query", 1), "Code 2013 is recognized as transient");
ok(test_IsTransientDBError(2002, "Can't connect to local server", 1), "Code 2002 is recognized as transient");
ok(test_IsTransientDBError(1205, "Lock wait timeout exceeded", 1), "Code 1205 (lock wait) is transient");
ok(test_IsTransientDBError(1213, "Deadlock found when trying to get lock", 1), "Code 1213 (deadlock) is transient");
ok(test_IsTransientDBError(1040, "Too many connections", 1), "Code 1040 is transient");
ok(test_IsTransientDBError(0, "DBI connect failed: Connection refused", 1), "Connection refused in string is transient");
ok(test_IsTransientDBError(0, "broken pipe", 1), "Broken pipe in string is transient");
ok(test_IsTransientDBError(0, "Normal error", 0), "Failing ping (dbh_ping=0) is recognized as transient");

# Permanent errors
ok(!test_IsTransientDBError(1366, "Incorrect string value: '\\xF0\\x9F\\x98\\x8A' for column 'subject'", 1), "Code 1366 (charset) is PERMANENT");
ok(!test_IsTransientDBError(1062, "Duplicate entry 'foo' for key 'PRIMARY'", 1), "Code 1062 (dup entry) is PERMANENT");
ok(!test_IsTransientDBError(1406, "Data too long for column 'subject'", 1), "Code 1406 (data too long) is PERMANENT");
ok(!test_IsTransientDBError(1048, "Column 'id' cannot be null", 1), "Code 1048 (bad null) is PERMANENT");
ok(!test_IsTransientDBError(1054, "Unknown column 'foo' in field list", 1), "Code 1054 (bad field) is PERMANENT");
ok(!test_IsTransientDBError(1146, "Table 'maillog' doesn't exist", 1), "Code 1146 (no table) is PERMANENT");

print "\n2. String Sanitization & UTF-8 Protection:\n";
my $msg1 = {
    id => "test\000123",
    subject => "Important \000Notice",
    token => "a" x 100,
    sascore => "invalid",
};
test_StripNulBytes($msg1);
is($msg1->{id}, "test123", "NUL byte removed from id");
is($msg1->{subject}, "Important Notice", "NUL byte removed from subject");

my $msg_emoji = {
    id => "msg-emoji-01",
    subject => "Special offer \x{1F60A} for you!",
    reports => "Spam report with \x{1F525} emoji",
    from => "user\@example.com",
    token => "b" x 80,
    sascore => "3.14",
};
test_SanitizeMessageFields($msg_emoji, 1);
is(length($msg_emoji->{token}), 64, "Token truncated to CHAR(64)");
ok($msg_emoji->{subject} =~ /Special offer \x{FFFD} for you!/, "4-byte emoji replaced with U+FFFD replacement char in subject");
ok($msg_emoji->{reports} =~ /Spam report with \x{FFFD} emoji/, "4-byte emoji replaced with U+FFFD in reports");
is($msg_emoji->{sascore}, "3.14", "Valid sascore preserved");

my $msg_cyrillic = {
    id => "msg-cyrillic-01",
    subject => "Тестовое сообщение с кириллицей",
};
test_SanitizeMessageFields($msg_cyrillic, 1);
is($msg_cyrillic->{subject}, "Тестовое сообщение с кириллицей", "2-byte Cyrillic UTF-8 preserved completely untouched");

print "\n3. JSON Formatting & Serialization:\n";
my $record = {
    failed_at => "2026-09-11 12:00:00",
    error_code => 1366,
    error_str => "Incorrect string value: \"bad quotes\"",
    message_id => "12345",
    fields => {
        id => "12345",
        size => 2048,
        isspam => 0,
        subject => "Hello \"World\"\nNext line",
    }
};
my $json = test_json_encode_value($record, 0, undef);
ok($json =~ /"error_code": 1366/, "JSON contains error_code");
ok($json =~ /"message_id": "12345"/, "JSON message_id formatted as string");
ok($json =~ /"size": 2048/, "JSON size formatted as integer");
ok($json =~ /\\\"bad quotes\\\"/, "Quotes properly escaped in JSON");
ok($json =~ /\\nNext line/, "Newlines properly escaped in JSON");

print "\n4. Pipeline Simulation: Non-Blocking Dead-Letter Routing:\n";
# Simulate a sequence:
# Message 1: Good
# Message 2: Permanent error (Error 1062 duplicate key) -> should divert to DLQ without infinite loop
# Message 3: Good -> should process immediately!
my $temp_dir = tempdir(CLEANUP => 1);
my @processed_log;
my @dlq_files;

sub simulate_logger_pipeline {
    my (@messages) = @_;
    for my $msg (@messages) {
        my $max_retries = 3;
        my $retry_count = 0;
        my $sanitized = 0;

        while (1) {
            my ($ok, $err_code, $err_str);
            # Mock DB execute behaviour based on message ID
            if ($msg->{id} eq 'msg-good-1' || $msg->{id} eq 'msg-good-2') {
                $ok = 1;
            } elsif ($msg->{id} eq 'msg-perm-error') {
                $ok = 0;
                $err_code = 1062;
                $err_str = "Duplicate entry for key 'PRIMARY'";
            } elsif ($msg->{id} eq 'msg-charset-error') {
                if ($sanitized) {
                    $ok = 1; # Succeeds after sanitization!
                } else {
                    $ok = 0;
                    $err_code = 1366;
                    $err_str = "Incorrect string value: '\xF0\x9F\x98\x8A' for column 'subject'";
                }
            } elsif ($msg->{id} eq 'msg-transient-fail') {
                $ok = 0;
                $err_code = 2006;
                $err_str = "MySQL server has gone away";
            }

            if ($ok) {
                push @processed_log, { id => $msg->{id}, status => ($sanitized ? 'sanitized_ok' : 'ok') };
                last;
            }

            if (!test_IsTransientDBError($err_code, $err_str, 1)) {
                if (($err_code == 1366 || $err_str =~ /incorrect string value/i) && !$sanitized) {
                    $sanitized = 1;
                    test_SanitizeMessageFields($msg, 1);
                    next;
                }
                # Divert to DLQ
                my $file = "$temp_dir/$msg->{id}.json";
                open(my $fh, '>', $file) or die $!;
                print $fh test_json_encode_value($msg, 0, undef);
                close($fh);
                push @dlq_files, $file;
                push @processed_log, { id => $msg->{id}, status => 'dlq_permanent', code => $err_code };
                last; # Never block next message!
            }

            $retry_count++;
            if ($retry_count > $max_retries) {
                my $file = "$temp_dir/$msg->{id}.json";
                open(my $fh, '>', $file) or die $!;
                print $fh test_json_encode_value($msg, 0, undef);
                close($fh);
                push @dlq_files, $file;
                push @processed_log, { id => $msg->{id}, status => 'dlq_transient_exhausted', code => $err_code };
                last;
            }
        }
    }
}

my @test_stream = (
    { id => 'msg-good-1', subject => 'Good Message 1' },
    { id => 'msg-perm-error', subject => 'Broken Duplicate Key' },
    { id => 'msg-good-2', subject => 'Good Message 2' },
    { id => 'msg-charset-error', subject => "Emoji \x{1F60A} Subject" },
    { id => 'msg-transient-fail', subject => 'Dead MariaDB' },
);

simulate_logger_pipeline(@test_stream);

is($processed_log[0]->{id}, 'msg-good-1', "Message 1 processed");
is($processed_log[0]->{status}, 'ok', "Message 1 succeeded immediately");

is($processed_log[1]->{id}, 'msg-perm-error', "Message 2 processed");
is($processed_log[1]->{status}, 'dlq_permanent', "Message 2 diverted to DLQ without infinite loop");

is($processed_log[2]->{id}, 'msg-good-2', "Message 3 processed");
is($processed_log[2]->{status}, 'ok', "Message 3 succeeded immediately without being blocked!");

is($processed_log[3]->{id}, 'msg-charset-error', "Message 4 processed");
is($processed_log[3]->{status}, 'sanitized_ok', "Message 4 succeeded on retry after 4-byte sanitization!");

is($processed_log[4]->{id}, 'msg-transient-fail', "Message 5 processed");
is($processed_log[4]->{status}, 'dlq_transient_exhausted', "Message 5 saved to DLQ after bounded retries exhausted");

ok(-f "$temp_dir/msg-perm-error.json", "DLQ file created for permanent error");
ok(-f "$temp_dir/msg-transient-fail.json", "DLQ file created for transient exhausted error");
ok(!-f "$temp_dir/msg-good-1.json", "No DLQ file for good message 1");
ok(!-f "$temp_dir/msg-good-2.json", "No DLQ file for good message 2");
ok(!-f "$temp_dir/msg-charset-error.json", "No DLQ file for sanitized message 4");

print "\n===================================\n";
if ($pass_count == $test_count) {
    print "ALL MW-10 PERL REGRESSION TESTS PASSED ($pass_count/$test_count) ✓\n";
    exit(0);
} else {
    print "SOME TESTS FAILED ($pass_count/$test_count passed)\n";
    exit(1);
}
