<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

$categorySourceStmt = $pdo->query('SELECT slug, name FROM project_categories WHERE is_active = 1 ORDER BY name ASC');
$categorySources = $categorySourceStmt->fetchAll();
$cashSourceLabels = [
    'manual' => 'Manual',
    'sales' => 'Sales',
    'inventory' => 'Inventory Expenses',
    'other' => 'Other',
];
foreach ($categorySources as $sourceCategory) {
    $cashSourceLabels[(string) $sourceCategory['slug']] = (string) $sourceCategory['name'];
}
$validSources = array_keys($cashSourceLabels);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect('cashflow.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add_transaction') {
        $txnDate = normalize_datetime_input((string) ($_POST['txn_date'] ?? ''));
        $direction = (string) ($_POST['direction'] ?? 'in');
        $source = (string) ($_POST['source_module'] ?? 'manual');
        $amount = (float) ($_POST['amount'] ?? 0);
        $orNumber = trim((string) ($_POST['or_number'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        $validDirections = ['in', 'out'];

        if (!in_array($direction, $validDirections, true) || !in_array($source, $validSources, true)) {
            set_flash('error', 'Invalid transaction type or source.');
            redirect('cashflow.php');
        }

        if ($amount <= 0) {
            set_flash('error', 'Amount must be greater than zero.');
            redirect('cashflow.php');
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, amount, or_number, description, created_by)
                VALUES (:txn_date, :direction, :source_module, :amount, :or_number, :description, :created_by)');
            $stmt->execute([
                'txn_date' => $txnDate,
                'direction' => $direction,
                'source_module' => $source,
                'amount' => $amount,
                'or_number' => $orNumber !== '' ? $orNumber : null,
                'description' => $description !== '' ? $description : null,
                'created_by' => (int) $user['id'],
            ]);
            audit_log($pdo, $user, 'create_transaction', 'cashflow', 'cash_transaction', (int) $pdo->lastInsertId(), [
                'direction' => $direction,
                'source' => $source,
                'amount' => $amount,
            ]);
            set_flash('success', 'Cash transaction saved.');
        } catch (Throwable $e) {
            log_system_issue($pdo, 'error', 'Failed to save cash transaction.', ['error' => $e->getMessage()], $user);
            set_flash('error', 'Failed to save cash transaction.');
        }
        redirect('cashflow.php');
    }
}

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
$directionFilter = (string) ($_GET['direction'] ?? 'all');
$sourceFilter = (string) ($_GET['source_module'] ?? 'all');
$q = trim((string) ($_GET['q'] ?? ''));
[$fromDateTime, $toDateTimeExclusive] = date_filter_bounds($from, $to);

$where = ['txn_date >= :from_dt AND txn_date < :to_dt'];
$params = [
    'from_dt' => $fromDateTime,
    'to_dt' => $toDateTimeExclusive,
];

if (in_array($directionFilter, ['in', 'out'], true)) {
    $where[] = 'direction = :direction';
    $params['direction'] = $directionFilter;
}

if (in_array($sourceFilter, $validSources, true)) {
    $where[] = 'source_module = :source_module';
    $params['source_module'] = $sourceFilter;
}

if ($q !== '') {
    $where[] = '(description LIKE :q OR or_number LIKE :q)';
    $params['q'] = prefix_search_param($q);
}

$countSql = 'SELECT COUNT(*)
    FROM cash_transactions
    WHERE ' . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$pagination = pagination_meta((int) $countStmt->fetchColumn(), page_param(), 25);

$listSql = 'SELECT txn_date, direction, source_module, amount, or_number, description
    FROM (
        SELECT txn_date, direction, source_module, amount, or_number, description,
            ROW_NUMBER() OVER (ORDER BY txn_date DESC, id DESC) AS row_num
        FROM cash_transactions
        WHERE ' . implode(' AND ', $where) . '
    ) ranked_cash
    WHERE row_num BETWEEN :first_row AND :last_row
    ORDER BY row_num';
$listStmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStmt->bindValue(':' . ltrim((string) $key, ':'), $value);
}
[$firstRow, $lastRow] = pagination_row_bounds($pagination);
$listStmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
$listStmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll();

$summarySql = 'SELECT
    COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE 0 END), 0) AS total_in,
    COALESCE(SUM(CASE WHEN direction = "out" THEN amount ELSE 0 END), 0) AS total_out
    FROM cash_transactions
    WHERE ' . implode(' AND ', $where);
$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

render_header('Cash Flow', $user);
?>

<div class="section-heading mb-4">
    <div>
        <p class="page-intro">Track cash in, cash out, sources, and references.</p>
    </div>
    <button type="button" data-open-modal="cash-transaction-modal">Add Transaction</button>
</div>

<dialog id="cash-transaction-modal" class="modal">
    <div class="modal-header">
        <h3>Add Cash Transaction</h3>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_transaction">

        <div class="form-grid">
            <div>
                <label for="txn_date">Date and Time</label>
                <input id="txn_date" type="datetime-local" name="txn_date" value="<?= date('Y-m-d\\TH:i') ?>">
            </div>
            <div>
                <label for="direction">Type</label>
                <select id="direction" name="direction" required>
                    <option value="in">Cash In</option>
                    <option value="out">Cash Out</option>
                </select>
            </div>
            <div>
                <label for="source_module">Source</label>
                <select id="source_module" name="source_module" required>
                    <?php foreach ($cashSourceLabels as $sourceKey => $sourceLabel): ?>
                        <option value="<?= h($sourceKey) ?>"><?= h($sourceLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="amount">Amount</label>
                <input id="amount" type="number" min="0.01" step="0.01" name="amount" required>
            </div>
            <div>
                <label for="or_number">Reference No.</label>
                <input id="or_number" name="or_number" placeholder="Optional">
            </div>
            <div>
                <label for="description">Description</label>
                <input id="description" name="description" placeholder="Optional">
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <button type="submit">Save Transaction</button>
        </div>
    </form>
</dialog>

<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3>Cash Transactions</h3>
        </div>
        <span class="badge"><?= h((string) $pagination['total_rows']) ?> transaction<?= (int) $pagination['total_rows'] === 1 ? '' : 's' ?></span>
    </div>
    <div class="metric-grid">
        <div class="rounded-lg border border-brand-100 bg-brand-50 p-4">
            <h3 class="text-sm font-semibold text-slate-700">Cash In</h3>
            <div class="stat"><?= h(money((float) $summary['total_in'])) ?></div>
        </div>
        <div class="rounded-lg border border-brand-100 bg-white p-4">
            <h3 class="text-sm font-semibold text-slate-700">Cash Out</h3>
            <div class="stat"><?= h(money((float) $summary['total_out'])) ?></div>
        </div>
        <div class="rounded-lg border border-brand-100 bg-white p-4">
            <h3 class="text-sm font-semibold text-slate-700">Net Cash</h3>
            <div class="stat"><?= h(money((float) $summary['total_in'] - (float) $summary['total_out'])) ?></div>
        </div>
        <div class="rounded-lg border border-brand-100 bg-white p-4">
            <h3 class="text-sm font-semibold text-slate-700">Total Transactions</h3>
            <div class="stat"><?= h((string) $pagination['total_rows']) ?></div>
        </div>
    </div>
    <form method="get" class="form-grid data-panel-filters">
        <div>
            <label for="from">From</label>
            <input id="from" type="date" name="from" value="<?= h($from) ?>">
        </div>
        <div>
            <label for="to">To</label>
            <input id="to" type="date" name="to" value="<?= h($to) ?>">
        </div>
        <div>
                <label for="direction_filter">Type</label>
            <select id="direction_filter" name="direction">
                <option value="all" <?= $directionFilter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="in" <?= $directionFilter === 'in' ? 'selected' : '' ?>>Cash In</option>
                <option value="out" <?= $directionFilter === 'out' ? 'selected' : '' ?>>Cash Out</option>
            </select>
        </div>
        <div>
            <label for="source_filter">Source</label>
            <select id="source_filter" name="source_module">
                <option value="all" <?= $sourceFilter === 'all' ? 'selected' : '' ?>>All</option>
                <?php foreach ($cashSourceLabels as $sourceKey => $sourceLabel): ?>
                    <option value="<?= h($sourceKey) ?>" <?= $sourceFilter === $sourceKey ? 'selected' : '' ?>>
                        <?= h($sourceLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="q">Search</label>
            <input id="q" name="q" value="<?= h($q) ?>" placeholder="OR or description">
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Apply</button>
            <a class="btn alt" href="cashflow.php">Reset</a>
        </div>
    </form>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Date and Time</th>
                <th>Type</th>
                <th>Source</th>
                <th>Amount</th>
                <th>Reference No.</th>
                <th>Description</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="6"><?php render_empty_state('No cash transactions found.', 'Add a transaction or adjust your filters.', 'Add Transaction', 'cash-transaction-modal'); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $isCashIn = $row['direction'] === 'in';
                $amountLabel = ($isCashIn ? '+ ' : '- ') . money((float) $row['amount']);
                ?>
                <tr>
                    <td><?= h($row['txn_date']) ?></td>
                    <td><span class="status-pill <?= $isCashIn ? 'cash-in' : 'cash-out' ?>"><?= $isCashIn ? 'Cash In' : 'Cash Out' ?></span></td>
                    <td><?= h($cashSourceLabels[$row['source_module']] ?? ucwords(str_replace(['_', '-'], ' ', (string) $row['source_module']))) ?></td>
                    <td class="font-semibold <?= $isCashIn ? 'text-emerald-700' : 'text-red-700' ?>"><?= h($amountLabel) ?></td>
                    <td><?= h($row['or_number']) ?></td>
                    <td><?= h($row['description']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($pagination); ?>
    <div class="data-panel-footer">
        Total Cash In: <?= h(money((float) $summary['total_in'])) ?> | Total Cash Out: <?= h(money((float) $summary['total_out'])) ?> | Net Cash: <?= h(money((float) $summary['total_in'] - (float) $summary['total_out'])) ?>
    </div>
</section>

<?php render_footer();
