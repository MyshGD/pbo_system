<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);
require_permission($user, 'manage_users');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        set_flash('error', 'Invalid form token.');
        redirect('users.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    handle_person_post($pdo, $user, 'users.php');

    if ($action === 'add_user') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'staff');
        $status = (string) ($_POST['status'] ?? 'approved');

        if ($username === '' || $fullName === '' || strlen($password) < 8 || !in_array($role, ['admin', 'staff'], true) || !in_array($status, ['pending', 'approved', 'suspended'], true)) {
            set_flash('error', 'Complete user details are required. Password must be at least 8 characters.');
            redirect('users.php');
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, status, approved_by, approved_at)
                VALUES (:username, :password_hash, :full_name, :role, :status, :approved_by, :approved_at)');
            $stmt->execute([
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name' => $fullName,
                'role' => $role,
                'status' => $status,
                'approved_by' => $status === 'approved' ? (int) $user['id'] : null,
                'approved_at' => $status === 'approved' ? date('Y-m-d H:i:s') : null,
            ]);
            audit_log($pdo, $user, 'create_user', 'users', 'user', (int) $pdo->lastInsertId(), ['username' => $username, 'role' => $role, 'status' => $status]);
            set_flash('success', 'User account created.');
        } catch (PDOException $e) {
            log_system_issue($pdo, 'error', 'Could not create user.', ['error' => $e->getMessage(), 'username' => $username], $user);
            set_flash('error', 'Could not create user. Username may already exist.');
        }

        redirect('users.php');
    }

    if ($action === 'update_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? 'staff');
        $status = (string) ($_POST['status'] ?? 'pending');

        if ($userId <= 0 || !in_array($role, ['admin', 'staff'], true) || !in_array($status, ['pending', 'approved', 'suspended'], true)) {
            set_flash('error', 'Invalid user update.');
            redirect('users.php');
        }

        $stmt = $pdo->prepare('UPDATE users
            SET role = :role,
                status = :status,
                approved_by = CASE WHEN :status = "approved" THEN :approved_by ELSE approved_by END,
                approved_at = CASE WHEN :status = "approved" THEN NOW() ELSE approved_at END
            WHERE id = :id');
        $stmt->execute([
            'id' => $userId,
            'role' => $role,
            'status' => $status,
            'approved_by' => (int) $user['id'],
        ]);
        audit_log($pdo, $user, 'update_user', 'users', 'user', $userId, ['role' => $role, 'status' => $status]);
        set_flash('success', 'User account updated.');
        redirect('users.php');
    }
}

$rows = $pdo->query('SELECT u.id, u.username, u.full_name, u.role, u.status, u.created_at, u.approved_at, approver.username AS approved_by_username
    FROM users u
    LEFT JOIN users approver ON approver.id = u.approved_by
    ORDER BY FIELD(u.status, "pending", "approved", "suspended"), u.full_name')->fetchAll();
$people = $pdo->query('SELECT p.id, p.full_name, p.person_code, p.department, p.role_or_position, p.contact_info, p.status, p.created_at, p.updated_at, approver.full_name AS approved_by_name
    FROM people p
    LEFT JOIN users approver ON approver.id = p.approved_by
    ORDER BY FIELD(p.status, "pending", "approved", "inactive"), p.full_name ASC')->fetchAll();

render_header('User Management', $user);
?>

<?php render_page_header('Manage user roles, approval status, and account access.', 'Add User', 'user-modal'); ?>
<div class="mb-4">
    <button type="button" class="btn alt" data-open-modal="person-modal">Add Person</button>
</div>

<dialog id="user-modal" class="modal">
    <div class="modal-header">
        <h3>Add User</h3>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_user">
        <div class="form-grid">
            <div><label for="username">Username</label><input id="username" name="username" required></div>
            <div><label for="full_name">Full Name</label><input id="full_name" name="full_name" required></div>
            <div><label for="password">Temporary Password</label><input id="password" type="password" name="password" minlength="8" placeholder="Enter temporary password" required></div>
            <div><label for="role">Role</label><select id="role" name="role"><option value="staff">Staff</option><option value="admin">Admin</option></select></div>
            <div><label for="status">Status</label><select id="status" name="status"><option value="approved">Approved</option><option value="pending">Pending</option><option value="suspended">Suspended</option></select></div>
        </div>
        <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Save User</button></div>
    </form>
</dialog>

<section class="table-card">
    <div class="section-heading">
        <h3>Accounts</h3>
        <span class="badge"><?= h((string) count($rows)) ?> user<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Approved By</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= h($row['full_name']) ?></td>
                    <td><?= h($row['username']) ?></td>
                    <td><?= h(ucfirst((string) $row['role'])) ?></td>
                    <td><?php render_status_badge((string) $row['status']); ?></td>
                    <td><?= h($row['approved_by_username'] ?: '-') ?></td>
                    <td>
                        <details class="action-menu">
                            <summary>Actions</summary>
                            <div class="action-menu-panel w-64 p-3">
                                <form method="post" class="space-y-2">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_user">
                                    <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                    <label>Role</label>
                                    <select name="role" aria-label="Role">
                                        <option value="staff" <?= $row['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                                        <option value="admin" <?= $row['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    </select>
                                    <label>Status</label>
                                    <select name="status" aria-label="Status">
                                        <option value="pending" <?= $row['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="approved" <?= $row['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                                        <option value="suspended" <?= $row['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                    </select>
                                    <button type="submit" class="w-full">Save</button>
                                </form>
                            </div>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="data-panel-footer">
        <?= h((string) count($rows)) ?> user account<?= count($rows) === 1 ? '' : 's' ?> listed.
    </div>
</section>

<section class="table-card mt-4">
    <div class="section-heading">
        <h3>People Master List</h3>
        <span class="badge"><?= h((string) count($people)) ?> person<?= count($people) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Full Name</th>
                <th>ID or Code</th>
                <th>Department</th>
                <th>Role or Position</th>
                <th>Status</th>
                <th>Approved By</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$people): ?>
                <tr><td colspan="7" class="muted">No people records yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($people as $person): ?>
                <tr>
                    <td><?= h($person['full_name']) ?></td>
                    <td><?= h($person['person_code'] ?: '-') ?></td>
                    <td><?= h($person['department'] ?: '-') ?></td>
                    <td><?= h($person['role_or_position'] ?: '-') ?></td>
                    <td><?php render_status_badge((string) $person['status']); ?></td>
                    <td><?= h($person['approved_by_name'] ?: '-') ?></td>
                    <td><button type="button" class="btn alt" data-open-modal="edit-person-<?= (int) $person['id'] ?>">Edit</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="data-panel-footer">
        Pending staff-submitted people become selectable after admin approval.
    </div>
</section>

<?php render_add_person_modal($user, 'users.php'); ?>

<?php foreach ($people as $person): ?>
    <dialog id="edit-person-<?= (int) $person['id'] ?>" class="modal">
        <div class="modal-header">
            <h3>Edit Person</h3>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_person">
            <input type="hidden" name="return_to" value="users.php">
            <input type="hidden" name="person_id" value="<?= (int) $person['id'] ?>">
            <div class="form-grid">
                <div><label for="person_name_<?= (int) $person['id'] ?>">Full Name</label><input id="person_name_<?= (int) $person['id'] ?>" name="full_name" value="<?= h($person['full_name']) ?>" required></div>
                <div><label for="person_code_<?= (int) $person['id'] ?>">ID or Code</label><input id="person_code_<?= (int) $person['id'] ?>" name="person_code" value="<?= h($person['person_code']) ?>"></div>
                <div><label for="person_dept_<?= (int) $person['id'] ?>">Department</label><input id="person_dept_<?= (int) $person['id'] ?>" name="department" value="<?= h($person['department']) ?>"></div>
                <div><label for="person_role_<?= (int) $person['id'] ?>">Role or Position</label><input id="person_role_<?= (int) $person['id'] ?>" name="role_or_position" value="<?= h($person['role_or_position']) ?>"></div>
                <div><label for="person_contact_<?= (int) $person['id'] ?>">Contact Info</label><input id="person_contact_<?= (int) $person['id'] ?>" name="contact_info" value="<?= h($person['contact_info']) ?>"></div>
                <div><label for="person_status_<?= (int) $person['id'] ?>">Status</label><select id="person_status_<?= (int) $person['id'] ?>" name="status"><option value="pending" <?= $person['status'] === 'pending' ? 'selected' : '' ?>>Pending</option><option value="approved" <?= $person['status'] === 'approved' ? 'selected' : '' ?>>Approved</option><option value="inactive" <?= $person['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn alt" data-close-modal>Cancel</button>
                <button type="submit">Save Person</button>
            </div>
        </form>
    </dialog>
<?php endforeach; ?>

<?php render_footer();
