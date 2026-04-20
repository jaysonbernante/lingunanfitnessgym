<?php
// component/admin_header.php
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Session timeout: auto-logout after 20 minutes of inactivity ───────────
define('SESSION_TIMEOUT_SECONDS', 1200); // 20 minutes
$_inSubHeader = (strpos(str_replace('\\','/',$_SERVER['SCRIPT_FILENAME']), '/management/') !== false
              || strpos(str_replace('\\','/',$_SERVER['SCRIPT_FILENAME']), '/system/')    !== false);
$_loginUrl = $_inSubHeader ? '../index.php' : 'index.php';

if (!isset($_SESSION['user_id'])) {
    // Not logged in — redirect to login
    header('Location: ' . $_loginUrl);
    exit();
}
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
    // Session expired
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['login_error'] = 'Your session has expired due to inactivity. Please log in again.';
    header('Location: ' . $_loginUrl);
    exit();
}
$_SESSION['last_activity'] = time();
// ──────────────────────────────────────────────────────────────────────────

$_displayName = htmlspecialchars($_SESSION['user_name'] ?? 'User');

// ── Build notifications ────────────────────────────────────────────────────
$_notifications = [];
if (isset($pdo)) {
    // New members today
    try {
        $rows = $pdo->query("SELECT first_name, last_name FROM members WHERE DATE(Joined_Date)=CURDATE() ORDER BY id DESC LIMIT 5")->fetchAll();
        foreach ($rows as $r) {
            $_notifications[] = ['icon'=>'&#127381;','color'=>'#1976d2','title'=>htmlspecialchars(trim($r['first_name'].' '.$r['last_name'])).' joined','sub'=>'New member registered today','type'=>'member'];
        }
    } catch(Exception $e) {}
    // Expiring memberships within 7 days
    try {
        $rows = $pdo->query("SELECT first_name, last_name, membership_expiry FROM members WHERE type='member' AND membership_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY membership_expiry ASC LIMIT 5")->fetchAll();
        foreach ($rows as $r) {
            $days = (int)((strtotime($r['membership_expiry']) - strtotime('today')) / 86400);
            $when = $days === 0 ? 'today' : "in $days day".($days>1?'s':'');
            $_notifications[] = ['icon'=>'&#9203;','color'=>'#f57c00','title'=>htmlspecialchars(trim($r['first_name'].' '.$r['last_name'])),'sub'=>'Membership expires '.$when,'type'=>'member'];
        }
    } catch(Exception $e) {}
    // Low stock
    try {
        $rows = $pdo->query("SELECT product_name, quantity FROM products WHERE quantity <= 5 ORDER BY quantity ASC LIMIT 5")->fetchAll();
        foreach ($rows as $r) {
            $_notifications[] = ['icon'=>'&#128230;','color'=>'#e53935','title'=>htmlspecialchars($r['product_name']),'sub'=>'Only '.$r['quantity'].' left in stock','type'=>'ecommerce'];
        }
    } catch(Exception $e) {}
}

// Detect if we're in a subdirectory (management/ or system/) or root client folder
$_inSub = (strpos(str_replace('\\','/',$_SERVER['SCRIPT_FILENAME']), '/management/') !== false
        || strpos(str_replace('\\','/',$_SERVER['SCRIPT_FILENAME']), '/system/')    !== false);
$_linkMap = [
    'member'     => $_inSub ? '../management/member.php'  : 'management/member.php',
    'ecommerce'  => $_inSub ? '../system/Ecommerce.php'   : 'system/Ecommerce.php',
];
$_notifCount = count($_notifications);
?>
<style>
/* ── Notification bell + dropdown ─────────────────────────── */
.notif-wrap {
    position: relative;
}
.notif-badge {
    position: absolute; top: -6px; right: -8px;
    background: #e53935; color: #fff;
    font-size: 10px; font-weight: 700;
    min-width: 17px; height: 17px;
    border-radius: 9px; display: flex;
    align-items: center; justify-content: center;
    padding: 0 4px; pointer-events: none;
    border: 2px solid #181818;
}
.notif-dropdown {
    display: none;
    position: absolute; top: calc(100% + 14px); right: -10px;
    width: 320px; background: #1e1e1e;
    border-radius: 14px; border: 1px solid #2a2a2a;
    box-shadow: 0 8px 32px rgba(0,0,0,.6);
    z-index: 9999; overflow: hidden;
}
.notif-dropdown.open { display: block; }
.notif-dropdown-header {
    padding: 13px 16px 10px;
    border-bottom: 1px solid #2a2a2a;
    display: flex; align-items: center; justify-content: space-between;
}
.notif-dropdown-header span {
    font-size: 13px; font-weight: 700; color: #bbb; text-transform: uppercase; letter-spacing: .4px;
}
.notif-dropdown-header small {
    font-size: 11px; color: #555;
}
.notif-list { max-height: 340px; overflow-y: auto; }
.notif-list::-webkit-scrollbar { width: 4px; }
.notif-list::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
.notif-item {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 11px 16px; border-bottom: 1px solid #242424;
    cursor: pointer; transition: background .15s; text-decoration: none;
}
.notif-item:last-child { border-bottom: none; }
.notif-item:hover { background: rgba(255,255,255,.04); }
.notif-item-icon {
    width: 34px; height: 34px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
}
.notif-item-text { flex: 1; min-width: 0; }
.notif-item-title {
    font-size: 13px; font-weight: 600; color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.notif-item-sub { font-size: 11.5px; color: #777; margin-top: 2px; }
.notif-empty {
    text-align: center; padding: 30px 16px; color: #444; font-size: 13px;
}
.notif-empty span { display: block; font-size: 2rem; margin-bottom: 8px; }
</style>
<div class="dashboard-header">
  <div class="logo">
    <div class="logo-img"></div>
    <div class="logo-text">Lingunan<span>FitnessGym</span></div>
  </div>
  <div class="header-actions">
    <!-- Notification bell -->
    <div class="notif-wrap" id="notifWrap">
      <span class="notif" id="notifBell" title="Notifications">&#128276;</span>
      <?php if ($_notifCount > 0): ?>
      <span class="notif-badge"><?php echo $_notifCount; ?></span>
      <?php endif; ?>
      <!-- Dropdown -->
      <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-dropdown-header">
          <span>Notifications</span>
          <small><?php echo $_notifCount; ?> item<?php echo $_notifCount!==1?'s':''; ?></small>
        </div>
        <div class="notif-list">
          <?php if (empty($_notifications)): ?>
            <div class="notif-empty"><span>&#127881;</span>All clear — no new alerts.</div>
          <?php else: ?>
            <?php foreach ($_notifications as $n): ?>
            <a class="notif-item" href="<?php echo $_linkMap[$n['type']]; ?>">
              <div class="notif-item-icon" style="background:<?php echo $n['color']; ?>22; color:<?php echo $n['color']; ?>;">
                <?php echo $n['icon']; ?>
              </div>
              <div class="notif-item-text">
                <div class="notif-item-title"><?php echo $n['title']; ?></div>
                <div class="notif-item-sub"><?php echo $n['sub']; ?></div>
              </div>
            </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="user-info">
      <span class="user-icon">👤</span>
      <span class="user-name"><?php echo $_displayName; ?></span>
    </div>
  </div>
</div>
<script>
(function(){
    var bell     = document.getElementById('notifBell');
    var dropdown = document.getElementById('notifDropdown');
    if (!bell || !dropdown) return;
    bell.addEventListener('click', function(e){
        e.stopPropagation();
        dropdown.classList.toggle('open');
    });
    document.addEventListener('click', function(e){
        if (!document.getElementById('notifWrap').contains(e.target)) {
            dropdown.classList.remove('open');
        }
    });
})();
</script>
