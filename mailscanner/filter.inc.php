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

/**
 * Class Filter.
 */
class Filter
{
    public $item = [];
    public $operators = [];
    public $columns = [];
    public $reports = [];
    public $last_operator;
    public $last_column;
    public $last_value;
    public $display_last = 0;

    /**
     * Filter constructor.
     */
    public function __construct()
    {
        $this->operators = [
            '=' => __('equal09'),
            '<>' => __('notequal09'),
            '>' => __('greater09'),
            '>=' => __('greaterequal09'),
            '<' => __('less09'),
            '<=' => __('lessequal09'),
            'LIKE' => __('like09'),
            'NOT LIKE' => __('notlike09'),
            'REGEXP' => __('regexp09'),
            'NOT REGEXP' => __('notregexp09'),
            'IS NULL' => __('isnull09'),
            'IS NOT NULL' => __('isnotnull09'),
        ];
        $this->columns = [
            'date' => __('date09'),
            'headers' => __('headers09'),
            'id' => __('id09'),
            'size' => __('size09'),
            'from_address' => __('fromaddress09'),
            'from_domain' => __('fromdomain09'),
            'to_address' => __('toaddress09'),
            'to_domain' => __('todomain09'),
            'subject' => __('subject09'),
            'clientip' => __('clientip09'),
            'isspam' => __('isspam09'),
            'ishighspam' => __('ishighspam09'),
            'issaspam' => __('issaspam09'),
            'isrblspam' => __('isrblspam09'),
            'spamwhitelisted' => __('spamwhitelisted09'),
            'spamblacklisted' => __('spamblacklisted09'),
            'sascore' => __('sascore09'),
            'spamreport' => __('spamreport09'),
            'ismcp' => __('ismcp09'),
            'ishighmcp' => __('ishighmcp09'),
            'issamcp' => __('issamcp09'),
            'mcpwhitelisted' => __('mcpwhitelisted09'),
            'mcpblacklisted' => __('mcpblacklisted09'),
            'mcpscore' => __('mcpscore09'),
            'mcpreport' => __('mcpreport09'),
            'virusinfected' => __('virusinfected09'),
            'nameinfected' => __('nameinfected09'),
            'otherinfected' => __('otherinfected09'),
            'report' => __('report09'),
            'hostname' => __('hostname09'),
            'released' => __('released09'),
            'salearn' => __('learned09'),
        ];
    }

    /**
     * @param string $column
     * @param string $operator
     * @param string $value
     */
    public function Add($column, $operator, $value)
    {
        // Don't show the last column, operator, and value now
        $value = deepSanitizeInput($value, 'string');
        if (!$this->ValidateOperator($operator) || !$this->ValidateColumn($column)
            || !validateInput($value, 'general')) {
            return;
        }
        $this->display_last = 0;

        //  Make sure this is not a duplicate
        foreach ($this->item as $val) {
            if (($val[0] === $column) && ($val[1] === $operator) && ($val[2] === $value)) {
                return;
            }
        }

        $this->item[] = [$column, $operator, $value];
        $this->RecordHistory();
    }

    /**
     * @param string $item
     */
    public function Remove($item)
    {
        // Store the last column, operator, and value, and force the form to default to them
        $this->last_column = $this->item[$item][0];
        $this->last_operator = $this->item[$item][1];
        $this->last_value = $this->item[$item][2];
        $this->display_last = 1;
        unset($this->item[$item]);
        if (count($this->item) > 0) {
            $this->RecordHistory();
        }
    }

    /**
     * Records current filter set in session filter history
     */
    public function RecordHistory()
    {
        if (!is_array($this->item) || 0 === count($this->item)) {
            return;
        }
        if (!isset($_SESSION['filter_history']) || !is_array($_SESSION['filter_history'])) {
            $_SESSION['filter_history'] = [];
        }

        $hash = md5(json_encode($this->item));

        // Build human readable summary
        $summaryParts = [];
        foreach ($this->item as $val) {
            $col = $this->TranslateColumn($val[0]);
            $op = $this->TranslateOperator($val[1]);
            $summaryParts[] = $col . ' ' . $op . ' "' . stripslashes($val[2]) . '"';
        }
        $summary = implode(' AND ', $summaryParts);

        // Remove if already in history so we move it to top
        foreach ($_SESSION['filter_history'] as $k => $h) {
            if (isset($h['hash']) && $h['hash'] === $hash) {
                unset($_SESSION['filter_history'][$k]);
            }
        }

        array_unshift($_SESSION['filter_history'], [
            'hash' => $hash,
            'items' => $this->item,
            'summary' => $summary,
            'time' => time(),
        ]);

        // Keep last 10
        $_SESSION['filter_history'] = array_values(array_slice($_SESSION['filter_history'], 0, 10));
    }

    /**
     * @return string
     */
    public function GetCompactBarHtml()
    {
        $hasFilters = (is_array($this->item) && count($this->item) > 0);
        $currentPage = basename($_SERVER['PHP_SELF']);
        $queryString = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        $returnToParam = '';
        if (0 === strpos($currentPage, 'rep_')) {
            $returnToUrl = $currentPage . (!empty($queryString) ? '?' . $queryString : '');
            $returnToParam = '&amp;return_to=' . urlencode($returnToUrl);
        }

        $html = '<div class="active-filter-bar' . ($hasFilters ? ' has-active-filters' : '') . '">' . "\n";
        $html .= '  <div class="filter-bar-left">' . "\n";
        $html .= '    <span class="filter-bar-icon">🔍</span>' . "\n";
        $html .= '    <span class="filter-bar-label">' . __('activefilters09') . ':</span>' . "\n";

        if ($hasFilters) {
            $html .= '    <div class="filter-chips-list">' . "\n";
            foreach ($this->item as $key => $val) {
                $colName = $this->TranslateColumn($val[0]);
                $opName = $this->TranslateOperator($val[1]);
                $valStr = htmlspecialchars(stripslashes($val[2]));
                $tokenStr = isset($_SESSION['token']) ? $_SESSION['token'] : '';
                $removeUrl = 'reports.php?token=' . $tokenStr . '&amp;action=remove&amp;column=' . $key . $returnToParam;

                $html .= '      <span class="filter-chip">';
                $html .= '<span class="chip-col">' . $colName . '</span> ';
                $html .= '<span class="chip-op">' . $opName . '</span> ';
                $html .= '<span class="chip-val">"' . $valStr . '"</span>';
                $html .= '<a href="' . $removeUrl . '" class="chip-remove" title="' . __('remove09') . '">✕</a>';
                $html .= '</span>' . "\n";
            }
            $html .= '    </div>' . "\n";
            $clearUrl = 'reports.php?token=' . (isset($_SESSION['token']) ? $_SESSION['token'] : '') . '&amp;action=clear' . $returnToParam;
            $html .= '    <a href="' . $clearUrl . '" class="filter-bar-clear-btn" title="Reset all filters">🗑️ ' . __('reset07') . '</a>' . "\n";
        } else {
            $html .= '    <span class="filter-bar-empty">' . __('none09') . ' (' . __('allmessages03', false) . ')</span>' . "\n";
        }
        $html .= '  </div>' . "\n";

        $html .= '  <div class="filter-bar-right">' . "\n";
        $html .= '    <a href="reports.php" class="filter-bar-action-btn">⚙️ ' . __('addfilter09') . ' / 📋 ' . __('reports09') . '</a>' . "\n";
        $html .= '  </div>' . "\n";
        $html .= '</div>' . "\n";

        return $html;
    }

    /**
     * Ensure default standard reports list is populated
     *
     * @param string $token
     * @return void
     */
    public function ensureReportsPopulated($token = '')
    {
        if (!empty($this->reports)) {
            return;
        }
        $this->AddReport('rep_message_listing.php', __('messlisting14', false) ?: 'Message Listing', true);
        $this->AddReport('rep_message_ops.php', __('messop14', false) ?: 'Message Operations', true);
        $this->AddReport('rep_total_mail_by_date.php', __('messdate14', false) ?: 'Total Mail by Date');
        $this->AddReport('rep_previous_day.php', __('messhours14', false) ?: 'Total Mail by Hour');
        $this->AddReport('rep_top_mail_relays.php', __('topmailrelay14', false) ?: 'Top Mail Relays');
        $this->AddReport('rep_top_viruses.php', __('topvirus14', false) ?: 'Top Viruses');
        $this->AddReport('rep_viruses.php', __('virusrepor14', false) ?: 'Virus Report');
        $this->AddReport('rep_top_senders_by_quantity.php', __('topsendersqt14', false) ?: 'Top Senders by Quantity');
        $this->AddReport('rep_top_senders_by_volume.php', __('topsendersvol14', false) ?: 'Top Senders by Volume');
        $this->AddReport('rep_top_recipients_by_quantity.php', __('toprecipqt14', false) ?: 'Top Recipients by Quantity');
        $this->AddReport('rep_top_recipients_by_volume.php', __('toprecipvol14', false) ?: 'Top Recipients by Volume');
        $this->AddReport('rep_top_sender_domains_by_quantity.php', __('topsendersdomqt14', false) ?: 'Top Sender Domains by Quantity');
        $this->AddReport('rep_top_sender_domains_by_volume.php', __('topsendersdomvol14', false) ?: 'Top Sender Domains by Volume');
        $this->AddReport('rep_top_recipient_domains_by_quantity.php', __('toprecipdomqt14', false) ?: 'Top Recipient Domains by Quantity');
        $this->AddReport('rep_top_recipient_domains_by_volume.php', __('toprecipdomvol14', false) ?: 'Top Recipient Domains by Volume');

        if (true === get_conf_truefalse('UseSpamAssassin')) {
            $this->AddReport('rep_sa_score_dist.php', __('assassinscoredist14', false) ?: 'SpamAssassin Score Distribution');
            $this->AddReport('rep_sa_rule_hits.php', __('assassinrulhit14', false) ?: 'SpamAssassin Rule Hits');
        }
        if (true === get_conf_truefalse('MCPChecks')) {
            $this->AddReport('rep_mcp_score_dist.php', __('mcpscoredist14', false) ?: 'MCP Score Distribution');
            $this->AddReport('rep_mcp_rule_hits.php', __('mcprulehit14', false) ?: 'MCP Rule Hits');
        }
        if (isset($_SESSION['user_type']) && 'A' === $_SESSION['user_type']) {
            $this->AddReport('rep_audit_log.php', __('auditlog14', false) ?: 'Audit Log', true);
        }
    }

    /**
     * Get categorized reports mapping for modern accordion sidebar
     *
     * @param string $token
     * @return array
     */
    public function getCategorizedReports($token = '')
    {
        $this->ensureReportsPopulated($token);

        $categories = [
            'traffic' => [
                'title' => __('traffic14', false) ?: 'Traffic & Volumes',
                'icon' => '📊',
                'items' => [
                    ['url' => 'rep_total_mail_by_date.php', 'title' => __('messdate14', false) ?: 'Total Mail by Date', 'icon' => '📈'],
                    ['url' => 'rep_previous_day.php', 'title' => __('messhours14', false) ?: 'Total Mail by Hour', 'icon' => '🕒'],
                    ['url' => 'rep_message_ops.php' . ($token ? '?token=' . $token : ''), 'title' => __('messop14', false) ?: 'Message Operations', 'icon' => '⚙️'],
                ],
            ],
            'senders' => [
                'title' => __('senders14', false) ?: 'Senders & Relays',
                'icon' => '📤',
                'items' => [
                    ['url' => 'rep_top_mail_relays.php', 'title' => __('topmailrelay14', false) ?: 'Top Mail Relays', 'icon' => '🌐'],
                    ['url' => 'rep_top_senders_by_quantity.php', 'title' => __('topsendersqt14', false) ?: 'Top Senders by Quantity', 'icon' => '👥'],
                    ['url' => 'rep_top_senders_by_volume.php', 'title' => __('topsendersvol14', false) ?: 'Top Senders by Volume', 'icon' => '📦'],
                    ['url' => 'rep_top_sender_domains_by_quantity.php', 'title' => __('topsendersdomqt14', false) ?: 'Top Sender Domains by Qty', 'icon' => '🏢'],
                    ['url' => 'rep_top_sender_domains_by_volume.php', 'title' => __('topsendersdomvol14', false) ?: 'Top Sender Domains by Vol', 'icon' => '📊'],
                ],
            ],
            'recipients' => [
                'title' => __('recipients14', false) ?: 'Recipients & Domains',
                'icon' => '📥',
                'items' => [
                    ['url' => 'rep_top_recipients_by_quantity.php', 'title' => __('toprecipqt14', false) ?: 'Top Recipients by Quantity', 'icon' => '👥'],
                    ['url' => 'rep_top_recipients_by_volume.php', 'title' => __('toprecipvol14', false) ?: 'Top Recipients by Volume', 'icon' => '📦'],
                    ['url' => 'rep_top_recipient_domains_by_quantity.php', 'title' => __('toprecipdomqt14', false) ?: 'Top Recipient Domains by Qty', 'icon' => '🏢'],
                    ['url' => 'rep_top_recipient_domains_by_volume.php', 'title' => __('toprecipdomvol14', false) ?: 'Top Recipient Domains by Vol', 'icon' => '📊'],
                ],
            ],
            'security' => [
                'title' => __('security14', false) ?: 'Security & Threat Rules',
                'icon' => '🛡️',
                'items' => [
                    ['url' => 'rep_viruses.php', 'title' => __('virusrepor14', false) ?: 'Virus Report', 'icon' => '🦠'],
                    ['url' => 'rep_top_viruses.php', 'title' => __('topvirus14', false) ?: 'Top Viruses', 'icon' => '☣️'],
                ],
            ],
            'logs' => [
                'title' => __('messagelisting14', false) ?: 'Message Log & Audit',
                'icon' => '📜',
                'items' => [
                    ['url' => 'rep_message_listing.php' . ($token ? '?token=' . $token : ''), 'title' => __('messlisting14', false) ?: 'Message Listing', 'icon' => '📨'],
                ],
            ],
        ];

        if (true === get_conf_truefalse('UseSpamAssassin')) {
            $categories['security']['items'][] = ['url' => 'rep_sa_score_dist.php', 'title' => __('assassinscoredist14', false) ?: 'SpamAssassin Scores', 'icon' => '🎯'];
            $categories['security']['items'][] = ['url' => 'rep_sa_rule_hits.php', 'title' => __('assassinrulhit14', false) ?: 'SpamAssassin Rule Hits', 'icon' => '⚡'];
        }
        if (true === get_conf_truefalse('MCPChecks')) {
            $categories['security']['items'][] = ['url' => 'rep_mcp_score_dist.php', 'title' => __('mcpscoredist14', false) ?: 'MCP Scores', 'icon' => '🔒'];
            $categories['security']['items'][] = ['url' => 'rep_mcp_rule_hits.php', 'title' => __('mcprulehit14', false) ?: 'MCP Rule Hits', 'icon' => '🚨'];
        }
        if (isset($_SESSION['user_type']) && 'A' === $_SESSION['user_type']) {
            $categories['logs']['items'][] = ['url' => 'rep_audit_log.php' . ($token ? '?token=' . $token : ''), 'title' => __('auditlog14', false) ?: 'Audit Log', 'icon' => '📋'];
        }

        return $categories;
    }

    /**
     * Render the reports sidebar HTML (Mini rail + Full panel with dropdown accordion matching kit4mail)
     *
     * @param string $token
     * @return string
     */
    public function DisplaySidebarHtml($token = '')
    {
        $categories = $this->getCategorizedReports($token);
        $currentPage = basename($_SERVER['PHP_SELF']);

        $html = '  <aside class="reports-sidebar sidebar" id="reportsSidebar">' . "\n";

        // 1. Narrow icon rail (visible when collapsed/minimized)
        $html .= '    <div class="sidebar-mini-rail" onclick="toggleReportsSidebar()" title="Expand Sidebar">' . "\n";
        $html .= '      <button type="button" class="mini-rail-btn btn-sidebar-show" title="Expand Sidebar">▶</button>' . "\n";
        $html .= '      <div class="mini-rail-icons">' . "\n";
        $html .= '        <span class="mini-icon" title="Traffic & Volumes">📊</span>' . "\n";
        $html .= '        <span class="mini-icon" title="Senders & Relays">📤</span>' . "\n";
        $html .= '        <span class="mini-icon" title="Recipients & Domains">📥</span>' . "\n";
        $html .= '        <span class="mini-icon" title="Security & Threat Rules">🛡️</span>' . "\n";
        $html .= '        <span class="mini-icon" title="Message Log & Audit">📜</span>' . "\n";
        $html .= '        <span class="mini-icon" title="Filter Builder">🔍</span>' . "\n";
        if (isset($_SESSION['filter_history']) && count($_SESSION['filter_history']) > 0) {
            $html .= '        <span class="mini-icon" title="Filter History">🕒</span>' . "\n";
        }
        $html .= '      </div>' . "\n";
        $html .= '    </div>' . "\n";

        // 2. Full Sidebar Panel (rendered normally or as overlay on hover in minimized mode)
        $html .= '    <div class="sidebar-full-panel">' . "\n";
        $html .= '      <div class="sidebar-header">' . "\n";
        $html .= '        <div class="sidebar-title">' . "\n";
        $html .= '          <span class="sidebar-icon">📊</span>' . "\n";
        $html .= '          <span class="sidebar-brand-text">' . __('reports03') . '</span>' . "\n";
        $html .= '        </div>' . "\n";
        $html .= '        <button type="button" class="btn-sidebar-close sidebar-toggle-btn" onclick="toggleReportsSidebar()" title="Collapse sidebar">◀</button>' . "\n";
        $html .= '      </div>' . "\n";

        $html .= '      <div class="sidebar-content">' . "\n";
        $html .= '        <ul class="sidebar-nav-list">' . "\n";

        // Hub / Dashboard Link
        $isHubActive = ('reports.php' === $currentPage);
        $html .= '          <li class="sidebar-nav-item' . ($isHubActive ? ' is-active' : '') . '">' . "\n";
        $html .= '            <a href="reports.php" class="sidebar-nav-link">' . "\n";
        $html .= '              <i class="nav-icon">📊</i>' . "\n";
        $html .= '              <span class="menu-text">' . (__('reports14', false) ?: 'Reports Overview') . '</span>' . "\n";
        $html .= '              <span class="badge badge-pill badge-primary">Hub</span>' . "\n";
        $html .= '            </a>' . "\n";
        $html .= '          </li>' . "\n";

        // Render each category dropdown accordion
        foreach ($categories as $catKey => $cat) {
            $hasActiveChild = false;
            foreach ($cat['items'] as $item) {
                if ($currentPage === explode('?', $item['url'])[0]) {
                    $hasActiveChild = true;
                    break;
                }
            }
            $activeClass = $hasActiveChild ? ' active' : '';

            $html .= '          <li class="sidebar-dropdown' . $activeClass . '">' . "\n";
            $html .= '            <a href="javascript:void(0);" class="sidebar-dropdown-toggle">' . "\n";
            $html .= '              <i class="nav-icon">' . $cat['icon'] . '</i>' . "\n";
            $html .= '              <span class="menu-text">' . $cat['title'] . '</span>' . "\n";
            $html .= '              <span class="badge badge-pill badge-info">' . count($cat['items']) . '</span>' . "\n";
            $html .= '            </a>' . "\n";
            $html .= '            <div class="sidebar-submenu"' . ($hasActiveChild ? ' style="display:block;"' : '') . '>' . "\n";
            $html .= '              <ul>' . "\n";
            foreach ($cat['items'] as $item) {
                $isItemActive = ($currentPage === explode('?', $item['url'])[0]);
                $itemClass = $isItemActive ? ' class="is-active"' : '';
                $html .= '                <li' . $itemClass . '><a href="' . $item['url'] . '"><span class="sub-icon">' . $item['icon'] . '</span> <span class="sub-text">' . $item['title'] . '</span></a></li>' . "\n";
            }
            $html .= '              </ul>' . "\n";
            $html .= '            </div>' . "\n";
            $html .= '          </li>' . "\n";
        }

        // Section B: Filter Builder dropdown
        $hasActiveFilter = (count($this->item) > 0);
        $html .= '          <li class="sidebar-dropdown sidebar-filter-builder' . ($hasActiveFilter ? ' active' : '') . '">' . "\n";
        $html .= '            <a href="javascript:void(0);" class="sidebar-dropdown-toggle">' . "\n";
        $html .= '              <i class="nav-icon">🔍</i>' . "\n";
        $html .= '              <span class="menu-text">' . __('addfilter09') . '</span>' . "\n";
        if ($hasActiveFilter) {
            $html .= '              <span class="badge badge-pill badge-warning">' . count($this->item) . '</span>' . "\n";
        }
        $html .= '            </a>' . "\n";
        $html .= '            <div class="sidebar-submenu"' . ($hasActiveFilter ? ' style="display:block;"' : '') . '>' . "\n";
        $html .= '              <div class="sidebar-filter-wrapper">' . "\n";
        $html .= '                ' . $this->DisplayForm() . "\n";
        $html .= '              </div>' . "\n";
        $html .= '            </div>' . "\n";
        $html .= '          </li>' . "\n";

        // Section C: Filter History dropdown (if present)
        if (isset($_SESSION['filter_history']) && count($_SESSION['filter_history']) > 0) {
            $html .= '          <li class="sidebar-dropdown sidebar-filter-history">' . "\n";
            $html .= '            <a href="javascript:void(0);" class="sidebar-dropdown-toggle">' . "\n";
            $html .= '              <i class="nav-icon">🕒</i>' . "\n";
            $html .= '              <span class="menu-text">' . (__('filterhistory09', false) ?: 'Filter History') . '</span>' . "\n";
            $html .= '              <span class="badge badge-pill badge-secondary">' . count($_SESSION['filter_history']) . '</span>' . "\n";
            $html .= '            </a>' . "\n";
            $html .= '            <div class="sidebar-submenu">' . "\n";
            $html .= '              ' . $this->DisplayHistoryHtml($token) . "\n";
            $html .= '            </div>' . "\n";
            $html .= '          </li>' . "\n";
        }

        $html .= '        </ul>' . "\n";
        $html .= '      </div>' . "\n"; // End sidebar-content
        $html .= '    </div>' . "\n"; // End sidebar-full-panel
        $html .= '  </aside>' . "\n";

        return $html;
    }

    /**
     * Render the reports dashboard main content (Stats + Portal Grid)
     *
     * @param string $token
     * @return void
     */
    public function DisplayDashboardHtml($token = '')
    {
        $this->ensureReportsPopulated($token);

        // Fetch quick summary numbers
        $query = "
SELECT
 DATE_FORMAT(MIN(date),'" . DATE_FORMAT . "') AS oldest,
 DATE_FORMAT(MAX(date),'" . DATE_FORMAT . "') AS newest,
 COUNT(date) AS messages,
 SUM(CASE WHEN virusinfected>0 THEN 1 ELSE 0 END) AS infected,
 SUM(CASE WHEN isspam>0 THEN 1 ELSE 0 END) AS spam
FROM
 maillog
WHERE
 1=1
" . $this->CreateSQL();
        $sth = dbquery($query);
        $stats = $sth ? $sth->fetch_object() : null;
        $totalMsgs = $stats ? number_format($stats->messages) : '0';
        $oldestDate = ($stats && $stats->oldest) ? $stats->oldest : 'N/A';
        $newestDate = ($stats && $stats->newest) ? $stats->newest : 'N/A';
        $infectedCount = ($stats && $stats->infected) ? number_format($stats->infected) : '0';
        $spamCount = ($stats && $stats->spam) ? number_format($stats->spam) : '0';

        // Dashboard Stats Bar
        echo '    <div class="reports-stats-grid">' . "\n";
        echo '      <div class="report-stat-card stat-total">' . "\n";
        echo '        <div class="stat-card-icon">📬</div>' . "\n";
        echo '        <div class="stat-card-data">' . "\n";
        echo '          <span class="stat-card-num">' . $totalMsgs . '</span>' . "\n";
        echo '          <span class="stat-card-label">' . __('messagecount09') . '</span>' . "\n";
        echo '        </div>' . "\n";
        echo '      </div>' . "\n";

        echo '      <div class="report-stat-card stat-dates">' . "\n";
        echo '        <div class="stat-card-icon">📅</div>' . "\n";
        echo '        <div class="stat-card-data">' . "\n";
        echo '          <span class="stat-card-range">' . $oldestDate . ' &rarr; ' . $newestDate . '</span>' . "\n";
        echo '          <span class="stat-card-label">' . __('date09') . '</span>' . "\n";
        echo '        </div>' . "\n";
        echo '      </div>' . "\n";

        echo '      <div class="report-stat-card stat-threats">' . "\n";
        echo '        <div class="stat-card-icon">🛡️</div>' . "\n";
        echo '        <div class="stat-card-data">' . "\n";
        echo '          <span class="stat-card-num">' . $spamCount . ' <span class="sub-stat">/ ' . $infectedCount . ' 🦠</span></span>' . "\n";
        echo '          <span class="stat-card-label">' . __('spam103') . ' / ' . __('virusinfected09') . '</span>' . "\n";
        echo '        </div>' . "\n";
        echo '      </div>' . "\n";
        echo '    </div>' . "\n";

        // Quick Launch Report Cards Grid
        echo '    <div class="reports-portal-grid">' . "\n";
        foreach ($this->reports as $report) {
            $url = $report['url'];
            if ($report['useToken']) {
                $url .= '?token=' . $token;
            }
            $icon = '📄';
            $badge = 'Report';
            if (strpos($url, 'message_listing') !== false) {
                $icon = '📨';
                $badge = 'Messages';
            } elseif (strpos($url, 'message_ops') !== false) {
                $icon = '⚙️';
                $badge = 'Operations';
            } elseif (strpos($url, 'total_mail') !== false || strpos($url, 'previous_day') !== false) {
                $icon = '📈';
                $badge = 'Traffic';
            } elseif (strpos($url, 'virus') !== false) {
                $icon = '🦠';
                $badge = 'Threats';
            } elseif (strpos($url, 'sender') !== false || strpos($url, 'recipient') !== false || strpos($url, 'relay') !== false) {
                $icon = '👥';
                $badge = 'Statistics';
            } elseif (strpos($url, 'sa_') !== false || strpos($url, 'mcp_') !== false) {
                $icon = '🎯';
                $badge = 'Rules';
            } elseif (strpos($url, 'audit') !== false) {
                $icon = '📜';
                $badge = 'Security';
            }

            echo '      <a href="' . $url . '" class="portal-card">' . "\n";
            echo '        <div class="portal-card-top">' . "\n";
            echo '          <span class="portal-card-icon">' . $icon . '</span>' . "\n";
            echo '          <span class="portal-card-badge">' . $badge . '</span>' . "\n";
            echo '        </div>' . "\n";
            echo '        <div class="portal-card-title">' . $report['description'] . '</div>' . "\n";
            echo '        <div class="portal-card-arrow">View &rarr;</div>' . "\n";
            echo '      </a>' . "\n";
        }
        echo '    </div>' . "\n";
    }

    /**
     * @param string $token
     */
    public function Display($token = '')
    {
        $this->DisplayDashboardHtml($token);
    }

    /**
     * @param string $token
     * @return string
     */
    public function DisplayHistoryHtml($token)
    {
        if (!isset($_SESSION['filter_history']) || !is_array($_SESSION['filter_history']) || 0 === count($_SESSION['filter_history'])) {
            return '';
        }

        $currentPage = basename($_SERVER['PHP_SELF']);
        $queryString = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        $returnToParam = '';
        if (0 === strpos($currentPage, 'rep_')) {
            $returnToUrl = $currentPage . (!empty($queryString) ? '?' . $queryString : '');
            $returnToParam = '&amp;return_to=' . urlencode($returnToUrl);
        }

        $html = '      <div class="sidebar-section filter-history-section">' . "\n";
        $html .= '        <div class="sidebar-section-title history-title-row">' . "\n";
        $html .= '          <span>🕒 ' . (__('filterhistory09', false) ?: 'Filter History') . '</span>' . "\n";
        $clearHistoryUrl = 'reports.php?token=' . $token . '&amp;action=clear_history' . $returnToParam;
        $html .= '          <a href="' . $clearHistoryUrl . '" class="history-clear-btn" title="' . __('reset07') . '">🗑️ ' . __('reset07') . '</a>' . "\n";
        $html .= '        </div>' . "\n";
        $html .= '        <div class="filter-history-list">' . "\n";

        foreach ($_SESSION['filter_history'] as $idx => $hist) {
            $summary = htmlspecialchars($hist['summary']);
            $applyUrl = 'reports.php?token=' . $token . '&amp;action=apply_history&amp;history_index=' . $idx . $returnToParam;

            $html .= '          <div class="history-item-card">' . "\n";
            $html .= '            <div class="history-item-summary" title="' . $summary . '">' . $summary . '</div>' . "\n";
            $html .= '            <div class="history-item-actions">' . "\n";
            $html .= '              <a href="' . $applyUrl . '" class="history-action-btn history-btn-apply" title="' . (__('apply09', false) ?: 'Apply') . '">⚡ ' . (__('apply09', false) ?: 'Apply') . '</a>' . "\n";
            $html .= '              <button type="button" class="history-action-btn history-btn-save" onclick="promptSaveHistory(' . $idx . ', \'' . addslashes($hist['summary']) . '\')" title="' . __('save09') . '">💾 ' . __('save09') . '</button>' . "\n";
            $html .= '            </div>' . "\n";
            $html .= '          </div>' . "\n";
        }

        $html .= '        </div>' . "\n";
        $html .= '      </div>' . "\n";

        return $html;
    }

    public function CreateMtalogSQL()
    {
        $sql = '';
        foreach ($this->item as $key => $val) {
            if ('date' === $val[0]) {
                // Change field from timestamp to date format
                $val[0] = "DATE_FORMAT(timestamp,'%Y-%m-%d')";

                $sql .= self::getSqlCondition($val);
            }
        }

        return $sql;
    }

    public function CreateSQL()
    {
        $sql = 'AND ' . $_SESSION['global_filter'] . "\n";
        foreach ($this->item as $key => $val) {
            $sql .= self::getSqlCondition($val);
        }

        return $sql;
    }

    private static function getSqlCondition($val)
    {
        // If LIKE selected - place wildcards either side of the query string
        if ('LIKE' === $val[1] || 'NOT LIKE' === $val[1]) {
            $val[2] = '%' . $val[2] . '%';
        }
        if (is_numeric($val[2])) {
            return "AND\n $val[0] $val[1] $val[2]\n";
        } elseif ('IS NULL' === $val[1] || 'IS NOT NULL' === $val[1]) {
            // Handle NULL and NOT NULL's
            return "AND\n $val[0] $val[1]\n";
        } elseif ('' !== $val[2] && '!' === $val[2][0]) {
            // Allow !<sql_function>
            return "AND\n $val[0] $val[1] " . substr($val[2], 1) . "\n";
        } else {
            // Regular string
            return "AND\n $val[0] $val[1] '$val[2]'\n";
        }
    }

    /**
     * @param string $column
     */
    public function TranslateColumn($column)
    {
        return isset($this->columns[$column]) ? $this->columns[$column] : $column;
    }

    /**
     * @param string $operator
     */
    public function TranslateOperator($operator)
    {
        return isset($this->operators[$operator]) ? $this->operators[$operator] : $operator;
    }

    public function DisplayForm()
    {
        // Form
        $return = '<form method="post" action="' . sanitizeInput($_SERVER['PHP_SELF']) . '" class="filter-builder-form">' . "\n";

        $return .= '<div class="filter-field-group">' . "\n";
        $return .= '  <label class="filter-label">' . __('column09', false) . ':</label>' . "\n";
        $return .= '  <select name="column" class="filter-select">' . "\n";
        foreach ($this->columns as $key => $val) {
            $return .= ' <option value="' . $key . '"';
            if ($this->display_last && $key === $this->last_column) {
                $return .= ' SELECTED';
            }
            $return .= '>' . $val . '</option>' . "\n";
        }
        $return .= '  </select>' . "\n";
        $return .= '</div>' . "\n";

        $return .= '<div class="filter-field-group">' . "\n";
        $return .= '  <label class="filter-label">' . __('operator09', false) . ':</label>' . "\n";
        $return .= '  <select name="operator" class="filter-select">' . "\n";
        foreach ($this->operators as $key => $val) {
            $return .= ' <option value="' . $key . '"';
            if ($this->display_last && $key === $this->last_operator) {
                $return .= ' SELECTED';
            }
            $return .= '>' . $val . '</option>' . "\n";
        }
        $return .= '  </select>' . "\n";
        $return .= '</div>' . "\n";

        $return .= '<div class="filter-field-group">' . "\n";
        $return .= '  <label class="filter-label">' . __('value09', false) . ':</label>' . "\n";
        $return .= '  <div class="filter-input-row">' . "\n";
        $return .= '    <input type="text" name="value" class="filter-input" placeholder="' . __('value09', false) . '..."';
        if ($this->display_last) {
            $return .= ' value="' . htmlentities(stripslashes($this->last_value)) . '"';
        }
        $return .= '>' . "\n";
        $return .= '    <button type="submit" name="action" value="add" class="filter-btn filter-btn-add">➕ ' . __('add09') . '</button>' . "\n";
        $return .= '  </div>' . "\n";
        $return .= '  <span class="filter-help-text">' . __('tosetdate09') . '</span>' . "\n";
        $return .= '</div>' . "\n";

        $return .= '<div class="filter-saved-section">' . "\n";
        $return .= '  <div class="filter-saved-title">💾 ' . __('loadsavef09') . '</div>' . "\n";
        $return .= '  <div class="filter-save-row">' . "\n";
        $return .= '    <input type="text" name="save_as" placeholder="Save filter name..." class="filter-input">' . "\n";
        $return .= '    <button type="submit" name="action" value="save" class="filter-btn">💾 ' . __('save09') . '</button>' . "\n";
        $return .= '  </div>' . "\n";
        $return .= '  <div class="filter-load-row">' . "\n";
        $return .= '    ' . $this->ListSaved() . "\n";
        $return .= '    <div class="filter-saved-actions">' . "\n";
        $return .= '      <button type="submit" name="action" value="load" class="filter-btn filter-btn-load">📂 ' . __('load09') . '</button>' . "\n";
        $return .= '      <button type="submit" name="action" value="delete" class="filter-btn filter-btn-del" onclick="return confirm(\'Delete saved filter?\');">🗑️</button>' . "\n";
        $return .= '    </div>' . "\n";
        $return .= '  </div>' . "\n";
        $return .= '</div>' . "\n";

        $return .= '<input type="hidden" name="token" value="' . $_SESSION['token'] . '">' . "\n";
        $return .= '<input type="hidden" name="formtoken" value="' . generateFormToken('/filter.inc.php form token') . '">' . "\n";
        $return .= '</form>' . "\n";

        return $return;
    }

    /**
     * @param string $url
     * @param string $description
     * @param bool   $useToken
     */
    public function AddReport($url, $description, $useToken = false)
    {
        // test if url exists if it is remove the old one. This fixes double shown urls for the reports
        foreach ($this->reports as $key => $report) {
            if ($report['url'] === $url) {
                unset($this->reports[$key]);
            }
        }
        $this->reports[] = ['url' => $url, 'description' => $description, 'useToken' => $useToken];
    }

    /**
     * @param string $name
     */
    public function Save($name)
    {
        $name = deepSanitizeInput($name, 'string');
        if (!validateInput($name, 'general')) {
            return;
        }

        dbconn();
        if (count($this->item) > 0) {
            // Delete the existing first
            $dsql = "DELETE FROM `saved_filters` WHERE `username`='" . safe_value(stripslashes($_SESSION['myusername'])) . "' AND `name`='$name'";
            dbquery($dsql);
            foreach ($this->item as $key => $val) {
                $sql = "REPLACE INTO `saved_filters` (`name`, `col`, `operator`, `value`, `username`)  VALUES ('$name',";
                foreach ($val as $value) {
                    $sql .= "'" . safe_value($value) . "',";
                }
                $sql .= "'" . safe_value(stripslashes($_SESSION['myusername'])) . "')";
                dbquery($sql);
            }
        }
    }

    /**
     * @param string $name
     */
    public function Load($name)
    {
        $name = deepSanitizeInput($name, 'string');
        if (!validateInput($name, 'general')) {
            return;
        }

        dbconn();
        $sql = "SELECT `col`, `operator`, `value` FROM `saved_filters` WHERE `name`='$name' AND username='" . safe_value(stripslashes($_SESSION['myusername'])) . "'";
        $sth = dbquery($sql);
        while ($row = $sth->fetch_row()) {
            $this->item[] = $row;
        }
        if (count($this->item) > 0) {
            $this->RecordHistory();
        }
    }

    /**
     * @param string $name
     */
    public function Delete($name)
    {
        $name = deepSanitizeInput($name, 'string');
        if (!validateInput($name, 'general')) {
            return;
        }

        dbconn();
        $sql = "DELETE FROM `saved_filters` WHERE `username`='" . safe_value(stripslashes($_SESSION['myusername'])) . "' AND `name`='$name'";
        dbquery($sql);
    }

    public function ListSaved()
    {
        $sql = "SELECT DISTINCT `name` FROM `saved_filters` WHERE `username`='" . safe_value(stripslashes($_SESSION['myusername'])) . "'";
        $sth = dbquery($sql);
        $return = '<select name="filter">' . "\n";
        $return .= ' <option value="_none_">' . __('none09') . '</option>' . "\n";
        while ($row = $sth->fetch_array()) {
            $return .= ' <option value="' . $row[0] . '">' . $row[0] . '</option>' . "\n";
        }
        $return .= '</select>' . "\n";

        return $return;
    }

    /**
     * @param string $operator
     *
     * @return bool
     */
    private function ValidateOperator($operator)
    {
        $validKeys = array_keys($this->operators);

        return in_array($operator, $validKeys, true);
    }

    /**
     * @param string $column
     *
     * @return bool
     */
    private function ValidateColumn($column)
    {
        $validKeys = array_keys($this->columns);

        return in_array($column, $validKeys, true);
    }
}
