<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        set_flash('error', 'Invalid form token.');
        redirect('proposals.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    handle_person_post($pdo, $user, 'proposals.php');

    if ($action === 'submit_proposal') {
        if ($user['role'] === 'admin') {
            set_flash('error', 'Admins review proposals. Staff should submit requests.');
            redirect('proposals.php');
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        $proposerId = (int) ($_POST['proposer_id'] ?? 0);
        $department = trim((string) ($_POST['department'] ?? ''));
        $budget = (float) ($_POST['estimated_budget'] ?? 0);
        $targetDate = trim((string) ($_POST['target_date'] ?? ''));
        $summary = trim((string) ($_POST['summary'] ?? ''));
        // Allow both approved and pending people as proposers
        $proposer = find_person($pdo, $proposerId, false);

        if ($title === '' || !$proposer || $budget < 0) {
            set_flash('error', 'Title, proposer, and valid budget are required.');
            redirect('proposals.php');
        }

        $departmentValue = $department !== '' ? $department : (string) ($proposer['department'] ?? '');

        // Staff proposals always start as Pending; Admin can set other statuses if needed
        $proposalStatus = $user['role'] === 'admin' ? (string) ($_POST['status'] ?? 'submitted') : 'submitted';

        $stmt = $pdo->prepare('INSERT INTO proposals (title, proposer_id, proposer_name, department, estimated_budget, target_date, summary, status, created_by, created_at)
            VALUES (:title, :proposer_id, :proposer_name, :department, :estimated_budget, :target_date, :summary, :status, :created_by, NOW())');
        $stmt->execute([
            'title' => $title,
            'proposer_id' => (int) $proposer['id'],
            'proposer_name' => (string) $proposer['full_name'],
            'department' => $departmentValue !== '' ? $departmentValue : null,
            'estimated_budget' => $budget,
            'target_date' => $targetDate !== '' ? $targetDate : null,
            'summary' => $summary !== '' ? $summary : null,
            'status' => $proposalStatus,
            'created_by' => (int) $user['id'],
        ]);
        $proposalId = (int) $pdo->lastInsertId();
        if ($user['role'] === 'staff') {
            try {
                create_approval_request(
                    $pdo,
                    (int) $user['id'],
                    'proposals',
                    'submit_proposal',
                    'proposal',
                    (string) $proposalId,
                    null,
                    [
                        'title' => $title,
                        'proposer_name' => (string) $proposer['full_name'],
                        'department' => $departmentValue !== '' ? $departmentValue : null,
                        'estimated_budget' => $budget,
                        'target_date' => $targetDate !== '' ? $targetDate : null,
                        'summary' => $summary !== '' ? $summary : null,
                        'status' => $proposalStatus,
                    ]
                );
            } catch (Throwable $e) {
                error_log('Proposal approval request creation failed: ' . $e->getMessage());
            }
        }
        audit_log($pdo, $user, 'submit_proposal', 'proposals', 'proposal', $proposalId, ['title' => $title, 'status' => $proposalStatus]);
        set_flash('success', 'Proposal submitted for admin review.');
        redirect('proposals.php');
    }

    if ($action === 'update_proposal_status') {
        if ($user['role'] !== 'admin') {
            set_flash('error', 'You do not have permission to perform this action.');
            redirect('proposals.php');
        }

        $proposalId = (int) ($_POST['proposal_id'] ?? 0);
        $newStatus = (string) ($_POST['status'] ?? '');
        $adminNotes = trim((string) ($_POST['admin_notes'] ?? ''));
        $allowedStatuses = ['submitted', 'under_review', 'approved', 'rejected', 'needs_revision', 'cancelled', 'implemented'];

        if ($proposalId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
            set_flash('error', 'Invalid proposal status update.');
            redirect('proposals.php');
        }

        $proposalStmt = $pdo->prepare('SELECT id, status FROM proposals WHERE id = :id');
        $proposalStmt->execute(['id' => $proposalId]);
        $proposal = $proposalStmt->fetch();
        if (!$proposal) {
            set_flash('error', 'Proposal not found.');
            redirect('proposals.php');
        }

        $oldStatus = $proposal['status'];
        $stmt = $pdo->prepare('UPDATE proposals
            SET status = :status, admin_notes = :admin_notes, reviewed_by = :reviewed_by, reviewed_at = NOW()
            WHERE id = :id');
        $stmt->execute([
            'id' => $proposalId,
            'status' => $newStatus,
            'admin_notes' => $adminNotes !== '' ? $adminNotes : null,
            'reviewed_by' => (int) $user['id'],
        ]);

        audit_log($pdo, $user, 'proposal_status_updated', 'proposals', 'proposal', $proposalId, [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'admin_notes' => $adminNotes,
        ]);

        set_flash('success', 'Proposal status updated.');
        redirect('proposals.php');
    }

    if ($action === 'approve_proposal' || $action === 'reject_proposal' || $action === 'request_revision_proposal') {
        // Only Admin can approve, reject, or request revision
        if ($user['role'] !== 'admin') {
            set_flash('error', 'You do not have permission to perform this action.');
            redirect('proposals.php');
        }

        $proposalId = (int) ($_POST['proposal_id'] ?? 0);
        if ($proposalId <= 0) {
            set_flash('error', 'Invalid proposal.');
            redirect('proposals.php');
        }

        $proposalStmt = $pdo->prepare('SELECT id, status FROM proposals WHERE id = :id');
        $proposalStmt->execute(['id' => $proposalId]);
        $proposal = $proposalStmt->fetch();
        if (!$proposal) {
            set_flash('error', 'Proposal not found.');
            redirect('proposals.php');
        }

        $oldStatus = $proposal['status'];
        $adminNotes = trim((string) ($_POST['admin_notes'] ?? ''));

        $newStatus = match($action) {
            'approve_proposal' => 'approved',
            'reject_proposal' => 'rejected',
            'request_revision_proposal' => 'needs_revision',
            default => $oldStatus,
        };

        $actionLabel = match($action) {
            'approve_proposal' => 'Approved',
            'reject_proposal' => 'Rejected',
            'request_revision_proposal' => 'Revision Requested',
            default => 'Updated',
        };

        $stmt = $pdo->prepare('UPDATE proposals
            SET status = :status, admin_notes = :admin_notes, reviewed_by = :reviewed_by, reviewed_at = NOW()
            WHERE id = :id');
        $stmt->execute([
            'id' => $proposalId,
            'status' => $newStatus,
            'admin_notes' => $adminNotes !== '' ? $adminNotes : null,
            'reviewed_by' => (int) $user['id'],
        ]);

        $auditAction = match($action) {
            'approve_proposal' => 'proposal_approved',
            'reject_proposal' => 'proposal_rejected',
            'request_revision_proposal' => 'proposal_revision_requested',
            default => 'proposal_updated',
        };

        audit_log($pdo, $user, $auditAction, 'proposals', 'proposal', $proposalId, [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'admin_notes' => $adminNotes,
        ]);

        set_flash('success', 'Proposal ' . $actionLabel . '.');
        redirect('proposals.php');
    }

    if ($action === 'cancel_proposal') {
        // Only Admin can cancel; Staff can only cancel their own pending proposals
        $proposalId = (int) ($_POST['proposal_id'] ?? 0);
        if ($proposalId <= 0) {
            set_flash('error', 'Invalid proposal.');
            redirect('proposals.php');
        }

        $proposalStmt = $pdo->prepare('SELECT id, status, created_by FROM proposals WHERE id = :id');
        $proposalStmt->execute(['id' => $proposalId]);
        $proposal = $proposalStmt->fetch();
        if (!$proposal) {
            set_flash('error', 'Proposal not found.');
            redirect('proposals.php');
        }

        // Staff can only cancel their own pending proposals
        if ($user['role'] === 'staff' && ($proposal['created_by'] !== (int) $user['id'] || $proposal['status'] !== 'submitted')) {
            set_flash('error', 'You can only cancel your own pending proposals.');
            redirect('proposals.php');
        }

        $oldStatus = $proposal['status'];

        $stmt = $pdo->prepare('UPDATE proposals SET status = :status, reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $proposalId,
            'status' => 'cancelled',
            'reviewed_by' => (int) $user['id'],
        ]);

        audit_log($pdo, $user, 'proposal_cancelled', 'proposals', 'proposal', $proposalId, ['old_status' => $oldStatus]);
        set_flash('success', 'Proposal cancelled.');
        redirect('proposals.php');
    }
}

$status = (string) ($_GET['status'] ?? 'all');
$proposalTabs = [
    'all' => 'All',
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'needs_revision' => 'Needs Revision',
];
$statusOptions = [
    'submitted' => 'Submitted',
    'under_review' => 'Under Review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'needs_revision' => 'Needs Revision',
    'cancelled' => 'Cancelled',
    'implemented' => 'Implemented',
];
if (!array_key_exists($status, $proposalTabs)) {
    $status = 'all';
}
$validStatuses = ['submitted', 'under_review', 'approved', 'rejected', 'implemented'];
$where = ['1=1'];
$params = [];
if ($status === 'pending') {
    $where[] = 'p.status IN ("submitted", "under_review")';
} elseif (in_array($status, ['approved', 'rejected'], true)) {
    $where[] = 'p.status = :status';
    $params['status'] = $status;
} elseif ($status === 'needs_revision') {
    $where[] = 'p.status = :status';
    $params['status'] = 'needs_revision';
}

$stmt = $pdo->prepare('SELECT p.*,
        COALESCE(person.full_name, p.proposer_name) AS proposer_display_name,
        COALESCE(person.department, p.department) AS proposer_display_department,
        person.person_code AS proposer_person_code,
        creator.username AS created_by_username,
        reviewer.username AS reviewed_by_username
    FROM proposals p
    LEFT JOIN people person ON person.id = p.proposer_id
    LEFT JOIN users creator ON creator.id = p.created_by
    LEFT JOIN users reviewer ON reviewer.id = p.reviewed_by
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY p.submitted_at DESC, p.id DESC');
$stmt->execute($params);
$proposals = $stmt->fetchAll();

$proposalCounts = [
    'all' => (int) $pdo->query('SELECT COUNT(*) FROM proposals')->fetchColumn(),
    'pending' => (int) $pdo->query('SELECT COUNT(*) FROM proposals WHERE status IN ("submitted", "under_review")')->fetchColumn(),
    'approved' => (int) $pdo->query('SELECT COUNT(*) FROM proposals WHERE status = "approved"')->fetchColumn(),
    'rejected' => (int) $pdo->query('SELECT COUNT(*) FROM proposals WHERE status = "rejected"')->fetchColumn(),
    'needs_revision' => (int) $pdo->query('SELECT COUNT(*) FROM proposals WHERE status = "needs_revision"')->fetchColumn(),
];
// Include both approved and pending people so newly added people appear immediately in the selector
$people = people_options($pdo, false);

render_header('Proposal Requests', $user);
?>

<div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
    <p class="page-intro mb-0"><?= $user['role'] === 'admin' ? 'Review, approve, or update proposal requests.' : 'Submit and track university project proposal requests.' ?></p>
    <div class="actions-row lg:justify-end">
        <?php if ($user['role'] === 'staff'): ?>
            <button type="button" data-open-modal="proposal-modal">Submit Proposal</button>
        <?php elseif ($user['role'] === 'admin'): ?>
            <button type="button" class="btn alt" data-open-modal="person-modal">Add Person</button>
        <?php endif; ?>
    </div>
</div>

<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3 class="text-base font-semibold text-slate-950">Proposal Records</h3>
        </div>
        <span class="badge"><?= h((string) count($proposals)) ?> record<?= count($proposals) === 1 ? '' : 's' ?></span>
    </div>
    <nav class="tabs" aria-label="Proposal status">
        <?php foreach ($proposalTabs as $tabKey => $tabLabel): ?>
            <a class="tab-link <?= $status === $tabKey ? 'active' : '' ?>" href="proposals.php?status=<?= h($tabKey) ?>">
                <?= h($tabLabel) ?>
                <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"><?= h((string) ($proposalCounts[$tabKey] ?? 0)) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php if (!$proposals): ?>
        <?php 
            if ($user['role'] === 'staff') {
                render_empty_state('No proposal requests yet.', 'Submit a project proposal for review and tracking.');
            } else {
                render_empty_state('No proposal requests found.', 'Pending proposal requests will appear here for review.');
            }
        ?>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Title</th>
                    <th>Proposer</th>
                    <th>Budget</th>
                    <th>Status</th>
                    <th>Admin Review</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($proposals as $proposal): ?>
                    <tr>
                        <td><?= h($proposal['submitted_at']) ?></td>
                        <td><strong><?= h($proposal['title']) ?></strong><br><span class="muted"><?= h($proposal['summary']) ?></span></td>
                        <td><?= h($proposal['proposer_display_name']) ?><br><span class="muted"><?= h($proposal['proposer_display_department'] ?: '-') ?></span></td>
                        <td><?= h(money($proposal['estimated_budget'])) ?></td>
                        <td><?php render_status_badge((string) $proposal['status']); ?></td>
                        <td><?= h($proposal['admin_notes'] ?: '-') ?></td>
                        <td>
                            <details class="action-menu">
                                <summary>Actions</summary>
                                <div class="action-menu-panel w-48">
                                    <button type="button" class="btn alt action-menu-item" data-open-modal="proposal-view-<?= (int) $proposal['id'] ?>">View Details</button>
                                    
                                    <?php if ($user['role'] === 'admin'): ?>
                                        <button type="button" class="btn alt action-menu-item mt-1" data-open-modal="proposal-status-<?= (int) $proposal['id'] ?>">Update Status</button>
                                        <?php if (in_array($proposal['status'], ['submitted', 'under_review', 'needs_revision'], true)): ?>
                                            <button type="button" class="btn alt action-menu-item mt-1" data-open-modal="proposal-review-<?= (int) $proposal['id'] ?>">Review & Decide</button>
                                            <form method="post" class="mt-1">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="approve_proposal">
                                                <input type="hidden" name="proposal_id" value="<?= (int) $proposal['id'] ?>">
                                                <button type="submit" class="btn alt action-menu-item text-green-700 hover:bg-green-50">Approve</button>
                                            </form>
                                            <form method="post" class="mt-1">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="reject_proposal">
                                                <input type="hidden" name="proposal_id" value="<?= (int) $proposal['id'] ?>">
                                                <button type="submit" class="btn alt action-menu-item text-red-700 hover:bg-red-50">Reject</button>
                                            </form>
                                            <form method="post" class="mt-1">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="request_revision_proposal">
                                                <input type="hidden" name="proposal_id" value="<?= (int) $proposal['id'] ?>">
                                                <button type="submit" class="btn alt action-menu-item text-amber-700 hover:bg-amber-50">Request Revision</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php elseif ($user['role'] === 'staff' && $proposal['created_by'] === (int) $user['id']): ?>
                                        <?php if (in_array($proposal['status'], ['submitted', 'needs_revision'], true)): ?>
                                            <button type="button" class="btn alt action-menu-item mt-1" data-open-modal="proposal-edit-<?= (int) $proposal['id'] ?>">Edit</button>
                                            <form method="post" class="mt-1">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="cancel_proposal">
                                                <input type="hidden" name="proposal_id" value="<?= (int) $proposal['id'] ?>">
                                                <button type="submit" class="btn alt action-menu-item text-slate-600 hover:bg-slate-100">Cancel</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="data-panel-footer">
            Total proposals <?= h((string) $proposalCounts['all']) ?> | Pending <?= h((string) $proposalCounts['pending']) ?> | Approved <?= h((string) $proposalCounts['approved']) ?> | Rejected <?= h((string) $proposalCounts['rejected']) ?>
        </div>
    <?php endif; ?>
</section>

<dialog id="proposal-modal" class="modal modal-wide">
    <div class="modal-header"><h3>Submit Proposal</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="submit_proposal">
        <div class="form-grid">
            <div><label for="title">Title</label><input id="title" name="title" required></div>
            <?php render_person_selector($people, 'proposer_id', 'proposer_id', null, 'Proposer', true, ['department_target' => 'department']); ?>
            <div><label for="department">Department</label><input id="department" name="department"></div>
            <div><label for="estimated_budget">Estimated Budget</label><input id="estimated_budget" type="number" min="0" step="0.01" name="estimated_budget" placeholder="Enter budget"></div>
            <div><label for="target_date">Target Date</label><input id="target_date" type="date" name="target_date"></div>
            <div class="field-wide"><label for="summary">Summary</label><textarea id="summary" name="summary"></textarea></div>
        </div>
        <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Submit</button></div>
    </form>
</dialog>

<?php foreach ($proposals as $proposal): ?>
    <dialog id="proposal-view-<?= (int) $proposal['id'] ?>" class="modal">
        <div class="modal-header"><h3><?= h($proposal['title']) ?></h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
        <div class="modal-content">
            <p class="muted">Submitted by <?= h($proposal['proposer_display_name']) ?><?= $proposal['proposer_display_department'] ? ' - ' . h($proposal['proposer_display_department']) : '' ?></p>
            <dl class="mt-4 grid gap-3 text-sm">
                <div><dt class="font-semibold text-slate-700">Budget</dt><dd><?= h(money($proposal['estimated_budget'])) ?></dd></div>
                <div><dt class="font-semibold text-slate-700">Target Date</dt><dd><?= h($proposal['target_date'] ?: '-') ?></dd></div>
                <div><dt class="font-semibold text-slate-700">Summary</dt><dd><?= h($proposal['summary'] ?: '-') ?></dd></div>
                <div><dt class="font-semibold text-slate-700">Admin Notes</dt><dd><?= h($proposal['admin_notes'] ?: '-') ?></dd></div>
            </dl>
        </div>
    </dialog>
    <?php if ($user['role'] === 'admin'): ?>
        <dialog id="proposal-status-<?= (int) $proposal['id'] ?>" class="modal">
            <div class="modal-header"><h3>Update Proposal Status</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_proposal_status">
                <input type="hidden" name="proposal_id" value="<?= (int) $proposal['id'] ?>">
                <div class="form-grid">
                    <div>
                        <label for="status_<?= (int) $proposal['id'] ?>">Status</label>
                        <select id="status_<?= (int) $proposal['id'] ?>" name="status" required>
                            <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?= h($value) ?>" <?= $proposal['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-wide">
                        <label for="admin_notes_status_<?= (int) $proposal['id'] ?>">Admin Remarks</label>
                        <textarea id="admin_notes_status_<?= (int) $proposal['id'] ?>" name="admin_notes" placeholder="Add any remarks or feedback for this proposal"><?= h($proposal['admin_notes'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn alt" data-close-modal>Cancel</button>
                    <button type="submit">Save Status</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>
    <?php if (user_can($user, 'manage_proposals')): ?>
        <dialog id="proposal-review-<?= (int) $proposal['id'] ?>" class="modal">
            <div class="modal-header"><h3>Review Proposal</h3><button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button></div>
            <form id="proposal-review-form-<?= (int) $proposal['id'] ?>" method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="proposal_id" value="<?= (int) $proposal['id'] ?>">
                <div class="form-grid">
                    <div class="field-wide">
                        <label for="admin_notes_<?= (int) $proposal['id'] ?>">Admin Remarks</label>
                        <textarea id="admin_notes_<?= (int) $proposal['id'] ?>" name="admin_notes" placeholder="Add any remarks or feedback for this proposal"><?= h($proposal['admin_notes'] ?? '') ?></textarea>
                        <p class="mt-1 text-xs text-slate-500">These remarks will be visible to the proposer.</p>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn alt" data-close-modal>Cancel</button>
                    <button type="submit" class="btn" name="action" value="approve_proposal" form="proposal-review-form-<?= (int) $proposal['id'] ?>">Approve</button>
                    <button type="submit" class="btn alt text-red-700 hover:bg-red-50" name="action" value="reject_proposal" form="proposal-review-form-<?= (int) $proposal['id'] ?>">Reject</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>
<?php endforeach; ?>

<?php render_add_person_modal($user, 'proposals.php'); ?>

<?php render_footer();
