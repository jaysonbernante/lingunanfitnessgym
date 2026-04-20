<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../../app/config/connection.php';

// One-time: create products table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(100) NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    img VARCHAR(255),
    date_stocked DATE DEFAULT (CURRENT_DATE)
)");

// One-time: create sales table
$pdo->exec("CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    product_name VARCHAR(100) NOT NULL,
    qty_sold INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    sold_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Migrate: add member_name and transacted_by columns if not present
try { $pdo->exec("ALTER TABLE sales ADD COLUMN member_name VARCHAR(100) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE sales ADD COLUMN transacted_by VARCHAR(100) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE sales ADD COLUMN transaction_id VARCHAR(32) DEFAULT NULL"); } catch(Exception $e){}

// AJAX: get products JSON
if (isset($_GET['ajax_products'])) {
    header('Content-Type: application/json');
    $rows = $pdo->query("SELECT * FROM products ORDER BY product_name ASC")->fetchAll();
    echo json_encode($rows);
    exit;
}

// AJAX: process sale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_sale'])) {
    header('Content-Type: application/json');
    $items      = json_decode($_POST['items'] ?? '[]', true);
    $payMethod  = trim($_POST['payment_method'] ?? 'cash');
    $rfid       = trim($_POST['rfid'] ?? '');
    if (empty($items)) { echo json_encode(['success' => false, 'error' => 'No items']); exit; }
    try {
        $pdo->beginTransaction();
        // Pass 1: validate stock + lock rows + calculate total
        $locked = [];
        $total  = 0;
        foreach ($items as $item) {
            $pid = intval($item['id']);
            $qty = intval($item['qty']);
            $row = $pdo->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
            $row->execute([$pid]);
            $product = $row->fetch();
            if (!$product || $product['quantity'] < $qty) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Not enough stock for: ' . ($product['product_name'] ?? 'item')]);
                exit;
            }
            $lineTotal        = $qty * floatval($product['price']);
            $total           += $lineTotal;
            $locked[$pid]     = ['product' => $product, 'qty' => $qty, 'lineTotal' => $lineTotal];
        }
        // Card payment: check member credit
        $memberId = null;
        if ($payMethod === 'card') {
            if ($rfid === '') { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => 'No RFID provided for card payment']); exit; }
            $mStmt = $pdo->prepare("SELECT id, first_name, last_name, COALESCE(credit, 0) as credit FROM members WHERE RFID = ? FOR UPDATE");
            $mStmt->execute([$rfid]);
            $member = $mStmt->fetch();
            if (!$member) { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => 'Invalid RFID card']); exit; }
            if (floatval($member['credit']) < $total) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Insufficient credit. Balance: ₱' . number_format($member['credit'], 2)]);
                exit;
            }
            $pdo->prepare("UPDATE members SET credit = credit - ? WHERE id = ?")->execute([$total, $member['id']]);
            $memberId = $member['id'];
        }
        $memberName   = ($payMethod === 'card' && isset($member)) ? trim($member['first_name'] . ' ' . $member['last_name']) : '-';
        $transactedBy = $_SESSION['user_name'] ?? 'Unknown';
        $txnId        = bin2hex(random_bytes(8));
        // Pass 2: deduct stock + record sales
        foreach ($locked as $pid => $data) {
            $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?")->execute([$data['qty'], $pid]);
            $pdo->prepare("INSERT INTO sales (product_id, product_name, qty_sold, unit_price, total, payment_method, member_name, transacted_by, transaction_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$pid, $data['product']['product_name'], $data['qty'], $data['product']['price'], $data['lineTotal'], $payMethod, $memberName, $transactedBy, $txnId]);
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'total' => $total]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// AJAX: add / restock product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_add_product'])) {
    header('Content-Type: application/json');
    $name  = trim($_POST['product_name'] ?? '');
    $qty   = intval($_POST['quantity'] ?? 0);
    $price = floatval($_POST['price'] ?? 0);
    $img   = '';
    $editId = intval($_POST['edit_id'] ?? 0);

    if ($name === '' || $qty <= 0 || $price <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']); exit;
    }

    // Handle image upload
    if (!empty($_FILES['img']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['img']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Invalid image type']); exit;
        }
        $uploadDir = '../../../assets/image/products/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $filename = 'prod_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        move_uploaded_file($_FILES['img']['tmp_name'], $uploadDir . $filename);
        $img = $filename;
    }

    if ($editId > 0) {
        // Restock: add qty, optionally update price/image
        if ($img !== '') {
            $pdo->prepare("UPDATE products SET quantity = quantity + ?, price = ?, img = ? WHERE id = ?")->execute([$qty, $price, $img, $editId]);
        } else {
            $pdo->prepare("UPDATE products SET quantity = quantity + ?, price = ? WHERE id = ?")->execute([$qty, $price, $editId]);
        }
        echo json_encode(['success' => true, 'mode' => 'restock']);
    } else {
        // Check if product with same name already exists → merge stock
        $existing = $pdo->prepare("SELECT id FROM products WHERE LOWER(product_name) = LOWER(?)");
        $existing->execute([$name]);
        $found = $existing->fetch();
        if ($found) {
            if ($img !== '') {
                $pdo->prepare("UPDATE products SET quantity = quantity + ?, price = ?, img = ? WHERE id = ?")->execute([$qty, $price, $img, $found['id']]);
            } else {
                $pdo->prepare("UPDATE products SET quantity = quantity + ?, price = ? WHERE id = ?")->execute([$qty, $price, $found['id']]);
            }
            echo json_encode(['success' => true, 'mode' => 'merged']);
        } else {
            $pdo->prepare("INSERT INTO products (product_name, quantity, price, img, date_stocked) VALUES (?, ?, ?, ?, CURDATE())")->execute([$name, $qty, $price, $img]);
            echo json_encode(['success' => true, 'mode' => 'add']);
        }
    }
    exit;
}

// AJAX: check RFID for card payment
if (isset($_GET['ajax_rfid_pay'])) {
    header('Content-Type: application/json');
    $rfid = trim($_GET['rfid'] ?? '');
    if ($rfid === '') { echo json_encode(['found' => false, 'error' => 'No RFID provided']); exit; }
    try {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, COALESCE(credit, 0) as credit FROM members WHERE RFID = ?");
        $stmt->execute([$rfid]);
        $m = $stmt->fetch();
        if ($m) {
            echo json_encode(['found' => true, 'member' => $m]);
        } else {
            echo json_encode(['found' => false, 'error' => 'No member found for this RFID']);
        }
    } catch (Exception $e) {
        echo json_encode(['found' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// AJAX: delete product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete_product'])) {
    header('Content-Type: application/json');
    $id = intval($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// AJAX: sale history
if (isset($_GET['ajax_sale_history'])) {
    header('Content-Type: application/json');
    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = $pdo->prepare("SELECT * FROM sales WHERE product_name LIKE ? OR COALESCE(member_name,'') LIKE ? OR COALESCE(transacted_by,'') LIKE ? ORDER BY sold_at DESC, id ASC LIMIT 500");
        $stmt->execute([$like, $like, $like]);
    } else {
        $stmt = $pdo->query("SELECT * FROM sales ORDER BY sold_at DESC, id ASC LIMIT 500");
    }
    $rows = $stmt->fetchAll();
    // Group by transaction_id (fallback: sold_at + transacted_by for old rows)
    $grouped = [];
    $order   = [];
    foreach ($rows as $row) {
        $key = !empty($row['transaction_id']) ? $row['transaction_id'] : ($row['sold_at'] . '_' . $row['transacted_by']);
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'sold_at'        => $row['sold_at'],
                'payment_method' => $row['payment_method'] ?? 'cash',
                'member_name'    => $row['member_name'] ?? '-',
                'transacted_by'  => $row['transacted_by'] ?? '-',
                'total'          => 0,
                'items'          => []
            ];
            $order[] = $key;
        }
        $grouped[$key]['total'] += floatval($row['total']);
        $grouped[$key]['items'][] = [
            'product_name' => $row['product_name'],
            'qty_sold'     => $row['qty_sold'],
            'unit_price'   => $row['unit_price'],
            'total'        => $row['total']
        ];
    }
    $result = [];
    foreach ($order as $k) $result[] = $grouped[$k];
    echo json_encode($result);
    exit;
}

$page = 'Ecommerce';
include '../../../component/admin_header.php';
include '../../../component/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce</title>
    <link href="../../../assets/css/toastednotif.css" rel="stylesheet">
    <link href="../../../assets/css/admin_header.css" rel="stylesheet">
    <link href="../../../assets/css/admin_sidebar.css" rel="stylesheet">
    <link href="../../../assets/css/admin.css" rel="stylesheet">
</head>
<style>
    .ec-content {
        margin-left: 250px;
        margin-top: 60px;
        padding: 1.5rem 2rem;
        min-height: calc(100vh - 60px);
        background: #1e1e1e;
        color: #fff;
    }
    @media (max-width: 900px) { .ec-content { margin-left: 0; padding: 1rem; } }

    /* Tabs */
    .ec-tabs { display: flex; gap: 4px; margin-bottom: 24px; border-bottom: 2px solid #333; }
    .ec-tab {
        padding: 10px 28px; cursor: pointer; font-weight: 600; font-size: 15px;
        color: #aaa; border-radius: 8px 8px 0 0; transition: background 0.2s, color 0.2s;
    }
    .ec-tab.active { background: #2c2c2c; color: #f5c518; border-bottom: 2px solid #f5c518; }
    .ec-tab:hover:not(.active) { color: #fff; background: #2a2a2a; }
    .ec-panel { display: none; }
    .ec-panel.active { display: block; }

    /* ── SELL PANEL ── */
    .sell-layout { display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap; }
    .products-grid {
        flex: 2; min-width: 300px;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 14px;
    }
    .product-card {
        background: #2c2c2c;
        border-radius: 14px;
        padding: 14px 10px 12px;
        text-align: center;
        cursor: pointer;
        border: 2px solid transparent;
        transition: border-color 0.18s, transform 0.15s, box-shadow 0.18s;
        position: relative;
        user-select: none;
    }
    .product-card:hover { border-color: #f5c518; transform: translateY(-2px); box-shadow: 0 4px 18px rgba(0,0,0,0.35); }
    .product-card.out-of-stock { opacity: 0.45; cursor: not-allowed; }
    .product-card.out-of-stock:hover { border-color: transparent; transform: none; box-shadow: none; }
    .product-card img {
        width: 90px; height: 90px; object-fit: cover;
        border-radius: 10px; margin-bottom: 8px; background: #333;
    }
    .product-card .prod-name { font-weight: 700; font-size: 13px; color: #fff; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .product-card .prod-price { font-size: 14px; color: #f5c518; font-weight: 700; }
    .product-card .prod-stock { font-size: 11px; color: #888; margin-top: 2px; }
    .product-card .card-qty-badge {
        position: absolute; top: 8px; right: 8px;
        background: #f5c518; color: #111;
        border-radius: 50%; width: 22px; height: 22px;
        display: flex; align-items: center; justify-content: center;
        font-size: 12px; font-weight: 800; display: none;
    }
    .product-card.in-cart .card-qty-badge { display: flex; }
    .product-card.in-cart { border-color: #f5c518; }

    /* Cart */
    .cart-panel {
        flex: 1; min-width: 240px; max-width: 320px;
        background: #2c2c2c;
        border-radius: 16px;
        padding: 20px;
        position: sticky;
        top: 80px;
    }
    .cart-panel h3 { margin: 0 0 16px; font-size: 1rem; color: #f5c518; display: flex; align-items: center; gap: 8px; }
    .cart-empty { color: #666; font-size: 13px; text-align: center; padding: 24px 0; }
    .cart-item {
        display: flex; align-items: center; justify-content: space-between;
        padding: 8px 0; border-bottom: 1px solid #3a3a3a; gap: 8px;
    }
    .cart-item .ci-name { font-size: 13px; color: #fff; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cart-item .ci-qty-ctrl { display: flex; align-items: center; gap: 4px; }
    .ci-qty-btn {
        width: 24px; height: 24px; border-radius: 6px; border: none;
        background: #444; color: #fff; font-size: 15px; cursor: pointer;
        display: flex; align-items: center; justify-content: center; font-weight: 700;
    }
    .ci-qty-btn:hover { background: #f5c518; color: #111; }
    .ci-qty-num { font-size: 14px; font-weight: 700; min-width: 20px; text-align: center; }
    .cart-item .ci-total { font-size: 13px; color: #f5c518; font-weight: 700; min-width: 52px; text-align: right; }
    .cart-total-row {
        display: flex; justify-content: space-between; align-items: center;
        margin-top: 16px; padding-top: 12px; border-top: 2px solid #444;
        font-weight: 800; font-size: 16px;
    }
    .cart-total-row span:last-child { color: #f5c518; }
    .btn-checkout {
        width: 100%; margin-top: 16px; padding: 13px;
        border-radius: 10px; border: none;
        background: #f5c518; color: #111;
        font-size: 16px; font-weight: 800; cursor: pointer;
        transition: background 0.2s;
    }
    .btn-checkout:hover { background: #ffe066; }
    .btn-checkout:disabled { background: #444; color: #666; cursor: not-allowed; }
    .btn-clear-cart {
        width: 100%; margin-top: 8px; padding: 9px;
        border-radius: 10px; border: 1px solid #444;
        background: transparent; color: #aaa;
        font-size: 13px; cursor: pointer;
    }
    .btn-clear-cart:hover { background: #333; color: #fff; }

    /* ── STOCK PANEL ── */
    .stock-toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 20px; }
    .stock-toolbar input[type=text] {
        padding: 8px 12px; border-radius: 8px; border: 1px solid #444;
        background: #1a1a1a; color: #fff; font-size: 14px; width: 220px;
    }
    .btn-add-product {
        padding: 8px 18px; border-radius: 8px; border: none;
        background: #1976d2; color: #fff; font-weight: 600; cursor: pointer;
    }
    .stock-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 16px;
    }
    .stock-card {
        background: #2c2c2c; border-radius: 14px; padding: 14px;
        position: relative; text-align: center;
    }
    .stock-card img { width: 100px; height: 100px; object-fit: cover; border-radius: 10px; margin-bottom: 8px; background: #333; }
    .stock-card .sc-name { font-weight: 700; font-size: 14px; color: #fff; margin-bottom: 4px; }
    .stock-card .sc-price { font-size: 13px; color: #f5c518; font-weight: 700; }
    .stock-card .sc-qty { font-size: 12px; color: #aaa; margin-top: 2px; }
    .stock-card .sc-date { font-size: 11px; color: #666; margin-top: 4px; }
    .stock-card-actions { display: flex; gap: 6px; justify-content: center; margin-top: 10px; }
    .sc-btn { padding: 5px 14px; border-radius: 8px; border: none; font-size: 12px; font-weight: 600; cursor: pointer; }
    .sc-btn.restock { background: #388e3c; color: #fff; }
    .sc-btn.delete  { background: #c62828; color: #fff; }
    .sc-qty-badge {
        position: absolute; top: 8px; right: 8px;
        background: #444; color: #fff; font-size: 11px; font-weight: 700;
        padding: 2px 7px; border-radius: 8px;
    }
    .sc-qty-badge.low { background: #e65100; }

    /* Modal */
    .modal-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,0.65); z-index: 1000;
        justify-content: center; align-items: center;
    }
    .modal-overlay.active { display: flex; }
    .modal-box {
        background: #2c2c2c; border-radius: 16px; padding: 28px;
        max-width: 400px; width: 90%; color: #fff;
        box-shadow: 0 8px 32px rgba(0,0,0,0.5);
    }
    .modal-box h2 { margin: 0 0 18px; font-size: 1.2rem; }
    .modal-box label { display: block; font-size: 13px; color: #bbb; margin-bottom: 4px; }
    .modal-box input[type=text], .modal-box input[type=number], .modal-box input[type=file] {
        width: 100%; padding: 8px 12px; border-radius: 8px;
        border: 1px solid #444; background: #1a1a1a; color: #fff;
        font-size: 14px; margin-bottom: 12px; box-sizing: border-box;
    }
    .img-preview { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; margin-bottom: 10px; display: none; }
    .modal-actions { display: flex; gap: 10px; margin-top: 6px; }
    .btn-modal-submit { padding: 9px 22px; border-radius: 8px; border: none; background: #1976d2; color: #fff; font-weight: 600; cursor: pointer; }
    .btn-modal-cancel { padding: 9px 22px; border-radius: 8px; border: none; background: #444; color: #fff; font-weight: 600; cursor: pointer; }

    /* Confirm Sale Modal */
    .sale-item-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #3a3a3a; font-size: 14px; }
    .sale-grand-total { display: flex; justify-content: space-between; font-size: 18px; font-weight: 800; margin-top: 14px; color: #f5c518; }
    .btn-confirm-sale { width: 100%; padding: 13px; border-radius: 10px; border: none; background: #f5c518; color: #111; font-size: 16px; font-weight: 800; cursor: pointer; margin-top: 16px; }
    .btn-confirm-sale:hover { background: #ffe066; }
    .btn-confirm-sale:disabled { background: #555; color: #888; cursor: not-allowed; }
    .pay-method-btn {
        flex: 1; padding: 14px 10px; border-radius: 10px; border: 2px solid #444;
        background: #333; color: #fff; font-size: 15px; font-weight: 700; cursor: pointer;
        transition: border-color 0.18s, background 0.18s;
    }
    .pay-method-btn.selected { border-color: #f5c518; background: rgba(245,197,24,0.12); color: #f5c518; }
    .pay-method-btn:hover:not(.selected) { border-color: #666; }
    .rfid-pay-section { margin-top: 16px; }
    /* Tap-to-scan button */
    .rfid-tap-btn {
        display: flex; align-items: center; gap: 10px;
        width: 100%; padding: 14px 16px; border-radius: 10px;
        border: 2px dashed #444; background: #1a1a1a;
        color: #bbb; font-size: 14px; font-weight: 600;
        cursor: pointer; transition: border-color 0.2s, color 0.2s;
        box-sizing: border-box;
    }
    .rfid-tap-btn:hover { border-color: #1976d2; color: #fff; }
    .rfid-tap-btn.scanning { border-color: #f5c518; color: #f5c518; animation: rfid-pulse 1s infinite; }
    .rfid-tap-btn .rfid-icon { font-size: 1.3rem; flex-shrink: 0; }
    @keyframes rfid-pulse { 0%,100%{opacity:1} 50%{opacity:0.45} }
    /* Captured card */
    .rfid-captured {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 14px; border-radius: 8px;
        background: rgba(67,160,71,0.15); border: 1px solid #43a047;
        font-size: 14px; color: #81c784;
    }
    .rfid-captured .rfid-val { font-weight: 700; flex: 1; }
    .rfid-captured .rfid-clear { background: none; border: none; color: #e57373; cursor: pointer; font-size: 16px; padding: 0 4px; line-height: 1; }
    /* Member info box */
    .rfid-member-info {
        background: #1a1a1a; border-radius: 10px; padding: 12px 14px; margin-top: 10px;
    }
    /* History Table */
    .history-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .history-table th { background: #2c2c2c; color: #f5c518; padding: 10px 14px; text-align: left; border-bottom: 2px solid #444; white-space: nowrap; }
    .history-table td { padding: 9px 14px; border-bottom: 1px solid #333; color: #ddd; vertical-align: middle; }
    .history-table tbody tr:hover { background: #252525; }
    .badge-pay { display: inline-block; padding: 2px 10px; border-radius: 8px; font-size: 12px; font-weight: 700; }
    .badge-pay.cash { background: #388e3c; color: #fff; }
    .badge-pay.card { background: #1976d2; color: #fff; }
</style>
<body>
<div class="ec-content">
    <h1 style="margin-bottom:20px;">E-Commerce</h1>

    <!-- Tabs -->
    <div class="ec-tabs">
        <div class="ec-tab active" data-tab="sell">🛒 Sell</div>
        <div class="ec-tab" data-tab="stock">📦 Stock</div>
        <div class="ec-tab" data-tab="history">📋 History</div>
    </div>

    <!-- ── SELL PANEL ── -->
    <div class="ec-panel active" id="panel-sell">
        <div class="sell-layout">
            <!-- Product Grid -->
            <div class="products-grid" id="productGrid">
                <div style="color:#666; grid-column:1/-1; padding:40px 0; text-align:center;">Loading products...</div>
            </div>
            <!-- Cart -->
            <div class="cart-panel">
                <h3>🛒 Cart <span id="cartCount" style="font-size:12px;background:#f5c518;color:#111;border-radius:10px;padding:1px 8px;">0</span></h3>
                <div id="cartItems"><div class="cart-empty">Tap a product to add</div></div>
                <div class="cart-total-row"><span>Total</span><span id="cartTotal">₱0.00</span></div>
                <button class="btn-checkout" id="btnCheckout" disabled>Confirm Sale</button>
                <button class="btn-clear-cart" id="btnClearCart">Clear Cart</button>
            </div>
        </div>
    </div>

    <!-- ── STOCK PANEL ── -->
    <div class="ec-panel" id="panel-stock">
        <div class="stock-toolbar">
            <input type="text" id="stockSearch" placeholder="Search product...">
            <button class="btn-add-product" id="btnAddProduct">+ Add Product</button>
        </div>
        <div class="stock-grid" id="stockGrid">
            <div style="color:#666; grid-column:1/-1; padding:40px 0; text-align:center;">Loading...</div>
        </div>
    </div>

    <!-- ── HISTORY PANEL ── -->
    <div class="ec-panel" id="panel-history">
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:20px;flex-wrap:wrap;">
            <input type="text" id="historySearch" placeholder="Search product, member, staff..." style="padding:8px 12px;border-radius:8px;border:1px solid #444;background:#1a1a1a;color:#fff;font-size:14px;width:280px;">
            <button id="btnHistoryRefresh" style="padding:8px 18px;border-radius:8px;border:none;background:#1976d2;color:#fff;font-weight:600;cursor:pointer;">↻ Refresh</button>
        </div>
        <div style="overflow-x:auto;">
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Member / Customer</th>
                        <th>By (Staff)</th>
                    </tr>
                </thead>
                <tbody id="historyBody">
                    <tr><td colspan="8" style="text-align:center;color:#666;padding:40px 0;">Click the "History" tab to load records.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add / Restock Product Modal -->
<div class="modal-overlay" id="productModal">
    <div class="modal-box">
        <h2 id="productModalTitle">Add Product</h2>
        <form id="productForm" enctype="multipart/form-data">
            <input type="hidden" id="pEditId" name="edit_id" value="0">
            <label>Product Name *</label>
            <input type="text" name="product_name" id="pName" required placeholder="e.g. Protein Shake">
            <label id="qtyLabel">Quantity *</label>
            <input type="number" name="quantity" id="pQty" required min="1" placeholder="0">
            <label>Price (₱) *</label>
            <input type="number" name="price" id="pPrice" required min="0.01" step="0.01" placeholder="0.00">
            <label>Product Image</label>
            <img id="imgPreview" class="img-preview" src="" alt="preview">
            <input type="file" name="img" id="pImg" accept="image/*">
            <div class="modal-actions">
                <button type="submit" class="btn-modal-submit" id="pSubmitBtn">Add Product</button>
                <button type="button" class="btn-modal-cancel" id="btnCancelProduct">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirm Sale Modal -->
<div class="modal-overlay" id="confirmSaleModal">
    <div class="modal-box" style="max-width:440px;">
        <h2>Confirm Sale</h2>
        <div id="saleItemsList"></div>
        <div class="sale-grand-total"><span>Total</span><span id="saleGrandTotal">₱0.00</span></div>

        <!-- Payment method -->
        <div style="margin-top:20px;">
            <div style="font-size:13px;color:#bbb;margin-bottom:10px;font-weight:600;">How to Pay?</div>
            <div style="display:flex;gap:12px;">
                <button type="button" class="pay-method-btn" id="btnPayCash">💵 Cash</button>
                <button type="button" class="pay-method-btn" id="btnPayCard">💳 Card Credit</button>
            </div>
        </div>

        <!-- RFID section (card only) -->
        <div class="rfid-pay-section" id="rfidPaySection" style="display:none; margin-top:16px;">
            <div style="font-size:13px;color:#bbb;margin-bottom:10px;font-weight:600;">Member RFID Card</div>
            <!-- Tap button (shown when no card scanned yet) -->
            <div id="saleRfidTapWrap">
                <button type="button" class="rfid-tap-btn" id="btnSaleTapRfid">
                    <span class="rfid-icon">&#128276;</span>
                    <span id="saleTapLabel">Tap RFID Card to Scan</span>
                </button>
            </div>
            <!-- Captured card info (shown after scan) -->
            <div class="rfid-captured" id="saleRfidCaptured" style="display:none;">
                <span class="rfid-val" id="saleRfidVal"></span>
                <button type="button" class="rfid-clear" id="btnSaleRfidClear" title="Clear">&#10005;</button>
            </div>
            <!-- Member info -->
            <div class="rfid-member-info" id="rfidMemberInfo" style="display:none; margin-top:10px;">
                <div style="font-weight:700;color:#fff;" id="rfidMemberName">-</div>
                <div style="font-size:13px;color:#aaa;margin-top:2px;">Credit: <span id="rfidMemberCredit" style="color:#f5c518;font-weight:700;">&#8369;0.00</span></div>
                <div id="rfidCreditStatus" style="margin-top:6px;font-size:13px;"></div>
            </div>
        </div>
        <!-- Hidden HID capture input (off-screen) -->
        <input type="text" id="saleRfidHidden" autocomplete="off" tabindex="-1" style="position:fixed;top:-9999px;left:-9999px;opacity:0;width:1px;height:1px;">

        <button class="btn-confirm-sale" id="btnConfirmSale" disabled>✔ Complete Sale</button>
        <button class="btn-modal-cancel" style="width:100%;margin-top:8px;" id="btnCancelSale">Back to Cart</button>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
let cart = {};      // { productId: {name, price, qty, stock} }
let allProducts = [];
let toastTimer = null;
let selectedPayMethod = null;
let rfidMemberData = null;
let cartGrandTotal = 0;

function showToast(msg, type = 'error') {
    let t = document.getElementById('toastNotif');
    if (!t) { t = document.createElement('div'); t.id = 'toastNotif'; t.className = 'toast'; document.body.appendChild(t); }
    t.className = 'toast' + (type === 'success' ? ' success' : '');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 3500);
}

// ── Product image path helper ──
function imgSrc(img) {
    if (!img) return '../../../assets/image/Logo.png';
    return '../../../assets/image/products/' + img;
}

// ── Load products from server ──
function loadProducts(callback) {
    $.getJSON('Ecommerce.php?ajax_products=1', function(data) {
        allProducts = data;
        if (callback) callback();
    });
}

// ── Render sell grid ──
function renderSellGrid(filter) {
    filter = (filter || '').toLowerCase();
    const grid = $('#productGrid');
    grid.empty();
    const list = filter ? allProducts.filter(p => p.product_name.toLowerCase().includes(filter)) : allProducts;
    if (!list.length) { grid.html('<div style="color:#666;grid-column:1/-1;text-align:center;padding:40px 0;">No products found.</div>'); return; }
    list.forEach(p => {
        const inCart = cart[p.id] ? cart[p.id].qty : 0;
        const outOfStock = parseInt(p.quantity) <= 0;
        const card = $(`
            <div class="product-card ${outOfStock ? 'out-of-stock' : ''} ${inCart > 0 ? 'in-cart' : ''}" data-id="${p.id}">
                <div class="card-qty-badge">${inCart}</div>
                <img src="${imgSrc(p.img)}" alt="${p.product_name}" onerror="this.src='../../../assets/image/Logo.png'">
                <div class="prod-name">${p.product_name}</div>
                <div class="prod-price">₱${parseFloat(p.price).toFixed(2)}</div>
                <div class="prod-stock">${outOfStock ? 'Out of stock' : 'Stock: ' + p.quantity}</div>
            </div>
        `);
        if (!outOfStock) {
            card.on('click', function() { addToCart(p); });
        }
        grid.append(card);
    });
}

// ── Add to cart ──
function addToCart(p) {
    const id = p.id;
    const maxStock = parseInt(p.quantity);
    if (!cart[id]) {
        cart[id] = { name: p.product_name, price: parseFloat(p.price), qty: 1, stock: maxStock };
    } else {
        if (cart[id].qty >= maxStock) { showToast('Max stock reached for ' + p.product_name); return; }
        cart[id].qty++;
    }
    renderCart();
    renderSellGrid($('#sellSearch').val());
}

// ── Render cart ──
function renderCart() {
    const keys = Object.keys(cart);
    $('#cartCount').text(keys.length);
    if (!keys.length) {
        $('#cartItems').html('<div class="cart-empty">Tap a product to add</div>');
        $('#cartTotal').text('₱0.00');
        $('#btnCheckout').prop('disabled', true);
        return;
    }
    let html = '';
    let total = 0;
    keys.forEach(id => {
        const item = cart[id];
        const line = item.qty * item.price;
        total += line;
        html += `
            <div class="cart-item">
                <span class="ci-name">${item.name}</span>
                <div class="ci-qty-ctrl">
                    <button class="ci-qty-btn" onclick="changeQty(${id}, -1)">−</button>
                    <span class="ci-qty-num">${item.qty}</span>
                    <button class="ci-qty-btn" onclick="changeQty(${id}, 1)">+</button>
                </div>
                <span class="ci-total">₱${line.toFixed(2)}</span>
            </div>`;
    });
    $('#cartItems').html(html);
    $('#cartTotal').text('₱' + total.toFixed(2));
    $('#btnCheckout').prop('disabled', false);
}

function changeQty(id, delta) {
    if (!cart[id]) return;
    cart[id].qty += delta;
    if (cart[id].qty <= 0) { delete cart[id]; }
    else if (cart[id].qty > cart[id].stock) { cart[id].qty = cart[id].stock; }
    renderCart();
    renderSellGrid($('#sellSearch').val());
}

// ── Payment method selection ──
function selectPayMethod(method) {
    selectedPayMethod = method;
    $('.pay-method-btn').removeClass('selected');
    if (method === 'cash') {
        $('#btnPayCash').addClass('selected');
        $('#rfidPaySection').hide();
        rfidMemberData = null;
        $('#btnConfirmSale').prop('disabled', false);
    } else {
        $('#btnPayCard').addClass('selected');
        $('#rfidPaySection').show();
        rfidMemberData = null;
        $('#btnConfirmSale').prop('disabled', true);
        $('#rfidMemberInfo').hide();
        $('#saleRfidCaptured').hide();
        $('#saleRfidTapWrap').show();
        saleRfidBuffer = '';
        focusSaleRfid();
    }
}

// ── RFID HID scanner for card payment ──
var saleRfidBuffer = '', saleRfidTimer = null;

function focusSaleRfid() {
    $('#saleRfidHidden').focus();
}

function lookupSaleRfid(rfid) {
    $('#saleTapLabel').text('Scanning\u2026');
    $('#btnSaleTapRfid').addClass('scanning');
    $.getJSON('Ecommerce.php?ajax_rfid_pay=1&rfid=' + encodeURIComponent(rfid), function(res) {
        $('#btnSaleTapRfid').removeClass('scanning');
        $('#saleTapLabel').text('Tap RFID Card to Scan');
        if (res.found) {
            rfidMemberData = res.member;
            const credit = parseFloat(res.member.credit);
            $('#saleRfidVal').text(rfid);
            $('#saleRfidCaptured').show();
            $('#saleRfidTapWrap').hide();
            $('#rfidMemberName').text(res.member.first_name + ' ' + res.member.last_name);
            $('#rfidMemberCredit').text('\u20b1' + credit.toFixed(2));
            if (credit >= cartGrandTotal) {
                $('#rfidCreditStatus').html('<span style="color:#43a047;">\u2714 Sufficient credit</span>');
                $('#btnConfirmSale').prop('disabled', false);
            } else {
                $('#rfidCreditStatus').html('<span style="color:#e53935;">\u2718 Insufficient credit (need \u20b1' + cartGrandTotal.toFixed(2) + ')</span>');
                rfidMemberData = null;
                $('#btnConfirmSale').prop('disabled', true);
            }
            $('#rfidMemberInfo').show();
        } else {
            showToast(res.error || 'Invalid RFID card');
            rfidMemberData = null;
            $('#rfidMemberInfo').hide();
            $('#btnConfirmSale').prop('disabled', true);
            focusSaleRfid();
        }
    }).fail(function(){
        showToast('Server error. Try again.');
        $('#btnSaleTapRfid').removeClass('scanning');
        $('#saleTapLabel').text('Tap RFID Card to Scan');
    });
}

// HID keyboard capture on hidden input
$(document).on('keydown', '#saleRfidHidden', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(saleRfidTimer);
        var val = saleRfidBuffer.trim(); saleRfidBuffer = ''; $(this).val('');
        if (val) lookupSaleRfid(val);
        return;
    }
    clearTimeout(saleRfidTimer);
    saleRfidTimer = setTimeout(function() {
        var val = saleRfidBuffer.trim(); saleRfidBuffer = ''; $('#saleRfidHidden').val('');
        if (val) lookupSaleRfid(val);
    }, 300);
});
$(document).on('input', '#saleRfidHidden', function() {
    saleRfidBuffer += $(this).val(); $(this).val('');
});

// ── Checkout → show confirm modal ──
function openConfirmModal() {
    const keys = Object.keys(cart);
    if (!keys.length) return;
    let html = '';
    cartGrandTotal = 0;
    keys.forEach(id => {
        const item = cart[id];
        const line = item.qty * item.price;
        cartGrandTotal += line;
        html += `<div class="sale-item-row"><span>${item.name} \u00d7 ${item.qty}</span><span>\u20b1${line.toFixed(2)}</span></div>`;
    });
    document.getElementById('saleItemsList').innerHTML = html;
    document.getElementById('saleGrandTotal').textContent = '\u20b1' + cartGrandTotal.toFixed(2);
    // Reset payment state
    selectedPayMethod = null;
    rfidMemberData = null;
    document.querySelectorAll('.pay-method-btn').forEach(b => b.classList.remove('selected'));
    $('#rfidPaySection').hide();
    $('#rfidMemberInfo').hide();
    $('#saleRfidCaptured').hide();
    $('#saleRfidTapWrap').show();
    $('#saleTapLabel').text('Tap RFID Card to Scan');
    $('#btnSaleTapRfid').removeClass('scanning');
    saleRfidBuffer = '';
    $('#btnConfirmSale').prop('disabled', true);
    $('#confirmSaleModal').addClass('active');
}

$(document).ready(function() {
    // Checkout button
    $('#btnCheckout').on('click', openConfirmModal);

    // Payment method buttons
    $('#btnPayCash').on('click', function() { selectPayMethod('cash'); });
    $('#btnPayCard').on('click', function() { selectPayMethod('card'); });

    // Tap button → focus hidden input
    $(document).on('click', '#btnSaleTapRfid', function() { focusSaleRfid(); });

    // Clear RFID card
    $(document).on('click', '#btnSaleRfidClear', function() {
        rfidMemberData = null;
        $('#saleRfidCaptured').hide();
        $('#saleRfidTapWrap').show();
        $('#rfidMemberInfo').hide();
        $('#saleTapLabel').text('Tap RFID Card to Scan');
        saleRfidBuffer = '';
        $('#btnConfirmSale').prop('disabled', true);
        focusSaleRfid();
    });

    // Confirm sale modal
    $('#btnCancelSale').on('click', () => $('#confirmSaleModal').removeClass('active'));
    $('#confirmSaleModal').on('click', function(e) { if (e.target === this) $(this).removeClass('active'); });

    // Confirm sale submit
    $('#btnConfirmSale').on('click', function() {
        if (!selectedPayMethod) { showToast('Please select a payment method'); return; }
        if (selectedPayMethod === 'card' && !rfidMemberData) { showToast('Please scan a valid RFID card first'); return; }
        const items = Object.keys(cart).map(id => ({ id: id, qty: cart[id].qty }));
        const postData = { ajax_sale: 1, items: JSON.stringify(items), payment_method: selectedPayMethod };
        if (selectedPayMethod === 'card') postData.rfid = $('#saleRfidVal').text().trim();
        $.ajax({
            url: 'Ecommerce.php',
            method: 'POST',
            data: postData,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    const payLabel = selectedPayMethod === 'card' ? 'card credit' : 'cash';
                    showToast('Sale completed! \u20b1' + parseFloat(res.total).toFixed(2) + ' via ' + payLabel, 'success');
                    cart = {};
                    renderCart();
                    $('#confirmSaleModal').removeClass('active');
                    loadProducts(() => renderSellGrid());
                } else {
                    showToast(res.error || 'Sale failed.');
                }
            },
            error: () => showToast('Server error.')
        });
    });

    // Clear cart
    $('#btnClearCart').off('click').on('click', function() {
        cart = {};
        renderCart();
        renderSellGrid($('#sellSearch').val());
    });

    // Add product modal
    $('#btnAddProduct').on('click', function() {
        $('#productModalTitle').text('Add Product');
        $('#pSubmitBtn').text('Add Product');
        $('#pEditId').val(0);
        $('#pName').val('').prop('readonly', false);
        $('#pQty').val('');
        $('#pPrice').val('');
        $('#imgPreview').hide();
        $('#pImg').val('');
        $('#qtyLabel').text('Quantity *');
        $('#productModal').addClass('active');
    });
    $('#btnCancelProduct').on('click', () => $('#productModal').removeClass('active'));
    $('#productModal').on('click', function(e) { if (e.target === this) $(this).removeClass('active'); });

    // Tabs
    $('.ec-tab').on('click', function() {
        const tab = $(this).data('tab');
        $('.ec-tab').removeClass('active');
        $('.ec-panel').removeClass('active');
        $(this).addClass('active');
        $('#panel-' + tab).addClass('active');
        if (tab === 'stock') renderStockGrid($('#stockSearch').val());
        if (tab === 'sell') renderSellGrid($('#sellSearch').val());
        if (tab === 'history') loadSaleHistory($('#historySearch').val());
    });

    // History expand/collapse
    $('#historyBody').on('click', '.history-row', function() {
        const idx = $(this).data('idx');
        const $detail = $('#hdetail-' + idx);
        const $arrow = $(this).find('.h-arrow');
        $detail.toggle();
        $arrow.text($detail.is(':visible') ? '▲' : '▼');
    });

    // Search
    $('#sellSearch').on('keyup', function() { renderSellGrid($(this).val()); });
    $('#stockSearch').on('keyup', function() { renderStockGrid($(this).val()); });
    $('#historySearch').on('keyup', function() { loadSaleHistory($(this).val()); });
    $('#btnHistoryRefresh').on('click', function() { loadSaleHistory($('#historySearch').val()); });

    // Image preview
    $('#pImg').on('change', function() {
        const file = this.files[0];
        if (file) { const url = URL.createObjectURL(file); $('#imgPreview').attr('src', url).show(); }
    });

    // Product form submit
    $('#productForm').on('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('ajax_add_product', '1');
        $.ajax({
            url: 'Ecommerce.php', method: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json',
            success: function(res) {
                if (res.success) {
                    showToast(res.mode === 'restock' ? 'Stock updated!' : (res.mode === 'merged' ? 'Stock merged!' : 'Product added!'), 'success');
                    $('#productModal').removeClass('active');
                    loadProducts(() => { renderSellGrid(); renderStockGrid($('#stockSearch').val()); });
                } else { showToast(res.error || 'Failed.'); }
            },
            error: () => showToast('Server error.')
        });
    });

    // Search bar above sell grid
    $('#panel-sell .sell-layout').before('<input type="text" id="sellSearch" placeholder="Search product..." style="padding:8px 14px;border-radius:8px;border:1px solid #444;background:#1a1a1a;color:#fff;font-size:14px;width:240px;margin-bottom:14px;">');

    loadProducts(() => { renderSellGrid(); });
});

// ── Render stock grid ──
function renderStockGrid(filter) {
    filter = (filter || '').toLowerCase();
    const grid = $('#stockGrid');
    grid.empty();
    const list = filter ? allProducts.filter(p => p.product_name.toLowerCase().includes(filter)) : allProducts;
    if (!list.length) { grid.html('<div style="color:#666;grid-column:1/-1;text-align:center;padding:40px 0;">No products.</div>'); return; }
    list.forEach(p => {
        const qty = parseInt(p.quantity);
        const low = qty <= 5;
        grid.append(`
            <div class="stock-card">
                <span class="sc-qty-badge ${low ? 'low' : ''}">${qty} left</span>
                <img src="${imgSrc(p.img)}" alt="${p.product_name}" onerror="this.src='../../../assets/image/Logo.png'">
                <div class="sc-name">${p.product_name}</div>
                <div class="sc-price">₱${parseFloat(p.price).toFixed(2)}</div>
                <div class="sc-date">Stocked: ${p.date_stocked || '-'}</div>
                <div class="stock-card-actions">
                    <button class="sc-btn restock" onclick="openRestock(${p.id},'${p.product_name.replace(/'/g,"\\'")}',${p.price})">Restock</button>
                    <button class="sc-btn delete" onclick="deleteProduct(${p.id})">Delete</button>
                </div>
            </div>
        `);
    });
}

function openRestock(id, name, price) {
    $('#productModalTitle').text('Restock: ' + name);
    $('#pSubmitBtn').text('Add Stock');
    $('#pEditId').val(id);
    $('#pName').val(name).prop('readonly', true);
    $('#pQty').val('');
    $('#pPrice').val(parseFloat(price).toFixed(2));
    $('#imgPreview').hide();
    $('#pImg').val('');
    $('#qtyLabel').text('Add Quantity *');
    $('#productModal').addClass('active');
}

function deleteProduct(id) {
    if (!confirm('Delete this product?')) return;
    $.ajax({
        url: 'Ecommerce.php', method: 'POST',
        data: { ajax_delete_product: 1, id: id },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                showToast('Product deleted.', 'success');
                loadProducts(() => { renderSellGrid(); renderStockGrid($('#stockSearch').val()); });
            }
        }
    });
}

// ── Sale History ──
function loadSaleHistory(search) {
    const q = search ? '&search=' + encodeURIComponent(search) : '';
    $('#historyBody').html('<tr><td colspan="8" style="text-align:center;color:#666;padding:30px 0;">Loading...</td></tr>');
    $.getJSON('Ecommerce.php?ajax_sale_history=1' + q, function(data) {
        renderHistoryTable(data);
    }).fail(function() {
        $('#historyBody').html('<tr><td colspan="8" style="text-align:center;color:#e53935;padding:30px 0;">Failed to load history.</td></tr>');
    });
}

function renderHistoryTable(transactions) {
    if (!transactions.length) {
        $('#historyBody').html('<tr><td colspan="6" style="text-align:center;color:#666;padding:30px 0;">No sales records found.</td></tr>');
        return;
    }
    let html = '';
    transactions.forEach((t, idx) => {
        const payClass = t.payment_method === 'cash' ? 'cash' : 'card';
        const payLabel = t.payment_method === 'cash' ? '\uD83D\uDCB5 Cash' : '\uD83D\uDCB3 Card';
        const date = t.sold_at ? t.sold_at.substring(0, 16).replace('T', ' ') : '-';
        const itemCount = t.items.length;
        const itemLabel = itemCount === 1
            ? t.items[0].product_name
            : itemCount + ' products';
        const itemRows = t.items.map(item => `
            <tr>
                <td style="padding:7px 20px;color:#ddd;border-top:1px solid #2a2a2a;">${item.product_name}</td>
                <td style="padding:7px 14px;text-align:center;color:#ddd;border-top:1px solid #2a2a2a;">${item.qty_sold}</td>
                <td style="padding:7px 14px;color:#ddd;border-top:1px solid #2a2a2a;">&#8369;${parseFloat(item.unit_price).toFixed(2)}</td>
                <td style="padding:7px 14px;color:#f5c518;font-weight:700;border-top:1px solid #2a2a2a;">&#8369;${parseFloat(item.total).toFixed(2)}</td>
            </tr>`).join('');
        html += `
        <tr class="history-row" data-idx="${idx}" style="cursor:pointer;">
            <td style="white-space:nowrap;">${date}</td>
            <td>${itemLabel} ${itemCount > 1 ? '<span class="h-arrow" style="font-size:11px;color:#f5c518;margin-left:4px;">&or;</span>' : ''}</td>
            <td style="color:#f5c518;font-weight:700;">&#8369;${parseFloat(t.total).toFixed(2)}</td>
            <td><span class="badge-pay ${payClass}">${payLabel}</span></td>
            <td>${t.member_name || '-'}</td>
            <td>${t.transacted_by || '-'}</td>
        </tr>`;
        if (itemCount > 1) {
            html += `
        <tr id="hdetail-${idx}" style="display:none;">
            <td colspan="6" style="padding:0;">
                <table style="width:100%;background:#1a1a1a;border-collapse:collapse;">
                    <thead>
                        <tr style="background:#222;">
                            <th style="padding:8px 20px;color:#aaa;font-weight:600;font-size:13px;text-align:left;">Product</th>
                            <th style="padding:8px 14px;color:#aaa;font-weight:600;font-size:13px;text-align:center;">Qty</th>
                            <th style="padding:8px 14px;color:#aaa;font-weight:600;font-size:13px;">Unit Price</th>
                            <th style="padding:8px 14px;color:#aaa;font-weight:600;font-size:13px;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>${itemRows}</tbody>
                </table>
            </td>
        </tr>`;
        }
    });
    $('#historyBody').html(html);
}

</script>
</body>
</html>
