<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);
require_permission($user, 'view_security_logs');

$type = (string) ($_GET['type'] ?? 'audit');
$type = in_array($type, ['audit', 'errors', 'login', 'sessions'], true) ? $type : 'audit';
$q = trim((string) ($_GET['q'] ?? ''));
$order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$page = page_param();
$perPage = 25;

function readable_log_details(?string $details): string
{
    $raw = trim((string) $details);
    if ($raw === '') {
        return '-';
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $raw;
    }

    $parts = [];
    foreach ($decoded as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $parts[] = ucwords(str_replace('_', ' ', (string) $key)) . ': ' . (string) $value;
    }

    return implode('; ', $parts);
}

if ($type === 'errors') {
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(sel.message LIKE :q OR sel.context LIKE :q OR sel.severity LIKE :q OR u.username LIKE :q)';
        $params['q'] = prefix_search_param($q);
    }

    $countSql = 'SELECT COUNT(*)
        FROM system_error_logs sel
        LEFT JOIN users u ON u.id = sel.user_id
        WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $pagination = pagination_meta((int) $countStmt->fetchColumn(), $page, $perPage);

    $listSql = 'SELECT created_at, severity, message, context, ip_address, username
        FROM (
            SELECT sel.created_at, sel.severity, sel.message, sel.context, sel.ip_address, u.username,
                ROW_NUMBER() OVER (ORDER BY sel.created_at ' . $order . ', sel.id ' . $order . ') AS row_num
            FROM system_error_logs sel
            LEFT JOIN users u ON u.id = sel.user_id
            WHERE ' . implode(' AND ', $where) . '
        ) ranked_errors
        WHERE row_num BETWEEN :first_row AND :last_row
        ORDER BY row_num';
    $stmt = $pdo->prepare($listSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
    }
    [$firstRow, $lastRow] = pagination_row_bounds($pagination);
    $stmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
    $stmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} elseif ($type === 'login') {
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(username LIKE :q OR ip_address LIKE :q)';
        $params['q'] = prefix_search_param($q);
    }

    $countSql = 'SELECT COUNT(*) FROM login_attempts WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $pagination = pagination_meta((int) $countStmt->fetchColumn(), $page, $perPage);

    $listSql = 'SELECT created_at, username, ip_address, was_successful
        FROM (
            SELECT attempted_at AS created_at, username, ip_address, was_successful,
                ROW_NUMBER() OVER (ORDER BY attempted_at ' . $order . ', id ' . $order . ') AS row_num
            FROM login_attempts
            WHERE ' . implode(' AND ', $where) . '
        ) ranked_login
        WHERE row_num BETWEEN :first_row AND :last_row
        ORDER BY row_num';
    $stmt = $pdo->prepare($listSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
    }
    [$firstRow, $lastRow] = pagination_row_bounds($pagination);
    $stmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
    $stmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} elseif ($type === 'sessions') {
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(sl.event LIKE :q OR sl.ip_address LIKE :q OR sl.user_agent LIKE :q OR u.username LIKE :q)';
        $params['q'] = prefix_search_param($q);
    }

    $countSql = 'SELECT COUNT(*)
        FROM session_logs sl
        LEFT JOIN users u ON u.id = sl.user_id
        WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $pagination = pagination_meta((int) $countStmt->fetchColumn(), $page, $perPage);

    $listSql = 'SELECT created_at, event, session_id, ip_address, user_agent, username
        FROM (
            SELECT sl.created_at, sl.event, sl.session_id, sl.ip_address, sl.user_agent, u.username,
                ROW_NUMBER() OVER (ORDER BY sl.created_at ' . $order . ', sl.id ' . $order . ') AS row_num
            FROM session_logs sl
            LEFT JOIN users u ON u.id = sl.user_id
            WHERE ' . implode(' AND ', $where) . '
        ) ranked_sessions
        WHERE row_num BETWEEN :first_row AND :last_row
        ORDER BY row_num';
    $stmt = $pdo->prepare($listSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
    }
    [$firstRow, $lastRow] = pagination_row_bounds($pagination);
    $stmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
    $stmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} else {
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(al.action LIKE :q OR al.module LIKE :q OR al.entity_type LIKE :q OR al.entity_id LIKE :q OR al.details LIKE :q OR u.username LIKE :q)';
        $params['q'] = prefix_search_param($q);
    }

    $countSql = 'SELECT COUNT(*)
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $pagination = pagination_meta((int) $countStmt->fetchColumn(), $page, $perPage);

    $listSql = 'SELECT created_at, action, module, entity_type, entity_id, details, ip_address, username
        FROM (
            SELECT al.created_at, al.action, al.module, al.entity_type, al.entity_id, al.details, al.ip_address, u.username,
                ROW_NUMBER() OVER (ORDER BY al.created_at ' . $order . ', al.id ' . $order . ') AS row_num
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE ' . implode(' AND ', $where) . '
        ) ranked_audit
        WHERE row_num BETWEEN :first_row AND :last_row
        ORDER BY row_num';
    $stmt = $pdo->prepare($listSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
    }
    [$firstRow, $lastRow] = pagination_row_bounds($pagination);
    $stmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
    $stmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

audit_log($pdo, $user, 'view_security_logs', 'security', $type, null, ['q' => $q]);

render_header('Security and Audit Logs', $user);
?>

<?php render_page_header('Review audit activity, login attempts, sessions, and system issues.'); ?>

<section class="table-card data-panel">
    <div class="section-heading">
        <h3><?= h($type === 'errors' ? 'Error Logs' : ($type === 'login' ? 'Login Attempts' : ($type === 'sessions' ? 'Login Sessions' : 'Audit Logs'))) ?></h3>
        <span class="badge"><?= h((string) $pagination['total_rows']) ?> record<?= (int) $pagination['total_rows'] === 1 ? '' : 's' ?></span>
    </div>

    <form method="get" class="form-grid data-panel-filters">
        <div>
            <label for="type">Log Type</label>
            <select id="type" name="type">
                <option value="audit" <?= $type === 'audit' ? 'selected' : '' ?>>Audit Logs</option>
                <option value="errors" <?= $type === 'errors' ? 'selected' : '' ?>>Error Logs</option>
                <option value="login" <?= $type === 'login' ? 'selected' : '' ?>>Login Attempts</option>
                <option value="sessions" <?= $type === 'sessions' ? 'selected' : '' ?>>Login Sessions</option>
            </select>
        </div>
        <div>
            <label for="q">Search</label>
            <input id="q" name="q" value="<?= h($q) ?>" placeholder="User, action, module, IP">
        </div>
        <div>
            <label for="order">Order</label>
            <select id="order" name="order">
                <option value="desc" <?= $order === 'DESC' ? 'selected' : '' ?>>Newest First</option>
                <option value="asc" <?= $order === 'ASC' ? 'selected' : '' ?>>Oldest First</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Apply</button>
        </div>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
            <?php if ($type === 'errors'): ?>
                <tr>
                    <th>Date</th>
                    <th>Severity</th>
                    <th>Message</th>
                    <th>Details</th>
                    <th>User</th>
                    <th>IP</th>
                </tr>
            <?php elseif ($type === 'login'): ?>
                <tr>
                    <th>Date</th>
                    <th>Username</th>
                    <th>IP</th>
                    <th>Status</th>
                </tr>
            <?php elseif ($type === 'sessions'): ?>
                <tr>
                    <th>Date</th>
                    <th>User</th>
                    <th>Event</th>
                    <th>Session</th>
                    <th>IP</th>
                    <th>User Agent</th>
                </tr>
            <?php else: ?>
                <tr>
                    <th>Date</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Entity</th>
                    <th>Details</th>
                    <th>IP</th>
                </tr>
            <?php endif; ?>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="<?= $type === 'login' ? '4' : ($type === 'sessions' ? '6' : ($type === 'errors' ? '6' : '7')) ?>" class="muted">No logs found.</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($rows as $row): ?>
                <?php if ($type === 'errors'): ?>
                    <tr>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?php render_status_badge((string) $row['severity']); ?></td>
                        <td><?= h($row['message']) ?></td>
                        <td>
                            <?= h(readable_log_details((string) $row['context'])) ?>
                            <?php if (!empty($row['context'])): ?>
                                <details class="mt-1"><summary class="cursor-pointer text-xs font-semibold text-brand-700">View raw details</summary><pre class="mt-2 whitespace-pre-wrap rounded-md bg-slate-50 p-2 text-xs"><?= h($row['context']) ?></pre></details>
                            <?php endif; ?>
                        </td>
                        <td><?= h($row['username'] ?: '-') ?></td>
                        <td><?= h($row['ip_address']) ?></td>
                    </tr>
                <?php elseif ($type === 'login'): ?>
                    <tr>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?= h($row['username']) ?></td>
                        <td><?= h($row['ip_address']) ?></td>
                        <td><?php render_status_badge(((int) $row['was_successful']) === 1 ? 'approved' : 'rejected'); ?></td>
                    </tr>
                <?php elseif ($type === 'sessions'): ?>
                    <tr>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?= h($row['username'] ?: '-') ?></td>
                        <td><?= h($row['event']) ?></td>
                        <td><?= h(substr((string) $row['session_id'], 0, 16)) ?></td>
                        <td><?= h($row['ip_address']) ?></td>
                        <td><?= h($row['user_agent']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td><?= h($row['created_at']) ?></td>
                        <td><?= h($row['username'] ?: '-') ?></td>
                        <td><?= h($row['action']) ?></td>
                        <td><?= h($row['module']) ?></td>
                        <td><?= h(trim((string) $row['entity_type'] . ' ' . (string) $row['entity_id']) ?: '-') ?></td>
                        <td>
                            <?= h(readable_log_details((string) $row['details'])) ?>
                            <?php if (!empty($row['details'])): ?>
                                <details class="mt-1"><summary class="cursor-pointer text-xs font-semibold text-brand-700">View raw details</summary><pre class="mt-2 whitespace-pre-wrap rounded-md bg-slate-50 p-2 text-xs"><?= h($row['details']) ?></pre></details>
                            <?php endif; ?>
                        </td>
                        <td><?= h($row['ip_address']) ?></td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($pagination); ?>
    <div class="data-panel-footer">
        <?= h((string) $pagination['total_rows']) ?> <?= h($type === 'errors' ? 'error log' : ($type === 'login' ? 'login attempt' : ($type === 'sessions' ? 'login session' : 'audit log'))) ?><?= (int) $pagination['total_rows'] === 1 ? '' : 's' ?> under current filter.
    </div>
</section>

<?php render_footer();
