<?php
$page = 'staff';
include '../../../component/admin_header.php';
include '../../../component/admin_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management</title>
    <link href="../../../assets/css/toastednotif.css" rel="stylesheet">
    <link href="../../../assets/css/admin_header.css" rel="stylesheet">
    <link href="../../../assets/css/admin_sidebar.css" rel="stylesheet">
    <link href="../../../assets/css/admin.css" rel="stylesheet">
</head>
<style>
    .Member-content {
        margin-left: 250px;
        margin-top: 60px;
        padding: 2rem;
        min-height: calc(100vh - 60px);
        background: #222;
    }
    @media (max-width: 900px) {
        .Member-content { margin-left: 0; padding: 1rem; }
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
        table-layout: fixed;
    }
    .admin-table thead th:first-child { border-top-left-radius: 16px; }
    .admin-table thead th:last-child { border-top-right-radius: 16px; }
    .admin-table tbody tr:last-child td:first-child { border-bottom-left-radius: 16px; }
    .admin-table tbody tr:last-child td:last-child { border-bottom-right-radius: 16px; }
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
    .admin-table tr { transition: background 0.2s; }
    .admin-table tbody tr:hover { background: #f9f9f965; color: #333; }
    .admin-table td { border-bottom: 1px solid #333; font-size: 15px; }
    .badge-status {
        display: inline-block;
        padding: 2px 12px;
        border-radius: 12px;
        font-size: 90%;
        font-weight: 600;
        color: #fff;
    }
    .badge-staff   { background: #1976d2; }
    .badge-super_admin { background: #7b1fa2; }
    .badge-admin   { background: #e53935; }
    .action-btn {
        background: none;
        border: none;
        color: #1976d2;
        cursor: pointer;
        padding: 0 8px;
        font-size: 15px;
        transition: color 0.2s;
    }
    .action-btn.delete { color: #d32f2f; }
    .action-btn:hover { text-decoration: underline; }
    .admin-table th:nth-child(1){ width: 17%; }
    .admin-table th:nth-child(2){ width: 20%; }
    .admin-table th:nth-child(3){ width: 12%; }
    .admin-table th:nth-child(4){ width: 11%; }
    .admin-table th:nth-child(5){ width: 15%; }
    .admin-table th:nth-child(6){ width: 25%; }
    .badge-active-status   { display:inline-block; padding:2px 10px; border-radius:12px; font-size:90%; font-weight:600; color:#fff; background:#43a047; }
    .badge-inactive-status { display:inline-block; padding:2px 10px; border-radius:12px; font-size:90%; font-weight:600; color:#fff; background:#757575; }
    .action-btn.deactivate { color: #f57c00; }
    .table-wrapper { overflow-x: auto; width: 100%; }
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
        max-width: 440px;
        width: 90%;
        color: #fff;
        box-shadow: 0 8px 32px rgba(0,0,0,0.5);
    }
    .modal-box h2 { margin: 0 0 20px; font-size: 1.3rem; }
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
</style>
<body>
    <div class="Member-content">
        <h1>Staff Management</h1>
        <?php
        require_once '../../../app/config/connection.php';

        // One-time migration: add status column if not exists
        try { $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'active'"); } catch (Exception $e) {}

        // Handle Add Staff
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff'])) {
            $username = trim($_POST['username'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $role     = trim($_POST['role'] ?? 'staff');
            $password = password_hash('12345fitness', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, role, password, status, created_at) VALUES (?, ?, ?, ?, 'active', NOW())");
            $stmt->execute([$username, $email, $role, $password]);
            echo "<meta http-equiv='refresh' content='0'>";
            exit;
        }

        // Handle Edit Staff (staff only, super_admin protected)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_staff'])) {
            $editId      = intval($_POST['edit_id'] ?? 0);
            $username    = trim($_POST['username'] ?? '');
            $email       = trim($_POST['email'] ?? '');
            $role        = trim($_POST['role'] ?? 'staff');
            $newPassword = trim($_POST['new_password'] ?? '');
            if ($editId > 0 && $username !== '') {
                if ($newPassword !== '') {
                    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET username = ?, email = ?, role = ?, password = ? WHERE id = ? AND role != 'super_admin'")
                        ->execute([$username, $email, $role, $hashed, $editId]);
                } else {
                    $pdo->prepare("UPDATE users SET username = ?, email = ?, role = ? WHERE id = ? AND role != 'super_admin'")
                        ->execute([$username, $email, $role, $editId]);
                }
            }
            echo "<meta http-equiv='refresh' content='0'>";
            exit;
        }

        // Handle Toggle Status (staff only, super_admin protected)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status_id'])) {
            $toggleId = intval($_POST['toggle_status_id']);
            $pdo->prepare("UPDATE users SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = ? AND role != 'super_admin'")
                ->execute([$toggleId]);
            echo "<meta http-equiv='refresh' content='0'>";
            exit;
        }

        // Handle Delete (super_admin rows are protected)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
            $deleteId = intval($_POST['delete_id']);
            $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'super_admin'")->execute([$deleteId]);
            echo "<meta http-equiv='refresh' content='0'>";
            exit;
        }

        $search     = $_GET['search'] ?? '';
        $roleFilter = $_GET['role'] ?? '';

        try {
            $query  = "SELECT * FROM users WHERE 1";
            $params = [];
            if ($search !== '') {
                $query   .= " AND username LIKE ?";
                $params[] = "%$search%";
            }
            if ($roleFilter !== '') {
                $query   .= " AND role = ?";
                $params[] = $roleFilter;
            }
            $query .= " ORDER BY created_at DESC";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $staffList = $stmt->fetchAll();
        } catch (Exception $e) {
            echo '<div style="color:red">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            $staffList = [];
        }
        ?>

        <!-- Toolbar -->
        <div style="margin-bottom:15px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <input type="text" id="searchUsername" placeholder="Search username..."
                style="padding:8px 12px; border-radius:8px; border:1px solid #ccc; width:250px;">
            <select id="roleFilter" style="padding:8px 12px; border-radius:8px; border:1px solid #ccc;">
                <option value="">All Roles</option>
                <option value="staff">Staff</option>
                <option value="super_admin">Super Admin</option>
            </select>
            <div style="margin-left:auto;">
                <button type="button" id="addStaffBtn"
                    style="padding:8px 18px; border-radius:8px; border:none; background:#1976d2; color:#fff; font-weight:600; cursor:pointer;">
                    + Add Staff
                </button>
            </div>
        </div>

        <!-- Table -->
        <div class="table-wrapper" style="margin-top:24px;">
            <table class="admin-table" style="width:100%; background:#333;">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($staffList)): ?>
                    <tr><td colspan="6" style="text-align:center;">No staff found.</td></tr>
                <?php else: ?>
                    <?php foreach ($staffList as $staff): ?>
                        <?php
                            $avatarColors = ['#1976d2','#e53935','#388e3c','#f57c00','#7b1fa2','#00838f','#c62828','#2e7d32','#1565c0','#6a1b9a'];
                            $firstLetter  = strtoupper(substr($staff['username'], 0, 1)) ?: '?';
                            $avatarColor  = $avatarColors[ord($firstLetter) % count($avatarColors)];
                            $roleBadge    = match($staff['role']) {
                                'super_admin' => '<span class="badge-status badge-super_admin">Super Admin</span>',
                                'admin'       => '<span class="badge-status badge-admin">Admin</span>',
                                default       => '<span class="badge-status badge-staff">Staff</span>',
                            };
                            $joined = $staff['created_at'] ? date('d M Y', strtotime($staff['created_at'])) : '-';
                        ?>
                        <tr>
                            <td data-label="Username">
                                <div class="user-cell">
                                    <div class="user-avatar" style="background:<?= $avatarColor ?>"><?= htmlspecialchars($firstLetter) ?></div>
                                    <span class="user-name-text"><?= htmlspecialchars($staff['username']) ?></span>
                                </div>
                            </td>
                            <td data-label="Email"><?= htmlspecialchars($staff['email']) ?></td>
                            <td data-label="Role"><?= $roleBadge ?></td>
                            <td data-label="Status">
                                <?php $staffStatus = $staff['status'] ?? 'active'; ?>
                                <?php if ($staffStatus === 'active'): ?>
                                    <span class="badge-active-status">Active</span>
                                <?php else: ?>
                                    <span class="badge-inactive-status">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Joined Date"><?= htmlspecialchars($joined) ?></td>
                            <td data-label="Actions">
                                <?php if ($staff['role'] !== 'super_admin'): ?>
                                    <button type="button" class="action-btn"
                                        data-id="<?= $staff['id'] ?>"
                                        data-username="<?= htmlspecialchars($staff['username'], ENT_QUOTES) ?>"
                                        data-email="<?= htmlspecialchars($staff['email'] ?? '', ENT_QUOTES) ?>"
                                        data-role="<?= htmlspecialchars($staff['role'], ENT_QUOTES) ?>"
                                        onclick="openEditModal(this)">Edit</button>
                                    <form method="post" action="" style="display:inline">
                                        <input type="hidden" name="toggle_status_id" value="<?= $staff['id'] ?>">
                                        <button type="submit" class="action-btn deactivate">
                                            <?= ($staff['status'] ?? 'active') === 'active' ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                    <form method="post" action="" style="display:inline" onsubmit="return confirm('Delete this staff member?');">
                                        <input type="hidden" name="delete_id" value="<?= $staff['id'] ?>">
                                        <button type="submit" class="action-btn delete">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#666; font-size:13px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Staff Modal -->
    <div class="modal-overlay" id="addStaffModal">
        <div class="modal-box">
            <h2>Add Staff</h2>
            <div class="modal-form">
                <form method="post">
                    <input type="hidden" name="add_staff" value="1">
                    <label>Username <span style="color:#e57373">*</span></label>
                    <input type="text" name="username" required placeholder="Enter username">
                    <label>Email <span style="color:#666">(optional)</span></label>
                    <input type="email" name="email" placeholder="example@email.com">
                    <label>Role <span style="color:#e57373">*</span></label>
                    <select name="role">
                        <option value="staff">Staff</option>
                        <option value="super_admin">Super Admin</option>
                    </select>
                    <p style="font-size:12px; color:#888; margin: -4px 0 12px;">Default password: <strong style="color:#aaa">12345fitness</strong></p>
                    <div class="modal-actions">
                        <button type="submit" class="btn-submit">Add Staff</button>
                        <button type="button" class="btn-cancel-modal" id="btnCloseModal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Staff Modal -->
    <div class="modal-overlay" id="editStaffModal">
        <div class="modal-box">
            <h2>Edit Staff</h2>
            <div class="modal-form">
                <form method="post">
                    <input type="hidden" name="edit_staff" value="1">
                    <input type="hidden" name="edit_id" id="editId">
                    <label>Username <span style="color:#e57373">*</span></label>
                    <input type="text" name="username" id="editUsername" required placeholder="Enter username">
                    <label>Email <span style="color:#666">(optional)</span></label>
                    <input type="email" name="email" id="editEmail" placeholder="example@email.com">
                    <label>Role <span style="color:#e57373">*</span></label>
                    <select name="role" id="editRole">
                        <option value="staff">Staff</option>
                        <option value="super_admin">Super Admin</option>
                    </select>
                    <label>New Password <span style="color:#666">(leave blank to keep current)</span></label>
                    <input type="password" name="new_password" id="editPassword" placeholder="Enter new password">
                    <div class="modal-actions">
                        <button type="submit" class="btn-submit">Save Changes</button>
                        <button type="button" class="btn-cancel-modal" id="btnCloseEditModal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    function openEditModal(btn) {
        $('#editId').val(btn.dataset.id);
        $('#editUsername').val(btn.dataset.username);
        $('#editEmail').val(btn.dataset.email);
        $('#editRole').val(btn.dataset.role);
        $('#editPassword').val('');
        $('#editStaffModal').addClass('active');
    }

    $(document).ready(function () {
        function fetchStaff() {
            var search = $('#searchUsername').val();
            var role   = $('#roleFilter').val();
            $.ajax({
                url: 'staff.php',
                method: 'GET',
                data: { search: search, role: role },
                success: function (data) {
                    var newTbody = $(data).find('tbody').html();
                    $('tbody').html(newTbody);
                }
            });
        }

        $('#searchUsername').on('keyup', fetchStaff);
        $('#roleFilter').on('change', fetchStaff);

        $('#addStaffBtn').on('click', function () {
            $('#addStaffModal').addClass('active');
        });
        $('#btnCloseModal').on('click', function () {
            $('#addStaffModal').removeClass('active');
        });
        $('#addStaffModal').on('click', function (e) {
            if (e.target === this) $('#addStaffModal').removeClass('active');
        });

        $('#btnCloseEditModal').on('click', function () {
            $('#editStaffModal').removeClass('active');
        });
        $('#editStaffModal').on('click', function (e) {
            if (e.target === this) $('#editStaffModal').removeClass('active');
        });
    });
    </script>
</body>
</html>
