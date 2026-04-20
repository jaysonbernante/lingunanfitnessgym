<?php
require_once '../../../app/config/connection.php';

// Migrations
try { $pdo->exec("ALTER TABLE members ADD COLUMN plan_months INT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE members ADD COLUMN membership_expiry DATE DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_rfids (id INT AUTO_INCREMENT PRIMARY KEY, rfid VARCHAR(100) NOT NULL, member_id INT DEFAULT NULL, blocked_at DATETIME DEFAULT NOW(), reason VARCHAR(100) DEFAULT 'lost')"); } catch(Exception $e) {}

// AJAX: Delete member
if (isset($_POST['ajax_delete_member'])) {
    header('Content-Type: application/json');
    $id = intval($_POST['id'] ?? 0);
    try {
        $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
    } catch(Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

// AJAX: Add session user
if (isset($_POST['ajax_add_user'])) {
    header('Content-Type: application/json');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $gmail      = trim($_POST['gmail'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    $rfid       = trim($_POST['rfid_number'] ?? '') ?: null;
    if (!$first_name || !$last_name) { echo json_encode(['success' => false, 'message' => 'First and last name are required.']); exit; }
    $username = strtolower($first_name . $last_name);
    $password = password_hash('12345fitness', PASSWORD_DEFAULT);
    try {
        // Duplicate username check
        $chk = $pdo->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
        $chk->execute([$username]);
        if ($chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Username "' . $username . '" is already taken. Use a different name.']); exit; }
        // Duplicate gmail check
        if ($gmail) {
            $chk = $pdo->prepare("SELECT id FROM members WHERE gmail = ? LIMIT 1");
            $chk->execute([$gmail]);
            if ($chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Email "' . $gmail . '" is already registered.']); exit; }
        }
        // Duplicate RFID check
        if ($rfid) {
            $chk = $pdo->prepare("SELECT id FROM members WHERE RFID = ? LIMIT 1");
            $chk->execute([$rfid]);
            if ($chk->fetch()) { echo json_encode(['success' => false, 'message' => 'RFID card is already assigned to another member.']); exit; }
        }
        $pdo->prepare("INSERT INTO members (first_name, last_name, username, gmail, phone, address, type, password, RFID, Joined_Date) VALUES (?, ?, ?, ?, ?, ?, 'session', ?, ?, CURDATE())")
            ->execute([$first_name, $last_name, $username, $gmail, $phone, $address, $password, $rfid]);
        echo json_encode(['success' => true]);
    } catch(Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

// AJAX: Add membership user
if (isset($_POST['ajax_add_membership'])) {
    header('Content-Type: application/json');
    $first_name  = trim($_POST['first_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $gmail       = trim($_POST['gmail'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $plan_months = intval($_POST['plan_months'] ?? 0);
    $rfid        = trim($_POST['rfid_number'] ?? '') ?: null;
    if (!$first_name || !$last_name) { echo json_encode(['success' => false, 'message' => 'First and last name are required.']); exit; }
    if (!$plan_months) { echo json_encode(['success' => false, 'message' => 'Please select a monthly plan.']); exit; }
    $username = strtolower($first_name . $last_name);
    $password = password_hash('12345fitness', PASSWORD_DEFAULT);
    $expiry   = date('Y-m-d', strtotime("+{$plan_months} months"));
    try {
        // Duplicate username check
        $chk = $pdo->prepare("SELECT id FROM members WHERE username = ? LIMIT 1");
        $chk->execute([$username]);
        if ($chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Username "' . $username . '" is already taken. Use a different name.']); exit; }
        // Duplicate gmail check
        if ($gmail) {
            $chk = $pdo->prepare("SELECT id FROM members WHERE gmail = ? LIMIT 1");
            $chk->execute([$gmail]);
            if ($chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Email "' . $gmail . '" is already registered.']); exit; }
        }
        // Duplicate RFID check
        if ($rfid) {
            $chk = $pdo->prepare("SELECT id FROM members WHERE RFID = ? LIMIT 1");
            $chk->execute([$rfid]);
            if ($chk->fetch()) { echo json_encode(['success' => false, 'message' => 'RFID card is already assigned to another member.']); exit; }
        }
        $pdo->prepare("INSERT INTO members (first_name, last_name, username, gmail, phone, address, type, password, RFID, plan_months, membership_expiry, Joined_Date) VALUES (?, ?, ?, ?, ?, ?, 'member', ?, ?, ?, ?, CURDATE())")
            ->execute([$first_name, $last_name, $username, $gmail, $phone, $address, $password, $rfid, $plan_months, $expiry]);
        echo json_encode(['success' => true]);
    } catch(Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

// AJAX: Edit member
if (isset($_POST['ajax_edit_member'])) {
    header('Content-Type: application/json');
    $id         = intval($_POST['id'] ?? 0);
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $gmail      = trim($_POST['gmail'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    $new_rfid   = trim($_POST['new_rfid'] ?? '') ?: null;
    $old_rfid   = trim($_POST['old_rfid'] ?? '') ?: null;
    $block_old  = ($_POST['block_old'] ?? '0') === '1';
    if (!$first_name || !$last_name || !$id) { echo json_encode(['success' => false, 'message' => 'Required fields missing.']); exit; }
    try {
        // Duplicate gmail check (exclude self)
        if ($gmail) {
            $chk = $pdo->prepare("SELECT id FROM members WHERE gmail = ? AND id != ? LIMIT 1");
            $chk->execute([$gmail, $id]);
            if ($chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Email "' . $gmail . '" is already registered to another member.']); exit; }
        }
        // Duplicate RFID check (exclude self)
        if ($new_rfid) {
            $chk = $pdo->prepare("SELECT id FROM members WHERE RFID = ? AND id != ? LIMIT 1");
            $chk->execute([$new_rfid, $id]);
            if ($chk->fetch()) { echo json_encode(['success' => false, 'message' => 'RFID card is already assigned to another member.']); exit; }
        }
        if ($new_rfid && $old_rfid && $block_old) {
            $pdo->prepare("INSERT INTO blocked_rfids (rfid, member_id, reason) VALUES (?, ?, 'lost')")->execute([$old_rfid, $id]);
        }
        if ($new_rfid) {
            $pdo->prepare("UPDATE members SET first_name=?, last_name=?, gmail=?, phone=?, address=?, RFID=? WHERE id=?")
                ->execute([$first_name, $last_name, $gmail, $phone, $address, $new_rfid, $id]);
        } else {
            $pdo->prepare("UPDATE members SET first_name=?, last_name=?, gmail=?, phone=?, address=? WHERE id=?")
                ->execute([$first_name, $last_name, $gmail, $phone, $address, $id]);
        }
        echo json_encode(['success' => true]);
    } catch(Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

$page = 'member';
include '../../../component/admin_header.php';
include '../../../component/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Management</title>
    <link href="../../../assets/css/toastednotif.css" rel="stylesheet">
    <link href="../../../assets/css/admin_header.css" rel="stylesheet">
    <link href="../../../assets/css/admin_sidebar.css" rel="stylesheet">
    <link href="../../../assets/css/admin.css" rel="stylesheet">
</head>
<style>
</style>
<style>
    .Member-content{
        margin-left: 250px;
        margin-top: 60px;
        padding: 2rem;
        min-height: calc(100vh - 60px);
        background: #222;
    }
    @media (max-width: 900px) {
        .Member-content {
            margin-left: 0;
            padding: 1rem;
        }
    }
    .admin-table {
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
        background: rgba(0,0,0,0.04);
        font-family: 'Inter', Arial, sans-serif;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        border-radius: 16px;
        overflow: hidden;
    }
    .admin-table thead th:first-child {
        border-top-left-radius: 16px;
    }
    .admin-table thead th:last-child {
        border-top-right-radius: 16px;
    }
    .admin-table tbody tr:last-child td:first-child {
        border-bottom-left-radius: 16px;
    }
    .admin-table tbody tr:last-child td:last-child {
        border-bottom-right-radius: 16px;
    }
    .admin-table th, .admin-table td {
        padding: 12px 10px;
    text-align: left;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    }
    .admin-table th {
        background: rgba(0,0,0,0.04);
        font-weight: 700;
        color: #fff;
        border-bottom: 2px solid #333;
    }
    .admin-table tr {
        transition: background 0.2s;
    }
    .admin-table tbody tr:hover {
        background: #f9f9f965;
        color: #333;
    }
    .admin-table td {
        border-bottom: 1px solid #333;
        font-size: 15px;
    }
    .badge-status {
        display: inline-block;
        padding: 2px 12px;
        border-radius: 12px;
        font-size: 90%;
        font-weight: 600;
        color: #fff;
    }
    .badge-active {
        background: #43a047;
    }
    .badge-inactive {
        background: #e53935;
    }
    .badge-auth {
        background: #ffe082;
        color: #795548;
        border-radius: 8px;
        padding: 2px 10px;
        font-weight: 600;
    }
    .action-btn {
        background: none;
        border: none;
        color: #1976d2;
        cursor: pointer;
        padding: 0 8px;
        font-size: 15px;
        transition: color 0.2s;
    }
    .action-btn.delete {
        color: #d32f2f;
    }
    .action-btn:hover {
        text-decoration: underline;
    }
    .admin-table th:nth-child(1){width:18%;}
    .admin-table th:nth-child(2){width:20%;}
    .admin-table th:nth-child(3){width:9%;}
    .admin-table th:nth-child(4){width:10%;}
    .admin-table th:nth-child(5){width:12%;}
    .admin-table th:nth-child(6){width:12%;}
    .admin-table th:nth-child(7){width:auto;}
    .admin-table th:nth-child(6){width:15%;}

    /* Responsive table - horizontal scroll on small screens */
    .table-wrapper {
        overflow-x: auto;
        width: 100%;
    }
    @media (max-width: 700px) {
        #searchUsername { width: 100% !important; box-sizing: border-box; }
        .admin-table { min-width: 600px; }
    }
    /* Modal */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.65);
        z-index: 1000;
        justify-content: center;
        align-items: center;
    }
    .modal-overlay.active { display: flex; }
    .modal-box {
        background: #2c2c2c;
        border-radius: 16px;
        padding: 32px;
        max-width: 480px;
        width: 90%;
        color: #fff;
        box-shadow: 0 8px 32px rgba(0,0,0,0.5);
        max-height: 90vh;
        overflow-y: auto;
    }
    .modal-box h2 { margin: 0 0 8px; font-size: 1.3rem; }
    .modal-subtitle { color: #aaa; font-size: 14px; margin: 0 0 20px; }
    .type-cards { display: flex; gap: 16px; }
    .type-card {
        flex: 1;
        padding: 24px 16px;
        border-radius: 12px;
        border: 2px solid #444;
        text-align: center;
        cursor: pointer;
        background: #333;
        font-weight: 600;
        font-size: 1rem;
        color: #fff;
        transition: border-color 0.2s, background 0.2s;
    }
    .type-card.session:hover { border-color: #1976d2; background: rgba(25,118,210,0.12); }
    .type-card.membership:hover { border-color: #f5c518; background: rgba(245,197,24,0.12); }
    .type-card .card-icon { font-size: 1.8rem; margin-bottom: 8px; }
    /* Plan selection cards */
    .plan-cards { display:flex; gap:12px; margin-bottom:16px; }
    .plan-card {
        flex:1; padding:16px 8px; border-radius:12px; border:2px solid #444;
        text-align:center; cursor:pointer; background:#333;
        transition:border-color 0.2s, background 0.2s;
    }
    .plan-card:hover { border-color:#1976d2; background:rgba(25,118,210,0.12); }
    .plan-card.selected { border-color:#f5c518; background:rgba(245,197,24,0.1); }
    .plan-duration { font-weight:700; font-size:0.9rem; color:#fff; margin-bottom:6px; }
    .plan-price { font-size:1.15rem; font-weight:800; color:#f5c518; }
    /* RFID toggle */
    .avail-card-toggle { display:flex; align-items:center; gap:8px; margin-bottom:12px; cursor:pointer; font-size:14px; color:#bbb; }
    .avail-card-toggle input[type=checkbox] { width:auto !important; margin:0; }
    .modal-form { display: none; }
    .modal-form.active { display: block; }
    .btn-back {
        background: none;
        border: none;
        color: #aaa;
        cursor: pointer;
        font-size: 13px;
        padding: 0;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .btn-back:hover { color: #fff; }
    .modal-form label { display: block; margin-bottom: 4px; font-size: 13px; color: #bbb; }
    .modal-form input, .modal-form select {
        width: 100%;
        padding: 8px 12px;
        border-radius: 8px;
        border: 1px solid #444;
        background: #1a1a1a;
        color: #fff;
        margin-bottom: 12px;
        box-sizing: border-box;
        font-size: 14px;
    }
    .modal-form .form-row { display: flex; gap: 10px; }
    .modal-form .form-row > div { flex: 1; }
    .modal-actions { display: flex; gap: 10px; margin-top: 4px; }
    .btn-submit { padding: 9px 22px; border-radius: 8px; border: none; background: #1976d2; color: #fff; font-weight: 600; cursor: pointer; }
    .btn-submit:hover { background: #1565c0; }
    .btn-cancel-modal { padding: 9px 22px; border-radius: 8px; border: none; background: #444; color: #fff; font-weight: 600; cursor: pointer; }
    .user-cell { display: flex; align-items: center; gap: 10px; }
    .user-avatar {
        width: 36px; height: 36px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 15px; font-weight: 700; color: #fff;
        flex-shrink: 0; text-transform: uppercase;
    }
    .user-name-text { font-weight: 500; color: #fff; }
    /* Member stat chips */
    .member-stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
    .ms-chip {
        display:flex; align-items:center; gap:8px;
        background:#1e1e1e; border-radius:8px; padding:9px 18px;
        font-size:13px; font-weight:600; border:1px solid #2a2a2a;
    }
    .ms-dot { width:10px; height:10px; border-radius:50%; display:inline-block; flex-shrink:0; }
    .ms-dot.total      { background:#aaa; }
    .ms-dot.session    { background:#1976d2; }
    .ms-dot.membership { background:#f5c518; }
    .ms-num  { color:#fff; }
    .ms-lbl  { color:#777; font-weight:500; }
    /* Confirm modal */
    .confirm-box { background:#2c2c2c; border-radius:16px; padding:28px 32px; max-width:360px; width:90%; color:#fff; box-shadow:0 8px 32px rgba(0,0,0,0.5); }
    .confirm-box h3 { margin:0 0 8px; font-size:1.1rem; }
    .confirm-box p { color:#aaa; font-size:14px; margin:0 0 20px; }
    .btn-danger { padding:9px 22px; border-radius:8px; border:none; background:#d32f2f; color:#fff; font-weight:600; cursor:pointer; }
    .btn-danger:hover { background:#b71c1c; }
    /* RFID Tap scanner */
    .rfid-tap-btn { display:flex; align-items:center; gap:8px; width:100%; padding:12px 16px; margin-bottom:12px; border-radius:10px; border:2px dashed #444; background:#1a1a1a; color:#bbb; font-size:14px; font-weight:600; cursor:pointer; transition:border-color 0.2s,color 0.2s; box-sizing:border-box; }
    .rfid-tap-btn:hover { border-color:#1976d2; color:#fff; }
    .rfid-tap-btn.scanning { border-color:#f5c518; color:#f5c518; animation:rfid-pulse 1s infinite; }
    .rfid-tap-btn .rfid-icon { font-size:1.4rem; flex-shrink:0; }
    .rfid-captured { display:none; align-items:center; gap:10px; padding:10px 14px; border-radius:8px; background:rgba(67,160,71,0.15); border:1px solid #43a047; margin-bottom:12px; font-size:14px; color:#81c784; }
    .rfid-captured .rfid-val { font-weight:700; flex:1; }
    .rfid-captured .rfid-clear { background:none; border:none; color:#e57373; cursor:pointer; font-size:16px; padding:0 4px; }
    .rfid-hidden-input { position:absolute; opacity:0; width:0; height:0; pointer-events:none; }
    @keyframes rfid-pulse { 0%,100%{opacity:1} 50%{opacity:0.5} }
    </style>
<body>
    <div class="Member-content">
        <h1>Member Management</h1>
        <?php
// Member type counts (always unfiltered)
try {
    $countStmt = $pdo->query("SELECT type, COUNT(*) as cnt FROM members GROUP BY type");
    $countRows = $countStmt->fetchAll();
    $countTotal = 0; $countSession = 0; $countMembership = 0;
    foreach ($countRows as $cr) {
        $countTotal += intval($cr['cnt']);
        if ($cr['type'] === 'session') $countSession = intval($cr['cnt']);
        if ($cr['type'] === 'member')  $countMembership = intval($cr['cnt']);
    }
} catch(Exception $e) { $countTotal = $countSession = $countMembership = 0; }
?>
        <div class="member-stats" id="memberStats">
            <div class="ms-chip"><span class="ms-dot total"></span><span class="ms-num" id="statTotal"><?= $countTotal ?></span><span class="ms-lbl">&nbsp;Total</span></div>
            <div class="ms-chip"><span class="ms-dot session"></span><span class="ms-num" id="statSession"><?= $countSession ?></span><span class="ms-lbl">&nbsp;Session</span></div>
            <div class="ms-chip"><span class="ms-dot membership"></span><span class="ms-num" id="statMembership"><?= $countMembership ?></span><span class="ms-lbl">&nbsp;Membership</span></div>
        </div>
        <?php
$search = $_GET['search'] ?? '';

$typeFilter = $_GET['type'] ?? '';

try {
    $query = "SELECT * FROM members WHERE 1";
    $params = [];
    if ($search !== '') {
        $query .= " AND username LIKE ?";
        $params[] = "%$search%";
    }
    if ($typeFilter !== '') {
        $query .= " AND type = ?";
        $params[] = $typeFilter;
    }
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $members = $stmt->fetchAll();

} catch (Exception $e) {
    echo '<div style="color:red">Error fetching members: ' . htmlspecialchars($e->getMessage()) . '</div>';
    $members = [];
}
?>

        <div style="margin-bottom:15px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
    <input type="text" id="searchUsername" placeholder="Search username..."
        style="padding:8px 12px;border-radius:8px;border:1px solid #ccc;width:250px;">
    <select id="typeFilter" style="padding:8px 12px;border-radius:8px;border:1px solid #ccc;">
        <option value="">All Types</option>
        <option value="session">Session</option>
        <option value="member">Member</option>
    </select>
    <div style="margin-left:auto; display:flex; gap:8px;">
        <button type="button" id="addUserBtn" style="padding:8px 18px; border-radius:8px; border:none; background:#1976d2; color:#fff; font-weight:600; cursor:pointer;">+ Add User</button>
        
    </div>
</div>
        <div class="table-wrapper" style="margin-top: 24px;">
            <table class="admin-table" border="1" cellpadding="8" cellspacing="0" style="width:100%; background:#333;">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>RFID</th>
                        <th>Joined Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($members)): ?>
                    <tr><td colspan="7" style="text-align: center;" >No members found.</td></tr>
                <?php else: ?>
                    <?php foreach ($members as $member): ?>
                        <?php
                            $username = trim($member['first_name'] . ' ' . $member['last_name']);
                            $status = ($member['type'] === 'active')
                                ? '<span class="badge-status badge-active">Active</span>'
                                : '<span class="badge-status badge-inactive">Inactive</span>';
                            $joined = $member['Joined_Date'] ? date('d M Y', strtotime($member['Joined_Date'])) : '-';
                            $auth2f = '<span class="badge-auth">Enabled</span>'; // Placeholder
                            $avatarColors = ['#1976d2','#e53935','#388e3c','#f57c00','#7b1fa2','#00838f','#c62828','#2e7d32','#1565c0','#6a1b9a'];
                            $firstLetter = strtoupper(substr($member['first_name'], 0, 1)) ?: '?';
                            $avatarColor = $avatarColors[ord($firstLetter) % count($avatarColors)];
                        ?>
                        <tr>
                            <td data-label="Username">
                                <div class="user-cell">
                                    <div class="user-avatar" style="background:<?= $avatarColor ?>"><?= htmlspecialchars($firstLetter) ?></div>
                                    <span class="user-name-text"><?= htmlspecialchars($username) ?></span>
                                </div>
                            </td>
                            <td data-label="Email"><?= htmlspecialchars($member['gmail']) ?></td>
                            <td data-label="Type"><?= htmlspecialchars($member['type']) ?></td>
                            <td data-label="Status"><?= $status ?></td>
                            <td data-label="RFID"><?= htmlspecialchars($member['RFID'] ?? '-') ?></td>
                            <td data-label="Joined Date"><?= htmlspecialchars($joined) ?></td>
                            <td data-label="Actions">
                                <button type="button" class="action-btn btn-edit-member"
                                    data-id="<?= $member['id'] ?>"
                                    data-first="<?= htmlspecialchars($member['first_name'], ENT_QUOTES) ?>"
                                    data-last="<?= htmlspecialchars($member['last_name'], ENT_QUOTES) ?>"
                                    data-gmail="<?= htmlspecialchars($member['gmail'] ?? '', ENT_QUOTES) ?>"
                                    data-phone="<?= htmlspecialchars($member['phone'] ?? '', ENT_QUOTES) ?>"
                                    data-address="<?= htmlspecialchars($member['address'] ?? '', ENT_QUOTES) ?>"
                                    data-rfid="<?= htmlspecialchars($member['RFID'] ?? '', ENT_QUOTES) ?>"
                                    data-type="<?= htmlspecialchars($member['type'], ENT_QUOTES) ?>">Edit</button>
                                <button type="button" class="action-btn delete btn-delete-member"
                                    data-id="<?= $member['id'] ?>"
                                    data-name="<?= htmlspecialchars(trim($member['first_name'].' '.$member['last_name']), ENT_QUOTES) ?>">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                </table>
        </div>
        
    </div>

<!-- Add User Modal -->
<div class="modal-overlay" id="addUserModal">
    <div class="modal-box">
        <h2>Add User</h2>
        <p class="modal-subtitle">Select registration type</p>

        <!-- Type selection cards -->
        <div id="typeSelection">
            <div class="type-cards">
                <div class="type-card session" id="btnSessionCard">
                    <div class="card-icon">&#127939;</div>
                    Session
                </div>
                <div class="type-card membership" id="btnMembershipCard">
                    <div class="card-icon">&#127942;</div>
                    Membership
                </div>
            </div>
        </div>

        <!-- Session Registration Form -->
        <div class="modal-form" id="sessionForm">
            <button type="button" class="btn-back" id="btnBack">&#8592; Back</button>
            <form id="sessionFormEl">
                <input type="hidden" name="add_user" value="1">
                <div class="form-row">
                    <div>
                        <label>First Name <span style="color:#e57373">*</span></label>
                        <input type="text" name="first_name" required placeholder="First name">
                    </div>
                    <div>
                        <label>Last Name <span style="color:#e57373">*</span></label>
                        <input type="text" name="last_name" required placeholder="Last name">
                    </div>
                </div>
                <label>Gmail <span style="color:#666">(optional)</span></label>
                <input type="email" name="gmail" placeholder="example@gmail.com">
                <label>Phone <span style="color:#666">(optional)</span></label>
                <input type="text" name="phone" placeholder="Phone number">
                <label>Address <span style="color:#666">(optional)</span></label>
                <input type="text" name="address" placeholder="Address">
                <label class="avail-card-toggle">
                    <input type="checkbox" id="availCardCheck" name="avail_card" value="1">
                    <span>Avail RFID Card?</span>
                </label>
                <div id="rfidFieldWrap" style="display:none;">
                    <input type="hidden" name="rfid_number" id="rfidNumber">
                    <input class="rfid-hidden-input" id="rfidScanInput" autocomplete="off" tabindex="-1">
                    <button type="button" class="rfid-tap-btn" id="btnTapRfidSession">
                        <span class="rfid-icon">&#128276;</span>
                        <span id="rfidSessionBtnTxt">Tap RFID Card to Scan</span>
                    </button>
                    <div class="rfid-captured" id="rfidSessionCaptured">
                        <span>&#10003;</span>
                        <span class="rfid-val" id="rfidSessionVal"></span>
                        <button type="button" class="rfid-clear" id="rfidSessionClear" title="Remove">&#10005;</button>
                    </div>
                </div>
                <p style="font-size:12px; color:#888; margin: -4px 0 12px;">Default password: <strong style="color:#aaa">12345fitness</strong></p>
                <div class="modal-actions">
                    <button type="submit" class="btn-submit">Register</button>
                    <button type="button" class="btn-cancel-modal" id="btnCloseModal">Cancel</button>
                </div>
            </form>
        </div>

        <!-- Membership Registration Form -->
        <div class="modal-form" id="membershipForm">
            <button type="button" class="btn-back" id="btnBackMembership">&#8592; Back</button>
            <form method="post" id="membershipFormEl">
                <input type="hidden" name="add_membership_user" value="1">
                <input type="hidden" name="plan_months" id="selectedPlanMonths" value="">
                <div class="form-row">
                    <div>
                        <label>First Name <span style="color:#e57373">*</span></label>
                        <input type="text" name="first_name" required placeholder="First name">
                    </div>
                    <div>
                        <label>Last Name <span style="color:#e57373">*</span></label>
                        <input type="text" name="last_name" required placeholder="Last name">
                    </div>
                </div>
                <label>Gmail <span style="color:#666">(optional)</span></label>
                <input type="email" name="gmail" placeholder="example@gmail.com">
                <label>Phone <span style="color:#666">(optional)</span></label>
                <input type="text" name="phone" placeholder="Phone number">
                <label>Address <span style="color:#666">(optional)</span></label>
                <input type="text" name="address" placeholder="Address">
                <label>RFID Card <span style="color:#e57373">*</span></label>
                <input type="hidden" name="rfid_number" id="membershipRfidNumber">
                <input class="rfid-hidden-input" id="membershipRfidScanInput" autocomplete="off" tabindex="-1">
                <button type="button" class="rfid-tap-btn" id="btnTapRfidMembership">
                    <span class="rfid-icon">&#128276;</span>
                    <span id="rfidMembershipBtnTxt">Tap RFID Card to Scan</span>
                </button>
                <div class="rfid-captured" id="rfidMembershipCaptured">
                    <span>&#10003;</span>
                    <span class="rfid-val" id="rfidMembershipVal"></span>
                    <button type="button" class="rfid-clear" id="rfidMembershipClear" title="Remove">&#10005;</button>
                </div>
                <label>Monthly Plan <span style="color:#e57373">*</span></label>
                <div class="plan-cards">
                    <div class="plan-card" data-months="1" data-price="850">
                        <div class="plan-duration">1 Month</div>
                        <div class="plan-price">&#8369;850</div>
                    </div>
                    <div class="plan-card" data-months="3" data-price="1800">
                        <div class="plan-duration">3 Months</div>
                        <div class="plan-price">&#8369;1,800</div>
                    </div>
                    <div class="plan-card" data-months="5" data-price="2500">
                        <div class="plan-duration">5 Months</div>
                        <div class="plan-price">&#8369;2,500</div>
                    </div>
                </div>
                <p style="font-size:12px; color:#888; margin: -4px 0 12px;">Default password: <strong style="color:#aaa">12345fitness</strong></p>
                <div class="modal-actions">
                    <button type="submit" class="btn-submit" id="btnMembershipSubmit">Register</button>
                    <button type="button" class="btn-cancel-modal" id="btnCloseMembershipModal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Confirm Modal -->
<div class="modal-overlay" id="confirmModal">
    <div class="confirm-box">
        <h3 id="confirmTitle">Confirm</h3>
        <p id="confirmMessage">Are you sure?</p>
        <div class="modal-actions">
            <button type="button" class="btn-danger" id="btnConfirmYes">Yes, Proceed</button>
            <button type="button" class="btn-cancel-modal" id="btnConfirmNo">Cancel</button>
        </div>
    </div>
</div>

<!-- Edit Member Modal -->
<div class="modal-overlay" id="editMemberModal">
    <div class="modal-box">
        <h2>Edit Member</h2>
        <input type="hidden" id="editMemberId">
        <input type="hidden" id="editOldRfid">
        <div class="modal-form active">
            <div class="form-row">
                <div>
                    <label>First Name <span style="color:#e57373">*</span></label>
                    <input type="text" id="editMemberFirst" placeholder="First name">
                </div>
                <div>
                    <label>Last Name <span style="color:#e57373">*</span></label>
                    <input type="text" id="editMemberLast" placeholder="Last name">
                </div>
            </div>
            <label>Gmail</label>
            <input type="email" id="editMemberGmail" placeholder="example@gmail.com">
            <label>Phone</label>
            <input type="text" id="editMemberPhone" placeholder="Phone number">
            <label>Address</label>
            <input type="text" id="editMemberAddress" placeholder="Address">
            <label>RFID Card</label>
            <!-- Current RFID row (shown when member already has RFID) -->
            <div id="editCurrentRfidRow" style="display:none; align-items:center; gap:10px; margin-bottom:12px;">
                <div class="rfid-captured" style="display:flex; flex:1; margin-bottom:0;">
                    <span>&#10003;</span>
                    <span class="rfid-val" id="editCurrentRfidVal" style="margin-left:6px;"></span>
                </div>
                <button type="button" id="btnChangeRfid" style="padding:6px 14px; border-radius:8px; border:1px solid #f57c00; background:none; color:#f57c00; cursor:pointer; font-size:13px; font-weight:600; white-space:nowrap;">Change Card</button>
            </div>
            <!-- New RFID tap scanner -->
            <div id="editRfidTapWrap" style="display:none;">
                <input type="hidden" id="editNewRfid">
                <input class="rfid-hidden-input" id="editRfidScanInput" autocomplete="off" tabindex="-1">
                <button type="button" class="rfid-tap-btn" id="btnTapEditRfid">
                    <span class="rfid-icon">&#128276;</span>
                    <span id="editRfidBtnTxt">Tap RFID Card</span>
                </button>
                <div class="rfid-captured" id="editNewRfidCaptured">
                    <span>&#10003;</span>
                    <span class="rfid-val" id="editNewRfidVal" style="margin-left:6px;"></span>
                    <button type="button" class="rfid-clear" id="editNewRfidClear" title="Remove">&#10005;</button>
                </div>
                <!-- Block lost card (only when replacing existing RFID) -->
                <div id="blockLostCardRow" style="display:none;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; color:#bbb; margin-bottom:12px;">
                        <input type="checkbox" id="blockOldRfidCheck" checked style="width:auto!important; margin:0;">
                        <span>Block lost card <span id="blockOldRfidLabel" style="color:#f5c518; font-weight:600;"></span></span>
                    </label>
                </div>
            </div>
        </div>
        <div class="modal-actions" style="margin-top:16px;">
            <button type="button" class="btn-submit" id="btnSaveMember">Save Changes</button>
            <button type="button" class="btn-cancel-modal" id="btnCloseEditMember">Cancel</button>
        </div>
    </div>
</div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
$(document).ready(function(){

    // ── Toast ─────────────────────────────────────────────────────────
    var toastTimer;
    function showToast(message, type) {
        type = type || 'error';
        var toast = document.getElementById('toastNotif');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'toastNotif';
            toast.className = 'toast';
            document.body.appendChild(toast);
        }
        toast.className = 'toast' + (type === 'success' ? ' success' : '');
        toast.textContent = message;
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function() { toast.classList.remove('show'); }, 3500);
    }

    // ── Confirm Modal ─────────────────────────────────────────────────
    var confirmCallback = null;
    function showConfirm(title, message, callback) {
        $('#confirmTitle').text(title);
        $('#confirmMessage').text(message);
        confirmCallback = callback;
        $('#confirmModal').addClass('active');
    }
    $('#btnConfirmYes').on('click', function() {
        $('#confirmModal').removeClass('active');
        if (confirmCallback) { confirmCallback(); confirmCallback = null; }
    });
    $('#btnConfirmNo').on('click', function() {
        $('#confirmModal').removeClass('active');
        confirmCallback = null;
    });
    $('#confirmModal').on('click', function(e) {
        if (e.target === this) { $(this).removeClass('active'); confirmCallback = null; }
    });

    // ── Fetch / reload table ──────────────────────────────────────────
    function fetchMembers() {
        $.ajax({
            url: 'member.php',
            method: 'GET',
            data: { search: $('#searchUsername').val(), type: $('#typeFilter').val() },
            success: function(data) {
                $('tbody').html($(data).find('tbody').html());
                // Refresh stat chips from unfiltered counts
                var chips = $(data).find('#memberStats');
                if (chips.length) {
                    $('#statTotal').text(chips.find('#statTotal').text());
                    $('#statSession').text(chips.find('#statSession').text());
                    $('#statMembership').text(chips.find('#statMembership').text());
                }
            }
        });
    }
    $('#searchUsername').on('keyup', fetchMembers);
    $('#typeFilter').on('change', fetchMembers);

    // ── Delete member ─────────────────────────────────────────────────
    $(document).on('click', '.btn-delete-member', function() {
        var id   = $(this).data('id');
        var name = $(this).data('name');
        showConfirm('Delete Member', 'Delete "' + name + '"? This cannot be undone.', function() {
            $.ajax({
                url: 'member.php', method: 'POST',
                data: { ajax_delete_member: 1, id: id },
                dataType: 'json',
                success: function(res) {
                    if (res.success) { showToast('Member deleted.', 'success'); fetchMembers(); }
                    else showToast(res.message || 'Delete failed.');
                },
                error: function() { showToast('Server error.'); }
            });
        });
    });

    // ── Edit member ───────────────────────────────────────────────────
    $(document).on('click', '.btn-edit-member', function() {
        var d = $(this).data();
        $('#editMemberId').val(d.id);
        $('#editOldRfid').val(d.rfid || '');
        $('#editMemberFirst').val(d.first);
        $('#editMemberLast').val(d.last);
        $('#editMemberGmail').val(d.gmail);
        $('#editMemberPhone').val(d.phone);
        $('#editMemberAddress').val(d.address);
        // Reset RFID state
        resetRfidScanner('edit');
        if (d.rfid) {
            $('#editCurrentRfidVal').text(d.rfid);
            $('#editCurrentRfidRow').css('display', 'flex');
            $('#editRfidTapWrap').hide();
        } else {
            $('#editCurrentRfidRow').hide();
            $('#editRfidTapWrap').show();
        }
        $('#editMemberModal').addClass('active');
    });

    // "Change Card" in edit modal
    $('#btnChangeRfid').on('click', function() {
        $('#editCurrentRfidRow').hide();
        $('#editRfidTapWrap').show();
        var oldRfid = $('#editOldRfid').val();
        if (oldRfid) {
            $('#blockOldRfidLabel').text('(' + oldRfid + ')');
            $('#blockLostCardRow').show();
        }
    });

    $('#btnSaveMember').on('click', function() {
        var firstName = $('#editMemberFirst').val().trim();
        var lastName  = $('#editMemberLast').val().trim();
        if (!firstName || !lastName) { showToast('First and last name are required.'); return; }
        var newRfid  = $('#editNewRfid').val();
        var oldRfid  = $('#editOldRfid').val();
        var blockOld = (newRfid && oldRfid && $('#blockOldRfidCheck').is(':checked')) ? '1' : '0';
        $.ajax({
            url: 'member.php', method: 'POST', dataType: 'json',
            data: {
                ajax_edit_member: 1,
                id:         $('#editMemberId').val(),
                first_name: firstName,
                last_name:  lastName,
                gmail:      $('#editMemberGmail').val().trim(),
                phone:      $('#editMemberPhone').val().trim(),
                address:    $('#editMemberAddress').val().trim(),
                new_rfid:   newRfid,
                old_rfid:   oldRfid,
                block_old:  blockOld
            },
            success: function(res) {
                if (res.success) {
                    $('#editMemberModal').removeClass('active');
                    showToast('Member updated successfully.', 'success');
                    fetchMembers();
                } else showToast(res.message || 'Update failed.');
            },
            error: function() { showToast('Server error.'); }
        });
    });

    $('#btnCloseEditMember').on('click', function() {
        $('#editMemberModal').removeClass('active');
        resetRfidScanner('edit');
    });
    $('#editMemberModal').on('click', function(e) {
        if (e.target === this) { $(this).removeClass('active'); resetRfidScanner('edit'); }
    });

    // ── Add User modal ────────────────────────────────────────────────
    $('#addUserBtn').on('click', function() {
        $('#addUserModal').addClass('active');
        $('#typeSelection').show();
        $('#sessionForm').removeClass('active');
        $('#membershipForm').removeClass('active');
    });
    $('#btnSessionCard').on('click', function() { $('#typeSelection').hide(); $('#sessionForm').addClass('active'); });
    $('#btnBack').on('click', function() { $('#sessionForm').removeClass('active'); $('#typeSelection').show(); });
    function closeAddModal() {
        $('#addUserModal').removeClass('active');
        resetRfidScanner('session');
        resetRfidScanner('membership');
        $('#rfidFieldWrap').hide();
        $('#availCardCheck').prop('checked', false);
        $('.plan-card').removeClass('selected');
        $('#selectedPlanMonths').val('');
    }
    $('#btnCloseModal').on('click', closeAddModal);
    $('#addUserModal').on('click', function(e) { if (e.target === this) closeAddModal(); });

    // Session form — AJAX submit
    $('#sessionFormEl').on('submit', function(e) {
        e.preventDefault();
        $.ajax({
            url: 'member.php', method: 'POST', dataType: 'json',
            data: {
                ajax_add_user: 1,
                first_name:  $(this).find('[name=first_name]').val(),
                last_name:   $(this).find('[name=last_name]').val(),
                gmail:       $(this).find('[name=gmail]').val(),
                phone:       $(this).find('[name=phone]').val(),
                address:     $(this).find('[name=address]').val(),
                rfid_number: $('#rfidNumber').val()
            },
            success: function(res) {
                if (res.success) {
                    closeAddModal();
                    $('#sessionFormEl')[0].reset();
                    showToast('Session user registered successfully!', 'success');
                    fetchMembers();
                } else showToast(res.message || 'Failed to add user.');
            },
            error: function() { showToast('Server error.'); }
        });
    });

    // ── Membership modal ──────────────────────────────────────────────
    $('#btnMembershipCard').on('click', function() { $('#typeSelection').hide(); $('#membershipForm').addClass('active'); });
    $('#btnBackMembership').on('click', function() {
        $('#membershipForm').removeClass('active');
        $('#typeSelection').show();
        resetRfidScanner('membership');
        $('.plan-card').removeClass('selected');
        $('#selectedPlanMonths').val('');
    });
    $('#btnCloseMembershipModal').on('click', closeAddModal);

    $(document).on('click', '.plan-card', function() {
        $('.plan-card').removeClass('selected');
        $(this).addClass('selected');
        $('#selectedPlanMonths').val($(this).data('months'));
    });

    $('#membershipFormEl').on('submit', function(e) {
        e.preventDefault();
        if (!$('#selectedPlanMonths').val()) { showToast('Please select a monthly plan.'); return; }
        if (!$('#membershipRfidNumber').val()) { showToast('Please scan the RFID card.'); return; }
        $.ajax({
            url: 'member.php', method: 'POST', dataType: 'json',
            data: {
                ajax_add_membership: 1,
                first_name:  $(this).find('[name=first_name]').val(),
                last_name:   $(this).find('[name=last_name]').val(),
                gmail:       $(this).find('[name=gmail]').val(),
                phone:       $(this).find('[name=phone]').val(),
                address:     $(this).find('[name=address]').val(),
                rfid_number: $('#membershipRfidNumber').val(),
                plan_months: $('#selectedPlanMonths').val()
            },
            success: function(res) {
                if (res.success) {
                    closeAddModal();
                    $('#membershipFormEl')[0].reset();
                    showToast('Member registered successfully!', 'success');
                    fetchMembers();
                } else showToast(res.message || 'Failed to register member.');
            },
            error: function() { showToast('Server error.'); }
        });
    });

    // ── RFID toggle (session) ─────────────────────────────────────────
    $('#availCardCheck').on('change', function() {
        if ($(this).is(':checked')) { $('#rfidFieldWrap').show(); }
        else { $('#rfidFieldWrap').hide(); $('#rfidNumber').val(''); resetRfidScanner('session'); }
    });

    // ── RFID Scanner engine ───────────────────────────────────────────
    function setupRfidScanner(triggerBtn, hiddenInput, scanInput, capturedDiv, valSpan, clearBtn, btnTxtSpan) {
        var scanning = false, rfidBuffer = '', rfidTimer = null;
        $(triggerBtn).on('click', function() {
            if (scanning) return;
            scanning = true; rfidBuffer = '';
            $(triggerBtn).addClass('scanning');
            $(btnTxtSpan).text('Scanning\u2026 Tap card now');
            $(scanInput).css({position:'fixed', top:'-9999px', left:'-9999px', opacity:0, width:'1px', height:'1px'}).focus();
        });
        $(scanInput).on('keydown', function(e) {
            if (!scanning) return;
            if (e.key === 'Enter') { e.preventDefault(); rfidBuffer.length > 0 ? captureRfid(rfidBuffer) : cancelScan(); return; }
            if (rfidTimer) clearTimeout(rfidTimer);
            rfidTimer = setTimeout(function() { if (rfidBuffer.length > 0) captureRfid(rfidBuffer); }, 500);
        });
        $(scanInput).on('input', function() { if (!scanning) return; rfidBuffer += $(this).val(); $(this).val(''); });
        function captureRfid(val) {
            scanning = false; rfidBuffer = ''; clearTimeout(rfidTimer);
            $(scanInput).val('').blur();
            $(triggerBtn).removeClass('scanning').hide();
            $(hiddenInput).val(val); $(valSpan).text(val);
            $(capturedDiv).css('display','flex');
            $(btnTxtSpan).text('Tap RFID Card to Scan');
        }
        function cancelScan() {
            scanning = false; rfidBuffer = ''; clearTimeout(rfidTimer);
            $(scanInput).val('').blur(); $(triggerBtn).removeClass('scanning');
            $(btnTxtSpan).text('Tap RFID Card to Scan');
        }
        $(clearBtn).on('click', function() {
            $(hiddenInput).val(''); $(capturedDiv).hide(); $(triggerBtn).show();
            rfidBuffer = ''; scanning = false;
        });
    }

    function resetRfidScanner(type) {
        if (type === 'session') {
            $('#rfidNumber').val(''); $('#rfidSessionCaptured').hide();
            $('#btnTapRfidSession').show().removeClass('scanning');
            $('#rfidSessionBtnTxt').text('Tap RFID Card to Scan');
        } else if (type === 'membership') {
            $('#membershipRfidNumber').val(''); $('#rfidMembershipCaptured').hide();
            $('#btnTapRfidMembership').show().removeClass('scanning');
            $('#rfidMembershipBtnTxt').text('Tap RFID Card to Scan');
        } else if (type === 'edit') {
            $('#editNewRfid').val(''); $('#editNewRfidCaptured').hide();
            $('#btnTapEditRfid').show().removeClass('scanning');
            $('#editRfidBtnTxt').text('Tap RFID Card');
            $('#editRfidTapWrap').hide(); $('#editCurrentRfidRow').hide();
            $('#blockLostCardRow').hide(); $('#blockOldRfidCheck').prop('checked', true);
        }
    }

    setupRfidScanner('#btnTapRfidSession', '#rfidNumber', '#rfidScanInput',
        '#rfidSessionCaptured', '#rfidSessionVal', '#rfidSessionClear', '#rfidSessionBtnTxt');
    setupRfidScanner('#btnTapRfidMembership', '#membershipRfidNumber', '#membershipRfidScanInput',
        '#rfidMembershipCaptured', '#rfidMembershipVal', '#rfidMembershipClear', '#rfidMembershipBtnTxt');
    setupRfidScanner('#btnTapEditRfid', '#editNewRfid', '#editRfidScanInput',
        '#editNewRfidCaptured', '#editNewRfidVal', '#editNewRfidClear', '#editRfidBtnTxt');

});
</script>
</body>
</html>

