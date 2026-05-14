<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

$dashboardSummary = false;
if (database_programmability_enabled()) {
    try {
        $dashboardStmt = $pdo->query('CALL sp_dashboard_summary()');
        $dashboardSummary = $dashboardStmt->fetch();
        $dashboardStmt->closeCursor();
    } catch (Throwable $e) {
        log_system_issue($pdo, 'warning', 'Dashboard summary procedure unavailable; using fallback query.', ['error' => $e->getMessage()], $user);
    }
}

if (!$dashboardSummary) {
    $dashboardSummary = $pdo->query("SELECT
            (SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE sale_date >= CURDATE() AND sale_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)) AS today_sales,
            (SELECT COALESCE(SUM(total_profit), 0) FROM sales WHERE sale_date >= CURDATE() AND sale_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)) AS today_profit,
            (SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) FROM cash_transactions WHERE txn_date >= CURDATE() AND txn_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)) AS today_cash_in,
            (SELECT COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) FROM cash_transactions WHERE txn_date >= CURDATE() AND txn_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)) AS today_cash_out,
            (SELECT COUNT(*)
                FROM project_accounts pa
                INNER JOIN project_categories pc ON pc.id = pa.category_id
                WHERE pc.slug = 'rental'
                    AND pa.status = 'active'
                    AND pa.next_due_date IS NOT NULL
                    AND pa.next_due_date < CURDATE()) AS overdue_rentals,
            (SELECT COUNT(*) FROM products WHERE type = 'item' AND is_active = 1 AND stock_qty <= low_stock_threshold) AS low_stock_items")->fetch();
}

$todaySales = ['amount' => $dashboardSummary['today_sales'], 'profit' => $dashboardSummary['today_profit']];
$todayCash = ['cash_in' => $dashboardSummary['today_cash_in'], 'cash_out' => $dashboardSummary['today_cash_out']];

$lowStockItems = $pdo->query("SELECT id, name, sku, stock_qty, low_stock_threshold
    FROM products
    WHERE type = 'item' AND is_active = 1 AND stock_qty <= low_stock_threshold
    ORDER BY stock_qty ASC")->fetchAll();

$overdueRentals = $pdo->query("SELECT pa.id, pa.account_name AS tenant_name, pa.code AS stall_name, pa.next_due_date, pa.expected_amount AS monthly_rent
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    WHERE pc.slug = 'rental'
        AND pa.status = 'active'
        AND pa.next_due_date IS NOT NULL
        AND pa.next_due_date < CURDATE()
    ORDER BY pa.next_due_date ASC")->fetchAll();

$releasedToga = $pdo->query("SELECT COUNT(*) AS released_count,
        COALESCE(SUM(CAST(deposit_meta.meta_value AS DECIMAL(12,2))), 0) AS deposit_total
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN project_account_meta status_meta
        ON status_meta.account_id = pa.id AND status_meta.meta_key = 'toga_status'
    LEFT JOIN project_account_meta deposit_meta
        ON deposit_meta.account_id = pa.id AND deposit_meta.meta_key = 'deposit_amount'
    WHERE pc.slug = 'toga'
        AND COALESCE(status_meta.meta_value, CASE WHEN pa.status = 'active' THEN 'released' ELSE 'returned' END) = 'released'")->fetch();

$fishpondRecent = $pdo->query("SELECT DATE(pe.entry_datetime) AS record_date, pe.entry_type AS activity_type, pe.amount, pe.notes
    FROM project_entries pe
    INNER JOIN project_categories pc ON pc.id = pe.category_id
    WHERE pc.slug = 'fishpond'
    ORDER BY pe.entry_datetime DESC, pe.id DESC")->fetchAll();

$togaRecent = $pdo->query("SELECT pa.start_date AS release_date,
        pa.account_name AS student_name,
        pa.code AS student_id,
        COALESCE(status_meta.meta_value, CASE WHEN pa.status = 'active' THEN 'released' ELSE 'returned' END) AS status
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN project_account_meta status_meta
        ON status_meta.account_id = pa.id AND status_meta.meta_key = 'toga_status'
    WHERE pc.slug = 'toga'
    ORDER BY pa.start_date DESC, pa.id DESC")->fetchAll();

$logbookRecent = $pdo->query("SELECT log_date, time_in, time_out, student_name, purpose
    FROM office_logbook
    ORDER BY log_date DESC, time_in DESC")->fetchAll();

$pendingProposals = (int) $pdo->query('SELECT COUNT(*) FROM proposals WHERE status IN ("submitted", "under_review")')->fetchColumn();
$pendingApprovals = count_pending_approvals($pdo);
$inventoryValue = (float) $pdo->query('SELECT COALESCE(SUM(CASE WHEN type = "item" AND is_active = 1 THEN stock_qty * cost_price ELSE 0 END), 0) FROM products')->fetchColumn();
$fishpondNetIncome = (float) $pdo->query('SELECT COALESCE(SUM(CASE WHEN pe.entry_type IN ("income", "payment", "harvest") THEN pe.amount WHEN pe.entry_type = "expense" THEN -pe.amount ELSE 0 END), 0)
    FROM project_entries pe
    INNER JOIN project_categories pc ON pc.id = pe.category_id
    WHERE pc.slug = "fishpond"')->fetchColumn();
$rentalNetIncome = (float) $pdo->query('SELECT COALESCE(SUM(CASE WHEN pe.entry_type IN ("income", "payment", "harvest") THEN pe.amount WHEN pe.entry_type = "expense" THEN -pe.amount ELSE 0 END), 0)
    FROM project_entries pe
    INNER JOIN project_categories pc ON pc.id = pe.category_id
    WHERE pc.slug IN ("rental", "toga")')->fetchColumn();

$salesTrend = $pdo->query("SELECT DATE(sale_date) AS report_date,
        COUNT(*) AS transactions,
        COALESCE(SUM(total_amount), 0) AS revenue,
        COALESCE(SUM(total_profit), 0) AS profit
    FROM sales
    WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(sale_date)
    ORDER BY DATE(sale_date) ASC")->fetchAll();

$cashTrend = $pdo->query("SELECT DATE(txn_date) AS report_date,
        COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) AS cash_in,
        COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) AS cash_out,
        COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS net_cash
    FROM cash_transactions
    WHERE txn_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(txn_date)
    ORDER BY DATE(txn_date) ASC")->fetchAll();

$projectCategoryChart = $pdo->query("SELECT pc.name,
        COALESCE(SUM(CASE WHEN pe.entry_type IN ('income', 'payment', 'harvest') THEN pe.amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN pe.entry_type = 'expense' THEN pe.amount ELSE 0 END), 0) AS expense,
        COALESCE(SUM(CASE WHEN pe.entry_type IN ('income', 'payment', 'harvest') THEN pe.amount WHEN pe.entry_type = 'expense' THEN -pe.amount ELSE 0 END), 0) AS net
    FROM project_categories pc
    LEFT JOIN project_entries pe
        ON pe.category_id = pc.id
        AND pe.entry_datetime >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    WHERE pc.is_active = 1
    GROUP BY pc.id, pc.name
    ORDER BY pc.name")->fetchAll();

$projectEntryTypes = $pdo->query("SELECT entry_type,
        COUNT(*) AS entry_count,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM project_entries
    WHERE entry_datetime >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY entry_type
    ORDER BY entry_type")->fetchAll();

$inventoryAnalytics = $pdo->query('SELECT product_group,
        COUNT(*) AS item_count,
        COALESCE(SUM(CASE WHEN type = "item" THEN stock_qty ELSE 0 END), 0) AS stock_units,
        COALESCE(SUM(CASE WHEN type = "item" THEN stock_qty * selling_price ELSE 0 END), 0) AS stock_value,
        COALESCE(SUM(CASE WHEN type = "item" AND stock_qty <= low_stock_threshold THEN 1 ELSE 0 END), 0) AS low_stock_count
    FROM products
    WHERE is_active = 1
    GROUP BY product_group
    ORDER BY FIELD(product_group, "product", "igp", "service")')->fetchAll();

$topProductProfit = $pdo->query("SELECT p.name,
        COALESCE(SUM(s.quantity), 0) AS quantity_sold,
        COALESCE(SUM(s.total_amount), 0) AS revenue,
        COALESCE(SUM(s.total_cost), 0) AS cost,
        COALESCE(SUM(s.total_profit), 0) AS profit
    FROM sales s
    INNER JOIN products p ON p.id = s.product_id
    WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY p.id, p.name
    ORDER BY profit DESC
    LIMIT 8")->fetchAll();

$rentalCollection = $pdo->query("SELECT pa.account_name,
        pa.expected_amount,
        pa.next_due_date,
        COALESCE(SUM(CASE WHEN pe.entry_type = 'payment' THEN pe.amount ELSE 0 END), 0) AS paid_amount,
        GREATEST(COALESCE(pa.expected_amount, 0) - COALESCE(SUM(CASE WHEN pe.entry_type = 'payment' THEN pe.amount ELSE 0 END), 0), 0) AS balance
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN project_entries pe ON pe.account_id = pa.id
        AND pe.entry_datetime >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    WHERE pc.slug = 'rental' AND pa.status = 'active'
    GROUP BY pa.id, pa.account_name, pa.expected_amount, pa.next_due_date
    ORDER BY balance DESC, pa.next_due_date ASC
    LIMIT 8")->fetchAll();

$salesTrendLabels = array_map(static fn (array $row): string => (string) $row['report_date'], $salesTrend);
$salesTrendTransactions = array_map(static fn (array $row): int => (int) $row['transactions'], $salesTrend);
$salesTrendRevenue = array_map(static fn (array $row): float => (float) $row['revenue'], $salesTrend);
$salesTrendProfit = array_map(static fn (array $row): float => (float) $row['profit'], $salesTrend);
$cashTrendLabels = array_map(static fn (array $row): string => (string) $row['report_date'], $cashTrend);
$cashTrendIn = array_map(static fn (array $row): float => (float) $row['cash_in'], $cashTrend);
$cashTrendOut = array_map(static fn (array $row): float => (float) $row['cash_out'], $cashTrend);
$cashTrendNet = array_map(static fn (array $row): float => (float) $row['net_cash'], $cashTrend);
$projectChartLabels = array_map(static fn (array $row): string => (string) $row['name'], $projectCategoryChart);
$projectChartIncome = array_map(static fn (array $row): float => (float) $row['income'], $projectCategoryChart);
$projectChartExpense = array_map(static fn (array $row): float => (float) $row['expense'], $projectCategoryChart);
$projectChartNet = array_map(static fn (array $row): float => (float) $row['net'], $projectCategoryChart);
$projectEntryTypeLabels = array_map(static fn (array $row): string => ucwords(str_replace('_', ' ', (string) $row['entry_type'])), $projectEntryTypes);
$projectEntryTypeCounts = array_map(static fn (array $row): int => (int) $row['entry_count'], $projectEntryTypes);
$projectEntryTypeAmounts = array_map(static fn (array $row): float => (float) $row['total_amount'], $projectEntryTypes);
$inventoryLabels = array_map(static fn (array $row): string => product_group_label((string) $row['product_group']), $inventoryAnalytics);
$inventoryCounts = array_map(static fn (array $row): int => (int) $row['item_count'], $inventoryAnalytics);
$inventoryStockValues = array_map(static fn (array $row): float => (float) $row['stock_value'], $inventoryAnalytics);
$inventoryLowStockCounts = array_map(static fn (array $row): int => (int) $row['low_stock_count'], $inventoryAnalytics);
$inventoryStockUnits = array_map(static fn (array $row): int => (int) $row['stock_units'], $inventoryAnalytics);
$topProductLabels = array_map(static fn (array $row): string => product_display_name($row), $topProductProfit);
$topProductQty = array_map(static fn (array $row): int => (int) $row['quantity_sold'], $topProductProfit);
$topProductRevenue = array_map(static fn (array $row): float => (float) $row['revenue'], $topProductProfit);
$topProductCost = array_map(static fn (array $row): float => (float) $row['cost'], $topProductProfit);
$topProductProfitValues = array_map(static fn (array $row): float => (float) $row['profit'], $topProductProfit);
$rentalCollectionLabels = array_map(static fn (array $row): string => (string) $row['account_name'], $rentalCollection);
$rentalExpected = array_map(static fn (array $row): float => (float) $row['expected_amount'], $rentalCollection);
$rentalPaid = array_map(static fn (array $row): float => (float) $row['paid_amount'], $rentalCollection);
$rentalBalance = array_map(static fn (array $row): float => (float) $row['balance'], $rentalCollection);
$rentalDueDates = array_map(static fn (array $row): string => (string) ($row['next_due_date'] ?? ''), $rentalCollection);
$lowStockPreview = array_slice($lowStockItems, 0, 5);
$overdueRentalPreview = array_slice($overdueRentals, 0, 5);
$fishpondPreview = array_slice($fishpondRecent, 0, 5);
$togaPreview = array_slice($togaRecent, 0, 5);
$logbookPreview = array_slice($logbookRecent, 0, 5);

render_header('Dashboard', $user);
?>

<p class="page-intro">Daily sales, cash movement, inventory alerts, rentals, approvals, and recent activity.</p>

<div class="dashboard-layout">
<section class="dashboard-section" aria-label="Key operation summary">
<div class="dashboard-card-grid">
    <a class="card dashboard-card dashboard-link dashboard-pos-card" href="sales.php" aria-label="Open POS">
        <h3>POS</h3>
        <div class="stat">Open POS</div>
        <div class="muted">Record a sale or service transaction.</div>
        <span class="dashboard-card-cta">Start sale -></span>
    </a>

    <a class="card dashboard-card dashboard-link" href="sales.php" aria-label="View Today Sales details">
        <h3>Today Sales</h3>
        <div class="stat"><?= h(money((float) $todaySales['amount'])) ?></div>
        <div class="muted">Profit <?= h(money((float) $todaySales['profit'])) ?></div>
        <span class="dashboard-card-cta">View details -></span>
    </a>

    <a class="card dashboard-card dashboard-link" href="cashflow.php" aria-label="View Net Cash Today details">
        <h3>Net Cash Today</h3>
        <div class="stat"><?= h(money((float) $todayCash['cash_in'] - (float) $todayCash['cash_out'])) ?></div>
        <div class="muted">In <?= h(money((float) $todayCash['cash_in'])) ?> | Out <?= h(money((float) $todayCash['cash_out'])) ?></div>
        <span class="dashboard-card-cta">View details -></span>
    </a>

    <a class="card dashboard-card dashboard-link <?= $lowStockItems ? 'border-gold-400 bg-gold-50' : '' ?>" href="products.php?view=low_stock" aria-label="View Low Stock Items">
        <h3>Low Stock Items</h3>
        <div class="stat"><?= h((string) count($lowStockItems)) ?></div>
        <span class="dashboard-card-cta">View details -></span>
    </a>

    <a class="card dashboard-card dashboard-link <?= $overdueRentals ? 'border-red-200 bg-red-50' : '' ?>" href="projects.php?category=rental&rental_type=stall&tab=overdue" aria-label="View Overdue Rentals">
        <h3>Overdue Rentals</h3>
        <div class="stat"><?= h((string) count($overdueRentals)) ?></div>
        <span class="dashboard-card-cta">View details -></span>
    </a>

    <a class="card dashboard-card dashboard-link" href="projects.php?category=rental&rental_type=toga&tab=accounts" aria-label="View Released Toga records">
        <h3>Released Toga</h3>
        <div class="stat"><?= h((string) $releasedToga['released_count']) ?></div>
        <div class="muted">Deposits <?= h(money((float) $releasedToga['deposit_total'])) ?></div>
        <span class="dashboard-card-cta">View details -></span>
    </a>

    <a class="card dashboard-card dashboard-link" href="proposals.php?status=pending" aria-label="View Pending Proposal Requests">
        <h3>Pending Proposals</h3>
        <div class="stat"><?= h((string) $pendingProposals) ?></div>
        <span class="dashboard-card-cta">View details -></span>
    </a>

    <?php if ($user['role'] === 'admin'): ?>
        <a class="card dashboard-card dashboard-link" href="approvals.php?status=pending" aria-label="View Pending Approval Requests">
            <h3>Pending Approvals</h3>
            <div class="stat"><?= h((string) $pendingApprovals) ?></div>
            <div class="muted"><?= $pendingApprovals > 0 ? 'Requests waiting for review.' : 'No approval requests right now.' ?></div>
            <span class="dashboard-card-cta">View details -></span>
        </a>
    <?php else: ?>
        <div class="card dashboard-card">
            <h3>Pending Approvals</h3>
            <div class="stat"><?= h((string) $pendingApprovals) ?></div>
            <div class="muted">Admin Only — approval queue access is restricted.</div>
        </div>
    <?php endif; ?>

    <a class="card dashboard-card dashboard-link" href="products.php" aria-label="View Total Inventory Value">
        <h3>Inventory Value</h3>
        <div class="stat"><?= h(money($inventoryValue)) ?></div>
        <span class="dashboard-card-cta">View details -></span>
    </a>

    <a class="card dashboard-card dashboard-link" href="projects.php?category=fishpond&tab=performance" aria-label="View Fishpond Net Income">
        <h3>Fishpond Net Income</h3>
        <div class="stat"><?= h(money($fishpondNetIncome)) ?></div>
        <span class="dashboard-card-cta">View details -></span>
    </a>

    <a class="card dashboard-card dashboard-link" href="projects.php?category=rental&rental_type=stall&tab=entries" aria-label="View Rental Net Income">
        <h3>Rental Net Income</h3>
        <div class="stat"><?= h(money($rentalNetIncome)) ?></div>
        <span class="dashboard-card-cta">View details -></span>
    </a>

    <a class="card dashboard-card dashboard-link" href="logbook.php" aria-label="View Recent Logbook">
        <h3>Recent Logbook</h3>
        <div class="stat"><?= h((string) count($logbookRecent)) ?></div>
        <span class="dashboard-card-cta">View details -></span>
    </a>
</div>
</section>

<section class="card chart-card dashboard-section" aria-label="Financial and operation charts">
    <div class="section-heading">
        <div>
            <h3>Operation Charts</h3>
            <p class="muted">Hover each chart for revenue, cost, stock, and collection details.</p>
        </div>
    </div>
    <div class="chart-grid dashboard-chart-grid">
        <div class="chart-panel">
            <h4>Sales Trend</h4>
            <div class="chart-frame">
                <canvas id="dashboardSalesChart"></canvas>
            </div>
        </div>
        <div class="chart-panel">
            <h4>Cash Movement</h4>
            <div class="chart-frame">
                <canvas id="dashboardCashChart"></canvas>
            </div>
        </div>
        <div class="chart-panel">
            <h4>Item Profit</h4>
            <div class="chart-frame">
                <canvas id="dashboardTopProductChart"></canvas>
            </div>
        </div>
        <div class="chart-panel">
            <h4>Inventory Stock Status</h4>
            <div class="chart-frame">
                <canvas id="dashboardInventoryStatusChart"></canvas>
            </div>
        </div>
        <div class="chart-panel">
            <h4>Income by Project</h4>
            <div class="chart-frame">
                <canvas id="dashboardProjectChart"></canvas>
            </div>
        </div>
        <div class="chart-panel">
            <h4>Rental Collection Status</h4>
            <div class="chart-frame">
                <canvas id="dashboardRentalCollectionChart"></canvas>
            </div>
        </div>
    </div>
</section>

<section class="dashboard-section" aria-label="Alerts and recent activity">
<div class="dashboard-activity-grid">
    <section class="card dashboard-card dashboard-activity-card <?= $lowStockItems ? 'border-red-200 bg-red-50' : '' ?>">
        <h3 class="<?= $lowStockItems ? 'text-red-800' : '' ?>">Low Stock Alert</h3>
        <?php if (!$lowStockItems): ?>
            <p class="muted">No low stock items right now.</p>
        <?php else: ?>
            <p class="mb-3 text-sm font-semibold text-red-700">Showing <?= h((string) count($lowStockPreview)) ?> of <?= h((string) count($lowStockItems)) ?> low-stock items.</p>
            <div class="table-wrap" data-no-table-enhance>
                <table>
                    <thead>
                    <tr>
                        <th>Item</th>
                        <th>Stock</th>
                        <th>Threshold</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lowStockPreview as $item): ?>
                        <tr class="low-stock">
                            <td>
                                <div class="font-semibold text-slate-950"><?= h(product_display_name($item)) ?></div>
                                <div class="mt-1 text-xs text-slate-500"><?= h(product_sku_text($item)) ?></div>
                            </td>
                            <td class="font-bold text-red-700"><?= h((string) $item['stock_qty']) ?></td>
                            <td><?= h((string) $item['low_stock_threshold']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card dashboard-card dashboard-activity-card" id="pending-approvals">
        <h3>Pending Approval Requests</h3>
        <?php if ($user['role'] === 'admin'): ?>
            <?php if ($pendingApprovals > 0): ?>
                <p class="muted">There are <?= h((string) $pendingApprovals) ?> approval request<?= $pendingApprovals === 1 ? '' : 's' ?> awaiting review.</p>
                <a class="btn alt mt-3" href="approvals.php?status=pending">Open approvals</a>
            <?php else: ?>
                <p class="muted">No approval requests are waiting right now.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">Admin Only — approvals are handled by administrators.</p>
        <?php endif; ?>
    </section>

    <section class="card dashboard-card dashboard-activity-card">
        <h3>Overdue Rental Monitoring</h3>
        <?php if (!$overdueRentals): ?>
            <p class="muted">All active rentals are up to date.</p>
        <?php else: ?>
            <p class="mb-3 text-sm text-slate-500">Showing <?= h((string) count($overdueRentalPreview)) ?> of <?= h((string) count($overdueRentals)) ?> overdue rentals.</p>
            <div class="table-wrap" data-no-table-enhance>
                <table>
                    <thead>
                    <tr>
                        <th>Tenant</th>
                        <th>Stall</th>
                        <th>Next Due</th>
                        <th>Monthly Rent</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($overdueRentalPreview as $rental): ?>
                        <tr>
                            <td><?= h($rental['tenant_name']) ?></td>
                            <td><?= h($rental['stall_name']) ?></td>
                            <td><?= h($rental['next_due_date']) ?></td>
                            <td><?= h(money((float) $rental['monthly_rent'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card dashboard-card dashboard-activity-card">
        <h3>Latest Fishpond Monitoring</h3>
        <?php if (!$fishpondRecent): ?>
            <p class="muted">No fishpond records yet.</p>
        <?php else: ?>
            <div class="table-wrap" data-no-table-enhance>
                <table>
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Activity</th>
                        <th>Amount</th>
                        <th>Notes</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($fishpondPreview as $row): ?>
                        <tr>
                            <td><?= h($row['record_date']) ?></td>
                            <td><?= h($row['activity_type']) ?></td>
                            <td><?= h(money((float) $row['amount'])) ?></td>
                            <td><?= h($row['notes']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card dashboard-card dashboard-activity-card">
        <h3>Latest Toga Monitoring</h3>
        <?php if (!$togaRecent): ?>
            <p class="muted">No toga records yet.</p>
        <?php else: ?>
            <div class="table-wrap" data-no-table-enhance>
                <table>
                    <thead>
                    <tr>
                        <th>Release Date</th>
                        <th>Student</th>
                        <th>Student ID</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($togaPreview as $row): ?>
                        <tr>
                            <td><?= h($row['release_date']) ?></td>
                            <td><?= h($row['student_name']) ?></td>
                            <td><?= h($row['student_id'] ?: '-') ?></td>
                            <td><?= h($row['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card dashboard-card dashboard-activity-card">
        <h3>Recent Office Logbook</h3>
        <?php if (!$logbookRecent): ?>
            <p class="muted">No logbook entries yet.</p>
        <?php else: ?>
            <div class="table-wrap" data-no-table-enhance>
                <table>
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Student</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Purpose</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logbookPreview as $row): ?>
                        <tr>
                            <td><?= h($row['log_date']) ?></td>
                            <td><?= h($row['student_name']) ?></td>
                            <td><?= h($row['time_in']) ?></td>
                            <td><?= h($row['time_out'] ?: '-') ?></td>
                            <td><?= h($row['purpose']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
</section>
</div>

<script>
    window.BPO_CHARTS = window.BPO_CHARTS || {};
    window.BPO_CHARTS.dashboard = {
        salesLabels: <?= json_encode($salesTrendLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        salesTransactions: <?= json_encode($salesTrendTransactions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        salesRevenue: <?= json_encode($salesTrendRevenue, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        salesProfit: <?= json_encode($salesTrendProfit, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        cashLabels: <?= json_encode($cashTrendLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        cashIn: <?= json_encode($cashTrendIn, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        cashOut: <?= json_encode($cashTrendOut, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        cashNet: <?= json_encode($cashTrendNet, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topProductLabels: <?= json_encode($topProductLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topProductQty: <?= json_encode($topProductQty, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topProductRevenue: <?= json_encode($topProductRevenue, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topProductCost: <?= json_encode($topProductCost, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        topProductProfit: <?= json_encode($topProductProfitValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        inventoryLabels: <?= json_encode($inventoryLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        inventoryCounts: <?= json_encode($inventoryCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        inventoryStockValues: <?= json_encode($inventoryStockValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        inventoryLowStockCounts: <?= json_encode($inventoryLowStockCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        inventoryStockUnits: <?= json_encode($inventoryStockUnits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        projectLabels: <?= json_encode($projectChartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        projectIncome: <?= json_encode($projectChartIncome, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        projectExpense: <?= json_encode($projectChartExpense, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        projectNet: <?= json_encode($projectChartNet, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        rentalCollectionLabels: <?= json_encode($rentalCollectionLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        rentalExpected: <?= json_encode($rentalExpected, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        rentalPaid: <?= json_encode($rentalPaid, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        rentalBalance: <?= json_encode($rentalBalance, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        rentalDueDates: <?= json_encode($rentalDueDates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
    };
    window.BPO_CHARTS.projects = {
        categoryLabels: <?= json_encode($projectChartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        income: <?= json_encode($projectChartIncome, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        expense: <?= json_encode($projectChartExpense, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        net: <?= json_encode($projectChartNet, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        entryTypeLabels: <?= json_encode($projectEntryTypeLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        entryTypeCounts: <?= json_encode($projectEntryTypeCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        entryTypeAmounts: <?= json_encode($projectEntryTypeAmounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
    };
    window.BPO_CHARTS.inventory = {
        labels: <?= json_encode($inventoryLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        counts: <?= json_encode($inventoryCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        values: <?= json_encode($inventoryStockValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
    };
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.dashboard-link').forEach(function (card) {
            card.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    window.location.href = card.href;
                }
            });
        });
    });
</script>

<?php render_footer();
