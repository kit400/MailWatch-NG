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
            $html .= '    <a href="' . $clearUrl . '" class="filter-bar-clear-btn" title="Reset all filters">🗑️ ' . __('reset08', false) . '</a>' . "\n";
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
     * @param string $token
     */
    public function Display($token)
    {
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

        echo '<div class="reports-layout" id="reportsLayout">' . "\n";

        // Expand Rail (visible when sidebar is minimized)
        echo '  <div class="sidebar-expand-rail" onclick="toggleReportsSidebar()" title="Expand Sidebar">' . "\n";
        echo '    <button type="button" class="rail-btn">▶</button>' . "\n";
        echo '    <span class="rail-label">📋 ' . __('reports09') . ' &amp; ' . __('search03', false) . '</span>' . "\n";
        echo '  </div>' . "\n";

        // 1. Collapsible Sidebar
        echo '  <aside class="reports-sidebar" id="reportsSidebar">' . "\n";
        echo '    <div class="sidebar-header">' . "\n";
        echo '      <div class="sidebar-title">' . "\n";
        echo '        <span class="sidebar-icon">📊</span>' . "\n";
        echo '        <span>' . __('reports09') . ' &amp; ' . __('search03', false) . '</span>' . "\n";
        echo '      </div>' . "\n";
        echo '      <button type="button" class="sidebar-toggle-btn" onclick="toggleReportsSidebar()" title="Minimize sidebar">◀</button>' . "\n";
        echo '    </div>' . "\n";

        echo '    <div class="sidebar-content">' . "\n";
        // Section A: Reports List
        echo '      <div class="sidebar-section">' . "\n";
        echo '        <div class="sidebar-section-title">📋 ' . __('reports09') . '</div>' . "\n";
        echo '        <ul class="sidebar-reports-menu">' . "\n";
        foreach ($this->reports as $report) {
            $url = $report['url'];
            if ($report['useToken']) {
                $url .= '?token=' . $token;
            }
            $icon = '📄';
            if (strpos($url, 'message_listing') !== false) {
                $icon = '📨';
            } elseif (strpos($url, 'message_ops') !== false) {
                $icon = '⚙️';
            } elseif (strpos($url, 'total_mail') !== false || strpos($url, 'previous_day') !== false) {
                $icon = '📈';
            } elseif (strpos($url, 'virus') !== false) {
                $icon = '🦠';
            } elseif (strpos($url, 'sender') !== false || strpos($url, 'recipient') !== false || strpos($url, 'relay') !== false) {
                $icon = '👥';
            } elseif (strpos($url, 'sa_') !== false || strpos($url, 'mcp_') !== false) {
                $icon = '🎯';
            } elseif (strpos($url, 'audit') !== false) {
                $icon = '📜';
            }

            echo '          <li class="sidebar-report-item"><a href="' . $url . '" class="sidebar-report-link"><span class="rep-icon">' . $icon . '</span> <span class="rep-title">' . $report['description'] . '</span></a></li>' . "\n";
        }
        echo '        </ul>' . "\n";
        echo '      </div>' . "\n";

        // Section B: Filter Builder
        echo '      <div class="sidebar-section">' . "\n";
        echo '        <div class="sidebar-section-title">🔍 ' . __('addfilter09') . '</div>' . "\n";
        echo $this->DisplayForm();
        echo '      </div>' . "\n";
        echo '    </div>' . "\n"; // End sidebar-content
        echo '  </aside>' . "\n";

        // 2. Main Content Dashboard
        echo '  <main class="reports-main-content">' . "\n";

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

        echo '  </main>' . "\n";
        echo '</div>' . "\n";

        // JavaScript for persistence & collapse
        echo '<script type="text/javascript">
function toggleReportsSidebar() {
    var l = document.getElementById("reportsLayout");
    if (!l) return;
    if (l.classList.contains("sidebar-minimized")) {
        l.classList.remove("sidebar-minimized");
        try { localStorage.setItem("mw_reports_sidebar_minimized", "0"); } catch(e) {}
    } else {
        l.classList.add("sidebar-minimized");
        try { localStorage.setItem("mw_reports_sidebar_minimized", "1"); } catch(e) {}
    }
}
(function() {
    try {
        if (localStorage.getItem("mw_reports_sidebar_minimized") === "1") {
            var l = document.getElementById("reportsLayout");
            if (l) l.classList.add("sidebar-minimized");
        }
    } catch(e) {}
})();
</script>' . "\n";
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
