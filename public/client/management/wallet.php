<?php
require_once '../../../app/config/connection.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Ensure wallet transaction log exists
$pdo->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    transaction_type VARCHAR(32) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    balance_before DECIMAL(10,2) NOT NULL,
    balance_after DECIMAL(10,2) NOT NULL,
    reason TEXT,
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(member_id)
)");

// AJAX: lookup member by RFID
if (isset($_GET['ajax_rfid'])) {
    header('Content-Type: application/json');
    $rfid = trim($_GET['rfid'] ?? '');
    if ($rfid === '') {
        echo json_encode(['found' => false, 'error' => 'Empty RFID']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, type, COALESCE(credit, 0) as credit, RFID FROM members WHERE RFID = ?");
        $stmt->execute([$rfid]);
        $member = $stmt->fetch();
        if ($member) {
            echo json_encode(['found' => true, 'member' => $member]);
        } else {
            echo json_encode(['found' => false, 'error' => 'No member found with RFID: ' . $rfid]);
        }
    } catch (Exception $e) {
        echo json_encode(['found' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// AJAX: add credit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_add_credit'])) {
    header('Content-Type: application/json');
    $id     = intval($_POST['member_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $user   = $_SESSION['user_name'] ?? 'system';
    if ($id > 0 && $amount > 0) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT COALESCE(credit,0) AS credit FROM members WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new Exception('Member not found.');
            }
            $before = floatval($row['credit']);
            $after = $before + $amount;
            $pdo->prepare("UPDATE members SET credit = credit + ? WHERE id = ?")->execute([$amount, $id]);
            $pdo->prepare("INSERT INTO wallet_transactions (member_id, transaction_type, amount, balance_before, balance_after, reason, created_by) VALUES (?, 'credit_add', ?, ?, ?, 'Credit input', ?)")
                ->execute([$id, $amount, $before, $after, $user]);
            $pdo->commit();
            echo json_encode(['success' => true, 'credit' => $after]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid member or amount.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_adjust_credit'])) {
    header('Content-Type: application/json');
    $id        = intval($_POST['member_id'] ?? 0);
    $amount    = abs(floatval($_POST['amount'] ?? 0));
    $action    = trim($_POST['action'] ?? 'refund');
    $direction = trim($_POST['direction'] ?? 'decrease');
    $reason    = trim($_POST['reason'] ?? '');
    $user      = $_SESSION['user_name'] ?? 'system';

    if ($id > 0 && $amount > 0 && $reason !== '') {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT COALESCE(credit,0) AS credit FROM members WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new Exception('Member not found.');
            }
            $before = floatval($row['credit']);
            $change = 0;
            $type = 'refund';
            if ($action === 'refund') {
                $change = -$amount;
                $type = 'refund';
            } else {
                $change = $direction === 'increase' ? $amount : -$amount;
                $type = 'correction';
            }
            $after = $before + $change;
            if ($after < 0) {
                throw new Exception('Resulting credit cannot be negative.');
            }
            $pdo->prepare("UPDATE members SET credit = credit + ? WHERE id = ?")->execute([$change, $id]);
            $pdo->prepare("INSERT INTO wallet_transactions (member_id, transaction_type, amount, balance_before, balance_after, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$id, $type, $change, $before, $after, $reason, $user]);
            $pdo->commit();
            echo json_encode(['success' => true, 'credit' => $after]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Please provide a valid amount and reason.']);
    }
    exit;
}

if (isset($_GET['ajax_wallet_history'])) {
    header('Content-Type: application/json');
    $memberId = intval($_GET['member_id'] ?? 0);
    if ($memberId > 0) {
        $stmt = $pdo->prepare("SELECT transaction_type, amount, balance_before, balance_after, reason, created_by, created_at FROM wallet_transactions WHERE member_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->execute([$memberId]);
        $history = $stmt->fetchAll();
        echo json_encode(['success' => true, 'history' => $history]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Member ID missing.']);
    }
    exit;
}

$page = 'wallet';
include '../../../component/admin_header.php';
include '../../../component/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wallet Management</title>
    <link href="../../../assets/css/toastednotif.css" rel="stylesheet">
    <link href="../../../assets/css/admin_header.css" rel="stylesheet">
    <link href="../../../assets/css/admin_sidebar.css" rel="stylesheet">
    <link href="../../../assets/css/admin.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<style>
    .wallet-content {
        margin-left: 250px;
        margin-top: 60px;
        padding: 2rem;
        min-height: calc(100vh - 60px);
        background: #222;
        color: #fff;
    }
    @media (max-width: 900px) {
        .wallet-content { margin-left: 0; padding: 1rem; }
    }
    .rfid-bar {
        display: flex;
        gap: 10px;
        align-items: center;
        max-width: 480px;
    }
    .rfid-bar input {
        flex: 1;
        padding: 10px 14px;
        border-radius: 8px;
        border: 1px solid #444;
        background: #1a1a1a;
        color: #fff;
        font-size: 15px;
    }
    .rfid-bar button {
        padding: 10px 20px;
        border-radius: 8px;
        border: none;
        background: #1976d2;
        color: #fff;
        font-weight: 600;
        cursor: pointer;
    }
    .rfid-bar button:hover { background: #1565c0; }
    .wallet-panel {
        display: none;
        gap: 32px;
        align-items: flex-start;
        flex-wrap: wrap;
    }
    .wallet-panel.active { display: flex; }
    /* Left: Wallet card */
    .wallet-card {
        background: #1a1a1a;
        border-radius: 20px;
        padding: 28px;
        min-width: 260px;
        flex: 1;
        max-width: 320px;
        text-align: center;
        box-shadow: 0 4px 24px rgba(0,0,0,0.4);
    }
    .wallet-card h3 { margin: 0 0 18px; font-size: 1rem; color: #bbb; }
    .donut-wrap { position: relative; width: 170px; height: 170px; margin: 0 auto 12px; }
    .donut-wrap canvas { position: absolute; top: 0; left: 0; }
    .donut-label {
        position: absolute;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        font-size: 13px;
        font-weight: 700;
        color: #fff;
        text-align: center;
        pointer-events: none;
    }
    .donut-label .amount { font-size: 16px; color: #f5c518; }
    .credit-label { color: #bbb; font-size: 14px; margin-top: 8px; }
    /* Member info box */
    .member-info-box {
        background: linear-gradient(135deg, #b8860b 0%, #f5c518 60%, #b8860b 100%);
        border-radius: 12px;
        padding: 14px 18px;
        margin-top: 18px;
        text-align: left;
        color: #222;
        font-size: 13px;
    }
    .member-info-box .info-title { font-weight: 800; margin-bottom: 8px; font-size: 14px; }
    .member-info-box .info-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
    .member-info-box .info-val { font-weight: 700; }
    /* Right: Gym card + button */
    .gym-card-section {
        flex: 1;
        min-width: 260px;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 24px;
    }
    .gym-card {
        background: #111;
        border-radius: 18px;
        padding: 28px 32px;
        width: 100%;
        max-width: 500px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        gap: 18px;
        position: relative;
        overflow: hidden;
    }
    .gym-card::after {
        content: '';
        position: absolute;
        right: -30px; top: -30px;
        width: 120px; height: 120px;
        border-radius: 50%;
        background: rgba(255,255,255,0.04);
    }
    .gym-card img { width: 264px; height: 264px; object-fit: contain; }
    .gym-card-text { color: #fff; }
    .gym-card-text .gym-name { font-size: 1.25rem; font-weight: 900; line-height: 1.1; letter-spacing: 1px; }
    .gym-card-text .gym-sub { font-size: 0.8rem; color: #f5c518; letter-spacing: 2px; text-transform: uppercase; margin-top: 4px; }
    .gym-card .wifi-icon { position: absolute; bottom: 16px; right: 20px; font-size: 1.4rem; color: #888; }
    .btn-input-credit {
        padding: 12px 32px;
        border-radius: 10px;
        border: 1px solid #555;
        background: #2c2c2c;
        color: #fff;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }
    .btn-input-credit:hover { background: #f5c518; border-color: #f5c518; }
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
        max-width: 360px;
        width: 90%;
        color: #fff;
        box-shadow: 0 8px 32px rgba(0,0,0,0.5);
    }
    .modal-box h2 { margin: 0 0 20px; font-size: 1.2rem; }
    .modal-box label { display: block; font-size: 13px; color: #bbb; margin-bottom: 6px; }
    .modal-box input[type="number"],
    .modal-box select,
    .modal-box textarea {
        width: 100%; padding: 9px 12px; border-radius: 8px;
        border: 1px solid #444; background: #1a1a1a;
        color: #fff; font-size: 15px; box-sizing: border-box; margin-bottom: 18px;
    }
    .modal-box textarea { resize: vertical; min-height: 90px; }
    .modal-actions { display: flex; gap: 10px; }
    .wallet-history-section {
        width: 100%; margin-top: 32px; background: #111;
        border-radius: 20px; padding: 24px;
    }
    .wallet-history-section h3 { margin: 0 0 8px; font-size: 1rem; color: #f5c518; }
    .wallet-history-section p { margin: 0 0 18px; color: #aaa; font-size: 13px; }
    .history-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .history-table th, .history-table td {
        padding: 10px 12px; border-bottom: 1px solid #222;
        color: #ddd; text-align: left;
    }
    .history-table th { background: #181818; color: #f5c518; }
    .history-table tbody tr:hover { background: rgba(255,255,255,0.04); }
    .history-empty { color: #888; text-align: center; padding: 24px 0; }
    .badge-card-required { background: #c62828; color: #fff; padding: 2px 10px; border-radius: 10px; font-size: 12px; font-weight: 700; }
    .badge-card-optional { background: #555; color: #ccc; padding: 2px 10px; border-radius: 10px; font-size: 12px; font-weight: 700; }
    .badge-card-has { background: #2e7d32; color: #fff; padding: 2px 10px; border-radius: 10px; font-size: 12px; font-weight: 700; }
    .badge-card-none { background: #e65100; color: #fff; padding: 2px 10px; border-radius: 10px; font-size: 12px; font-weight: 700; }
    .btn-cancel-modal { padding: 9px 22px; border-radius: 8px; border: none; background: #444; color: #fff; font-weight: 600; cursor: pointer; }
    .rfid-error { color: #e57373; font-size: 14px; margin-top: 10px; display: none; text-align: center; }
    .card-placeholder {
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        margin-top: 0;
        gap: 5px;
    }
    .card-placeholder img {
        max-width: 380px;
        width: 100%;
        border-radius: 20px;
        opacity: 0.85;
    }
    .card-desc {
        text-align: center;
        max-width: 420px;
    }
    .card-desc h3 {
        font-size: 1.1rem;
        color: #f5c518;
        margin: 0 0 6px;
    }
    .card-desc p {
        font-size: 13px;
        color: #aaa;
        margin: 0;
        line-height: 1.6;
    }
    .rfid-bar {
        display: flex;
        gap: 10px;
        align-items: center;
        max-width: 480px;
        width: 100%;
    }
</style>
<body>
    <div class="wallet-content">
        <h1>Wallet Management</h1>

        <!-- Card placeholder shown on first load -->
        <div class="card-placeholder" id="cardPlaceholder">
            <img src="../../../assets/image/card.png" alt="Gym Card">
            <div class="card-desc">
                <h3>Member Digital Wallet</h3>
                <p>Scan or enter a member's RFID card to view their wallet information and manage their credits. Credits can be used for gym sessions and services.</p>
            </div>
            <!-- RFID Input -->
            <div class="rfid-bar">
                <input type="text" id="rfidInput" placeholder="Scan or enter RFID..." autocomplete="off">
                <button type="button" id="rfidSearchBtn">Search</button>
            </div>
            <div class="rfid-error" id="rfidError">No member found with that RFID.</div>
        </div>

        <!-- Wallet Panel (hidden until member found) -->
        <div class="wallet-panel" id="walletPanel">
            <!-- Left: Digital Wallet -->
            <div class="wallet-card">
                <h3>Digital Wallet</h3>
                <div class="donut-wrap">
                    <canvas id="creditChart" width="170" height="170"></canvas>
                    <div class="donut-label">
                        <div class="amount" id="donutAmount">₱0</div>
                        <div>/ ₱5,000</div>
                    </div>
                </div>
                <div class="credit-label">Credit</div>
                <div class="member-info-box">
                    <div class="info-title">Member Information</div>
                    <div class="info-row"><span>Name :</span> <span class="info-val" id="infoName">-</span></div>
                    <div class="info-row"><span>Member type :</span> <span class="info-val" id="infoType">-</span></div>
                    <div class="info-row"><span>Credit :</span> <span class="info-val" id="infoCredit">₱0</span></div>
                    <div class="info-row"><span>Card :</span> <span class="info-val" id="infoCard">-</span></div>
                </div>
            </div>
            <!-- Right: Gym Card + Button -->
            <div class="gym-card-section">
                <div class="gym-card">
                    <img src="../../../assets/image/card.png" alt="Lingunan Logo">
                    <div class="gym-card-text">
                        <div class="gym-name">LINGUNAN<br>FITNESS GYM</div>
                        <div class="gym-sub">Member Card</div>
                    </div>
                    <span class="wifi-icon">&#8767;</span>
                </div>
                <button class="btn-input-credit" id="inputCreditBtn">Input Credit</button>
                <button class="btn-input-credit" id="adjustCreditBtn" style="background:#b71c1c;border-color:#b71c1c;">Refund / Correct</button>
            </div>
        </div>
        <div class="wallet-history-section" id="walletHistorySection" style="display:none;">
            <h3>Wallet Transaction History</h3>
            <p>Recent wallet operations, refunds, and corrections are recorded with a required reason.</p>
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Balance After</th>
                        <th>Reason</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody id="historyBody">
                    <tr><td colspan="6" class="history-empty">No history loaded yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Input Credit Modal -->
    <div class="modal-overlay" id="creditModal">
        <div class="modal-box">
            <h2>Input Credit</h2>
            <label>Amount (₱)</label>
            <input type="number" id="creditAmount" min="1" placeholder="Enter amount">
            <div class="modal-actions">
                <button class="btn-confirm" id="confirmCredit">Confirm</button>
                <button class="btn-cancel-modal" id="cancelCredit">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Adjust Credit Modal -->
    <div class="modal-overlay" id="adjustModal">
        <div class="modal-box">
            <h2>Refund / Correct Credit</h2>
            <label>Action</label>
            <select id="adjustAction">
                <option value="refund">Refund credit</option>
                <option value="correction">Correct credit</option>
            </select>
            <div id="adjustDirectionRow">
                <label>Correction type</label>
                <select id="adjustDirection">
                    <option value="decrease">Decrease credit</option>
                    <option value="increase">Increase credit</option>
                </select>
            </div>
            <label>Amount (₱)</label>
            <input type="number" id="adjustAmount" min="0.01" step="0.01" placeholder="Enter amount">
            <label>Reason</label>
            <textarea id="adjustReason" placeholder="Enter reason for refund or correction"></textarea>
            <div class="modal-actions">
                <button class="btn-confirm" id="confirmAdjust">Confirm</button>
                <button class="btn-cancel-modal" id="cancelAdjust">Cancel</button>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    let currentMemberId = null;
    let currentCredit = 0;
    const MAX_CREDIT = 5000;
    let creditChart = null;
    let toastTimer = null;

    function showToast(message, type = 'error') {
        let toast = document.getElementById('toastNotif');
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
        toastTimer = setTimeout(() => toast.classList.remove('show'), 3500);
    }

    function buildChart(credit) {
        const ctx = document.getElementById('creditChart').getContext('2d');
        const used = Math.min(credit, MAX_CREDIT);
        const remaining = Math.max(MAX_CREDIT - used, 0);
        if (creditChart) creditChart.destroy();
        creditChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [used, remaining],
                    backgroundColor: ['#f5c518', '#333'],
                    borderWidth: 0,
                    circumference: 360,
                }]
            },
            options: {
                cutout: '72%',
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                animation: { animateRotate: true }
            }
        });
    }

    function updateDisplay(member) {
        currentMemberId = member.id;
        currentCredit = parseFloat(member.credit) || 0;
        const name = member.first_name + ' ' + member.last_name;
        $('#infoName').text(name);
        $('#infoType').text(member.type);
        $('#infoCredit').text('₱' + currentCredit.toFixed(1));
        $('#donutAmount').text('₱' + currentCredit.toFixed(1));

        // Card status badge
        const hasRfid = member.RFID && member.RFID.trim() !== '';
        const type = (member.type || '').toLowerCase();
        let cardBadge = '';
        if (type === 'member') {
            cardBadge = '<span class="badge-card-has">Member</span>';
        } else {
            cardBadge = '<span class="badge-card-optional">Session</span>';
        }
        $('#infoCard').html(cardBadge);

        buildChart(currentCredit);
        $('#walletPanel').addClass('active');
        $('#cardPlaceholder').hide();
        $('#walletHistorySection').show();
        loadWalletHistory(currentMemberId);
        showToast('Member found successfully.', 'success');
    }

    function formatAmount(amount) {
        const sign = amount < 0 ? '-' : '';
        return sign + '₱' + Math.abs(amount).toFixed(2);
    }

    function loadWalletHistory(memberId) {
        if (!memberId) {
            $('#historyBody').html('<tr><td colspan="6" class="history-empty">No member selected.</td></tr>');
            return;
        }
        $('#historyBody').html('<tr><td colspan="6" class="history-empty">Loading history...</td></tr>');
        $.getJSON('wallet.php', { ajax_wallet_history: 1, member_id: memberId }, function(res) {
            if (res.success && Array.isArray(res.history) && res.history.length) {
                const rows = res.history.map(function(item) {
                    const typeLabel = item.transaction_type === 'credit_add' ? 'Credit Added' : (item.transaction_type === 'refund' ? 'Refund' : 'Correction');
                    return '<tr>' +
                        '<td>' + item.created_at + '</td>' +
                        '<td>' + typeLabel + '</td>' +
                        '<td>' + formatAmount(item.amount) + '</td>' +
                        '<td>₱' + parseFloat(item.balance_after).toFixed(2) + '</td>' +
                        '<td>' + (item.reason ? $('<div>').text(item.reason).html() : '-') + '</td>' +
                        '<td>' + (item.created_by || '-') + '</td>' +
                    '</tr>';
                });
                $('#historyBody').html(rows.join(''));
            } else {
                $('#historyBody').html('<tr><td colspan="6" class="history-empty">No transaction history found for this member.</td></tr>');
            }
        }).fail(function() {
            $('#historyBody').html('<tr><td colspan="6" class="history-empty">Unable to load history. Try again.</td></tr>');
        });
    }

    function searchByRFID() {
        const rfid = $('#rfidInput').val().trim();
        if (!rfid) {
            showToast('Please enter or scan an RFID card.');
            return;
        }
        $.ajax({
            url: 'wallet.php',
            method: 'GET',
            data: { ajax_rfid: 1, rfid: rfid },
            dataType: 'json',
            success: function(res) {
                if (res.found) {
                    updateDisplay(res.member);
                } else {
                    $('#walletPanel').removeClass('active');
                    $('#cardPlaceholder').show();
                    showToast('Invalid RFID. No member found for: "' + rfid + '"');
                }
            },
            error: function(xhr) {
                showToast('Server error. Please try again.');
                $('#cardPlaceholder').show();
            }
        });
    }

    $('#rfidSearchBtn').on('click', searchByRFID);
    $('#rfidInput').on('keydown', function(e) {
        if (e.key === 'Enter') searchByRFID();
    });

    $('#inputCreditBtn').on('click', function() {
        $('#creditAmount').val('');
        $('#creditModal').addClass('active');
    });
    $('#cancelCredit').on('click', function() {
        $('#creditModal').removeClass('active');
    });
    $('#creditModal').on('click', function(e) {
        if (e.target === this) $('#creditModal').removeClass('active');
    });

    $('#adjustCreditBtn').on('click', function() {
        $('#adjustAmount').val('');
        $('#adjustReason').val('');
        $('#adjustAction').val('refund');
        $('#adjustDirection').val('decrease');
        $('#adjustDirectionRow').hide();
        $('#adjustModal').addClass('active');
    });
    $('#cancelAdjust').on('click', function() {
        $('#adjustModal').removeClass('active');
    });
    $('#adjustModal').on('click', function(e) {
        if (e.target === this) $('#adjustModal').removeClass('active');
    });

    $('#adjustAction').on('change', function() {
        if ($(this).val() === 'refund') {
            $('#adjustDirectionRow').hide();
        } else {
            $('#adjustDirectionRow').show();
        }
    });

    $('#confirmCredit').on('click', function() {
        const amount = parseFloat($('#creditAmount').val());
        if (!amount || amount <= 0) {
            showToast('Please enter a valid credit amount.');
            return;
        }
        $.ajax({
            url: 'wallet.php',
            method: 'POST',
            data: { ajax_add_credit: 1, member_id: currentMemberId, amount: amount },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    currentCredit = parseFloat(res.credit);
                    $('#infoCredit').text('₱' + currentCredit.toFixed(1));
                    $('#donutAmount').text('₱' + currentCredit.toFixed(1));
                    buildChart(currentCredit);
                    $('#creditModal').removeClass('active');
                    loadWalletHistory(currentMemberId);
                    showToast('₱' + amount.toFixed(2) + ' credit added successfully.', 'success');
                } else {
                    showToast(res.error || 'Failed to add credit. Please try again.');
                }
            },
            error: function() {
                showToast('Server error. Could not add credit.');
            }
        });
    });

    $('#confirmAdjust').on('click', function() {
        const amount = parseFloat($('#adjustAmount').val());
        const reason = $('#adjustReason').val().trim();
        const action = $('#adjustAction').val();
        const direction = $('#adjustDirection').val();
        if (!amount || amount <= 0) {
            showToast('Please enter a valid adjustment amount.');
            return;
        }
        if (!reason) {
            showToast('Please enter a reason for this action.');
            return;
        }
        $.ajax({
            url: 'wallet.php',
            method: 'POST',
            data: {
                ajax_adjust_credit: 1,
                member_id: currentMemberId,
                amount: amount,
                action: action,
                direction: direction,
                reason: reason
            },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    currentCredit = parseFloat(res.credit);
                    $('#infoCredit').text('₱' + currentCredit.toFixed(1));
                    $('#donutAmount').text('₱' + currentCredit.toFixed(1));
                    buildChart(currentCredit);
                    $('#adjustModal').removeClass('active');
                    loadWalletHistory(currentMemberId);
                    showToast('Credit updated successfully.', 'success');
                } else {
                    showToast(res.error || 'Failed to update credit.');
                }
            },
            error: function() {
                showToast('Server error. Could not update credit.');
            }
        });
    });
    </script>
</body>
</html>
