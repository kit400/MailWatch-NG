<?php

/*
 * MailWatch for MailScanner
 * Copyright (C) 2003-2026 MailWatch Team
 */

// Include of necessary functions
require_once __DIR__ . '/functions.php';

// Authentication checking
require __DIR__ . '/login.function.php';

html_start(__('toolslinks10'), 0, false, false);

$virusScanner = get_conf_var('VirusScanners');
$userType = $_SESSION['user_type'] ?? 'U';
$isAdmin = ('A' === $userType);
?>

<div class="tools-links-container">

  <!-- Left Column: Tools -->
  <div class="tools-card">
    <div class="tools-card-header">
      <span class="tools-card-icon"><?php echo mw_icon('tools', '', 18); ?></span>
      <h3 class="tools-card-title"><?php echo __('tools10'); ?></h3>
    </div>
    <ul class="tools-list">
      <li>
        <a href="user_manager.php">
          <span class="tool-item-icon"><?php echo mw_icon('users', '', 16); ?></span>
          <span><?php echo __('usermgnt10'); ?></span>
        </a>
      </li>

      <?php if ($isAdmin): ?>
      <li>
        <a href="settings.php">
          <span class="tool-item-icon"><?php echo mw_icon('shield', '', 16); ?></span>
          <span><?php echo (__('systemsettings10', false) ?: 'System Settings &amp; Security'); ?></span>
        </a>
      </li>
      <li>
        <a href="system_notifications.php">
          <span class="tool-item-icon"><?php echo mw_icon('broadcast', '', 16); ?></span>
          <span><?php echo (__('notifications10', false) ?: 'System Notifications &amp; Broadcast'); ?></span>
        </a>
      </li>

      <?php
      if (preg_match('/sophos/i', $virusScanner)) {
          echo '<li><a href="sophos_status.php"><span class="tool-item-icon">' . mw_icon('shield', '', 16) . '</span><span>' . __('avsophosstatus10') . '</span></a></li>';
      }
      if (preg_match('/f-secure-12/i', $virusScanner)) {
          echo '<li><a href="f-secure12_status.php"><span class="tool-item-icon">' . mw_icon('shield', '', 16) . '</span><span>' . __('avfsecure12status10') . '</span></a></li>';
      }
      if (preg_match('/f-secured?(?!-12)/i', $virusScanner)) {
          echo '<li><a href="f-secure_status.php"><span class="tool-item-icon">' . mw_icon('shield', '', 16) . '</span><span>' . __('avfsecurestatus10') . '</span></a></li>';
      }
      if (preg_match('/clam/i', $virusScanner)) {
          echo '<li><a href="clamav_status.php"><span class="tool-item-icon">' . mw_icon('shield', '', 16) . '</span><span>' . __('avclamavstatus10') . '</span></a></li>';
      }
      if (preg_match('/mcafee/i', $virusScanner)) {
          echo '<li><a href="mcafee_status.php"><span class="tool-item-icon">' . mw_icon('shield', '', 16) . '</span><span>' . __('avmcafeestatus10') . '</span></a></li>';
      }
      if (preg_match('/f-prot/i', $virusScanner)) {
          echo '<li><a href="f-prot_status.php"><span class="tool-item-icon">' . mw_icon('shield', '', 16) . '</span><span>' . __('avfprotstatus10') . '</span></a></li>';
      }
      ?>

      <li>
        <a href="mysql_status.php">
          <span class="tool-item-icon"><?php echo mw_icon('database', '', 16); ?></span>
          <span><?php echo __('mysqldatabasestatus10'); ?></span>
        </a>
      </li>
      <li>
        <a href="msconfig.php">
          <span class="tool-item-icon"><?php echo mw_icon('search', '', 16); ?></span>
          <span><?php echo __('viewconfms10'); ?></span>
        </a>
      </li>
      <?php if (defined('MSRE') && MSRE === true): ?>
      <li>
        <a href="msre_index.php">
          <span class="tool-item-icon"><?php echo mw_icon('edit', '', 16); ?></span>
          <span><?php echo __('editmsrules10'); ?></span>
        </a>
      </li>
      <?php endif; ?>

      <?php if (!DISTRIBUTED_SETUP && true === get_conf_truefalse('UseSpamAssassin')): ?>
      <li>
        <a href="bayes_info.php">
          <span class="tool-item-icon"><?php echo mw_icon('brain', '', 16); ?></span>
          <span><?php echo __('spamassassinbayesdatabaseinfo10'); ?></span>
        </a>
      </li>
      <li>
        <a href="sa_lint.php">
          <span class="tool-item-icon"><?php echo mw_icon('info', '', 16); ?></span>
          <span>SpamAssassin Lint (Test)</span>
        </a>
      </li>
      <li>
        <a href="ms_lint.php">
          <span class="tool-item-icon"><?php echo mw_icon('info', '', 16); ?></span>
          <span>MailScanner Lint (Test)</span>
        </a>
      </li>
      <li>
        <a href="sa_rules_update.php">
          <span class="tool-item-icon"><?php echo mw_icon('clock', '', 16); ?></span>
          <span><?php echo __('updatesadesc10'); ?></span>
        </a>
      </li>
      <?php endif; ?>

      <?php if (!DISTRIBUTED_SETUP && true === get_conf_truefalse('MCPChecks')): ?>
      <li>
        <a href="mcp_rules_update.php">
          <span class="tool-item-icon"><?php echo mw_icon('clock', '', 16); ?></span>
          <span><?php echo __('updatemcpdesc10'); ?></span>
        </a>
      </li>
      <?php endif; ?>

      <li>
        <a href="geoip_update.php">
          <span class="tool-item-icon"><?php echo mw_icon('dashboard', '', 16); ?></span>
          <span><?php echo __('updategeoip10'); ?></span>
        </a>
      </li>
      <?php endif; ?>
    </ul>
  </div>

  <!-- Right Column: Links -->
  <?php if ($isAdmin): ?>
  <div class="tools-card">
    <div class="tools-card-header">
      <span class="tools-card-icon"><?php echo mw_icon('book', '', 18); ?></span>
      <h3 class="tools-card-title"><?php echo __('links10'); ?></h3>
    </div>
    <ul class="tools-list">
      <li>
        <a href="https://t.me/EFA_NG" target="_blank" rel="noopener noreferrer" style="font-weight: 600;">
          <span class="tool-item-icon"><i class="fa fa-paper-plane" style="color: #24A1DE;"></i></span>
          <span>EFA-NG Official Telegram Channel</span>
          <span class="external-arrow" style="color: #24A1DE;">↗</span>
        </a>
      </li>
      <li>
        <a href="https://efa-ng.space.ua" target="_blank" rel="noopener noreferrer">
          <span class="tool-item-icon"><?php echo mw_icon('globe', '', 16); ?></span>
          <span>EFA-NG Project Portal</span>
          <span class="external-arrow">↗</span>
        </a>
      </li>
      <li>
        <a href="https://mailwatch.org" target="_blank" rel="noopener noreferrer">
          <span class="tool-item-icon"><?php echo mw_icon('shield', '', 16); ?></span>
          <span>MailWatch for MailScanner</span>
          <span class="external-arrow">↗</span>
        </a>
      </li>
      <li>
        <a href="https://www.mailscanner.info" target="_blank" rel="noopener noreferrer">
          <span class="tool-item-icon"><?php echo mw_icon('book', '', 16); ?></span>
          <span>MailScanner Official Site</span>
          <span class="external-arrow">↗</span>
        </a>
      </li>

      <?php if (true === get_conf_truefalse('UseSpamAssassin')): ?>
      <li>
        <a href="https://spamassassin.apache.org/" target="_blank" rel="noopener noreferrer">
          <span class="tool-item-icon"><?php echo mw_icon('search', '', 16); ?></span>
          <span>Apache SpamAssassin</span>
          <span class="external-arrow">↗</span>
        </a>
      </li>
      <?php endif; ?>

      <?php if (preg_match('/sophos/i', $virusScanner)): ?>
      <li>
        <a href="https://www.sophos.com" target="_blank" rel="noopener noreferrer">
          <span class="tool-item-icon"><?php echo mw_icon('shield', '', 16); ?></span>
          <span>Sophos Antivirus</span>
          <span class="external-arrow">↗</span>
        </a>
      </li>
      <?php endif; ?>

      <?php if (preg_match('/clam/i', $virusScanner)): ?>
      <li>
        <a href="https://clamav.net" target="_blank" rel="noopener noreferrer">
          <span class="tool-item-icon"><?php echo mw_icon('shield', '', 16); ?></span>
          <span>ClamAV Antivirus</span>
          <span class="external-arrow">↗</span>
        </a>
      </li>
      <?php endif; ?>

      <li>
        <a href="https://mxtoolbox.com/NetworkTools.aspx" target="_blank" rel="noopener noreferrer">
          <span class="tool-item-icon"><?php echo mw_icon('tools', '', 16); ?></span>
          <span>MXToolbox Network Tools</span>
          <span class="external-arrow">↗</span>
        </a>
      </li>
      <li>
        <a href="https://multirbl.valli.org/" target="_blank" rel="noopener noreferrer">
          <span class="tool-item-icon"><?php echo mw_icon('shield', '', 16); ?></span>
          <span>Multi-RBL Blacklist Check</span>
          <span class="external-arrow">↗</span>
        </a>
      </li>
    </ul>
  </div>
  <?php endif; ?>

</div>

<?php
// Add footer
html_end();
// Close any open db connections
dbclose();
