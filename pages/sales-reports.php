<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
$q = trim((string) ($_GET['q'] ?? ''));
$tab = (string) ($_GET['tab'] ?? 'transactions');
if (!in_array($tab, ['transactions', 'profit'], true)) {
    $tab = 'transactions';
}
[$fromDateTime, $toDateTimeExclusive] = date_filter_bounds($from, $to);

$where = ['s.sale_date >= :from_dt AND s.sale_date < :to_dt'];
$params = ['from_dt' => $fromDateTime, 'to_dt' => $toDateTimeExclusive];

if ($q !== '') {
    $where[] = '(p.name LIKE :q OR p.sku LIKE :q)';
    $params['q'] = prefix_search_param($q);
}

$countSql = 'SELECT COUNT(*)
    FROM sales s
    INNER JOIN products p ON p.id = s.product_id
    WHERE ' . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$pagination = pagination_meta((int) $countStmt->fetchColumn(), page_param(), 25);

$listSql = 'SELECT id, sale_date, quantity, unit_price, unit_cost, total_amount, total_cost, total_profit, or_number, product_name, sku, type
    FROM (
        SELECT s.id, s.sale_date, s.quantity, s.unit_price, s.unit_cost, s.total_amount, s.total_cost, s.total_profit, s.or_number, p.name AS product_name, p.sku, p.type,
            ROW_NUMBER() OVER (ORDER BY s.sale_date DESC, s.id DESC) AS row_num
        FROM sales s
        INNER JOIN products p ON p.id = s.product_id
        WHERE ' . implode(' AND ', $where) . '
    ) ranked_sales
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
$salesRows = $listStmt->fetchAll();

$summarySql = 'SELECT COALESCE(SUM(s.total_amount), 0) AS revenue, COALESCE(SUM(s.total_cost), 0) AS cost, COALESCE(SUM(s.total_profit), 0) AS profit
    FROM sales s
    INNER JOIN products p ON p.id = s.product_id
    WHERE ' . implode(' AND ', $where);
$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

$profitByItemSql = 'SELECT p.name, p.sku, p.type, COALESCE(SUM(s.quantity), 0) AS qty, COALESCE(SUM(s.total_amount), 0) AS revenue, COALESCE(SUM(s.total_cost), 0) AS cost, COALESCE(SUM(s.total_profit), 0) AS profit
    FROM sales s
    INNER JOIN products p ON p.id = s.product_id
    WHERE ' . implode(' AND ', $where) . '
    GROUP BY p.id, p.name, p.sku, p.type
    ORDER BY profit DESC';
$profitByItemStmt = $pdo->prepare($profitByItemSql);
$profitByItemStmt->execute($params);
$profitByItem = $profitByItemStmt->fetchAll();

$todaySummary = $pdo->query("SELECT COUNT(*) AS sale_count, COALESCE(SUM(total_amount), 0) AS revenue FROM sales WHERE sale_date >= CURDATE() AND sale_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)")->fetch();

$baseQuery = [
    'from' => $from,
    'to' => $to,
    'q' => $q,
];

render_header('Sales Records', $user);
?>

<div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
    <p class="page-intro mb-0">Review POS sales, revenue, cost, and profit.</p>
    <a class="btn alt" href="sales.php">Back to POS</a>
</div>

<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3>Sales Transactions</h3>
        </div>
        <span class="badge"><?= h((string) $pagination['total_rows']) ?> transaction<?= (int) $pagination['total_rows'] === 1 ? '' : 's' ?></span>
    </div>
    <form method="get" class="form-grid data-panel-filters">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <div>
            <label for="from">From</label>
            <input id="from" type="date" name="from" value="<?= h($from) ?>">
        </div>
        <div>
            <label for="to">To</label>
            <input id="to" type="date" name="to" value="<?= h($to) ?>">
        </div>
        <div>
            <label for="q">Search Item/SKU</label>
            <input id="q" name="q" value="<?= h($q) ?>">
        </div>
        <div class="form-submit">
            <button type="submit">Apply</button>
            <a class="btn alt" href="sales-reports.php">Reset</a>
        </div>
    </form>

<nav class="tabs" aria-label="Sales records views">
    <a class="tab-link <?= $tab === 'transactions' ? 'active' : '' ?>" href="sales-reports.php?<?= h(http_build_query(array_merge($baseQuery, ['tab' => 'transactions']))) ?>">Sales Transactions</a>
    <a class="tab-link <?= $tab === 'profit' ? 'active' : '' ?>" href="sales-reports.php?<?= h(http_build_query(array_merge($baseQuery, ['tab' => 'profit']))) ?>">Item Profit</a>
</nav>

<?php if ($tab === 'transactions'): ?>
<div>
    <div class="section-heading">
        <div>
            <h3>Sales Transactions</h3>
            <p class="muted">Revenue <?= h(money((float) $summary['revenue'])) ?> | Cost <?= h(money((float) $summary['cost'])) ?> | Profit <?= h(money((float) $summary['profit'])) ?> | Transactions <?= h((string) $pagination['total_rows']) ?></p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Date and Time</th>
                <th>Item</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Unit Price</th>
                <th>Total Amount</th>
                <th>Cost</th>
                <th>Profit</th>
                <th>OR Number</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$salesRows): ?>
                <tr>
                    <td colspan="9"><?php render_empty_state('No sales records found.', 'Change the date range or search another item.'); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($salesRows as $sale): ?>
                <tr>
                    <td><?= h($sale['sale_date']) ?></td>
                    <td>
                        <div class="font-semibold text-slate-950"><?= h(product_display_name(['name' => $sale['product_name'], 'sku' => $sale['sku']])) ?></div>
                        <div class="mt-1 text-xs text-slate-500"><?= h(product_sku_text(['sku' => $sale['sku']])) ?></div>
                    </td>
                    <td><span class="status-pill <?= $sale['type'] === 'service' ? 'pending' : 'active' ?>"><?= h(product_type_label((string) $sale['type'])) ?></span></td>
                    <td><?= h((string) $sale['quantity']) ?></td>
                    <td><?= h(money((float) $sale['unit_price'])) ?></td>
                    <td><?= h(money((float) $sale['total_amount'])) ?></td>
                    <td><?= h(money((float) $sale['total_cost'])) ?></td>
                    <td><?= h(money((float) $sale['total_profit'])) ?></td>
                    <td><?= h($sale['or_number']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($pagination); ?>
    <div class="data-panel-footer">
        Today: <?= h((string) $todaySummary['sale_count']) ?> sale<?= (int) $todaySummary['sale_count'] === 1 ? '' : 's' ?>, <?= h(money((float) $todaySummary['revenue'])) ?> revenue.
    </div>
</div>
<?php else: ?>

<div>
    <div class="section-heading">
        <div>
            <h3>Item Profit</h3>
            <p class="muted">Revenue, cost, and profit grouped by item. Total profit <?= h(money((float) $summary['profit'])) ?>.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Item</th>
                <th>Type</th>
                <th>Sold Qty</th>
                <th>Revenue</th>
                <th>Cost</th>
                <th>Profit</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$profitByItem): ?>
                <tr>
                    <td colspan="6"><?php render_empty_state('No sales records found.', 'Change the date range or search another item.'); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($profitByItem as $row): ?>
                <tr>
                    <td>
                        <div class="font-semibold text-slate-950"><?= h(product_display_name($row)) ?></div>
                        <div class="mt-1 text-xs text-slate-500"><?= h(product_sku_text($row)) ?></div>
                    </td>
                    <td><span class="status-pill <?= $row['type'] === 'service' ? 'pending' : 'active' ?>"><?= h(product_type_label((string) $row['type'])) ?></span></td>
                    <td><?= h((string) $row['qty']) ?></td>
                    <td><?= h(money((float) $row['revenue'])) ?></td>
                    <td><?= h(money((float) $row['cost'])) ?></td>
                    <td><?= h(money((float) $row['profit'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="data-panel-footer">
        Total revenue <?= h(money((float) $summary['revenue'])) ?>, total cost <?= h(money((float) $summary['cost'])) ?>, total profit <?= h(money((float) $summary['profit'])) ?>.
    </div>
</div>
<?php endif; ?>
</section>

<?php render_footer();
