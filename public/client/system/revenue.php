<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../../app/config/connection.php';

// ── AJAX: revenue data ──────────────────────────────────────────────────────
if (isset($_GET['ajax_revenue'])) {
    header('Content-Type: application/json');

    $type  = $_GET['type']  ?? 'today';
    $from  = $_GET['from']  ?? '';
    $to    = $_GET['to']    ?? '';
    $year  = intval($_GET['year'] ?? date('Y'));

    $result = [];

    if ($type === 'today') {
        $row = $pdo->query("SELECT COALESCE(SUM(total),0) as total, COUNT(DISTINCT COALESCE(transaction_id, CONCAT(sold_at,'_',COALESCE(transacted_by,'')))) as txn_count FROM sales WHERE DATE(sold_at) = CURDATE()")->fetch();
        $result = ['total' => floatval($row['total']), 'txn_count' => intval($row['txn_count'])];

    } elseif ($type === 'range') {
        if ($from === '' || $to === '') { echo json_encode(['error' => 'Invalid dates']); exit; }
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) as total, COUNT(DISTINCT COALESCE(transaction_id, CONCAT(sold_at,'_',COALESCE(transacted_by,'')))) as txn_count FROM sales WHERE DATE(sold_at) BETWEEN ? AND ?");
        $stmt->execute([$from, $to]);
        $row = $stmt->fetch();
        // Daily breakdown
        $stmt2 = $pdo->prepare("SELECT DATE(sold_at) as day, COALESCE(SUM(total),0) as day_total FROM sales WHERE DATE(sold_at) BETWEEN ? AND ? GROUP BY DATE(sold_at) ORDER BY day ASC");
        $stmt2->execute([$from, $to]);
        $result = ['total' => floatval($row['total']), 'txn_count' => intval($row['txn_count']), 'daily' => $stmt2->fetchAll()];

    } elseif ($type === 'monthly') {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) as total FROM sales WHERE YEAR(sold_at) = ? AND MONTH(sold_at) = ?");
            $stmt->execute([$year, $m]);
            $months[] = floatval($stmt->fetch()['total']);
        }
        $result = ['year' => $year, 'months' => $months];

    } elseif ($type === 'yearly') {
        $stmt = $pdo->query("SELECT YEAR(sold_at) as yr, COALESCE(SUM(total),0) as total FROM sales GROUP BY YEAR(sold_at) ORDER BY yr ASC");
        $result = $stmt->fetchAll();

    } elseif ($type === 'payment_split') {
        $stmt = $pdo->prepare("SELECT payment_method, COALESCE(SUM(total),0) as total FROM sales WHERE YEAR(sold_at) = ? GROUP BY payment_method");
        $stmt->execute([$year]);
        $result = $stmt->fetchAll();

    } elseif ($type === 'top_products') {
        $stmt = $pdo->prepare("SELECT product_name, SUM(qty_sold) as total_qty, SUM(total) as total_revenue FROM sales WHERE YEAR(sold_at) = ? GROUP BY product_name ORDER BY total_revenue DESC LIMIT 10");
        $stmt->execute([$year]);
        $result = $stmt->fetchAll();
    }

    echo json_encode($result);
    exit;
}

$page = 'revenue';
include '../../../component/admin_header.php';
include '../../../component/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Report</title>
    <link href="../../../assets/css/toastednotif.css" rel="stylesheet">
    <link href="../../../assets/css/admin_header.css" rel="stylesheet">
    <link href="../../../assets/css/admin_sidebar.css" rel="stylesheet">
    <link href="../../../assets/css/admin.css" rel="stylesheet">
</head>
<style>
    .rev-content {
      margin-left: 250px;
        margin-top: 60px;
        padding: 2rem;
        min-height: calc(100vh - 60px);
        background: #222;
        color: #fff;
    }
    @media (max-width: 900px) { .rev-content { margin-left: 0; padding: 1rem; } }

    /* Header row */
    .rev-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 28px; }
    .rev-header h1 { margin: 0; font-size: 1.6rem; }
    .btn-export-pdf {
        padding: 9px 22px; border-radius: 8px; border: none;
        background: #e53935; color: #fff; font-weight: 700; font-size: 14px;
        cursor: pointer; display: flex; align-items: center; gap: 7px;
    }
    .btn-export-pdf:hover { background: #c62828; }

    /* Year selector */
    .year-selector { display: flex; align-items: center; gap: 10px; margin-bottom: 28px; flex-wrap: wrap; }
    .year-selector label { color: #aaa; font-size: 14px; }
    .year-selector select {
        padding: 7px 14px; border-radius: 8px; border: 1px solid #444;
        background: #2c2c2c; color: #fff; font-size: 14px; cursor: pointer;
    }

    /* Summary cards */
    .summary-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 32px; }
    .s-card {
        background: #2c2c2c; border-radius: 14px; padding: 20px 22px;
        display: flex; flex-direction: column; gap: 6px;
        border-left: 4px solid #f5c518;
    }
    .s-card.blue  { border-left-color: #1976d2; }
    .s-card.green { border-left-color: #388e3c; }
    .s-card.red   { border-left-color: #e53935; }
    .s-card .sc-label { font-size: 12px; color: #aaa; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
    .s-card .sc-value { font-size: 1.5rem; font-weight: 800; color: #fff; }
    .s-card .sc-sub   { font-size: 12px; color: #888; }

    /* Date filter */
    .date-filter {
        background: #2c2c2c; border-radius: 14px; padding: 20px 24px;
        margin-bottom: 28px; display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end;
    }
    .date-filter .df-group { display: flex; flex-direction: column; gap: 5px; }
    .date-filter label { font-size: 12px; color: #aaa; font-weight: 600; }
    .date-filter input[type=date] {
        padding: 8px 12px; border-radius: 8px; border: 1px solid #444;
        background: #1a1a1a; color: #fff; font-size: 14px;
    }
    .btn-filter {
        padding: 9px 22px; border-radius: 8px; border: none;
        background: #f5c518; color: #111; font-weight: 700; cursor: pointer;
    }
    .btn-filter:hover { background: #ffe066; }
    .filter-result {
        background: #1a1a1a; border-radius: 10px; padding: 14px 18px;
        margin-top: 12px; display: none; font-size: 14px; color: #ddd;
    }
    .filter-result .fr-total { font-size: 1.3rem; font-weight: 800; color: #f5c518; }

    /* Charts grid */
    .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px; }
    @media (max-width: 800px) { .charts-grid { grid-template-columns: 1fr; } }
    .chart-card {
        background: #2c2c2c; border-radius: 14px; padding: 20px;
    }
    .chart-card h3 { margin: 0 0 16px; font-size: 1rem; color: #f5c518; }
    .chart-card canvas { max-height: 280px; }

    /* Top products table */
    .top-products-card {
        background: #2c2c2c; border-radius: 14px; padding: 20px 24px; margin-bottom: 28px;
    }
    .top-products-card h3 { margin: 0 0 16px; font-size: 1rem; color: #f5c518; }
    .tp-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .tp-table th { color: #aaa; padding: 8px 12px; text-align: left; border-bottom: 1px solid #444; font-size: 12px; text-transform: uppercase; }
    .tp-table td { padding: 9px 12px; border-bottom: 1px solid #333; color: #ddd; }
    .tp-table tbody tr:hover { background: #252525; }
    .tp-rank { font-weight: 800; color: #f5c518; }
    .tp-bar-wrap { background: #333; border-radius: 4px; height: 8px; min-width: 80px; }
    .tp-bar { height: 8px; border-radius: 4px; background: #f5c518; }
</style>
<body>
<div class="rev-content" id="revContent">
   <h1>Revenue Management</h1>
    <div class="rev-header">
        <h1></h1>
        <button class="btn-export-pdf" id="btnExportPdf">🖨 Export PDF</button>
    </div>

    <!-- Year selector -->
    <div class="year-selector">
        <label>Year:</label>
        <select id="yearSelect"></select>
    </div>

    <!-- Summary cards -->
    <div class="summary-cards" id="summaryCards">
        <div class="s-card">
            <div class="sc-label">Today's Earnings</div>
            <div class="sc-value" id="todayTotal">₱0.00</div>
            <div class="sc-sub" id="todayTxn">0 transactions</div>
        </div>
        <div class="s-card blue">
            <div class="sc-label">This Month</div>
            <div class="sc-value" id="monthTotal">₱0.00</div>
            <div class="sc-sub" id="monthLabel">—</div>
        </div>
        <div class="s-card green">
            <div class="sc-label">This Year</div>
            <div class="sc-value" id="yearTotal">₱0.00</div>
            <div class="sc-sub" id="yearLabel">—</div>
        </div>
        <div class="s-card red">
            <div class="sc-label">All-Time Revenue</div>
            <div class="sc-value" id="allTimeTotal">₱0.00</div>
            <div class="sc-sub">Total recorded sales</div>
        </div>
    </div>

    <!-- Date Range Filter -->
    <div class="date-filter">
        <div class="df-group">
            <label>From</label>
            <input type="date" id="filterFrom">
        </div>
        <div class="df-group">
            <label>To</label>
            <input type="date" id="filterTo">
        </div>
        <button class="btn-filter" id="btnDateFilter">Filter</button>
    </div>
    <div class="filter-result" id="filterResult">
        <div style="color:#aaa;font-size:13px;margin-bottom:4px;" id="filterRangeLabel">—</div>
        <div class="fr-total" id="filterTotal">₱0.00</div>
        <div style="color:#888;font-size:12px;margin-top:4px;" id="filterTxnCount">0 transactions</div>
        <canvas id="chartRange" style="margin-top:16px;max-height:200px;"></canvas>
    </div>

    <!-- Charts -->
    <div class="charts-grid">
        <div class="chart-card">
            <h3>📅 Monthly Earnings <span id="chartYearLabel" style="color:#aaa;font-weight:400;font-size:13px;"></span></h3>
            <canvas id="chartMonthly"></canvas>
        </div>
        <div class="chart-card">
            <h3>📆 Yearly Earnings</h3>
            <canvas id="chartYearly"></canvas>
        </div>
        <div class="chart-card">
            <h3>💳 Payment Method Split <span id="payYearLabel" style="color:#aaa;font-weight:400;font-size:13px;"></span></h3>
            <canvas id="chartPayment"></canvas>
        </div>
        <div class="chart-card">
            <h3>🔝 Top Products Revenue <span id="topYearLabel" style="color:#aaa;font-weight:400;font-size:13px;"></span></h3>
            <canvas id="chartTopProducts"></canvas>
        </div>
    </div>

    <!-- Top Products Table -->
    <div class="top-products-card">
        <h3>🔝 Top 10 Products by Revenue</h3>
        <table class="tp-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>Qty Sold</th>
                    <th>Revenue</th>
                    <th style="width:140px;">Share</th>
                </tr>
            </thead>
            <tbody id="topProductsBody">
                <tr><td colspan="5" style="text-align:center;color:#666;padding:30px 0;">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
let chartMonthly = null, chartYearly = null, chartPayment = null, chartTopProducts = null, chartRange = null;
let topProductsData = [];
let currentYear = new Date().getFullYear();

function fmt(n) { return '₱' + parseFloat(n).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2}); }

// Populate year selector (current year back to 2020 or first sale year)
function populateYears() {
    const sel = document.getElementById('yearSelect');
    sel.innerHTML = '';
    for (let y = currentYear; y >= 2020; y--) {
        const o = document.createElement('option');
        o.value = y; o.textContent = y;
        if (y === currentYear) o.selected = true;
        sel.appendChild(o);
    }
}

function destroyChart(c) { if (c) { c.destroy(); } return null; }

function loadAll(year) {
    document.getElementById('chartYearLabel').textContent = year;
    document.getElementById('payYearLabel').textContent = year;
    document.getElementById('topYearLabel').textContent = year;
    document.getElementById('monthLabel').textContent = MONTHS[new Date().getMonth()] + ' ' + year;
    document.getElementById('yearLabel').textContent = year;
    loadToday();
    loadMonthly(year);
    loadYearly();
    loadPaymentSplit(year);
    loadTopProducts(year);
}

function loadToday() {
    $.getJSON('revenue.php?ajax_revenue=1&type=today', function(d) {
        document.getElementById('todayTotal').textContent = fmt(d.total);
        document.getElementById('todayTxn').textContent = d.txn_count + ' transaction' + (d.txn_count !== 1 ? 's' : '');
    });
}

function loadMonthly(year) {
    $.getJSON('revenue.php?ajax_revenue=1&type=monthly&year=' + year, function(d) {
        const thisMonth = new Date().getMonth();
        document.getElementById('monthTotal').textContent = fmt(d.months[thisMonth]);

        chartMonthly = destroyChart(chartMonthly);
        chartMonthly = new Chart(document.getElementById('chartMonthly'), {
            type: 'bar',
            data: {
                labels: MONTHS,
                datasets: [{
                    label: 'Revenue (₱)',
                    data: d.months,
                    backgroundColor: d.months.map((_, i) => i === thisMonth ? '#f5c518' : 'rgba(245,197,24,0.35)'),
                    borderRadius: 6
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#aaa' }, grid: { color: '#333' } },
                    y: { ticks: { color: '#aaa', callback: v => '₱' + v.toLocaleString() }, grid: { color: '#333' } }
                }
            }
        });
    });
}

function loadYearly() {
    $.getJSON('revenue.php?ajax_revenue=1&type=yearly', function(rows) {
        const thisYrTotal = rows.find(r => parseInt(r.yr) === currentYear);
        document.getElementById('yearTotal').textContent = fmt(thisYrTotal ? thisYrTotal.total : 0);
        const allTime = rows.reduce((s, r) => s + parseFloat(r.total), 0);
        document.getElementById('allTimeTotal').textContent = fmt(allTime);

        chartYearly = destroyChart(chartYearly);
        chartYearly = new Chart(document.getElementById('chartYearly'), {
            type: 'line',
            data: {
                labels: rows.map(r => r.yr),
                datasets: [{
                    label: 'Revenue (₱)',
                    data: rows.map(r => parseFloat(r.total)),
                    borderColor: '#f5c518',
                    backgroundColor: 'rgba(245,197,24,0.12)',
                    tension: 0.35,
                    pointBackgroundColor: '#f5c518',
                    fill: true
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#aaa' }, grid: { color: '#333' } },
                    y: { ticks: { color: '#aaa', callback: v => '₱' + v.toLocaleString() }, grid: { color: '#333' } }
                }
            }
        });
    });
}

function loadPaymentSplit(year) {
    $.getJSON('revenue.php?ajax_revenue=1&type=payment_split&year=' + year, function(rows) {
        chartPayment = destroyChart(chartPayment);
        if (!rows.length) {
            document.getElementById('chartPayment').parentElement.querySelector('canvas').style.display = 'none';
            return;
        }
        chartPayment = new Chart(document.getElementById('chartPayment'), {
            type: 'doughnut',
            data: {
                labels: rows.map(r => r.payment_method === 'cash' ? '💵 Cash' : '💳 Card'),
                datasets: [{
                    data: rows.map(r => parseFloat(r.total)),
                    backgroundColor: ['#388e3c','#1976d2'],
                    borderWidth: 0
                }]
            },
            options: {
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#ddd', padding: 14 } },
                    tooltip: { callbacks: { label: ctx => ' ' + fmt(ctx.raw) } }
                }
            }
        });
    });
}

function loadTopProducts(year) {
    $.getJSON('revenue.php?ajax_revenue=1&type=top_products&year=' + year, function(rows) {
        topProductsData = rows;
        renderTopTable(rows);

        chartTopProducts = destroyChart(chartTopProducts);
        if (!rows.length) return;
        chartTopProducts = new Chart(document.getElementById('chartTopProducts'), {
            type: 'bar',
            indexAxis: 'y',
            data: {
                labels: rows.map(r => r.product_name),
                datasets: [{
                    label: 'Revenue (₱)',
                    data: rows.map(r => parseFloat(r.total_revenue)),
                    backgroundColor: 'rgba(245,197,24,0.7)',
                    borderRadius: 4
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#aaa', callback: v => '₱' + v.toLocaleString() }, grid: { color: '#333' } },
                    y: { ticks: { color: '#ddd' }, grid: { color: '#333' } }
                }
            }
        });
    });
}

function renderTopTable(rows) {
    if (!rows.length) {
        document.getElementById('topProductsBody').innerHTML = '<tr><td colspan="5" style="text-align:center;color:#666;padding:20px 0;">No data for this period.</td></tr>';
        return;
    }
    const maxRev = Math.max(...rows.map(r => parseFloat(r.total_revenue)));
    let html = '';
    rows.forEach((r, i) => {
        const pct = maxRev > 0 ? (parseFloat(r.total_revenue) / maxRev * 100).toFixed(0) : 0;
        html += `<tr>
            <td class="tp-rank">${i + 1}</td>
            <td>${r.product_name}</td>
            <td style="text-align:center;">${r.total_qty}</td>
            <td style="color:#f5c518;font-weight:700;">${fmt(r.total_revenue)}</td>
            <td><div class="tp-bar-wrap"><div class="tp-bar" style="width:${pct}%"></div></div></td>
        </tr>`;
    });
    document.getElementById('topProductsBody').innerHTML = html;
}

// Date range filter
$('#btnDateFilter').on('click', function() {
    const from = $('#filterFrom').val();
    const to   = $('#filterTo').val();
    if (!from || !to) { alert('Please select both From and To dates.'); return; }
    if (from > to) { alert('"From" date must be before "To" date.'); return; }
    $.getJSON('revenue.php?ajax_revenue=1&type=range&from=' + from + '&to=' + to, function(d) {
        $('#filterRangeLabel').text(from + '  →  ' + to);
        $('#filterTotal').text(fmt(d.total));
        $('#filterTxnCount').text(d.txn_count + ' transaction' + (d.txn_count !== 1 ? 's' : ''));
        $('#filterResult').show();

        chartRange = destroyChart(chartRange);
        if (d.daily && d.daily.length > 1) {
            chartRange = new Chart(document.getElementById('chartRange'), {
                type: 'line',
                data: {
                    labels: d.daily.map(r => r.day),
                    datasets: [{
                        label: 'Daily Revenue',
                        data: d.daily.map(r => parseFloat(r.day_total)),
                        borderColor: '#f5c518',
                        backgroundColor: 'rgba(245,197,24,0.1)',
                        tension: 0.3,
                        fill: true,
                        pointBackgroundColor: '#f5c518'
                    }]
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: '#aaa' }, grid: { color: '#2a2a2a' } },
                        y: { ticks: { color: '#aaa', callback: v => '₱' + v.toLocaleString() }, grid: { color: '#2a2a2a' } }
                    }
                }
            });
            document.getElementById('chartRange').style.display = 'block';
        } else {
            document.getElementById('chartRange').style.display = 'none';
        }
    });
});

// Year change
$('#yearSelect').on('change', function() {
    currentYear = parseInt($(this).val());
    loadAll(currentYear);
});

// Export PDF
$('#btnExportPdf').on('click', function() {
    const btn = $(this);
    btn.text('Generating...').prop('disabled', true);
    html2canvas(document.getElementById('revContent'), {
        backgroundColor: '#1e1e1e',
        scale: 1.5,
        useCORS: true,
        scrollY: -window.scrollY
    }).then(canvas => {
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('p', 'mm', 'a4');
        const pageW = pdf.internal.pageSize.getWidth();
        const pageH = pdf.internal.pageSize.getHeight();
        const ratio = canvas.width / canvas.height;
        let imgW = pageW - 20;
        let imgH = imgW / ratio;
        let y = 10;
        // Add title
        pdf.setFontSize(16);
        pdf.setTextColor(245, 197, 24);
        pdf.text('Revenue Report — ' + currentYear, 10, y);
        y += 8;
        pdf.setFontSize(9);
        pdf.setTextColor(150, 150, 150);
        pdf.text('Generated: ' + new Date().toLocaleString(), 10, y);
        y += 5;
        // Multi-page support
        while (imgH > 0) {
            pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 10, y, imgW, Math.min(imgH, pageH - y - 10));
            imgH -= (pageH - y - 10);
            if (imgH > 0) { pdf.addPage(); y = 10; }
        }
        pdf.save('revenue-report-' + currentYear + '.pdf');
        btn.text('🖨 Export PDF').prop('disabled', false);
    });
});

// Init
populateYears();
// Set default date filter to current month
const now = new Date();
const y = now.getFullYear(), m = String(now.getMonth()+1).padStart(2,'0');
document.getElementById('filterFrom').value = y + '-' + m + '-01';
document.getElementById('filterTo').value   = now.toISOString().split('T')[0];

loadAll(currentYear);
</script>
</body>
</html>
