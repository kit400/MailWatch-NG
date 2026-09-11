#
# MailWatch for MailScanner
# Copyright (C) 2003-2011  Steve Freegard (steve@freegard.name)
# Copyright (C) 2011  Garrod Alwood (garrod.alwood@lorodoes.com)
# Copyright (C) 2014-2026  MailWatch Team (https://github.com/mailwatch/MailWatch/graphs/contributors)
#
#   Custom Module MailWatch
#
#   Version 1.8
#
# This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public
# License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later
# version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
# warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.
#
# In addition, as a special exception, the copyright holder gives permission to link the code of this program with
# those files in the PEAR library that are licensed under the PHP License (or with modified versions of those files
# that use the same license as those files), and distribute linked combinations including the two.
# You must obey the GNU General Public License in all respects for all of the code used other than those files in the
# PEAR library that are licensed under the PHP License. If you modify this program, you may extend this exception to
# your version of the program, but you are not obligated to do so.
# If you do not wish to do so, delete this exception statement from your version.
#
# You should have received a copy of the GNU General Public License along with this program; if not, write to the Free
# Software Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
#

package MailScanner::CustomConfig;

use strict;
use DBI;
use DBD::MariaDB;
use utf8;
use Sys::Hostname;
use Storable(qw[freeze thaw]);
use POSIX;
use Socket;
BEGIN {
    eval {
        require Encoding::FixLatin;
        Encoding::FixLatin->import('fix_latin');
    };
    if ($@) {
        *fix_latin = sub {
            my $str = shift;
            return $str unless defined $str;
            utf8::upgrade($str) unless utf8::is_utf8($str);
            return $str;
        };
    }
}
use Digest::SHA;
use Sys::Syslog;

# Uncomment the following line when debugging MailWatch.pm
#use Data::Dumper;

use vars qw($VERSION);

### The package version, both in 1.23 style *and* usable by MakeMaker:
$VERSION = '1.8';

# Trace settings - uncomment this to debug
#DBI->trace(2,'/tmp/dbitrace.log');

my ($dbh);
my ($sth);
my ($hostname) = hostname;
my $loop = inet_aton("127.0.0.1");
my $server_port = 11553;
my $timeout = 3600;
my ($SQLversion);

# Get database information from 00MailWatchConf.pm
use File::Basename;
my $dirname = dirname(__FILE__);
require $dirname.'/MailWatchConf.pm';

my ($db_name) = mailwatch_get_db_name();
my ($db_host) = mailwatch_get_db_host();
my ($db_user) = mailwatch_get_db_user();
my ($db_pass) = mailwatch_get_db_password();
my $failed_events_dir = defined &mailwatch_get_failed_events_dir ? mailwatch_get_failed_events_dir() : '/var/spool/mailwatch/failed_events';
my $configured_max_retries = defined &mailwatch_get_max_retries ? mailwatch_get_max_retries() : 5;

my $RunInForeground;

sub InitMailWatchLogging {
    # Detect if MailScanner Milter is calling this custom function and do not spawn
    # MSMilter uses the blocklists and allowlists, but not the logger
    if ($0 !~ /MSMilter/) {
        # Grab values from config prior to fork, after which MailScanner methods become
        # inaccessible to descendant
        my $facility = MailScanner::Config::Value('logfacility');
        my $logsock  = MailScanner::Config::Value('logsock');
        $RunInForeground = MailScanner::Config::Value('runinforeground');

        $| = 1 if $RunInForeground;

        my $pid = fork();
        if ($pid) {
            # MailScanner child process
            waitpid $pid, 0;
        } else {
            # New process
            # Detach from parent, make connections, and listen for requests
            POSIX::setsid();
            if (!fork()) {
                $SIG{HUP} = $SIG{INT} = $SIG{PIPE} = $SIG{TERM} = $SIG{ALRM} = \&ExitLogging;
                alarm $timeout;
                $0 = "MailWatch SQL";

                # Reinitialize logging (cannot use MailScanner::Log due to detach)
                if ($logsock eq '') {
                    if ($^O =~ /solaris|sunos|irix/i) {
                        $logsock = 'udp';
                    } else {
                        $logsock = 'unix';
                    }
                }

                eval { Sys::Syslog::setlogsock($logsock) unless $RunInForeground; };
                eval { Sys::Syslog::openlog($0, 'pid, nowait', $facility) unless $RunInForeground; };

                # Listen for messages unless connection can't be initialized
                ListenForMessages() unless InitConnection() == 1;
            }
        exit;
        }
    }
}

sub CheckSQLVersion {
    # Check SQL server version safely without clobbering persistent state
    eval {
        my $tmp_dbh = DBI->connect("DBI:MariaDB:database=$db_name;host=$db_host",
            $db_user, $db_pass,
            { PrintError => 0, AutoCommit => 1, RaiseError => 1 }
        );
        if ($tmp_dbh) {
            $SQLversion = $tmp_dbh->{mariadb_serverversion} || $tmp_dbh->{mysql_serverversion};
            $tmp_dbh->disconnect;
        }
    };
    if ($@ || !$SQLversion) {
        my $err = $@ || $DBI::errstr || "unknown error";
        LogMessage("warn", "Unable to check database version: $err");
        return 1;
    }

    return $SQLversion;
}

sub LogMessage {
    my $level = shift;
    my $msg = shift;

    eval {
        if (!$RunInForeground) {
            Sys::Syslog::syslog($level, $msg);
        } else {
            print STDOUT "$0: $level: $msg\n";
        }
    };
}

sub BindPort {
    # Set up TCP/IP socket.  We will start one server per MailScanner
    # child, but only one child will actually be able to get the socket.
    # The rest will die silently.  When one of the MailScanner children
    # tries to log a message and fails to connect, it will start a new
    # server.
    socket(SERVER, PF_INET, SOCK_STREAM, getprotobyname("tcp"));
    setsockopt(SERVER, SOL_SOCKET, SO_REUSEADDR, 1);
    my $addr = sockaddr_in($server_port, $loop);
    bind(SERVER, $addr) or return 1;

    return 0;
}

sub ListenPort {
    # Start listening
    listen(SERVER, SOMAXCONN) or return 1;

    return 0;
}

sub InitDB {
    # Persistent connection to the database
    eval {
        $dbh->disconnect if $dbh;
    };
    undef $dbh;
    undef $sth;

    eval {
        $dbh = DBI->connect("DBI:MariaDB:database=$db_name;host=$db_host",
            $db_user, $db_pass,
            { PrintError => 0, AutoCommit => 1, RaiseError => 1 }
        );
    };
    if ($@ || !$dbh) {
        my $err = $@ || $DBI::errstr || "unknown error";
        LogMessage('warn', "Unable to initialise database connection: $err");
        return 1;
    }

    eval {
        $SQLversion = $dbh->{mariadb_serverversion} || $dbh->{mysql_serverversion};
    };

    eval {
        $dbh->do('SET NAMES utf8mb4');
    };
    if ($@) {
        LogMessage('warn', "Warning: SET NAMES utf8mb4 failed: $@");
    }

    eval {
        $sth = $dbh->prepare("INSERT INTO maillog (timestamp, id, size, from_address, from_domain, to_address, to_domain, subject, clientip, archive, isspam, ishighspam, issaspam, isrblspam, spamwhitelisted, spamblacklisted, sascore, spamreport, virusinfected, nameinfected, otherinfected, report, ismcp, ishighmcp, issamcp, mcpwhitelisted, mcpblacklisted, mcpsascore, mcpreport, hostname, date, time, headers, quarantined, rblspamreport, token, messageid) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    };
    if ($@ || !$sth) {
        my $err = $@ || $DBI::errstr || "unknown error";
        LogMessage('warn', "Error preparing statement: $err");
        return 1;
    }

    return 0;
}

sub InitConnection {
    # Fail to bind, we'll just exit, port in use
    if (BindPort() == 1) { return 1; }
    if (InitDB() == 1) {
        # We are bound, but couldn't connect to DB, so close it all down
        close(SERVER);
        return 1;
    }
    if (ListenPort() == 1) {
        # We are bound, connected to DB but can't listen, so close it all down
        close(SERVER);
        return 1;
    }
    return 0;
}

sub ExitLogging {
    # Server exit - commit changes, close socket, and exit gracefully.
    close(SERVER);
    eval { $dbh->disconnect if $dbh; };
    exit;
}

sub StripNulBytes {
    my ($msg) = @_;
    return unless defined $msg && ref($msg) eq 'HASH';
    for my $f (qw(id from from_domain to to_domain subject clientip archiveplaces spamreport reports mcpreport hostname headers rblspamreport token messageid)) {
        if (defined $msg->{$f}) {
            $msg->{$f} =~ s/\0//g;
        }
    }
}

sub SanitizeMessageFields {
    my ($msg, $strip_4byte) = @_;
    return unless defined $msg && ref($msg) eq 'HASH';
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

sub IsTransientDBError {
    my ($err_code, $err_str, $dbh_ref) = @_;
    $err_code //= 0;
    $err_str  //= '';

    if (!$dbh_ref || eval { !$dbh_ref->ping }) {
        return 1;
    }

    my %transient_codes = map { $_ => 1 } (
        1040, # ER_CON_COUNT_ERROR (Too many connections)
        1041, # ER_OUTOFMEMORY
        1053, # ER_SERVER_SHUTDOWN
        1158, # ER_NET_READ_ERROR_HEADER
        1159, # ER_NET_READ_INTERRUPTED
        1160, # ER_NET_ERROR_ON_WRITE
        1161, # ER_NET_WRITE_INTERRUPTED
        1205, # ER_LOCK_WAIT_TIMEOUT
        1213, # ER_LOCK_DEADLOCK
        2002, # CR_CONNECTION_ERROR
        2003, # CR_CONN_HOST_ERROR
        2006, # CR_SERVER_GONE_ERROR
        2013, # CR_SERVER_LOST
        2055, # CR_SERVER_LOST_EXTENDED
    );

    if ($transient_codes{int($err_code)}) {
        return 1;
    }

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

sub GetFailedEventsDir {
    my $dir = $failed_events_dir;
    if (defined &mailwatch_get_failed_events_dir) {
        eval { $dir = mailwatch_get_failed_events_dir(); };
    }
    my @candidates = (
        $dir,
        '/var/spool/mailwatch/failed_events',
        '/var/spool/MailScanner/failed_events',
        '/var/cache/mailwatch/failed_events',
        '/tmp/mailwatch_failed_events'
    );
    for my $cand (@candidates) {
        next unless defined $cand && length($cand);
        if (-d $cand && -w $cand) {
            return $cand;
        }
        if (!-e $cand) {
            eval {
                require File::Path;
                File::Path::make_path($cand, { mode => 0770 });
            };
            if (-d $cand && -w $cand) {
                return $cand;
            }
        }
    }
    return '/tmp';
}

sub _escape_json_str {
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

sub _json_encode_value {
    my ($val, $depth, $key) = @_;
    $depth //= 0;
    my $indent = '  ' x $depth;
    my $sub_indent = '  ' x ($depth + 1);

    if (!defined $val) {
        return 'null';
    } elsif (ref($val) eq 'HASH') {
        my @items;
        for my $k (sort keys %$val) {
            push @items, sprintf('%s"%s": %s', $sub_indent, _escape_json_str($k), _json_encode_value($val->{$k}, $depth + 1, $k));
        }
        return "{\n" . join(",\n", @items) . "\n" . $indent . "}";
    } elsif (ref($val) eq 'ARRAY') {
        my @items;
        for my $item (@$val) {
            push @items, sprintf('%s%s', $sub_indent, _json_encode_value($item, $depth + 1, undef));
        }
        return "[\n" . join(",\n", @items) . "\n" . $indent . "]";
    } elsif (defined $key && $key =~ /^(?:size|is[a-z]+|spamwhitelisted|spamblacklisted|mcpwhitelisted|mcpblacklisted|quarantined|released|salearn)$/ && $val =~ /^-?\d+$/) {
        return "$val";
    } elsif (defined $key && $key =~ /^(?:sascore|mcpsascore)$/ && $val =~ /^-?\d+(?:\.\d+)?$/) {
        return "$val";
    } else {
        return '"' . _escape_json_str($val) . '"';
    }
}

sub FormatJSON {
    my ($data) = @_;
    return _json_encode_value($data, 0, undef) . "\n";
}

sub SaveFailedEvent {
    my ($msg, $err_code, $err_str) = @_;
    return unless defined $msg && ref($msg) eq 'HASH';

    my $dir = GetFailedEventsDir();
    my $msg_id = $msg->{id} || 'unknown';
    $msg_id =~ s/[^a-zA-Z0-9._-]/_/g;
    my $filename = sprintf("%s/%s-%d-%d.json", $dir, $msg_id, time(), $$);

    my %record = (
        failed_at   => POSIX::strftime("%Y-%m-%d %H:%M:%S", localtime()),
        error_code  => int($err_code || 0),
        error_str   => "$err_str",
        message_id  => $msg->{id} // '',
        fields      => $msg,
    );

    if (open(my $fh, '>', $filename)) {
        print $fh FormatJSON(\%record);
        close($fh);
        LogMessage('warn', "$msg_id: Saved failed event to dead-letter queue: $filename");
        return $filename;
    } else {
        LogMessage('err', "$msg_id: Could not write failed event to $filename: $!");
        return undef;
    }
}

sub ListenForMessages {
    my $message;
    LogMessage('info', "Started MailWatch SQL Logging child");
    # Wait for messages
    while (my $cli = accept(CLIENT, SERVER)) {
        my ($port, $packed_ip) = sockaddr_in($cli);
        my $dotted_quad = inet_ntoa($packed_ip);

        # Reset emergency timeout - if we haven't heard anything in $timeout
        # seconds, there is probably something wrong, so we should clean up
        # and let another process try.
        alarm $timeout;
        # Make sure we're only receiving local connections
        if ($dotted_quad ne "127.0.0.1") {
            LogMessage('warn', "Error: unexpected connection from $dotted_quad");
            close CLIENT;
            next;
        }
        my @in;
        while (<CLIENT>) {
            # End of normal logging message
            last if /^END$/;
            # MailScanner child telling us to shut down
            ExitLogging if /^EXIT$/;
            chop;
            push @in, $_;
        }
        close CLIENT;

        my $data = join "", @in;
        my $tmp = unpack("u", $data);
        $message = eval { thaw $tmp };

        next unless (defined $message && ref($message) eq 'HASH' && defined $$message{id});

        # Prevent loss of logging on transient errors, while preventing
        # infinite loops and blockage on permanent data/schema errors.
        my $max_retries = defined &mailwatch_get_max_retries ? mailwatch_get_max_retries() : $configured_max_retries;
        my $retry_count = 0;
        my $sanitized = 0;

        # Clean NUL bytes initially
        StripNulBytes($message);

        while (1) {
            # Ensure DB connection and statement handle exist
            if (!$dbh || !$sth || eval { !$dbh->ping }) {
                InitDB();
            }

            my $ok = 0;
            if ($sth) {
                $ok = eval {
                    $sth->execute(
                        $$message{timestamp},
                        $$message{id},
                        $$message{size},
                        $$message{from},
                        $$message{from_domain},
                        $$message{to},
                        $$message{to_domain},
                        $$message{subject},
                        $$message{clientip},
                        $$message{archiveplaces},
                        $$message{isspam},
                        $$message{ishigh},
                        $$message{issaspam},
                        $$message{isrblspam},
                        $$message{spamwhitelisted},
                        $$message{spamblacklisted},
                        $$message{sascore},
                        $$message{spamreport},
                        $$message{virusinfected},
                        $$message{nameinfected},
                        $$message{otherinfected},
                        $$message{reports},
                        $$message{ismcp},
                        $$message{ishighmcp},
                        $$message{issamcp},
                        $$message{mcpwhitelisted},
                        $$message{mcpblacklisted},
                        $$message{mcpsascore},
                        $$message{mcpreport},
                        $$message{hostname},
                        $$message{date},
                        $$message{"time"},
                        $$message{headers},
                        $$message{quarantined},
                        $$message{rblspamreport},
                        $$message{token},
                        $$message{messageid}
                    );
                };
            }

            if ($ok && !$@) {
                if ($sanitized) {
                    LogMessage('warn', "$$message{id}: Logged to MailWatch SQL after sanitizing incompatible characters");
                } elsif ($retry_count > 0) {
                    LogMessage('info', "$$message{id}: Logged to MailWatch SQL after $retry_count retry attempts");
                } else {
                    LogMessage('info', "$$message{id}: Logged to MailWatch SQL");
                }
                last;
            }

            # An error occurred
            my $err_str  = ($sth && $sth->errstr) || ($dbh && $dbh->errstr) || $DBI::errstr || $@ || "Unknown error";
            my $err_code = ($sth && $sth->err) || ($dbh && $dbh->err) || $DBI::err || 0;

            # Classify error: Transient vs Permanent
            if (!IsTransientDBError($err_code, $err_str, $dbh)) {
                # Permanent error!
                # Check if it is a character encoding issue (1366 / incorrect string value)
                if (($err_code == 1366 || $err_str =~ /incorrect string value/i || $err_str =~ /charset/i) && !$sanitized) {
                    $sanitized = 1;
                    LogMessage('warn', "$$message{id}: Encoding error ($err_code: $err_str). Sanitizing 4-byte UTF-8 sequences and retrying...");
                    SanitizeMessageFields($message, 1);
                    next;
                }

                # Other permanent error (or already sanitized):
                # Do NOT retry infinitely! Divert to dead-letter queue and proceed!
                LogMessage('err', "$$message{id}: Permanent database error ($err_code: $err_str). Event diverted to dead-letter store.");
                SaveFailedEvent($message, $err_code, $err_str);
                last;
            }

            # Transient error: connection drop, deadlock, server restart, etc.
            $retry_count++;
            if ($retry_count > $max_retries) {
                LogMessage('err', "$$message{id}: Database retries exhausted ($retry_count/$max_retries: $err_code: $err_str). Event diverted to dead-letter store.");
                SaveFailedEvent($message, $err_code, "Database retries exhausted: $err_str");
                last;
            }

            my $backoff = ($retry_count == 1) ? 1 : ($retry_count == 2) ? 2 : ($retry_count == 3) ? 4 : 5;
            LogMessage('warn', "$$message{id}: Transient database error ($err_code: $err_str). Reconnecting in ${backoff}s (attempt $retry_count of $max_retries)...");
            sleep($backoff);

            InitDB();
        }

        # Unset
        $message = undef;
    }
}

sub EndMailWatchLogging {
    # Tell server to shut down.  Another child will start a new server
    # if we are here due to old age instead of administrative intervention
    socket(TO_SERVER, PF_INET, SOCK_STREAM, getprotobyname("tcp"));
    my $addr = sockaddr_in($server_port, $loop);
    connect(TO_SERVER, $addr) or return;

    print TO_SERVER "EXIT\n";
    close TO_SERVER;
}

sub MailWatchLogging {
    my ($message) = @_;

    # Don't bother trying to do an insert if  no message is passed-in
    return unless $message;

    # Fix duplicate 'to' addresses for Postfix users
    my (%rcpts);
    map { $rcpts{$_} = 1; } @{$message->{to}};
    @{$message->{to}} = keys %rcpts;

    # Get rid of control chars and fix chars set in Subject
    my $subject = fix_latin($message->{utf8subject});
    $subject =~ s/\n/ /g;  # Make sure text subject only contains 1 line (LF)
    $subject =~ s/\t/ /g;  # and no TAB characters
    $subject =~ s/\r/ /g;  # and no CR characters

    # Uncomment the following line when debugging SQLBlackWhiteList.pm
    #MailScanner::Log::WarnLog("MailWatch: Debug: var subject: %s", Dumper($subject));

    # Get rid of control chars and tidy-up SpamAssassin report
    my $spamreport = $message->{spamreport};
    $spamreport =~ s/\n/ /g;  # Make sure text report only contains 1 line (LF)
    $spamreport =~ s/\t//g;   # and no TAB characters
    $spamreport =~ s/\r/ /g;  # and no CR characters

    # Get rid of control chars and tidy-up SpamAssassin MCP report
    my $mcpreport = $message->{mcpreport};
    $mcpreport =~ s/\n/ /g;  # Make sure text report only contains 1 line (LF)
    $mcpreport =~ s/\t//g;   # and no TAB characters
    $mcpreport =~ s/\r/ /g;  # and no CR characters

    # Workaround tiny bug in original MCP code
    my ($mcpsascore);
    if (defined $message->{mcpsascore}) {
        $mcpsascore = $message->{mcpsascore};
    } else {
        $mcpsascore = $message->{mcpscore};
    }

    # Set quarantine flag - This only works on MailScanner 4.43.7 or later
    my ($quarantined);
    $quarantined = 0;
    if ((scalar(@{$message->{quarantineplaces}}))
        + (scalar(@{$message->{spamarchive}})) > 0)
    {
        $quarantined = 1;
    }

    # Get timestamp, and format it so it is suitable to use with MySQL
    my ($sec, $min, $hour, $mday, $mon, $year, $wday, $yday, $isdst) = localtime();
    my ($timestamp) = sprintf("%d-%02d-%02d %02d:%02d:%02d",
        $year + 1900, $mon + 1, $mday, $hour, $min, $sec);

    my ($date) = sprintf("%d-%02d-%02d", $year + 1900, $mon + 1, $mday);
    my ($time) = sprintf("%02d:%02d:%02d", $hour, $min, $sec);

    # Also print 1 line for each report about this message. These lines
    # contain all the info above, + the attachment filename and text of
    # each report.
    my ($file, $text, @report_array);
    while(($file, $text) = each %{$message->{allreports}}) {
        $file = "the entire message" if $file eq "";
        # Use the sanitised filename to avoid problems caused by people forcing
        # logging of attachment filenames which contain nasty SQL instructions.
        $file = $message->{file2safefile}{$file} or $file;
        $text =~ s/\n/ /g;  # Make sure text report only contains 1 line (LF)
        $text =~ s/\t/ /g;  # and no TAB characters
        $text =~ s/\r/ /g;  # and no CR characters

        # Uncomment the following line when debugging MailWatch.pm
        #MailScanner::Log::WarnLog("MailWatch: Debug: VAR text: %s", Dumper($text));

        push (@report_array, $text);
    }

    # Sanitize reports
    my $reports = join(",", @report_array);

    # Uncomment the following line when debugging MailWatch.pm
    #MailScanner::Log::WarnLog("MailWatch: DEBUG: var reports: %s", Dumper($reports));

    # Fix the $message->{clientip} for later versions of Exim
    # where $message->{clientip} contains ip.ip.ip.ip.port
    my $clientip = $message->{clientip};
    $clientip =~ s/^(\d+\.\d+\.\d+\.\d+)(\.\d+)$/$1/;

    # Integrate SpamAssassin Allowlist/Blocklist reporting
    if ($spamreport =~ /USER_IN_WHITELIST/) {
        $message->{spamwhitelisted} = 1;
    }
    if ($spamreport =~ /USER_IN_BLACKLIST/) {
        $message->{spamblacklisted} = 1;
    }

    # Get the first domain from the list of recipients
    my ($todomain, @todomain);
    @todomain = @{$message->{todomain}};
    $todomain = $todomain[0];

    # Generate token for mail viewing
    my ($token, $sha1);
    $sha1 = Digest::SHA->new(1);
    $sha1->add($message->{id}, $timestamp, $message->{size}, $message->{headers});
    $token = $sha1->hexdigest;

    # Extract Message-ID from header
    my ($messageid, $inmessageid, $messageidbuffer);
    $messageid = "";
    $messageidbuffer = "";
    $inmessageid = 0;

    # Extract Message-ID from header (unfold if needed)
    foreach my $line (@{$message->{headers}}) {
        chomp $line;

        if ($line =~ /^message-id:\s*(.*)/i) {
            # Start Message-ID (value may be empty due to folding)
            $messageidbuffer = $1;
            $inmessageid = 1;

            # Uncomment the following line when debugging MailWatch.pm
            # MailScanner::Log::DebugLog("MailWatch: Found Message-ID header start: [%s]", $line);
            next;
        }
        elsif ($inmessageid) {
            if ($line =~ /^\s+(.*)/) {
                # RFC 5322 unfolding (MailScanner-safe)
                $messageidbuffer .= ' ' . $1;

                # Uncomment the following line when debugging MailWatch.pm
                # MailScanner::Log::DebugLog("MailWatch: Message-ID continuation line: [%s]", $line);
            } else {
                # Uncomment the following line when debugging MailWatch.pm
                #MailScanner::Log::DebugLog("MailWatch: End of Message-ID header");

                # End of Message-ID header
                last;
            }
        }
    }

    # Uncomment the following line when debugging MailWatch.pm
    # MailScanner::Log::DebugLog("MailWatch: Raw unfolded Message-ID buffer: [%s]", $messageidbuffer);

    # Normalize and extract the Message-ID value
    if ($messageidbuffer =~ /(<[^>]+>)/) {
        $messageid = $1;
        $messageid =~ s/^\s+|\s+$//g;

        # Uncomment the following line when debugging MailWatch.pm
        # MailScanner::Log::DebugLog("MailWatch: Extracted Message-ID value: [%s]", $messageid);
    }

    # Warn if Message-ID was not found
    if ($messageid eq "") {
      MailScanner::Log::WarnLog("MailWatch: Could not extract Message-ID for %s", $message->{id});
    }

    # Place all data into %msg
    my %msg;
    $msg{timestamp} = $timestamp;
    $msg{id} = $message->{id};
    $msg{size} = $message->{size};
    $msg{from} = fix_latin($message->{from});
    $msg{from_domain} = fix_latin($message->{fromdomain});
    $msg{to} = fix_latin(join(",", @{$message->{to}}));
    $msg{to_domain} = fix_latin($todomain);
    $msg{subject} = $subject;
    $msg{clientip} = $clientip;
    $msg{archiveplaces} = join(",", @{$message->{archiveplaces}});
    $msg{isspam} = $message->{isspam};
    $msg{ishigh} = $message->{ishigh};
    $msg{issaspam} = $message->{issaspam};
    $msg{isrblspam} = $message->{isrblspam};
    $msg{spamwhitelisted} = $message->{spamwhitelisted};
    $msg{spamblacklisted} = $message->{spamblacklisted};
    $msg{sascore} = $message->{sascore};
    $msg{spamreport} = fix_latin($spamreport);
    $msg{ismcp} = $message->{ismcp};
    $msg{ishighmcp} = $message->{ishighmcp};
    $msg{issamcp} = $message->{issamcp};
    $msg{mcpwhitelisted} = $message->{mcpwhitelisted};
    $msg{mcpblacklisted} = $message->{mcpblacklisted};
    $msg{mcpsascore} = $mcpsascore;
    $msg{mcpreport} = fix_latin($mcpreport);
    $msg{virusinfected} = $message->{virusinfected};
    $msg{nameinfected} = $message->{nameinfected};
    $msg{otherinfected} = $message->{otherinfected};
    $msg{reports} = fix_latin($reports);
    $msg{hostname} = $hostname;
    $msg{date} = $date;
    $msg{"time"} = $time;
    $msg{headers} = join("\n", map { fix_latin($_)} @{$message->{headers}});
    $msg{quarantined} = $quarantined;
    $msg{rblspamreport} = $message->{rblspamreport};
    $msg{token} = $token;
    $msg{messageid} = fix_latin($messageid);

    # Prepare data for transmission
    my $f = freeze \%msg;
    my $p = pack("u", $f);

    # Connect to server
    while (1) {
        socket(TO_SERVER, PF_INET, SOCK_STREAM, getprotobyname("tcp"));
        my $addr = sockaddr_in($server_port, $loop);
        connect(TO_SERVER, $addr) and last;
        # Failed to connect - kick off new child, wait, and try again
        InitMailWatchLogging();
        sleep 5;
    }

    # Pass data to server process
    MailScanner::Log::InfoLog("MailWatch: Logging message $msg{id} to SQL");
    print TO_SERVER $p;
    print TO_SERVER "END\n";
    close TO_SERVER;
}

1;
