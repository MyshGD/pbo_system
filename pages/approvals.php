<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_admin($pdo);

// Handle approval actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid security token. Please try again.');
        redirect('approvals.php');
    }

    $approvalId = isset($_POST['approval_id']) ? (int) $_POST['approval_id'] : 0;
    $action = (string) $_POST['action'];
    $remarks = isset($_POST['remarks']) ? trim((string) $_POST['remarks']) : null;

    if ($approvalId > 0) {
        $approval = get_approval_request($pdo, $approvalId);

        if (!$approval) {
            set_flash('error', 'Approval request not found.');
            redirect('approvals.php');
        }

        try {
            if ($action === 'approve') {
                approve_approval_request($pdo, $approvalId, (int) $user['id'], $remarks);
                set_flash('success', 'Approval request approved successfully.');
            } elseif ($action === 'reject') {
                reject_approval_request($pdo, $approvalId, (int) $user['id'], $remarks);
                set_flash('success', 'Approval request rejected successfully.');
            } elseif ($action === 'revision') {
                request_revision_on_approval($pdo, $approvalId, (int) $user['id'], $remarks);
                set_flash('success', 'Revision requested from requester.');
            }
        } catch (Throwable $e) {
            set_flash('error', 'Failed to process approval request: ' . $e->getMessage());
        }

        redirect('approvals.php?status=' . ($approval['status'] ?? 'pending'));
    }
}

// Get filter parameters
$status = isset($_GET['status']) ? (string) $_GET['status'] : 'pending';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

// Validate status
$validStatuses = ['pending', 'approved', 'rejected', 'needs_revision', 'all'];
if (!in_array($status, $validStatuses, true)) {
    $status = 'pending';
}

// Get approval requests
$displayStatus = $status === 'all' ? null : $status;
$limit = 20;

// Get total count for pagination
$countQuery = 'SELECT COUNT(*) FROM approval_requests';
$countParams = [];
if ($status !== 'all') {
    $countQuery .= ' WHERE status = :status';
    $countParams['status'] = $status;
}
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($countParams);
$totalApprovals = (int) $countStmt->fetchColumn();
$pagination = pagination_meta($totalApprovals, $page, $limit);
$page = (int) $pagination['page'];

$approvals = list_approval_requests($pdo, $displayStatus, $page, $limit);

// Status counts for tabs
$statusCounts = [
    'all' => (int) $pdo->query('SELECT COUNT(*) FROM approval_requests')->fetchColumn(),
];
foreach (['pending', 'approved', 'rejected', 'needs_revision'] as $s) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM approval_requests WHERE status = :status');
    $stmt->execute(['status' => $s]);
    $statusCounts[$s] = (int) $stmt->fetchColumn();
}

render_header('Approval Requests', $user);
?>

<p class="page-intro">Manage approval requests from staff members.</p>

<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3 class="text-base font-semibold text-slate-950">Approval Requests</h3>
        </div>
        <span class="badge"><?= h((string) $totalApprovals) ?> request<?= $totalApprovals === 1 ? '' : 's' ?></span>
    </div>
    <nav class="tabs" aria-label="Approval status">
        <?php
            $tabItems = [
                'all' => 'All',
                'pending' => 'Pending',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                'needs_revision' => 'Needs Revision',
            ];
        ?>
        <?php foreach ($tabItems as $tabKey => $tabLabel): ?>
            <a class="tab-link <?= $status === $tabKey ? 'active' : '' ?>" href="approvals.php?status=<?= h($tabKey) ?>">
                <?= h($tabLabel) ?>
                <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"><?= h((string) ($statusCounts[$tabKey] ?? 0)) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if (!empty($approvals)): ?>
        <div class="table-wrap" data-no-table-enhance>
            <table>
                <thead>
                    <tr>
                        <th>Date Requested</th>
                        <th>Requested By</th>
                        <th>Module</th>
                        <th>Action Type</th>
                        <th>Record</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approvals as $approval): ?>
                        <?php
                            $recordLabel = $approval['entity_type'] ? (string) $approval['entity_type'] . '#' . (string) $approval['entity_id'] : '-';
                            $recordLink = null;
                            if (
                                strtolower((string) $approval['module']) === 'proposals'
                                && strtolower((string) $approval['entity_type']) === 'proposal'
                            ) {
                                $recordLink = 'proposals.php?status=pending&proposal_id=' . rawurlencode((string) $approval['entity_id']);
                            }
                        ?>
                        <tr>
                            <td><?= h(date('M d, Y H:i', strtotime($approval['created_at']))) ?></td>
                            <td>
                                <div class="font-semibold text-slate-900"><?= h($approval['requester_name']) ?></div>
                                <div class="muted mt-1"><?= h($approval['requester_role']) ?></div>
                            </td>
                            <td><?= h($approval['module']) ?></td>
                            <td><?= h($approval['action_type']) ?></td>
                            <td>
                                <?php if ($recordLink): ?>
                                    <a href="<?= h($recordLink) ?>"><?= h($recordLabel) ?></a>
                                <?php else: ?>
                                    <span class="muted"><?= h($recordLabel) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php render_status_badge((string) $approval['status']); ?></td>
                            <td>
                                <details class="action-menu">
                                    <summary>Actions</summary>
                                    <div class="action-menu-panel w-48">
                                        <button type="button" class="btn alt action-menu-item" data-open-modal="approval-view-<?= (int) $approval['id'] ?>">View Details</button>
                                        <?php if ($approval['status'] === 'pending'): ?>
                                            <button type="button" class="btn alt action-menu-item mt-1" data-open-modal="approval-review-<?= (int) $approval['id'] ?>">Review & Decide</button>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="data-panel-footer flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <span>Total <?= h((string) ($statusCounts['all'] ?? 0)) ?> | Pending <?= h((string) ($statusCounts['pending'] ?? 0)) ?> | Approved <?= h((string) ($statusCounts['approved'] ?? 0)) ?> | Rejected <?= h((string) ($statusCounts['rejected'] ?? 0)) ?></span>
            <?php render_pagination($pagination); ?>
        </div>
    <?php else: ?>
        <?php render_empty_state('No approval requests found.', 'Requests needing review will appear here.'); ?>
    <?php endif; ?>
</section>

<?php foreach ($approvals as $approval): ?>
    <dialog id="approval-view-<?= (int) $approval['id'] ?>" class="modal modal-wide">
        <div class="modal-header"><h3>Approval Request Details</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
        <div class="modal-content">
            <dl class="grid gap-3 text-sm md:grid-cols-2">
                <div>
                    <dt class="font-semibold text-slate-700">Requested By</dt>
                    <dd><?= h($approval['requester_name']) ?> (<?= h($approval['requester_role']) ?>)</dd>
                </div>
                <div>
                    <dt class="font-semibold text-slate-700">Date Requested</dt>
                    <dd><?= h(date('M d, Y H:i:s', strtotime($approval['created_at']))) ?></dd>
                </div>
                <div>
                    <dt class="font-semibold text-slate-700">Module</dt>
                    <dd><?= h($approval['module']) ?></dd>
                </div>
                <div>
                    <dt class="font-semibold text-slate-700">Action Type</dt>
                    <dd><?= h($approval['action_type']) ?></dd>
                </div>
                <?php if ($approval['entity_type']): ?>
                    <div>
                        <dt class="font-semibold text-slate-700">Entity Type</dt>
                        <dd><?= h($approval['entity_type']) ?></dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-slate-700">Entity ID</dt>
                        <dd><?= h($approval['entity_id']) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>

            <?php if ($approval['old_value']): ?>
                <div class="mt-4">
                    <p class="text-sm font-semibold text-slate-700">Previous Value</p>
                    <pre class="mt-2 rounded-lg border border-brand-100 bg-brand-50 p-3 text-xs text-slate-700"><?= h(json_encode($approval['old_value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                </div>
            <?php endif; ?>

            <?php if ($approval['new_value']): ?>
                <div class="mt-4">
                    <p class="text-sm font-semibold text-slate-700">Proposed Value</p>
                    <pre class="mt-2 rounded-lg border border-brand-100 bg-brand-50 p-3 text-xs text-slate-700"><?= h(json_encode($approval['new_value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                </div>
            <?php endif; ?>

            <?php if ($approval['status'] !== 'pending'): ?>
                <div class="mt-4 rounded-lg border border-brand-100 bg-brand-50 p-3 text-sm">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <p class="font-semibold text-slate-700">Decision Date</p>
                            <p><?= h(date('M d, Y H:i:s', strtotime($approval['decision_date']))) ?></p>
                        </div>
                        <div>
                            <p class="font-semibold text-slate-700">Decided By</p>
                            <p><?= h($approval['decided_by_name'] ?? 'System') ?></p>
                        </div>
                    </div>
                    <?php if ($approval['admin_remarks']): ?>
                        <div class="mt-3">
                            <p class="font-semibold text-slate-700">Admin Remarks</p>
                            <p><?= h($approval['admin_remarks']) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </dialog>

    <?php if ($approval['status'] === 'pending'): ?>
        <dialog id="approval-review-<?= (int) $approval['id'] ?>" class="modal">
            <div class="modal-header"><h3>Review Approval Request</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="approval_id" value="<?= (int) $approval['id'] ?>">
                <div class="form-grid">
                    <div class="field-wide">
                        <label for="remarks_<?= (int) $approval['id'] ?>">Admin Remarks</label>
                        <textarea id="remarks_<?= (int) $approval['id'] ?>" name="remarks" placeholder="Add remarks or feedback for this request"></textarea>
                        <p class="mt-1 text-xs text-slate-500">Remarks will be visible to the requester.</p>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn alt" data-close-modal>Cancel</button>
                    <button type="submit" name="action" value="approve">Approve</button>
                    <button type="submit" class="btn alt text-amber-700 hover:bg-amber-50" name="action" value="revision">Request Revision</button>
                    <button type="submit" class="btn alt text-red-700 hover:bg-red-50" name="action" value="reject">Reject</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>
<?php endforeach; ?>

<?php render_footer(); ?>
