<?php
require_once '../../../app/config/connection.php';

// ── Migrations ─────────────────────────────────────────────────────────────
try { $pdo->exec("CREATE TABLE IF NOT EXISTS entry_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT DEFAULT NULL,
    member_name VARCHAR(100) NOT NULL DEFAULT 'Walk-in',
    entry_type VARCHAR(20) NOT NULL DEFAULT 'session',
    amount_charged DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
    entry_time DATETIME NOT NULL DEFAULT NOW())"); } catch(Exception $e) {}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_rfids (id INT AUTO_INCREMENT PRIMARY KEY, rfid VARCHAR(100) NOT NULL, member_id INT DEFAULT NULL, blocked_at DATETIME DEFAULT NOW(), reason VARCHAR(100) DEFAULT 'lost')"); } catch(Exception $e) {}

define('SESSION_FEE', 50);

// ── AJAX: Scan RFID ────────────────────────────────────────────────────────
if (isset($_POST['ajax_scan_rfid'])) {
    header('Content-Type: application/json');
    $rfid = trim($_POST['rfid'] ?? '');
    if (!$rfid) { echo json_encode(['success'=>false,'error'=>'empty']); exit; }
    try {
        $blk = $pdo->prepare("SELECT id FROM blocked_rfids WHERE rfid=?");
        $blk->execute([$rfid]);
        if ($blk->fetch()) {
            echo json_encode(['success'=>false,'error'=>'blocked','message'=>'This card has been blocked (reported lost). Please contact staff.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM members WHERE RFID=?");
        $stmt->execute([$rfid]);
        $m = $stmt->fetch();
        if (!$m) { echo json_encode(['success'=>false,'error'=>'not_found','message'=>'No member found for this RFID card.']); exit; }
        echo json_encode(['success'=>true,'member'=>[
            'id'     => $m['id'],
            'name'   => trim($m['first_name'].' '.$m['last_name']),
            'type'   => $m['type'],
            'credit' => floatval($m['credit'] ?? 0),
            'expiry' => $m['membership_expiry'] ?? null,
        ]]);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'db','message'=>$e->getMessage()]); }
    exit;
}

// ── AJAX: Name lookup ──────────────────────────────────────────────────────
if (isset($_POST['ajax_name_lookup'])) {
    header('Content-Type: application/json');
    $name = trim($_POST['name'] ?? '');
    if (strlen($name) < 2) { echo json_encode(['success'=>false,'error'=>'too_short']); exit; }
    try {
        $stmt = $pdo->prepare("SELECT id,first_name,last_name,type,COALESCE(credit,0) as credit,membership_expiry FROM members WHERE first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name,' ',last_name) LIKE ? LIMIT 6");
        $stmt->execute(["%$name%","%$name%","%$name%"]);
        $rows = $stmt->fetchAll();
        if (empty($rows)) { echo json_encode(['success'=>false,'error'=>'not_found']); exit; }
        $list = [];
        foreach ($rows as $r) {
            $list[] = ['id'=>$r['id'],'name'=>trim($r['first_name'].' '.$r['last_name']),'type'=>$r['type'],'credit'=>floatval($r['credit']),'expiry'=>$r['membership_expiry']];
        }
        echo json_encode(['success'=>true,'members'=>$list]);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'db']); }
    exit;
}

// ── AJAX: Process entry ────────────────────────────────────────────────────
if (isset($_POST['ajax_process_entry'])) {
    header('Content-Type: application/json');
    $member_id  = intval($_POST['member_id'] ?? 0);
    $pay_method = trim($_POST['payment_method'] ?? 'credit');
    $walk_name  = trim($_POST['walk_in_name'] ?? '');
    try {
        if ($member_id) {
            $stmt = $pdo->prepare("SELECT * FROM members WHERE id=?");
            $stmt->execute([$member_id]);
            $m = $stmt->fetch();
            if (!$m) { echo json_encode(['success'=>false,'message'=>'Member not found.']); exit; }
            $name = trim($m['first_name'].' '.$m['last_name']);
            if ($m['type'] === 'member') {
                if ($m['membership_expiry'] && strtotime($m['membership_expiry']) < strtotime('today')) {
                    echo json_encode(['success'=>false,'error'=>'expired','message'=>'Membership expired on '.date('d M Y',strtotime($m['membership_expiry'])).'.']);
                    exit;
                }
                $pdo->prepare("INSERT INTO entry_logs (member_id,member_name,entry_type,amount_charged,payment_method) VALUES (?,?,'membership',0,'free')")->execute([$m['id'],$name]);
                echo json_encode(['success'=>true,'type'=>'membership','message'=>"Welcome, $name!",'sub'=>'Membership \xe2\x80\x94 Free Entry','amount'=>0,'payment'=>'free']);
                exit;
            }
            // Session
            $fee = SESSION_FEE;
            if ($pay_method === 'credit') {
                $credit = floatval($m['credit'] ?? 0);
                if ($credit < $fee) {
                    echo json_encode(['success'=>false,'error'=>'insufficient_credit','credit'=>$credit,'fee'=>$fee,'member_name'=>$name,'member_id'=>$m['id']]);
                    exit;
                }
                $pdo->prepare("UPDATE members SET credit=credit-? WHERE id=?")->execute([$fee,$m['id']]);
                $pdo->prepare("INSERT INTO entry_logs (member_id,member_name,entry_type,amount_charged,payment_method) VALUES (?,?,'session',?,'credit')")->execute([$m['id'],$name,$fee]);
                echo json_encode(['success'=>true,'type'=>'session','message'=>"Welcome, $name!",'sub'=>"\xe2\x82\xb1{$fee} deducted from credit",'remaining_credit'=>floatval($m['credit'])-$fee,'amount'=>$fee,'payment'=>'credit']);
            } else {
                $pdo->prepare("INSERT INTO entry_logs (member_id,member_name,entry_type,amount_charged,payment_method) VALUES (?,?,'session',?,'cash')")->execute([$m['id'],$name,$fee]);
                echo json_encode(['success'=>true,'type'=>'session','message'=>"Welcome, $name!",'sub'=>"\xe2\x82\xb1{$fee} paid in cash",'amount'=>$fee,'payment'=>'cash']);
            }
        } else {
            if (!$walk_name) { echo json_encode(['success'=>false,'message'=>'No name provided.']); exit; }
            $fee = SESSION_FEE;
            $pdo->prepare("INSERT INTO entry_logs (member_id,member_name,entry_type,amount_charged,payment_method) VALUES (NULL,?,'walk-in',?,'cash')")->execute([$walk_name,$fee]);
            echo json_encode(['success'=>true,'type'=>'walk-in','message'=>"Welcome, $walk_name!",'sub'=>"\xe2\x82\xb1{$fee} walk-in cash",'amount'=>$fee,'payment'=>'cash']);
        }
    } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── AJAX: Today's log ──────────────────────────────────────────────────────
if (isset($_GET['ajax_today_entries'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT member_name,entry_type,amount_charged,payment_method,entry_time FROM entry_logs WHERE DATE(entry_time)=CURDATE() ORDER BY entry_time DESC LIMIT 15");
        echo json_encode(['success'=>true,'entries'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Exception $e) { echo json_encode(['success'=>true,'entries'=>[]]); }
    exit;
}

$page = 'RFID';
include '../../../component/admin_header.php';
include '../../../component/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entry System</title>
    <link href="../../../assets/css/toastednotif.css" rel="stylesheet">
    <link href="../../../assets/css/admin_header.css" rel="stylesheet">
    <link href="../../../assets/css/admin_sidebar.css" rel="stylesheet">
    <link href="../../../assets/css/admin.css" rel="stylesheet">
</head>
<style>
    .rfid-content {
        margin-left: 250px; margin-top: 60px; padding: 2rem;
        min-height: calc(100vh - 60px); background: #222; color: #fff;
    }
    @media (max-width: 900px) { .rfid-content { margin-left: 0; padding: 1rem; } }

    /* ── Scan terminal ────────────────────────────────────────────── */
    .scan-terminal { max-width: 560px; margin: 0 auto 32px; }
    .scan-zone {
        background: #1a1a1a; border-radius: 20px; padding: 44px 32px;
        text-align: center; border: 2px dashed #444;
        transition: border-color 0.3s, background 0.3s;
        min-height: 230px; display: flex; flex-direction: column;
        align-items: center; justify-content: center; cursor: default;
    }
    .scan-zone.state-ready    { border-color: #444; }
    .scan-zone.state-scanning { border-color: #f5c518; background: rgba(245,197,24,.04); }
    .scan-zone.state-success  { border-color: #43a047; background: rgba(67,160,71,.06); }
    .scan-zone.state-error    { border-color: #e53935; background: rgba(229,57,53,.06); }
    .scan-zone.state-warn     { border-color: #f57c00; background: rgba(245,124,0,.06); }

    .scan-icon  { font-size: 3.5rem; margin-bottom: 12px; }
    .scan-title { font-size: 1.3rem; font-weight: 700; color: #fff; margin: 0 0 6px; }
    .scan-sub   { font-size: 0.92rem; color: #aaa; margin: 0; }

    /* Result card */
    .result-card { display: none; width: 100%; }
    .result-card.show { display: block; }
    .result-name { font-size: 1.5rem; font-weight: 800; color: #fff; margin: 0 0 8px; }
    .result-type-badge {
        display: inline-block; padding: 3px 14px; border-radius: 20px;
        font-size: 0.78rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.5px; margin-bottom: 12px;
    }
    .badge-session    { background: #1976d2; color: #fff; }
    .badge-membership { background: #f5c518; color: #1a1a1a; }
    .badge-walkin     { background: #616161; color: #fff; }
    .result-msg       { font-size: 1rem; font-weight: 600; margin: 0; color: #81c784; }
    .result-msg.error { color: #ef9a9a; }
    .result-msg.warn  { color: #ffcc80; }
    .result-sub       { font-size: 0.88rem; color: #aaa; margin-top: 6px; }
    .progress-bar  { height: 3px; background: #333; border-radius: 2px; margin-top: 20px; overflow: hidden; }
    .progress-fill { height: 100%; background: #43a047; width: 100%; transition: width linear; }
    .progress-fill.error { background: #e53935; }

    /* ── Controls row ─────────────────────────────────────────────── */
    .controls-row { display: flex; gap: 10px; margin-top: 14px; }
    .btn-nocard {
        flex: 1; padding: 10px 14px; border-radius: 10px; border: 1px solid #555;
        background: none; color: #bbb; font-size: 13px; font-weight: 600; cursor: pointer;
        transition: border-color .2s, color .2s;
    }
    .btn-nocard:hover, .btn-nocard.active { border-color: #1976d2; color: #90caf9; background: rgba(25,118,210,.08); }
    .btn-walkin {
        padding: 10px 14px; border-radius: 10px; border: 1px solid #555;
        background: none; color: #bbb; font-size: 13px; font-weight: 600; cursor: pointer;
        transition: border-color .2s, color .2s;
    }
    .btn-walkin:hover { border-color: #43a047; color: #81c784; }

    /* ── Manual search ────────────────────────────────────────────── */
    .manual-section {
        background: #1a1a1a; border-radius: 14px; padding: 18px 20px;
        margin-top: 12px; display: none;
    }
    .manual-section.show { display: block; }
    .manual-search-bar { display: flex; gap: 8px; }
    .manual-search-bar input {
        flex: 1; padding: 10px 14px; border-radius: 8px; border: 1px solid #444;
        background: #111; color: #fff; font-size: 14px;
    }
    .manual-search-bar input:focus { outline: none; border-color: #1976d2; }
    .search-results { margin-top: 10px; }
    .sri {
        display: flex; align-items: center; justify-content: space-between;
        padding: 10px 14px; border-radius: 8px; background: #222;
        margin-bottom: 6px; cursor: pointer; border: 1px solid #333;
        transition: border-color .2s, background .2s;
    }
    .sri:hover { border-color: #1976d2; background: rgba(25,118,210,.08); }
    .sri-name   { font-weight: 600; font-size: 14px; }
    .sri-meta   { font-size: 12px; color: #888; }
    .sri-credit { font-size: 13px; color: #f5c518; font-weight: 600; }

    /* ── Today's log ──────────────────────────────────────────────── */
    .today-log { max-width: 560px; margin: 0 auto; }
    .today-log h3 { font-size: 1rem; color: #bbb; margin: 0 0 10px; font-weight: 600; }
    .log-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .log-table th { color: #555; font-weight: 600; text-align: left; padding: 6px 8px; border-bottom: 1px solid #333; }
    .log-table td { padding: 8px 8px; border-bottom: 1px solid #2a2a2a; color: #ccc; }
    .log-table tr:last-child td { border-bottom: none; }
    .lbadge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
    .lb-session    { background:#1976d2; color:#fff; }
    .lb-membership { background:#f5c518; color:#1a1a1a; }
    .lb-walkin     { background:#616161; color:#fff; }
    .lb-credit     { background:rgba(67,160,71,.2); color:#81c784; }
    .lb-cash       { background:rgba(255,152,0,.2); color:#ffcc80; }
    .lb-free       { background:rgba(245,197,24,.15); color:#f5c518; }

    /* ── Modals ───────────────────────────────────────────────────── */
    .modal-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,.72); z-index: 2000;
        justify-content: center; align-items: center;
    }
    .modal-overlay.active { display: flex; }
    .modal-box {
        background: #2c2c2c; border-radius: 16px; padding: 28px 32px;
        max-width: 420px; width: 90%; color: #fff;
        box-shadow: 0 8px 40px rgba(0,0,0,.6);
    }
    .modal-box h3   { margin: 0 0 8px; font-size: 1.15rem; }
    .modal-box p    { color: #aaa; font-size: 14px; margin: 0 0 18px; line-height: 1.55; }
    .modal-actions  { display: flex; gap: 10px; flex-wrap: wrap; }
    .amount-highlight { font-size: 2.2rem; font-weight: 800; color: #f5c518; text-align: center; margin: 6px 0 14px; }
    .btn-green   { padding:9px 22px; border-radius:8px; border:none; background:#43a047; color:#fff; font-weight:600; cursor:pointer; }
    .btn-green:hover { background:#2e7d32; }
    .btn-blue    { padding:9px 22px; border-radius:8px; border:none; background:#1976d2; color:#fff; font-weight:600; cursor:pointer; }
    .btn-blue:hover  { background:#1565c0; }
    .btn-grey    { padding:9px 22px; border-radius:8px; border:none; background:#444; color:#fff; font-weight:600; cursor:pointer; }
    .btn-grey:hover  { background:#555; }
    .modal-input {
        width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid #444;
        background: #1a1a1a; color: #fff; font-size: 15px; box-sizing: border-box; margin-bottom: 16px;
    }
    .modal-input:focus { outline: none; border-color: #1976d2; }

    /* Hidden RFID capture input */
    #rfidHiddenInput { position:fixed; top:-9999px; left:-9999px; opacity:0; width:1px; height:1px; }

    @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.25} }
    .blink { animation: blink 1.6s infinite; }
    @keyframes spin  { to{transform:rotate(360deg)} }
    .spin  { display:inline-block; animation: spin .8s linear infinite; }
</style>
<body>
<div class="rfid-content">

    <!-- Header row -->
    <div style="max-width:560px; margin:0 auto 20px; display:flex; justify-content:space-between; align-items:center;">
        <h1 style="margin:0; font-size:1.4rem;">Entry System</h1>
        <div id="clockDisplay" style="font-size:1rem; color:#f5c518; font-weight:600; font-family:monospace;"></div>
    </div>

    <div class="scan-terminal">
        <!-- Scan zone -->
        <div class="scan-zone state-ready" id="scanZone">
            <!-- Idle state -->
            <div id="stateIdle">
                <div class="scan-icon blink">&#128276;</div>
                <p class="scan-title">Ready to Scan</p>
                <p class="scan-sub">Tap RFID card on the scanner</p>
            </div>
            <!-- Scanning spinner -->
            <div id="stateScanning" style="display:none;">
                <div class="scan-icon"><span class="spin">&#9696;</span></div>
                <p class="scan-title">Processing...</p>
            </div>
            <!-- Result -->
            <div class="result-card" id="resultCard">
                <div class="scan-icon" id="resultIcon"></div>
                <div class="result-name" id="resultName"></div>
                <span class="result-type-badge" id="resultBadge"></span>
                <p class="result-msg" id="resultMsg"></p>
                <p class="result-sub" id="resultSub"></p>
                <div class="progress-bar" id="resultProgress" style="display:none;">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
            </div>
        </div>

        <!-- Control buttons -->
        <div class="controls-row">
            <button type="button" class="btn-nocard" id="btnNoCard">&#128100; No Card — Search by Name</button>
            <button type="button" class="btn-walkin" id="btnWalkIn">Walk-in</button>
        </div>

        <!-- Manual name search -->
        <div class="manual-section" id="manualSection">
            <div class="manual-search-bar">
                <input type="text" id="nameSearchInput" placeholder="Type member name..." autocomplete="off">
            </div>
            <div class="search-results" id="searchResults"></div>
        </div>
    </div>

    <!-- Today's log -->
    <div class="today-log">
        <h3>Today's Entries <span id="entryCount" style="color:#555; font-weight:400; font-size:0.85rem;"></span></h3>
        <table class="log-table">
            <thead><tr>
                <th>Name</th><th>Type</th><th>Fee</th><th>Payment</th><th>Time</th>
            </tr></thead>
            <tbody id="todayLog">
                <tr><td colspan="5" style="color:#444; text-align:center; padding:16px;">No entries yet today</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Hidden RFID input -->
<input type="text" id="rfidHiddenInput" autocomplete="off" tabindex="-1">

<!-- ── Modal: Insufficient Credit ── -->
<div class="modal-overlay" id="cashModal">
    <div class="modal-box">
        <h3>&#9888;&#65039; Insufficient Credit</h3>
        <p id="cashModalMsg"></p>
        <div class="amount-highlight">&#8369;50</div>
        <div class="modal-actions">
            <button class="btn-green" id="btnPayCash">&#10003; Accept Cash &#8369;50</button>
            <button class="btn-grey"  id="btnCashCancel">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Modal: Not Found ── -->
<div class="modal-overlay" id="notFoundModal">
    <div class="modal-box">
        <h3>&#10060; No User Found</h3>
        <p id="notFoundMsg">No member found with that information.</p>
        <div class="modal-actions">
            <button class="btn-blue" id="btnGoRegister">&#43; Register New User</button>
            <button class="btn-grey" id="btnNotFoundCancel">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Modal: Blocked Card ── -->
<div class="modal-overlay" id="blockedModal">
    <div class="modal-box">
        <h3 style="color:#ef9a9a;">&#128683; Card Blocked</h3>
        <p id="blockedMsg"></p>
        <div class="modal-actions">
            <button class="btn-grey" id="btnBlockedClose">Close</button>
        </div>
    </div>
</div>

<!-- ── Modal: Membership Expired ── -->
<div class="modal-overlay" id="expiredModal">
    <div class="modal-box">
        <h3 style="color:#ffcc80;">&#8987; Membership Expired</h3>
        <p id="expiredMsg"></p>
        <div class="modal-actions">
            <button class="btn-grey" id="btnExpiredClose">Close</button>
        </div>
    </div>
</div>

<!-- ── Modal: Walk-in name ── -->
<div class="modal-overlay" id="walkInModal">
    <div class="modal-box">
        <h3>Walk-in Cash Entry</h3>
        <p>Enter the visitor's name for the record. Fee: <strong style="color:#f5c518;">&#8369;50</strong></p>
        <input type="text" id="walkInName" class="modal-input" placeholder="First and Last name">
        <div class="modal-actions">
            <button class="btn-green" id="btnConfirmWalkIn">&#8369;50 Confirm Entry</button>
            <button class="btn-grey"  id="btnWalkInCancel">Cancel</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function(){

    // ── Toast ──────────────────────────────────────────────────────────────
    var toastTimer;
    function showToast(msg, type) {
        type = type || 'error';
        var t = document.getElementById('toastNotif');
        if (!t) { t = document.createElement('div'); t.id = 'toastNotif'; t.className = 'toast'; document.body.appendChild(t); }
        t.className = 'toast' + (type === 'success' ? ' success' : '');
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function(){ t.classList.remove('show'); }, 3500);
    }

    // ── Clock ──────────────────────────────────────────────────────────────
    function updateClock() {
        var now = new Date();
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var days   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        var h = String(now.getHours()).padStart(2,'0');
        var m = String(now.getMinutes()).padStart(2,'0');
        var s = String(now.getSeconds()).padStart(2,'0');
        $('#clockDisplay').text(days[now.getDay()]+', '+months[now.getMonth()]+' '+now.getDate()+' \u2014 '+h+':'+m+':'+s);
    }
    updateClock(); setInterval(updateClock, 1000);

    // ── State management ───────────────────────────────────────────────────
    var currentMemberId   = null;
    var currentMemberName = '';
    var resetTimer = null;

    function setState(s) {
        $('#scanZone').removeClass('state-ready state-scanning state-success state-error state-warn').addClass('state-'+s);
    }

    function showResult(icon, name, badgeClass, badgeText, msg, msgClassExtra, sub, autoReset) {
        $('#stateIdle').hide(); $('#stateScanning').hide();
        $('#resultIcon').text(icon);
        $('#resultName').text(name);
        $('#resultBadge').attr('class','result-type-badge '+badgeClass).text(badgeText);
        $('#resultMsg').attr('class','result-msg'+(msgClassExtra?' '+msgClassExtra:'')).text(msg);
        $('#resultSub').text(sub || '');
        if (autoReset) {
            $('#resultProgress').show();
            $('#progressFill').css({transition:'none',width:'100%'});
            setTimeout(function(){ $('#progressFill').css({transition:'width 4.5s linear',width:'0%'}); }, 80);
        } else {
            $('#resultProgress').hide();
        }
        $('#resultCard').addClass('show');
    }

    function resetToIdle(delay) {
        clearTimeout(resetTimer);
        var fn = function(){
            currentMemberId = null; currentMemberName = '';
            setState('ready');
            $('#stateIdle').show(); $('#stateScanning').hide();
            $('#resultCard').removeClass('show');
            $('#progressFill').css({transition:'none',width:'100%'});
            focusRfid();
        };
        if (delay > 0) { resetTimer = setTimeout(fn, delay); } else { fn(); }
    }

    // ── RFID scanner (HID keyboard input) ─────────────────────────────────
    var rfidBuffer = '', rfidTimer = null;

    function focusRfid() {
        if (!$('#manualSection').hasClass('show')) $('#rfidHiddenInput').focus();
    }
    focusRfid();
    $('#scanZone').on('click', function(){ focusRfid(); });

    $('#rfidHiddenInput').on('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(rfidTimer);
            var val = rfidBuffer.trim(); rfidBuffer = ''; $(this).val('');
            if (val) handleRfidScan(val);
            return;
        }
        clearTimeout(rfidTimer);
        rfidTimer = setTimeout(function(){
            var val = rfidBuffer.trim(); rfidBuffer = ''; $('#rfidHiddenInput').val('');
            if (val) handleRfidScan(val);
        }, 300);
    });
    $('#rfidHiddenInput').on('input', function(){ rfidBuffer += $(this).val(); $(this).val(''); });

    function handleRfidScan(rfid) {
        clearTimeout(resetTimer);
        setState('scanning');
        $('#stateIdle').hide(); $('#resultCard').removeClass('show');
        $('#stateScanning').show();
        $.ajax({
            url: 'RFID.php', method: 'POST', dataType: 'json',
            data: { ajax_scan_rfid: 1, rfid: rfid },
            success: function(res) {
                if (!res.success) {
                    if (res.error === 'blocked') {
                        setState('error');
                        showResult('\u{1F6AB}', 'Card Blocked', 'badge-walkin', 'BLOCKED', res.message, 'error', '', false);
                        $('#blockedMsg').text(res.message);
                        $('#blockedModal').addClass('active');
                        resetToIdle(7000);
                    } else if (res.error === 'not_found') {
                        setState('error');
                        showResult('\u2717', 'Unknown Card', 'badge-walkin', 'NOT FOUND', res.message, 'error', '', false);
                        $('#notFoundMsg').text(res.message + ' This card is not registered in the system.');
                        $('#notFoundModal').addClass('active');
                        resetToIdle(8000);
                    } else {
                        showToast(res.message || 'Scan error.');
                        resetToIdle(2000);
                    }
                    return;
                }
                var m = res.member;
                currentMemberId = m.id; currentMemberName = m.name;
                processEntry(m.id, 'credit', '');
            },
            error: function(){ showToast('Server error.'); resetToIdle(2000); }
        });
    }

    // ── Process entry ──────────────────────────────────────────────────────
    function processEntry(member_id, pay_method, walk_name) {
        setState('scanning');
        $('#stateIdle').hide(); $('#resultCard').removeClass('show');
        $('#stateScanning').show();
        $.ajax({
            url: 'RFID.php', method: 'POST', dataType: 'json',
            data: { ajax_process_entry:1, member_id:member_id, payment_method:pay_method, walk_in_name:walk_name },
            success: function(res) {
                if (res.success) {
                    var bc = res.type === 'membership' ? 'badge-membership' : (res.type === 'walk-in' ? 'badge-walkin' : 'badge-session');
                    var bt = res.type === 'membership' ? 'Membership' : (res.type === 'walk-in' ? 'Walk-in' : 'Session');
                    setState('success');
                    showResult('\u2713', res.message.replace('Welcome, ','').replace('!',''), bc, bt, '\u2713 Entry Granted', '', res.sub, true);
                    showToast(res.message + ' ' + res.sub, 'success');
                    loadTodayLog();
                    resetToIdle(5000);
                } else {
                    if (res.error === 'insufficient_credit') {
                        setState('warn');
                        showResult('!', res.member_name, 'badge-session', 'SESSION',
                            'Insufficient credit (\u20b1' + parseFloat(res.credit).toFixed(2) + ')', 'warn',
                            'Session fee: \u20b1' + res.fee, false);
                        $('#cashModalMsg').text(res.member_name + ' has \u20b1' + parseFloat(res.credit).toFixed(2) + ' credit \u2014 not enough for the \u20b1' + res.fee + ' session fee. Accept cash instead?');
                        $('#cashModal').addClass('active');
                    } else if (res.error === 'expired') {
                        setState('error');
                        showResult('\u231B', currentMemberName || 'Member', 'badge-membership', 'MEMBERSHIP', res.message, 'error', '', false);
                        $('#expiredMsg').text(res.message);
                        $('#expiredModal').addClass('active');
                        resetToIdle(8000);
                    } else {
                        setState('error');
                        showResult('\u2717', 'Error', 'badge-walkin', 'ERROR', res.message || 'Entry failed.', 'error', '', false);
                        showToast(res.message || 'Entry failed.');
                        resetToIdle(4000);
                    }
                }
            },
            error: function(){ showToast('Server error.'); resetToIdle(2000); }
        });
    }

    // ── Cash modal ─────────────────────────────────────────────────────────
    $('#btnPayCash').on('click', function(){
        $('#cashModal').removeClass('active');
        processEntry(currentMemberId, 'cash', '');
    });
    $('#btnCashCancel').on('click', function(){
        $('#cashModal').removeClass('active');
        resetToIdle(0);
    });

    // ── Not found modal ────────────────────────────────────────────────────
    $('#btnGoRegister').on('click', function(){
        window.location.href = '../management/member.php';
    });
    $('#btnNotFoundCancel').on('click', function(){
        $('#notFoundModal').removeClass('active');
        resetToIdle(0);
    });

    // ── Blocked / Expired ──────────────────────────────────────────────────
    $('#btnBlockedClose').on('click',  function(){ $('#blockedModal').removeClass('active');  resetToIdle(0); });
    $('#btnExpiredClose').on('click',  function(){ $('#expiredModal').removeClass('active');  resetToIdle(0); });

    // ── No Card — name search ──────────────────────────────────────────────
    var nsTimer = null;
    $('#btnNoCard').on('click', function(){
        var active = $('#manualSection').hasClass('show');
        if (active) {
            $('#manualSection').removeClass('show'); $(this).removeClass('active');
            $('#searchResults').empty(); focusRfid();
        } else {
            $('#manualSection').addClass('show'); $(this).addClass('active');
            setTimeout(function(){ $('#nameSearchInput').focus(); }, 60);
        }
    });

    $('#nameSearchInput').on('input', function(){
        clearTimeout(nsTimer);
        var val = $(this).val().trim();
        if (val.length < 2) { $('#searchResults').empty(); return; }
        nsTimer = setTimeout(function(){
            $.ajax({
                url: 'RFID.php', method: 'POST', dataType: 'json',
                data: { ajax_name_lookup: 1, name: val },
                success: function(res){
                    if (!res.success) {
                        if (res.error === 'not_found') {
                            $('#searchResults').html('<div style="color:#888;font-size:13px;padding:10px 0;">No member found. <button id="btnSRRegister" style="background:none;border:none;color:#1976d2;cursor:pointer;font-size:13px;font-weight:600;padding:0;">+ Register new user</button></div>');
                            $('#btnSRRegister').on('click', function(){ window.location.href='../management/member.php'; });
                        }
                        return;
                    }
                    var html = '';
                    $.each(res.members, function(i, m){
                        var typeLabel = m.type === 'member' ? 'Membership' : 'Session';
                        var feeInfo   = m.type === 'member' ? 'Free entry' : '\u20b1' + parseFloat(m.credit).toFixed(2) + ' credit';
                        html += '<div class="sri" data-id="'+m.id+'" data-name="'+$('<span>').text(m.name).html()+'" data-type="'+m.type+'">';
                        html += '<div><div class="sri-name">'+$('<span>').text(m.name).html()+'</div>';
                        html += '<div class="sri-meta">'+typeLabel+'</div></div>';
                        html += '<div class="sri-credit">'+feeInfo+'</div></div>';
                    });
                    $('#searchResults').html(html);
                }
            });
        }, 280);
    });

    $(document).on('click', '.sri', function(){
        var id   = $(this).data('id');
        var name = $(this).data('name');
        currentMemberId = id; currentMemberName = name;
        $('#manualSection').removeClass('show');
        $('#btnNoCard').removeClass('active');
        $('#nameSearchInput').val('');
        $('#searchResults').empty();
        processEntry(id, 'credit', '');
    });

    // ── Walk-in modal ──────────────────────────────────────────────────────
    $('#btnWalkIn').on('click', function(){
        $('#walkInName').val('');
        $('#walkInModal').addClass('active');
        setTimeout(function(){ $('#walkInName').focus(); }, 80);
    });
    $('#btnConfirmWalkIn').on('click', function(){
        var name = $('#walkInName').val().trim();
        if (!name) { showToast('Please enter a name.'); return; }
        $('#walkInModal').removeClass('active');
        processEntry(0, 'cash', name);
    });
    $('#btnWalkInCancel').on('click', function(){ $('#walkInModal').removeClass('active'); focusRfid(); });
    $('#walkInName').on('keydown', function(e){ if (e.key === 'Enter') $('#btnConfirmWalkIn').trigger('click'); });

    // ── Close modals on overlay click ──────────────────────────────────────
    $('.modal-overlay').on('click', function(e){
        if (e.target === this) { $(this).removeClass('active'); focusRfid(); }
    });

    // ── Today's log ────────────────────────────────────────────────────────
    function loadTodayLog() {
        $.ajax({
            url: 'RFID.php', method: 'GET', dataType: 'json',
            data: { ajax_today_entries: 1 },
            success: function(res){
                if (!res.success || !res.entries.length) {
                    $('#todayLog').html('<tr><td colspan="5" style="color:#444;text-align:center;padding:16px;">No entries yet today</td></tr>');
                    $('#entryCount').text(''); return;
                }
                $('#entryCount').text('(' + res.entries.length + ' today)');
                var html = '';
                $.each(res.entries, function(i, e){
                    var tc = e.entry_type === 'membership' ? 'lb-membership' : (e.entry_type === 'walk-in' ? 'lb-walkin' : 'lb-session');
                    var pc = e.payment_method === 'credit' ? 'lb-credit' : (e.payment_method === 'free' ? 'lb-free' : 'lb-cash');
                    var fee  = parseFloat(e.amount_charged) > 0 ? '\u20b1' + parseFloat(e.amount_charged).toFixed(2) : '\u2014';
                    var time = String(e.entry_time).substr(11, 5);
                    html += '<tr><td>' + $('<span>').text(e.member_name).html() + '</td>';
                    html += '<td><span class="lbadge '+tc+'">'+e.entry_type+'</span></td>';
                    html += '<td>'+fee+'</td>';
                    html += '<td><span class="lbadge '+pc+'">'+e.payment_method+'</span></td>';
                    html += '<td>'+time+'</td></tr>';
                });
                $('#todayLog').html(html);
            }
        });
    }
    loadTodayLog();
    setInterval(loadTodayLog, 10000);

});
</script>
</body>
</html>
