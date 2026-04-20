<?php
require_once '../../app/config/connection.php';

$page = 'dashboard';
session_start();
$toast = '';
if (isset($_SESSION['login_success'])) {
    $toast = $_SESSION['login_success'];
    unset($_SESSION['login_success']);
}

// ── Stat queries ──────────────────────────────────────────────────────────
try {
    // Total members
    $totalMembers = $pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();

    // Members by type
    $sessionCount    = $pdo->query("SELECT COUNT(*) FROM members WHERE type='session'")->fetchColumn();
    $membershipCount = $pdo->query("SELECT COUNT(*) FROM members WHERE type='member'")->fetchColumn();

    // Active memberships (not expired)
    $activeMemberships = $pdo->query("SELECT COUNT(*) FROM members WHERE type='member' AND (membership_expiry IS NULL OR membership_expiry >= CURDATE())")->fetchColumn();

    // Memberships expiring within 7 days
    $expiringRows = $pdo->query("SELECT first_name, last_name, membership_expiry FROM members WHERE type='member' AND membership_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY membership_expiry ASC LIMIT 5")->fetchAll();

    // Today's entries
    $todayEntries = $pdo->query("SELECT COUNT(*) FROM entry_logs WHERE DATE(entry_time)=CURDATE()")->fetchColumn();

    // Today's entry revenue
    $todayEntryRevenue = $pdo->query("SELECT COALESCE(SUM(amount_charged),0) FROM entry_logs WHERE DATE(entry_time)=CURDATE()")->fetchColumn();

    // Today's sales revenue
    $todaySalesRevenue = 0;
    try { $todaySalesRevenue = $pdo->query("SELECT COALESCE(SUM(total),0) FROM sales WHERE DATE(created_at)=CURDATE()")->fetchColumn(); } catch(Exception $e) {}

    // This month's total revenue
    $monthEntryRevenue = $pdo->query("SELECT COALESCE(SUM(amount_charged),0) FROM entry_logs WHERE MONTH(entry_time)=MONTH(CURDATE()) AND YEAR(entry_time)=YEAR(CURDATE())")->fetchColumn();
    $monthSalesRevenue = 0;
    try { $monthSalesRevenue = $pdo->query("SELECT COALESCE(SUM(total),0) FROM sales WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn(); } catch(Exception $e) {}
    $monthRevenue = floatval($monthEntryRevenue) + floatval($monthSalesRevenue);

    // Last 6 entries today
    $recentEntries = $pdo->query("SELECT member_name, entry_type, amount_charged, payment_method, DATE_FORMAT(entry_time,'%h:%i %p') as t FROM entry_logs ORDER BY entry_time DESC LIMIT 6")->fetchAll();

    // Low stock products (qty <= 5)
    $lowStock = [];
    try { $lowStock = $pdo->query("SELECT product_name, quantity FROM products WHERE quantity <= 5 ORDER BY quantity ASC LIMIT 5")->fetchAll(); } catch(Exception $e) {}

    // Last 7 days entry count for chart
    $chartRows = $pdo->query("SELECT DATE(entry_time) as d, COUNT(*) as cnt FROM entry_logs WHERE entry_time >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(entry_time)")->fetchAll();
    $chartData = [];
    for ($i = 6; $i >= 0; $i--) {
        $dt = date('Y-m-d', strtotime("-$i days"));
        $chartData[$dt] = 0;
    }
    foreach ($chartRows as $r) { if (isset($chartData[$r['d']])) $chartData[$r['d']] = intval($r['cnt']); }

    // Newest 3 members
    $newMembers = $pdo->query("SELECT first_name, last_name, type, Joined_Date FROM members ORDER BY id DESC LIMIT 4")->fetchAll();

} catch (Exception $e) {
    $totalMembers = $sessionCount = $membershipCount = $activeMemberships = $todayEntries = 0;
    $todayEntryRevenue = $todaySalesRevenue = $monthRevenue = 0;
    $recentEntries = $expiringRows = $lowStock = $newMembers = [];
    $chartData = [];
}

$todayRevenue = floatval($todayEntryRevenue) + floatval($todaySalesRevenue);
$chartLabels = [];
$chartValues = [];
$chartMax    = 1;
foreach ($chartData as $dt => $cnt) {
    $chartLabels[] = date('D', strtotime($dt));
    $chartValues[] = $cnt;
    if ($cnt > $chartMax) $chartMax = $cnt;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link href="../../assets/css/toastednotif.css" rel="stylesheet">
    <link href="../../assets/css/admin_header.css" rel="stylesheet">
    <link href="../../assets/css/admin_sidebar.css" rel="stylesheet">
    <link href="../../assets/css/admin.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<style>
    .db-wrap { padding: 28px 32px; }

    /* ── Stat cards ──────────────────────────────────────────────── */
    .db-cards {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
        gap: 16px; margin-bottom: 26px;
    }
    .db-card {
        background: #1e1e1e; border-radius: 14px; padding: 20px 22px;
        border-left: 4px solid transparent; position: relative; overflow: hidden;
    }
    .db-card.c-blue   { border-left-color: #1976d2; }
    .db-card.c-green  { border-left-color: #43a047; }
    .db-card.c-yellow { border-left-color: #f5c518; }
    .db-card.c-purple { border-left-color: #8e24aa; }
    .db-card.c-orange { border-left-color: #f57c00; }
    .db-card.c-teal   { border-left-color: #00838f; }
    .db-card-icon {
        position: absolute; right: 16px; top: 16px;
        font-size: 2rem; opacity: .13;
    }
    .db-card-label { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; font-weight: 600; }
    .db-card-val   { font-size: 2rem; font-weight: 800; color: #fff; line-height: 1.1; }
    .db-card-sub   { font-size: 12px; color: #555; margin-top: 5px; }

    /* ── Two-column row ──────────────────────────────────────────── */
    .db-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 22px; }
    @media (max-width: 900px) { .db-row { grid-template-columns: 1fr; } }

    /* ── Panels ──────────────────────────────────────────────────── */
    .db-panel {
        background: #1e1e1e; border-radius: 14px; overflow: hidden;
    }
    .db-panel-header {
        padding: 14px 18px; border-bottom: 1px solid #2a2a2a;
        display: flex; align-items: center; justify-content: space-between;
    }
    .db-panel-header h3 { margin: 0; font-size: 13px; font-weight: 700; color: #bbb; text-transform: uppercase; letter-spacing: .4px; }
    .db-panel-header a  { font-size: 12px; color: #1976d2; text-decoration: none; }
    .db-panel-header a:hover { text-decoration: underline; }
    .db-panel-body  { padding: 14px 18px; }

    /* ── Recent entries list ─────────────────────────────────────── */
    .entry-item {
        display: flex; align-items: center; justify-content: space-between;
        padding: 9px 0; border-bottom: 1px solid #222;
    }
    .entry-item:last-child { border-bottom: none; }
    .entry-name  { font-size: 13.5px; font-weight: 600; color: #fff; }
    .entry-meta  { font-size: 12px; color: #666; margin-top: 2px; }
    .entry-right { text-align: right; }
    .entry-fee   { font-size: 13px; font-weight: 700; color: #f5c518; }
    .entry-time  { font-size: 11px; color: #555; margin-top: 2px; }

    /* ── Type badge ──────────────────────────────────────────────── */
    .tbadge {
        display: inline-block; padding: 2px 8px; border-radius: 8px;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
    }
    .tb-session    { background: #1976d2; color: #fff; }
    .tb-membership { background: #f5c518; color: #1a1a1a; }
    .tb-walkin     { background: #555; color: #fff; }

    /* ── New members list ────────────────────────────────────────── */
    .nm-item {
        display: flex; align-items: center; gap: 12px;
        padding: 9px 0; border-bottom: 1px solid #222;
    }
    .nm-item:last-child { border-bottom: none; }
    .nm-avatar {
        width: 34px; height: 34px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 14px; font-weight: 700; color: #fff; flex-shrink: 0;
    }
    .nm-name  { font-size: 13.5px; font-weight: 600; color: #fff; }
    .nm-meta  { font-size: 12px; color: #666; margin-top: 1px; }
    .nm-date  { font-size: 12px; color: #555; margin-left: auto; white-space: nowrap; }

    /* ── Chart ───────────────────────────────────────────────────── */
    .chart-wrap { padding: 10px 4px 4px; }

    /* ── Low stock ───────────────────────────────────────────────── */
    .stock-item {
        display: flex; align-items: center; justify-content: space-between;
        padding: 9px 0; border-bottom: 1px solid #222;
    }
    .stock-item:last-child { border-bottom: none; }
    .stock-name { font-size: 13.5px; color: #fff; font-weight: 500; }
    .stock-qty  {
        font-size: 13px; font-weight: 700; padding: 2px 10px;
        border-radius: 8px; background: rgba(229,57,53,.18); color: #ef9a9a;
    }
    .stock-qty.ok { background: rgba(67,160,71,.15); color: #81c784; }

    /* ── Expiring ────────────────────────────────────────────────── */
    .exp-item {
        display: flex; align-items: center; justify-content: space-between;
        padding: 9px 0; border-bottom: 1px solid #222;
    }
    .exp-item:last-child { border-bottom: none; }
    .exp-name { font-size: 13.5px; font-weight: 600; color: #fff; }
    .exp-date { font-size: 12px; color: #ffcc80; }

    /* ── Empty state ─────────────────────────────────────────────── */
    .db-empty { text-align: center; padding: 22px 0; color: #444; font-size: 13px; }

    /* ── Today summary bar ───────────────────────────────────────── */
    .today-bar {
        background: linear-gradient(135deg, #1565c0 0%, #1e1e1e 100%);
        border-radius: 14px; padding: 18px 24px; margin-bottom: 24px;
        display: flex; align-items: center; gap: 30px; flex-wrap: wrap;
    }
    .today-bar .tb-lbl  { font-size: 11px; color: #90caf9; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 4px; }
    .today-bar .tb-val  { font-size: 1.5rem; font-weight: 800; color: #fff; }
    .today-bar .tb-divider { width: 1px; height: 40px; background: rgba(255,255,255,.1); }
    .today-date { font-size: 1rem; font-weight: 700; color: #90caf9; margin-right: auto; }
</style>
<body>
<?php include '../../component/admin_header.php'; ?>
<?php include '../../component/admin_sidebar.php'; ?>

<div class="dashboard-content">
<div class="db-wrap">

    <!-- Today summary bar -->
    <div class="today-bar">
        <div class="today-date"><?php echo date('l, F j, Y'); ?></div>
        <div>
            <div class="tb-lbl">Today's Entries</div>
            <div class="tb-val"><?php echo intval($todayEntries); ?></div>
        </div>
        <div class="tb-divider"></div>
        <div>
            <div class="tb-lbl">Today's Revenue</div>
            <div class="tb-val">&#8369;<?php echo number_format($todayRevenue, 2); ?></div>
        </div>
        <div class="tb-divider"></div>
        <div>
            <div class="tb-lbl">This Month</div>
            <div class="tb-val">&#8369;<?php echo number_format($monthRevenue, 2); ?></div>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="db-cards">
        <div class="db-card c-blue">
            <div class="db-card-icon">&#128100;</div>
            <div class="db-card-label">Total Members</div>
            <div class="db-card-val"><?php echo intval($totalMembers); ?></div>
            <div class="db-card-sub">Session: <?php echo intval($sessionCount); ?> &nbsp;&bull;&nbsp; Membership: <?php echo intval($membershipCount); ?></div>
        </div>
        <div class="db-card c-green">
            <div class="db-card-icon">&#9989;</div>
            <div class="db-card-label">Active Memberships</div>
            <div class="db-card-val"><?php echo intval($activeMemberships); ?></div>
            <div class="db-card-sub">valid &amp; not expired</div>
        </div>
        <div class="db-card c-yellow">
            <div class="db-card-icon">&#128184;</div>
            <div class="db-card-label">Today's Revenue</div>
            <div class="db-card-val" style="font-size:1.5rem;">&#8369;<?php echo number_format($todayRevenue, 2); ?></div>
            <div class="db-card-sub">entries + sales</div>
        </div>
        <div class="db-card c-purple">
            <div class="db-card-icon">&#128201;</div>
            <div class="db-card-label">Month Revenue</div>
            <div class="db-card-val" style="font-size:1.5rem;">&#8369;<?php echo number_format($monthRevenue, 2); ?></div>
            <div class="db-card-sub"><?php echo date('F Y'); ?></div>
        </div>
        <div class="db-card c-teal">
            <div class="db-card-icon">&#128203;</div>
            <div class="db-card-label">Today's Entries</div>
            <div class="db-card-val"><?php echo intval($todayEntries); ?></div>
            <div class="db-card-sub">all walk-ins &amp; members</div>
        </div>
        <?php if (!empty($expiringRows)): ?>
        <div class="db-card c-orange">
            <div class="db-card-icon">&#8987;</div>
            <div class="db-card-label">Expiring Soon</div>
            <div class="db-card-val"><?php echo count($expiringRows); ?></div>
            <div class="db-card-sub">membership within 7 days</div>
        </div>
        <?php else: ?>
        <div class="db-card c-orange">
            <div class="db-card-icon">&#128293;</div>
            <div class="db-card-label">Low Stock</div>
            <div class="db-card-val"><?php echo count($lowStock); ?></div>
            <div class="db-card-sub">products &le; 5 units</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Row 1: Chart + Recent entries -->
    <div class="db-row">
        <!-- 7-day chart -->
        <div class="db-panel">
            <div class="db-panel-header">
                <h3>&#128200; Entries — Last 7 Days</h3>
            </div>
            <div class="db-panel-body chart-wrap">
                <canvas id="entryChart" height="160"></canvas>
            </div>
        </div>

        <!-- Recent entries -->
        <div class="db-panel">
            <div class="db-panel-header">
                <h3>&#128276; Recent Entries</h3>
                <a href="visitorLog.php">View All</a>
            </div>
            <div class="db-panel-body">
                <?php if (empty($recentEntries)): ?>
                    <div class="db-empty">No entries yet today.</div>
                <?php else: ?>
                    <?php foreach ($recentEntries as $e): ?>
                    <?php
                        $tc  = $e['entry_type'] === 'membership' ? 'tb-membership' : ($e['entry_type'] === 'walk-in' ? 'tb-walkin' : 'tb-session');
                        $fee = floatval($e['amount_charged']) > 0 ? '&#8369;'.number_format($e['amount_charged'],2) : 'Free';
                    ?>
                    <div class="entry-item">
                        <div>
                            <div class="entry-name"><?php echo htmlspecialchars($e['member_name']); ?></div>
                            <div class="entry-meta"><span class="tbadge <?php echo $tc; ?>"><?php echo $e['entry_type']; ?></span></div>
                        </div>
                        <div class="entry-right">
                            <div class="entry-fee"><?php echo $fee; ?></div>
                            <div class="entry-time"><?php echo $e['t']; ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Row 2: New members + Low stock + Expiring -->
    <div class="db-row">
        <!-- New members -->
        <div class="db-panel">
            <div class="db-panel-header">
                <h3>&#127381; Recently Joined</h3>
                <a href="management/member.php">Manage</a>
            </div>
            <div class="db-panel-body">
                <?php
                $avatarColors = ['#1976d2','#e53935','#388e3c','#f57c00','#7b1fa2','#00838f'];
                if (empty($newMembers)): ?>
                    <div class="db-empty">No members yet.</div>
                <?php else: foreach ($newMembers as $m):
                    $letter = strtoupper(substr($m['first_name'],0,1)) ?: '?';
                    $color  = $avatarColors[ord($letter) % count($avatarColors)];
                    $joined = $m['Joined_Date'] ? date('d M Y', strtotime($m['Joined_Date'])) : '—';
                    $tc     = $m['type'] === 'member' ? 'tb-membership' : 'tb-session';
                ?>
                <div class="nm-item">
                    <div class="nm-avatar" style="background:<?php echo $color; ?>"><?php echo htmlspecialchars($letter); ?></div>
                    <div>
                        <div class="nm-name"><?php echo htmlspecialchars(trim($m['first_name'].' '.$m['last_name'])); ?></div>
                        <div class="nm-meta"><span class="tbadge <?php echo $tc; ?>"><?php echo $m['type']; ?></span></div>
                    </div>
                    <div class="nm-date"><?php echo $joined; ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Low stock + Expiring -->
        <div style="display:flex; flex-direction:column; gap:18px;">

            <!-- Expiring memberships -->
            <div class="db-panel">
                <div class="db-panel-header">
                    <h3>&#8987; Memberships Expiring Soon</h3>
                </div>
                <div class="db-panel-body">
                    <?php if (empty($expiringRows)): ?>
                        <div class="db-empty">&#10003; No memberships expiring this week.</div>
                    <?php else: foreach ($expiringRows as $ex):
                        $expDate = date('d M Y', strtotime($ex['membership_expiry']));
                        $daysLeft = (int)((strtotime($ex['membership_expiry']) - strtotime('today')) / 86400);
                    ?>
                    <div class="exp-item">
                        <div>
                            <div class="exp-name"><?php echo htmlspecialchars(trim($ex['first_name'].' '.$ex['last_name'])); ?></div>
                        </div>
                        <div style="text-align:right;">
                            <div class="exp-date"><?php echo $expDate; ?></div>
                            <div style="font-size:11px;color:#e57373;"><?php echo $daysLeft === 0 ? 'Expires today' : "in $daysLeft day".($daysLeft>1?'s':''); ?></div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- Low stock -->
            <div class="db-panel">
                <div class="db-panel-header">
                    <h3>&#128293; Low Stock Products</h3>
                    <a href="system/Ecommerce.php">Manage</a>
                </div>
                <div class="db-panel-body">
                    <?php if (empty($lowStock)): ?>
                        <div class="db-empty">&#10003; All products are well stocked.</div>
                    <?php else: foreach ($lowStock as $p): ?>
                    <div class="stock-item">
                        <div class="stock-name"><?php echo htmlspecialchars($p['product_name']); ?></div>
                        <div class="stock-qty <?php echo intval($p['quantity']) > 2 ? 'ok' : ''; ?>">
                            <?php echo intval($p['quantity']); ?> left
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

        </div>
    </div>

</div>
</div>

<div id="toastNotif" class="toast" style="display:none;"></div>
<script>
// Login toast
(function(){
    var msg = <?php echo json_encode($toast); ?>;
    if (!msg) return;
    var t = document.getElementById('toastNotif');
    t.textContent = msg;
    t.classList.add('success');
    t.style.display = 'flex';
    setTimeout(function(){ t.classList.add('show'); }, 100);
    setTimeout(function(){ t.classList.remove('show'); setTimeout(function(){ t.style.display='none'; t.classList.remove('success'); },300); }, 3500);
})();

// Entry chart
(function(){
    var labels = <?php echo json_encode($chartLabels); ?>;
    var values = <?php echo json_encode($chartValues); ?>;
    var ctx = document.getElementById('entryChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Entries',
                data: values,
                backgroundColor: 'rgba(25,118,210,0.7)',
                borderColor: '#1976d2',
                borderWidth: 1,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx){ return ' ' + ctx.parsed.y + ' entr' + (ctx.parsed.y===1?'y':'ies'); }
                    }
                }
            },
            scales: {
                x: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#666' } },
                y: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#666', stepSize: 1, precision: 0 }, beginAtZero: true }
            }
        }
    });
})();
</script>
</body>
</html>
