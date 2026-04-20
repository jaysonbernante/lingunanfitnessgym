<?php
require_once '../../../app/config/connection.php';

// ── AJAX: Load log for a date ──────────────────────────────────────────────
if (isset($_GET['ajax_visitor_log'])) {
    header('Content-Type: application/json');
    $date = trim($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date.']);
        exit;
    }
    try {
        // All entries for that date
        $stmt = $pdo->prepare(
            "SELECT id, member_id, member_name, entry_type, amount_charged, payment_method,
                    DATE_FORMAT(entry_time,'%h:%i %p') AS time_display
             FROM entry_logs
             WHERE DATE(entry_time) = ?
             ORDER BY entry_time DESC"
        );
        $stmt->execute([$date]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Summary stats
        $total      = count($entries);
        $revenue    = 0;
        $nameCounts = [];
        $namePay    = [];
        foreach ($entries as $e) {
            $revenue += floatval($e['amount_charged']);
            $n = $e['member_name'];
            $nameCounts[$n] = ($nameCounts[$n] ?? 0) + 1;
            $namePay[$n]    = ($namePay[$n]    ?? 0) + floatval($e['amount_charged']);
        }

        // Top visitor (most visits)
        $topName = '—'; $topVisits = 0; $topPaid = 0;
        if (!empty($nameCounts)) {
            arsort($nameCounts);
            $topName   = array_key_first($nameCounts);
            $topVisits = $nameCounts[$topName];
            $topPaid   = $namePay[$topName];
        }

        // Breakdown by type
        $byType = ['session' => 0, 'membership' => 0, 'walk-in' => 0];
        foreach ($entries as $e) {
            $t = $e['entry_type'];
            if (isset($byType[$t])) $byType[$t]++;
        }

        echo json_encode([
            'success'    => true,
            'date'       => $date,
            'total'      => $total,
            'revenue'    => number_format($revenue, 2),
            'top_name'   => $topName,
            'top_visits' => $topVisits,
            'top_paid'   => number_format($topPaid, 2),
            'by_type'    => $byType,
            'entries'    => $entries,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$page = 'visitorLog';
include '../../../component/admin_header.php';
include '../../../component/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Log</title>
    <link href="../../../assets/css/toastednotif.css" rel="stylesheet">
    <link href="../../../assets/css/admin_header.css" rel="stylesheet">
    <link href="../../../assets/css/admin_sidebar.css" rel="stylesheet">
    <link href="../../../assets/css/admin.css" rel="stylesheet">
</head>
<style>
    /* ── Page wrapper ─────────────────────────────────────────────── */
    .vl-wrap {
        padding: 25px 0px;
    }

    /* ── Top bar ──────────────────────────────────────────────────── */
    .vl-topbar {
        display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
        margin-bottom: 24px;
    }
    .vl-topbar h1 {
        margin: 0;  font-weight: 700; flex: 1;
    }
    .date-label {
        font-size: 13px; color: #aaa; font-weight: 500;
    }
    .date-picker-wrap {
        display: flex; align-items: center; gap: 8px;
    }
    #datePicker {
        padding: 8px 14px; border-radius: 8px;
        border: 1px solid #444; background: #1e1e1e;
        color: #fff; font-size: 14px; cursor: pointer;
    }
    #datePicker:focus { outline: none; border-color: #1976d2; }
    .btn-today {
        padding: 8px 14px; border-radius: 8px; border: 1px solid #444;
        background: none; color: #bbb; font-size: 13px; font-weight: 600;
        cursor: pointer; white-space: nowrap; transition: border-color .2s, color .2s;
    }
    .btn-today:hover { border-color: #f5c518; color: #f5c518; }

    /* ── Stat cards ───────────────────────────────────────────────── */
    .vl-stats {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
        gap: 16px; margin-bottom: 26px;
    }
    .vl-stat {
        background: #1e1e1e; border-radius: 14px; padding: 18px 20px;
        border-left: 4px solid transparent; min-width: 0;
    }
    .vl-stat.c-blue   { border-left-color: #1976d2; }
    .vl-stat.c-green  { border-left-color: #43a047; }
    .vl-stat.c-yellow { border-left-color: #f5c518; }
    .vl-stat.c-orange { border-left-color: #f57c00; }
    .vl-stat-label { font-size: 11px; color: #777; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
    .vl-stat-val   { font-size: 1.7rem; font-weight: 800; color: #fff; line-height: 1.1; }
    .vl-stat-sub   { font-size: 12px; color: #666; margin-top: 4px; }

    /* ── Type breakdown bar ───────────────────────────────────────── */
    .type-breakdown {
        display: flex; gap: 10px; margin-bottom: 22px; flex-wrap: wrap;
    }
    .tb-chip {
        display: flex; align-items: center; gap: 7px;
        background: #1e1e1e; border-radius: 8px; padding: 9px 16px;
        font-size: 13px; font-weight: 600;
    }
    .tb-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
    .tb-dot.session    { background: #1976d2; }
    .tb-dot.membership { background: #f5c518; }
    .tb-dot.walkin     { background: #616161; }
    .tb-num { color: #fff; }
    .tb-lbl { color: #888; font-weight: 500; }

    /* ── Log table ────────────────────────────────────────────────── */
    .vl-table-wrap {
        background: #1e1e1e; border-radius: 14px; overflow: hidden;
    }
    .vl-table-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 20px; border-bottom: 1px solid #2a2a2a;
    }
    .vl-table-header span {
        font-size: 13px; font-weight: 600; color: #aaa;
    }
    #logSearch {
        padding: 6px 12px; border-radius: 7px; border: 1px solid #333;
        background: #111; color: #fff; font-size: 13px; width: 200px;
    }
    #logSearch:focus { outline: none; border-color: #1976d2; }

    table.vl-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    table.vl-table th {
        text-align: left; padding: 11px 16px; font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .5px; color: #555;
        border-bottom: 1px solid #2a2a2a;
    }
    table.vl-table td { padding: 11px 16px; border-bottom: 1px solid #222; color: #ccc; }
    table.vl-table tr:last-child td { border-bottom: none; }
    table.vl-table tbody tr:hover td { background: rgba(255,255,255,.025); }

    .vbadge {
        display: inline-block; padding: 2px 10px; border-radius: 10px;
        font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
    }
    .vb-session    { background: #1976d2; color: #fff; }
    .vb-membership { background: #f5c518; color: #1a1a1a; }
    .vb-walkin     { background: #616161; color: #fff; }
    .vbp-credit    { background: rgba(67,160,71,.2);  color: #81c784; }
    .vbp-cash      { background: rgba(255,152,0,.2);  color: #ffcc80; }
    .vbp-free      { background: rgba(245,197,24,.15);color: #f5c518; }

    .vl-empty {
        text-align: center; padding: 52px 20px; color: #444; font-size: 15px;
    }
    .vl-empty span { display: block; font-size: 2.5rem; margin-bottom: 10px; }

    /* ── Loading state ────────────────────────────────────────────── */
    .vl-loading {
        text-align: center; padding: 40px; color: #555; font-size: 14px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .spinner {
        display: inline-block; width: 22px; height: 22px;
        border: 3px solid #333; border-top-color: #1976d2;
        border-radius: 50%; animation: spin .7s linear infinite;
        vertical-align: middle; margin-right: 8px;
    }
</style>
<body>
<div class="dashboard-content">
<div class="vl-wrap">

    <!-- Top bar -->
    <div class="vl-topbar">
        <h1>Visitor Log</h1>
        <div class="date-picker-wrap">
            <span class="date-label">Viewing date:</span>
            <input type="date" id="datePicker">
            <button class="btn-today" id="btnToday">Today</button>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="vl-stats">
        <div class="vl-stat c-blue">
            <div class="vl-stat-label">Total Entries</div>
            <div class="vl-stat-val" id="statTotal">—</div>
            <div class="vl-stat-sub" id="statDateLabel">Select a date</div>
        </div>
        <div class="vl-stat c-green">
            <div class="vl-stat-label">Revenue Collected</div>
            <div class="vl-stat-val" id="statRevenue">—</div>
            <div class="vl-stat-sub">cash + credit entries</div>
        </div>
        <div class="vl-stat c-yellow">
            <div class="vl-stat-label">Top Visitor</div>
            <div class="vl-stat-val" id="statTopName" style="font-size:1.1rem; word-break:break-word;">—</div>
            <div class="vl-stat-sub" id="statTopSub"></div>
        </div>
        <div class="vl-stat c-orange">
            <div class="vl-stat-label">Session Entries</div>
            <div class="vl-stat-val" id="statSession">—</div>
            <div class="vl-stat-sub" id="statMembership"></div>
        </div>
    </div>

    <!-- Type breakdown -->
    <div class="type-breakdown" id="typeBreakdown" style="display:none;">
        <div class="tb-chip"><span class="tb-dot session"></span><span class="tb-num" id="tbSession">0</span><span class="tb-lbl">&nbsp;Session</span></div>
        <div class="tb-chip"><span class="tb-dot membership"></span><span class="tb-num" id="tbMembership">0</span><span class="tb-lbl">&nbsp;Membership</span></div>
        <div class="tb-chip"><span class="tb-dot walkin"></span><span class="tb-num" id="tbWalkin">0</span><span class="tb-lbl">&nbsp;Walk-in</span></div>
    </div>

    <!-- Log table -->
    <div class="vl-table-wrap">
        <div class="vl-table-header">
            <span id="tableLabel">Select a date to view entries</span>
            <input type="text" id="logSearch" placeholder="&#128269; Filter name..." style="display:none;">
        </div>
        <div id="logTableBody">
            <div class="vl-empty">
                <span>&#128467;</span>
                Pick a date above to view the visitor log.
            </div>
        </div>
    </div>

</div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function(){

    // ── Set date picker to today ───────────────────────────────────────────
    var today = new Date();
    var pad   = function(n){ return String(n).padStart(2,'0'); };
    var todayStr = today.getFullYear() + '-' + pad(today.getMonth()+1) + '-' + pad(today.getDate());
    $('#datePicker').val(todayStr);

    // ── Load on init ──────────────────────────────────────────────────────
    loadLog(todayStr);

    // ── Date change ────────────────────────────────────────────────────────
    $('#datePicker').on('change', function(){
        var d = $(this).val();
        if (d) loadLog(d);
    });

    $('#btnToday').on('click', function(){
        $('#datePicker').val(todayStr);
        loadLog(todayStr);
    });

    // ── Client-side filter ─────────────────────────────────────────────────
    $('#logSearch').on('input', function(){
        var q = $(this).val().toLowerCase();
        $('table.vl-table tbody tr').each(function(){
            var name = $(this).find('.vl-name').text().toLowerCase();
            $(this).toggle(name.indexOf(q) !== -1);
        });
    });

    // ── Load log ───────────────────────────────────────────────────────────
    function loadLog(date) {
        $('#logSearch').hide().val('');
        $('#typeBreakdown').hide();
        setStatsLoading();
        $('#tableLabel').text('Loading...');
        $('#logTableBody').html('<div class="vl-loading"><span class="spinner"></span>Loading entries&hellip;</div>');

        $.ajax({
            url: 'visitorLog.php',
            method: 'GET',
            dataType: 'json',
            data: { ajax_visitor_log: 1, date: date },
            success: function(res){
                if (!res.success) {
                    $('#logTableBody').html('<div class="vl-empty"><span>&#9888;&#65039;</span>' + (res.message || 'Failed to load.') + '</div>');
                    return;
                }
                renderStats(res);
                renderTable(res.entries, date);
            },
            error: function(){
                $('#logTableBody').html('<div class="vl-empty"><span>&#9888;&#65039;</span>Server error. Please try again.</div>');
            }
        });
    }

    function setStatsLoading(){
        $('#statTotal, #statRevenue, #statTopName, #statSession').text('—');
        $('#statDateLabel, #statTopSub, #statMembership').text('');
    }

    function formatDateLabel(dateStr) {
        var d = new Date(dateStr + 'T00:00:00');
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        return days[d.getDay()] + ', ' + months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

    function renderStats(res) {
        var dl = formatDateLabel(res.date);
        var isToday = (res.date === todayStr);
        $('#statDateLabel').text(isToday ? 'Today — ' + dl : dl);
        $('#statTotal').text(res.total);
        $('#statRevenue').text(res.total > 0 ? '\u20b1' + res.revenue : '\u20b10.00');

        if (res.top_name && res.top_name !== '\u2014' && res.top_name !== '—') {
            var nameParts = res.top_name.split(' ');
            var display   = nameParts.length >= 2 ? nameParts[0] + ' ' + nameParts[nameParts.length-1] : res.top_name;
            $('#statTopName').text(display);
            var sub = res.top_visits + ' visit' + (res.top_visits > 1 ? 's' : '');
            if (parseFloat(res.top_paid.replace(',','')) > 0) sub += ' \u2014 \u20b1' + res.top_paid + ' paid';
            $('#statTopSub').text(sub);
        } else {
            $('#statTopName').text('—');
            $('#statTopSub').text('');
        }

        var s  = res.by_type['session']    || 0;
        var m  = res.by_type['membership'] || 0;
        var wi = res.by_type['walk-in']    || 0;
        $('#statSession').text(s);
        $('#statMembership').text('Membership: ' + m + '  \u2022  Walk-in: ' + wi);

        $('#tbSession').text(s);
        $('#tbMembership').text(m);
        $('#tbWalkin').text(wi);
        if (res.total > 0) $('#typeBreakdown').show();
    }

    function renderTable(entries, date) {
        var dl = formatDateLabel(date);
        if (!entries || entries.length === 0) {
            $('#tableLabel').text('No entries on ' + dl);
            $('#logSearch').hide();
            $('#logTableBody').html('<div class="vl-empty"><span>&#128203;</span>No entries recorded on this date.</div>');
            return;
        }

        $('#tableLabel').text(entries.length + ' entr' + (entries.length === 1 ? 'y' : 'ies') + ' on ' + dl);
        $('#logSearch').show();

        var html = '<table class="vl-table"><thead><tr>'
            + '<th>#</th><th>Name</th><th>Type</th><th>Fee</th><th>Payment</th><th>Time</th>'
            + '</tr></thead><tbody>';

        $.each(entries, function(i, e){
            var tc  = e.entry_type === 'membership' ? 'vb-membership' : (e.entry_type === 'walk-in' ? 'vb-walkin' : 'vb-session');
            var pc  = e.payment_method === 'credit' ? 'vbp-credit' : (e.payment_method === 'free' ? 'vbp-free' : 'vbp-cash');
            var fee = parseFloat(e.amount_charged) > 0 ? '\u20b1' + parseFloat(e.amount_charged).toFixed(2) : '\u2014';
            var safeN = $('<span>').text(e.member_name).html();
            html += '<tr>'
                + '<td style="color:#555;">' + (entries.length - i) + '</td>'
                + '<td class="vl-name" style="font-weight:600;color:#fff;">' + safeN + '</td>'
                + '<td><span class="vbadge ' + tc + '">' + e.entry_type + '</span></td>'
                + '<td>' + fee + '</td>'
                + '<td><span class="vbadge ' + pc + '">' + e.payment_method + '</span></td>'
                + '<td style="color:#888;">' + e.time_display + '</td>'
                + '</tr>';
        });

        html += '</tbody></table>';
        $('#logTableBody').html(html);
    }

});
</script>
</body>
</html>
