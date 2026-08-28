# MailWatch-NG for MailScanner

[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)
[![Platform: CentOS Stream 10 / EL10](https://img.shields.io/badge/Platform-CentOS%20Stream%2010%20%7C%20EL10-red.svg)](https://centos.org)
[![Telegram Channel](https://img.shields.io/badge/Telegram-Official%20Channel-24A1DE.svg?logo=telegram&logoColor=white)](https://t.me/EFA_NG)

MailWatch-NG is a modernized web-based front-end to MailScanner, re-engineered for PHP 8.3/8.4 and MariaDB 10.11+ as part of the [EFA-NG](https://github.com/kit400/EFA-NG) ecosystem.

* **Official Support Channel (Telegram)**: [https://t.me/EFA_NG](https://t.me/EFA_NG)
* **Official Project Portal**: [https://efa-ng.space.ua](https://efa-ng.space.ua)
* **Main EFA-NG Appliance**: [https://github.com/kit400/EFA-NG](https://github.com/kit400/EFA-NG)

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

It comes with a CustomConfig module for MailScanner which causes MailScanner to log all message data (excluding body text email) to a MySQL database which is then queried by MailWatch for reporting and statistics.

## Features

* Displays the inbound/outbound mail queue size (currently for Exim, Postfix or Sendmail), Load Average and Today's Totals for Messages, Spam, Viruses and Blocked Content on each page header.
* Colour-coded display of recently processed mail.
* Drill-down onto each message to see detailed information.
* Quarantine management allows you to release, delete or run SpamAssasin `sa-learn` across any quarantined messages.
* Reports with customisable filters and graphs by Chart.js.
* Tools to view Virus Scanner status, MySQL database status and to view the MailScanner configuration files.
* Utilities for Postfix and Sendmail to monitor and display the mail queue sizes and to record and display message relay information.
* Multiple user levels: user, domain and admin that limit the data and features available to each.
* XML-RPC support that allows multiple MailScanner/MailWatch installations to act as one.
* Works with MySQL 5.7+ / MariaDB, PHP 5.6 to PHP 8, and has been tested on most popular Linux distributions (Debian/Ubuntu, CentOS and RedHat).


## Developed with the help of

* eFa - email Filter appliance
* HtmlPurifier
* IPSet
* ircmaxell/password_compat
* Chart.js
* MaxMind GeoIP
* Pear Mail
* Pear Pager
* PHP-XMLRPC
* Requests for PHP
* znk3r/hash_equals
