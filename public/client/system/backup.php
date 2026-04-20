<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../../app/config/connection.php';

// ── Config ────────────────────────────────────────────────────────────────
define('BACKUP_DIR', __DIR__ . '/../../../backups/');
if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);

// Table metadata: importance + description
$TABLE_META = [
    'members'       => ['label'=>'Members',       'importance'=>'critical', 'desc'=>'All gym members, credits, RFID, membership info'],
    'entry_logs'    => ['label'=>'Entry Logs',    'importance'=>'critical', 'desc'=>'All gym entries (RFID taps, walk-ins, session records)'],
    'sales'         => ['label'=>'Sales',         'importance'=>'critical', 'desc'=>'All ecommerce sales transactions'],
    'users'         => ['label'=>'Staff / Users',  'importance'=>'high',     'desc'=>'Admin and staff login accounts'],
    'products'      => ['label'=>'Products',      'importance'=>'high',     'desc'=>'Product inventory with prices and stock'],
    'blocked_rfids' => ['label'=>'Blocked RFIDs', 'importance'=>'normal',   'desc'=>'List of blocked/lost RFID cards'],
];

// ── Helpers ───────────────────────────────────────────────────────────────
function getDumpSQL($pdo, array $tables, string $filterType = '', string $filterValue = ''): string {
    $sql = "-- Lingunan Fitness Gym — Database Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Filter: " . ($filterType ? "$filterType = $filterValue" : 'ALL records') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // Structure
        $createRow = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $createSQL = $createRow['Create Table'] ?? $createRow[array_key_last($createRow)];
        $sql .= "-- Table: $table\n";
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql .= $createSQL . ";\n\n";

        // Data — apply date filter only to tables that have a date column
        $dateColumns = [
            'entry_logs' => 'entry_time',
            'sales'      => 'sold_at',
            'members'    => 'Joined_Date',
        ];
        $where = '';
        if ($filterType && isset($dateColumns[$table])) {
            $col = $dateColumns[$table];
            if ($filterType === 'date')  $where = " WHERE DATE(`$col`) = " . $pdo->quote($filterValue);
            if ($filterType === 'month') $where = " WHERE DATE_FORMAT(`$col`,'%Y-%m') = " . $pdo->quote($filterValue);
            if ($filterType === 'year')  $where = " WHERE YEAR(`$col`) = " . intval($filterValue);
        }

        $rows = $pdo->query("SELECT * FROM `$table`$where")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
            $sql .= "INSERT INTO `$table` ($cols) VALUES\n";
            $chunks = [];
            foreach ($rows as $row) {
                $vals = array_map(function($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote($v);
                }, array_values($row));
                $chunks[] = '(' . implode(', ', $vals) . ')';
            }
            $sql .= implode(",\n", $chunks) . ";\n\n";
        } else {
            $sql .= "-- (no rows match filter for $table)\n\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

function getBackupHistory(): array {
    $files = glob(BACKUP_DIR . '*.sql');
    if (!$files) return [];
    usort($files, function($a, $b){ return filemtime($b) - filemtime($a); });
    $result = [];
    foreach (array_slice($files, 0, 30) as $f) {
        $result[] = [
            'name'    => basename($f),
            'size'    => round(filesize($f) / 1024, 1),
            'time'    => date('d M Y H:i', filemtime($f)),
            'ts'      => filemtime($f),
        ];
    }
    return $result;
}

// ── AJAX: Export ──────────────────────────────────────────────────────────
if (isset($_POST['ajax_export'])) {
    header('Content-Type: application/json');
    $tables      = $_POST['tables'] ?? [];
    $filterType  = trim($_POST['filter_type'] ?? '');
    $filterValue = trim($_POST['filter_value'] ?? '');

    // Validate table names
    $allTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $tables = array_filter($tables, function($t) use ($allTables){ return in_array($t, $allTables, true); });
    if (empty($tables)) { echo json_encode(['success'=>false,'message'=>'No valid tables selected.']); exit; }

    try {
        $sql = getDumpSQL($pdo, $tables, $filterType, $filterValue);
        $label = $filterType && $filterValue ? "{$filterType}_{$filterValue}" : 'full';
        $filename = 'backup_' . $label . '_' . date('Ymd_His') . '.sql';
        file_put_contents(BACKUP_DIR . $filename, $sql);

        // Record last backup time in session for notification
        $_SESSION['last_backup'] = time();

        echo json_encode(['success'=>true,'filename'=>$filename,'size'=>round(strlen($sql)/1024,1)]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX: Download file ───────────────────────────────────────────────────
if (isset($_GET['ajax_download'])) {
    $name = basename($_GET['file'] ?? '');
    $path = BACKUP_DIR . $name;
    if (!$name || !file_exists($path) || pathinfo($name, PATHINFO_EXTENSION) !== 'sql') {
        http_response_code(404); echo 'File not found.'; exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── AJAX: Delete backup file ──────────────────────────────────────────────
if (isset($_POST['ajax_delete_backup'])) {
    header('Content-Type: application/json');
    $name = basename($_POST['file'] ?? '');
    $path = BACKUP_DIR . $name;
    if (!$name || pathinfo($name, PATHINFO_EXTENSION) !== 'sql' || !file_exists($path)) {
        echo json_encode(['success'=>false,'message'=>'File not found.']); exit;
    }
    unlink($path);
    echo json_encode(['success'=>true]);
    exit;
}

// ── AJAX: Import (restore) ────────────────────────────────────────────────
if (isset($_POST['ajax_import'])) {
    header('Content-Type: application/json');
    if (empty($_FILES['sql_file']['tmp_name'])) {
        echo json_encode(['success'=>false,'message'=>'No file uploaded.']); exit;
    }
    $ext = strtolower(pathinfo($_FILES['sql_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'sql') {
        echo json_encode(['success'=>false,'message'=>'Only .sql files are allowed.']); exit;
    }
    $sql = file_get_contents($_FILES['sql_file']['tmp_name']);
    if (!$sql) { echo json_encode(['success'=>false,'message'=>'Empty or unreadable file.']); exit; }

    try {
        $pdo->exec($sql);
        echo json_encode(['success'=>true]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── Check if backup reminder needed (not backed up in >30 days) ───────────
$needsBackupReminder = false;
$lastBackup = $_SESSION['last_backup'] ?? 0;
if ((time() - $lastBackup) > (30 * 24 * 3600)) {
    // Also check newest file on disk
    $files = glob(BACKUP_DIR . '*.sql');
    if ($files) {
        $newest = max(array_map('filemtime', $files));
        $needsBackupReminder = (time() - $newest) > (30 * 24 * 3600);
    } else {
        $needsBackupReminder = true;
    }
}

$backupHistory = getBackupHistory();
$allTables     = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

$page = 'backup';
include '../../../component/admin_header.php';
include '../../../component/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup System</title>
    <link href="../../../assets/css/toastednotif.css" rel="stylesheet">
    <link href="../../../assets/css/admin_header.css" rel="stylesheet">
    <link href="../../../assets/css/admin_sidebar.css" rel="stylesheet">
    <link href="../../../assets/css/admin.css" rel="stylesheet">
</head>
<style>
    .bk-wrap { padding: 28px 32px; max-width: 1100px; }

    /* ── Monthly reminder banner ─────────────────────────────────── */
    .bk-reminder {
        background: rgba(245,124,0,.12); border: 1px solid #f57c00;
        border-radius: 12px; padding: 14px 20px; display: flex;
        align-items: center; gap: 14px; margin-bottom: 24px;
    }
    .bk-reminder-icon { font-size: 1.8rem; flex-shrink: 0; }
    .bk-reminder-text h4 { margin: 0 0 2px; color: #ffcc80; font-size: 14px; font-weight: 700; }
    .bk-reminder-text p  { margin: 0; color: #aaa; font-size: 13px; }

    /* ── Two-column layout ───────────────────────────────────────── */
    .bk-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-bottom: 22px; }
    @media (max-width: 900px) { .bk-grid { grid-template-columns: 1fr; } }

    /* ── Panel ───────────────────────────────────────────────────── */
    .bk-panel { background: #1e1e1e; border-radius: 14px; overflow: hidden; }
    .bk-panel-header {
        padding: 14px 20px; border-bottom: 1px solid #2a2a2a;
        display: flex; align-items: center; gap: 10px;
    }
    .bk-panel-header h3 { margin: 0; font-size: 14px; font-weight: 700; color: #fff; }
    .bk-panel-header .ph-sub { font-size: 12px; color: #555; margin-left: auto; }
    .bk-panel-body { padding: 20px; }

    /* ── Table selection ─────────────────────────────────────────── */
    .table-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 18px; }
    .table-card {
        border: 2px solid #2a2a2a; border-radius: 10px; padding: 12px 14px;
        cursor: pointer; transition: border-color .18s, background .18s;
        display: flex; align-items: flex-start; gap: 10px;
        background: #161616;
    }
    .table-card:hover { border-color: #444; background: #1a1a1a; }
    .table-card.selected { border-color: #1976d2; background: rgba(25,118,210,.08); }
    .table-card input[type=checkbox] { margin: 3px 0 0; flex-shrink: 0; accent-color: #1976d2; width:15px; height:15px; }
    .table-card-info { flex: 1; min-width: 0; }
    .table-card-name { font-size: 13px; font-weight: 700; color: #fff; margin-bottom: 3px; display: flex; align-items: center; gap: 6px; }
    .table-card-desc { font-size: 11px; color: #666; line-height: 1.4; }
    .imp-badge {
        display: inline-block; padding: 1px 7px; border-radius: 6px;
        font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
    }
    .imp-critical { background: rgba(229,57,53,.2);  color: #ef9a9a; }
    .imp-high     { background: rgba(245,197,24,.15); color: #f5c518; }
    .imp-normal   { background: rgba(100,100,100,.25); color: #aaa; }

    /* ── Filter row ──────────────────────────────────────────────── */
    .filter-row { display: flex; gap: 10px; align-items: center; margin-bottom: 18px; flex-wrap: wrap; }
    .filter-row label { font-size: 13px; color: #aaa; font-weight: 600; }
    .filter-row select, .filter-row input {
        padding: 8px 12px; border-radius: 8px; border: 1px solid #333;
        background: #111; color: #fff; font-size: 13px;
    }
    .filter-row select:focus, .filter-row input:focus { outline: none; border-color: #1976d2; }
    #filterValueWrap { display: none; }

    /* ── Buttons ─────────────────────────────────────────────────── */
    .btn-export {
        width: 100%; padding: 12px; border-radius: 10px; border: none;
        background: #1976d2; color: #fff; font-size: 15px; font-weight: 700;
        cursor: pointer; transition: background .18s;
    }
    .btn-export:hover { background: #1565c0; }
    .btn-export:disabled { background: #333; color: #555; cursor: not-allowed; }
    .btn-select-all {
        background: none; border: 1px solid #333; color: #aaa;
        border-radius: 7px; padding: 5px 12px; font-size: 12px; cursor: pointer;
        margin-bottom: 10px; transition: border-color .15s, color .15s;
    }
    .btn-select-all:hover { border-color: #1976d2; color: #90caf9; }

    /* ── Import section ──────────────────────────────────────────── */
    .drop-zone {
        border: 2px dashed #333; border-radius: 12px; padding: 32px 20px;
        text-align: center; cursor: pointer; transition: border-color .2s, background .2s;
        background: #161616; margin-bottom: 14px;
    }
    .drop-zone:hover, .drop-zone.dragover { border-color: #1976d2; background: rgba(25,118,210,.06); }
    .drop-zone .dz-icon  { font-size: 2.2rem; margin-bottom: 8px; }
    .drop-zone .dz-text  { font-size: 13px; color: #666; }
    .drop-zone .dz-file  { font-size: 13px; font-weight: 700; color: #90caf9; margin-top: 6px; display: none; }
    .btn-import {
        width: 100%; padding: 11px; border-radius: 10px; border: none;
        background: #388e3c; color: #fff; font-size: 14px; font-weight: 700;
        cursor: pointer; transition: background .18s;
    }
    .btn-import:hover { background: #2e7d32; }
    .btn-import:disabled { background: #333; color: #555; cursor: not-allowed; }
    .import-warning {
        background: rgba(229,57,53,.1); border: 1px solid #e53935;
        border-radius: 8px; padding: 10px 14px; font-size: 12px; color: #ef9a9a;
        margin-bottom: 14px; line-height: 1.55;
    }

    /* ── History table ───────────────────────────────────────────── */
    .bk-hist-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .bk-hist-table th { color: #555; font-weight: 600; padding: 9px 12px; text-align: left; border-bottom: 1px solid #2a2a2a; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; }
    .bk-hist-table td { padding: 10px 12px; border-bottom: 1px solid #222; color: #ccc; }
    .bk-hist-table tr:last-child td { border-bottom: none; }
    .bk-hist-table tbody tr:hover td { background: rgba(255,255,255,.025); }
    .btn-dl  { padding: 5px 12px; border-radius: 6px; border: none; background: #1976d2; color: #fff; font-size: 12px; font-weight: 600; cursor: pointer; }
    .btn-del { padding: 5px 10px; border-radius: 6px; border: none; background: rgba(229,57,53,.18); color: #ef9a9a; font-size: 12px; font-weight: 600; cursor: pointer; margin-left: 4px; }
    .btn-dl:hover  { background: #1565c0; }
    .btn-del:hover { background: rgba(229,57,53,.35); }
    .bk-empty { text-align: center; padding: 30px; color: #444; font-size: 13px; }

    /* ── Progress overlay ────────────────────────────────────────── */
    .bk-progress-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,.75); z-index: 3000;
        justify-content: center; align-items: center;
    }
    .bk-progress-overlay.active { display: flex; }
    .bk-progress-box {
        background: #2c2c2c; border-radius: 16px; padding: 32px 40px;
        text-align: center; min-width: 300px;
    }
    .bk-progress-box .pb-icon { font-size: 3rem; margin-bottom: 12px; }
    .bk-progress-box h3 { margin: 0 0 8px; color: #fff; }
    .bk-progress-box p  { color: #aaa; font-size: 14px; margin: 0; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .spinner-lg { display: inline-block; width: 40px; height: 40px; border: 4px solid #333; border-top-color: #1976d2; border-radius: 50%; animation: spin .7s linear infinite; }

    /* ── Confirm modal ───────────────────────────────────────────── */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.7); z-index: 2000; justify-content: center; align-items: center; }
    .modal-overlay.active { display: flex; }
    .modal-box { background: #2c2c2c; border-radius: 14px; padding: 28px 30px; max-width: 380px; width: 90%; color: #fff; }
    .modal-box h3 { margin: 0 0 8px; }
    .modal-box p  { color: #aaa; font-size: 14px; margin: 0 0 18px; }
    .modal-actions { display: flex; gap: 10px; }
    .btn-danger { padding: 9px 20px; border-radius: 8px; border: none; background: #d32f2f; color: #fff; font-weight: 600; cursor: pointer; }
    .btn-grey   { padding: 9px 20px; border-radius: 8px; border: none; background: #444; color: #fff; font-weight: 600; cursor: pointer; }
</style>
<body>
<?php // Headers already included by admin_header.php above ?>
<div class="dashboard-content">
<div class="bk-wrap">

    <div style="margin-bottom:22px;">
        <h2 style="margin:0 0 4px; font-size:1.4rem;">&#128190; Backup &amp; Restore</h2>
        <p style="margin:0; color:#555; font-size:13px;">Export selected tables as .sql file, or restore data from a previously saved backup.</p>
    </div>

    <!-- Monthly reminder banner -->
    <?php if ($needsBackupReminder): ?>
    <div class="bk-reminder">
        <div class="bk-reminder-icon">&#9888;&#65039;</div>
        <div class="bk-reminder-text">
            <h4>Monthly Backup Reminder</h4>
            <p>It has been more than 30 days since the last backup. We recommend exporting a full backup now to protect your data.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="bk-grid">

        <!-- ── Export Panel ── -->
        <div class="bk-panel">
            <div class="bk-panel-header">
                <span style="font-size:1.3rem;">&#128229;</span>
                <h3>Export Backup</h3>
            </div>
            <div class="bk-panel-body">

                <!-- Table selection -->
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                    <div style="font-size:13px; color:#aaa; font-weight:600;">Select tables to include:</div>
                    <button type="button" class="btn-select-all" id="btnSelectAll">Select All</button>
                </div>
                <div class="table-grid" id="tableGrid">
                    <?php foreach ($TABLE_META as $tbl => $meta): ?>
                    <?php if (!in_array($tbl, $allTables, true)) continue; ?>
                    <div class="table-card selected" data-table="<?php echo $tbl; ?>">
                        <input type="checkbox" checked value="<?php echo $tbl; ?>" class="tbl-chk">
                        <div class="table-card-info">
                            <div class="table-card-name">
                                <?php echo htmlspecialchars($meta['label']); ?>
                                <span class="imp-badge imp-<?php echo $meta['importance']; ?>"><?php echo $meta['importance']; ?></span>
                            </div>
                            <div class="table-card-desc"><?php echo htmlspecialchars($meta['desc']); ?></div>
                        </div>
                    </div>
                    <?php endforeach;
                    // Any tables not in meta
                    foreach ($allTables as $tbl):
                        if (isset($TABLE_META[$tbl])) continue; ?>
                    <div class="table-card" data-table="<?php echo htmlspecialchars($tbl); ?>">
                        <input type="checkbox" value="<?php echo htmlspecialchars($tbl); ?>" class="tbl-chk">
                        <div class="table-card-info">
                            <div class="table-card-name"><?php echo htmlspecialchars($tbl); ?></div>
                            <div class="table-card-desc">—</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Date filter -->
                <div style="font-size:13px; color:#aaa; font-weight:600; margin-bottom:10px;">Filter by date <span style="color:#555; font-weight:400;">(applies to entry_logs, sales, members)</span></div>
                <div class="filter-row">
                    <select id="filterType">
                        <option value="">All records</option>
                        <option value="date">Specific date</option>
                        <option value="month">Month</option>
                        <option value="year">Year</option>
                    </select>
                    <div id="filterValueWrap"></div>
                </div>

                <button type="button" class="btn-export" id="btnExport">&#128229; Export &amp; Save Backup</button>
            </div>
        </div>

        <!-- ── Import Panel ── -->
        <div class="bk-panel">
            <div class="bk-panel-header">
                <span style="font-size:1.3rem;">&#128228;</span>
                <h3>Import / Restore</h3>
            </div>
            <div class="bk-panel-body">
                <div class="import-warning">
                    &#9888;&#65039; <strong>Warning:</strong> Importing will <strong>replace</strong> existing table data with the backup file contents. This cannot be undone. Make sure to export a current backup first.
                </div>
                <div class="drop-zone" id="dropZone">
                    <div class="dz-icon">&#128196;</div>
                    <div class="dz-text">Drag &amp; drop a <strong>.sql</strong> backup file here<br>or click to browse</div>
                    <div class="dz-file" id="dzFileName"></div>
                    <input type="file" id="importFileInput" accept=".sql" style="display:none;">
                </div>
                <button type="button" class="btn-import" id="btnImport" disabled>&#128228; Restore from Backup</button>

                <div style="margin-top:18px; padding-top:16px; border-top:1px solid #2a2a2a;">
                    <div style="font-size:12px; color:#555; line-height:1.7;">
                        <strong style="color:#aaa;">How to restore:</strong><br>
                        1. Download a backup file from the history below.<br>
                        2. Upload it using the form above.<br>
                        3. Click "Restore from Backup" to apply.<br>
                        4. The system will reload automatically after restore.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Backup History ── -->
    <div class="bk-panel">
        <div class="bk-panel-header">
            <span style="font-size:1.2rem;">&#128203;</span>
            <h3>Backup History</h3>
            <span class="ph-sub"><?php echo count($backupHistory); ?> file<?php echo count($backupHistory)!==1?'s':''; ?> stored</span>
        </div>
        <div id="historyWrap">
            <?php if (empty($backupHistory)): ?>
            <div class="bk-empty">No backups yet. Export your first backup above.</div>
            <?php else: ?>
            <table class="bk-hist-table">
                <thead><tr>
                    <th>Filename</th><th>Size</th><th>Created</th><th>Actions</th>
                </tr></thead>
                <tbody id="historyBody">
                <?php foreach ($backupHistory as $bk): ?>
                <tr data-file="<?php echo htmlspecialchars($bk['name']); ?>">
                    <td style="font-family:monospace; color:#90caf9;"><?php echo htmlspecialchars($bk['name']); ?></td>
                    <td><?php echo $bk['size']; ?> KB</td>
                    <td><?php echo $bk['time']; ?></td>
                    <td>
                        <a href="backup.php?ajax_download=1&file=<?php echo urlencode($bk['name']); ?>" class="btn-dl">&#8681; Download</a>
                        <button type="button" class="btn-del" data-file="<?php echo htmlspecialchars($bk['name']); ?>">&#128465; Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>

<!-- Progress overlay -->
<div class="bk-progress-overlay" id="progressOverlay">
    <div class="bk-progress-box">
        <div><span class="spinner-lg"></span></div>
        <h3 id="progressTitle" style="margin-top:16px;">Processing&hellip;</h3>
        <p id="progressSub">Please wait, do not close this page.</p>
    </div>
</div>

<!-- Confirm delete modal -->
<div class="modal-overlay" id="confirmDeleteModal">
    <div class="modal-box">
        <h3>&#128465; Delete Backup</h3>
        <p id="confirmDeleteMsg">Are you sure you want to delete this backup file? It cannot be recovered.</p>
        <div class="modal-actions">
            <button class="btn-danger" id="btnConfirmDelete">Delete</button>
            <button class="btn-grey"   id="btnCancelDelete">Cancel</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
(function(){

var toastTimer;
function showToast(msg, type) {
    type = type || 'error';
    var t = document.getElementById('toastNotif');
    if (!t) { t = document.createElement('div'); t.id = 'toastNotif'; t.className = 'toast'; document.body.appendChild(t); }
    t.className = 'toast' + (type === 'success' ? ' success' : '');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function(){ t.classList.remove('show'); }, 4000);
}

function showProgress(title, sub) {
    $('#progressTitle').text(title || 'Processing…');
    $('#progressSub').text(sub || 'Please wait, do not close this page.');
    $('#progressOverlay').addClass('active');
}
function hideProgress() { $('#progressOverlay').removeClass('active'); }

// ── Table card selection ──────────────────────────────────────────
$(document).on('click', '.table-card', function(e) {
    if ($(e.target).is('input')) return;
    var chk = $(this).find('.tbl-chk');
    chk.prop('checked', !chk.prop('checked'));
    $(this).toggleClass('selected', chk.prop('checked'));
});
$(document).on('change', '.tbl-chk', function() {
    $(this).closest('.table-card').toggleClass('selected', $(this).prop('checked'));
});

var allSelected = true;
$('#btnSelectAll').on('click', function() {
    allSelected = !allSelected;
    $('.tbl-chk').prop('checked', allSelected);
    $('.table-card').toggleClass('selected', allSelected);
    $(this).text(allSelected ? 'Deselect All' : 'Select All');
});

// ── Date filter ───────────────────────────────────────────────────
$('#filterType').on('change', function() {
    var val = $(this).val();
    var wrap = $('#filterValueWrap');
    wrap.empty();
    if (!val) { wrap.hide(); return; }
    if (val === 'date') {
        wrap.html('<input type="date" id="filterValue" value="' + new Date().toISOString().slice(0,10) + '">');
    } else if (val === 'month') {
        wrap.html('<input type="month" id="filterValue" value="' + new Date().toISOString().slice(0,7) + '">');
    } else if (val === 'year') {
        var y = new Date().getFullYear();
        var opts = '';
        for (var i = y; i >= y-5; i--) opts += '<option value="'+i+'"'+(i===y?' selected':'')+'>'+i+'</option>';
        wrap.html('<select id="filterValue">' + opts + '</select>');
        wrap.find('select').css({padding:'8px 12px',borderRadius:'8px',border:'1px solid #333',background:'#111',color:'#fff',fontSize:'13px'});
    }
    wrap.show();
});

// ── Export ────────────────────────────────────────────────────────
$('#btnExport').on('click', function() {
    var tables = [];
    $('.tbl-chk:checked').each(function(){ tables.push($(this).val()); });
    if (!tables.length) { showToast('Please select at least one table.'); return; }

    var filterType  = $('#filterType').val();
    var filterValue = $('#filterValue').val() || '';

    showProgress('Generating Backup…', 'Exporting ' + tables.length + ' table(s), please wait.');

    $.ajax({
        url: 'backup.php', method: 'POST', dataType: 'json',
        data: { ajax_export: 1, tables: tables, filter_type: filterType, filter_value: filterValue },
        success: function(res) {
            hideProgress();
            if (res.success) {
                showToast('Backup saved: ' + res.filename + ' (' + res.size + ' KB)', 'success');
                // Add to history
                var row = '<tr data-file="'+res.filename+'">'
                    + '<td style="font-family:monospace;color:#90caf9;">'+res.filename+'</td>'
                    + '<td>'+res.size+' KB</td>'
                    + '<td>just now</td>'
                    + '<td>'
                    + '<a href="backup.php?ajax_download=1&file='+encodeURIComponent(res.filename)+'" class="btn-dl">&#8681; Download</a>'
                    + ' <button type="button" class="btn-del" data-file="'+res.filename+'">&#128465; Delete</button>'
                    + '</td></tr>';
                if ($('#historyBody').length) {
                    $('#historyBody').prepend(row);
                } else {
                    $('#historyWrap').html('<table class="bk-hist-table"><thead><tr><th>Filename</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead><tbody id="historyBody">'+row+'</tbody></table>');
                }
                // Offer download
                window.location.href = 'backup.php?ajax_download=1&file=' + encodeURIComponent(res.filename);
            } else {
                showToast(res.message || 'Export failed.');
            }
        },
        error: function() { hideProgress(); showToast('Server error during export.'); }
    });
});

// ── Import ────────────────────────────────────────────────────────
var importFile = null;
$('#dropZone').on('click', function() { $('#importFileInput').trigger('click'); });
$('#importFileInput').on('change', function() {
    if (this.files[0]) { setImportFile(this.files[0]); }
});
$('#dropZone').on('dragover', function(e) { e.preventDefault(); $(this).addClass('dragover'); });
$('#dropZone').on('dragleave', function()  { $(this).removeClass('dragover'); });
$('#dropZone').on('drop', function(e) {
    e.preventDefault(); $(this).removeClass('dragover');
    var f = e.originalEvent.dataTransfer.files[0];
    if (f) setImportFile(f);
});
function setImportFile(f) {
    if (!f.name.endsWith('.sql')) { showToast('Only .sql files are allowed.'); return; }
    importFile = f;
    $('#dzFileName').text(f.name + ' (' + Math.round(f.size/1024) + ' KB)').show();
    $('#btnImport').prop('disabled', false);
}
$('#btnImport').on('click', function() {
    if (!importFile) return;
    if (!confirm('⚠ This will OVERWRITE existing data with the backup file contents.\n\nAre you sure you want to restore?')) return;
    var fd = new FormData();
    fd.append('ajax_import', 1);
    fd.append('sql_file', importFile);
    showProgress('Restoring Backup…', 'Importing data, please wait.');
    $.ajax({
        url: 'backup.php', method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
        success: function(res) {
            hideProgress();
            if (res.success) {
                showToast('Restore successful! Reloading…', 'success');
                setTimeout(function(){ location.reload(); }, 1800);
            } else {
                showToast(res.message || 'Import failed.');
            }
        },
        error: function() { hideProgress(); showToast('Server error during import.'); }
    });
});

// ── Delete backup ─────────────────────────────────────────────────
var deleteTarget = null;
$(document).on('click', '.btn-del', function() {
    deleteTarget = $(this).data('file');
    $('#confirmDeleteMsg').text('Delete "' + deleteTarget + '"? This cannot be recovered.');
    $('#confirmDeleteModal').addClass('active');
});
$('#btnConfirmDelete').on('click', function() {
    if (!deleteTarget) return;
    $('#confirmDeleteModal').removeClass('active');
    $.ajax({
        url: 'backup.php', method: 'POST', dataType: 'json',
        data: { ajax_delete_backup: 1, file: deleteTarget },
        success: function(res) {
            if (res.success) {
                $('tr[data-file="'+deleteTarget+'"]').remove();
                showToast('Backup file deleted.', 'success');
                if (!$('#historyBody tr').length) {
                    $('#historyWrap').html('<div class="bk-empty">No backups yet. Export your first backup above.</div>');
                }
            } else { showToast(res.message || 'Delete failed.'); }
            deleteTarget = null;
        }
    });
});
$('#btnCancelDelete').on('click', function() { $('#confirmDeleteModal').removeClass('active'); deleteTarget = null; });

})();
</script>
</body>
</html>
