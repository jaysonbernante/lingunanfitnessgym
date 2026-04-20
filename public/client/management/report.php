<?php
require_once '../../../app/config/connection.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Ensure wallet transaction log exists for credit adjustments and history
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

$page = 'report';
include '../../../component/admin_header.php';
include '../../../component/admin_sidebar.php';

$startDate = trim($_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days')));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
$search = trim($_GET['search'] ?? '');
$searchTerm = '%' . $search . '%';

$sql = "SELECT date, source, description, COALESCE(amount,0) AS amount, member_name, payment_method, operator FROM (
    SELECT wt.created_at AS date,
           'Wallet' AS source,
           CASE WHEN wt.transaction_type = 'credit_add' THEN 'Credit Input'
                WHEN wt.transaction_type = 'refund' THEN 'Refund'
                WHEN wt.transaction_type = 'correction' THEN 'Correction'
                ELSE wt.transaction_type END AS description,
           wt.amount,
           CONCAT(m.first_name, ' ', m.last_name) AS member_name,
           'wallet' AS payment_method,
           wt.created_by AS operator
    FROM wallet_transactions wt
    LEFT JOIN members m ON m.id = wt.member_id
    WHERE DATE(wt.created_at) BETWEEN ? AND ?
    UNION ALL
    SELECT s.sold_at AS date,
           'Ecommerce' AS source,
           CONCAT('Sale: ', s.product_name, ' x', s.qty_sold) AS description,
           s.total AS amount,
           COALESCE(s.member_name, 'Guest') AS member_name,
           s.payment_method,
           COALESCE(s.transacted_by, 'System') AS operator
    FROM sales s
    WHERE DATE(s.sold_at) BETWEEN ? AND ?
    UNION ALL
    SELECT e.entry_time AS date,
           'Entry' AS source,
           CONCAT('Entry: ', e.entry_type) AS description,
           e.amount_charged AS amount,
           COALESCE(e.member_name, 'Guest') AS member_name,
           e.payment_method,
           'System' AS operator
    FROM entry_logs e
    WHERE DATE(e.entry_time) BETWEEN ? AND ?
) t
";
$params = [$startDate, $endDate, $startDate, $endDate, $startDate, $endDate];
if ($search !== '') {
    $sql .= " AND (source LIKE ? OR description LIKE ? OR member_name LIKE ? OR payment_method LIKE ? OR operator LIKE ? )";
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}
$sql .= " ORDER BY date DESC LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transaction Report</title>
    <link href="../../../assets/css/toastednotif.css" rel="stylesheet">
    <link href="../../../assets/css/admin_header.css" rel="stylesheet">
    <link href="../../../assets/css/admin_sidebar.css" rel="stylesheet">
    <link href="../../../assets/css/admin.css" rel="stylesheet">
    <style>
        .report-content {
            margin-left: 250px;
            margin-top: 60px;
            padding: 24px;
            min-height: calc(100vh - 60px);
            background: #222;
            color: #fff;
        }
        .report-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
            margin-bottom: 20px;
        }
        .report-controls label {
            display: flex;
            flex-direction: column;
            font-size: 13px;
            color: #bbb;
        }
        .report-controls input[type="date"],
        .report-controls input[type="text"],
        .report-controls button {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #444;
            background: #1a1a1a;
            color: #fff;
            font-size: 14px;
        }
        .report-controls button {
            background: #1976d2;
            border-color: #1976d2;
            cursor: pointer;
        }
        .report-controls button:hover {
            background: #1565c0;
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            min-width: 960px;
        }
        .report-table th, .report-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #333;
            color: #ddd;
            text-align: left;
            font-size: 14px;
        }
        .report-table th { background: #111; color: #f5c518; }
        .report-table tbody tr:hover { background: rgba(255,255,255,0.04); }
        .badge-source {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        .badge-source.wallet { background: #2e7d32; color: #e8f5e9; }
        .badge-source.ecommerce { background: #1976d2; color: #e3f2fd; }
        .badge-source.entry { background: #f57c00; color: #fff3e0; }
        .report-summary { margin-top: 16px; color: #bbb; font-size: 13px; }
        @media (max-width: 980px) {
            .report-content { margin-left: 0; padding: 16px; }
            .report-table { min-width: 0; }
        }
    </style>
</head>
<body>
    <div class="report-content">
        <h1>Transaction Report</h1>
        <div class="report-controls">
            <label>Start Date
                <input type="date" id="startDate" value="<?= htmlspecialchars($startDate) ?>">
            </label>
            <label>End Date
                <input type="date" id="endDate" value="<?= htmlspecialchars($endDate) ?>">
            </label>
            <label>Search
                <input type="text" id="searchTerm" placeholder="Member, description, payment, operator" value="<?= htmlspecialchars($search) ?>">
            </label>
            <button id="refreshReport">Refresh</button>
        </div>
        <div class="report-summary">
            Showing <?= count($transactions) ?> transaction<?= count($transactions) === 1 ? '' : 's' ?> from <?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?>.
        </div>
        <div style="overflow-x:auto;">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Source</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Member</th>
                        <th>Payment</th>
                        <th>Operator</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr><td colspan="7" style="text-align:center;color:#888;padding:24px;">No transactions found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $txn): ?>
                            <tr>
                                <td><?= htmlspecialchars($txn['date']) ?></td>
                                <td><span class="badge-source <?= strtolower($txn['source']) ?>"><?= htmlspecialchars($txn['source']) ?></span></td>
                                <td><?= htmlspecialchars($txn['description']) ?></td>
                                <td><?= $txn['amount'] >= 0 ? '₱' . number_format($txn['amount'],2) : '-₱' . number_format(abs($txn['amount']),2) ?></td>
                                <td><?= htmlspecialchars($txn['member_name']) ?></td>
                                <td><?= htmlspecialchars($txn['payment_method']) ?></td>
                                <td><?= htmlspecialchars($txn['operator']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script>
        document.getElementById('refreshReport').addEventListener('click', function() {
            const params = new URLSearchParams();
            params.set('start_date', document.getElementById('startDate').value);
            params.set('end_date', document.getElementById('endDate').value);
            params.set('search', document.getElementById('searchTerm').value.trim());
            window.location.search = params.toString();
        });
    </script>
</body>
</html>
