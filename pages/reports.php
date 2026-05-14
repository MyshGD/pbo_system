<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

$period = (string) ($_GET['period'] ?? 'daily');
$referenceDate = trim((string) ($_GET['reference_date'] ?? date('Y-m-d')));
$reportTab = (string) ($_GET['tab'] ?? 'sales');

$validPeriods = ['daily', 'weekly', 'monthly', 'annual'];
if (!in_array($period, $validPeriods, true)) {
    $period = 'daily';
}
if (!in_array($reportTab, ['sales', 'cash', 'projects', 'inventory'], true)) {
    $reportTab = 'sales';
}

[$start, $end] = period_bounds($period, $referenceDate);
$startDateTime = $start->format('Y-m-d H:i:s');
$endDateTime = $end->format('Y-m-d H:i:s');

$sales = false;
if (database_programmability_enabled()) {
    try {
        $salesStmt = $pdo->prepare('CALL sp_sales_summary_by_period(:start_date, :end_date)');
        $salesStmt->execute([
            'start_date' => $startDateTime,
            'end_date' => $end->modify('+1 second')->format('Y-m-d H:i:s'),
        ]);
        $sales = $salesStmt->fetch();
        $salesStmt->closeCursor();
    } catch (Throwable $e) {
        log_system_issue($pdo, 'warning', 'Sales summary procedure unavailable; using fallback query.', ['error' => $e->getMessage()], $user);
    }
}

if (!$sales) {
    $salesStmt = $pdo->prepare('SELECT
        COUNT(*) AS total_sales,
        COALESCE(SUM(total_amount), 0) AS revenue,
        COALESCE(SUM(total_cost), 0) AS cost,
        COALESCE(SUM(total_profit), 0) AS profit
        FROM sales
        WHERE sale_date BETWEEN :start_dt AND :end_dt');
    $salesStmt->execute([
        'start_dt' => $startDateTime,
        'end_dt' => $endDateTime,
    ]);
    $sales = $salesStmt->fetch();
}

$cash = false;
if (database_programmability_enabled()) {
    try {
        $cashStmt = $pdo->prepare('CALL sp_cash_summary_by_period(:start_date, :end_date)');
        $cashStmt->execute([
            'start_date' => $startDateTime,
            'end_date' => $end->modify('+1 second')->format('Y-m-d H:i:s'),
        ]);
        $cash = $cashStmt->fetch();
        $cashStmt->closeCursor();
    } catch (Throwable $e) {
        log_system_issue($pdo, 'warning', 'Cash summary procedure unavailable; using fallback query.', ['error' => $e->getMessage()], $user);
    }
}

if (!$cash) {
    $cashStmt = $pdo->prepare('SELECT
        COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE 0 END), 0) AS cash_in,
        COALESCE(SUM(CASE WHEN direction = "out" THEN amount ELSE 0 END), 0) AS cash_out
        FROM cash_transactions
        WHERE txn_date BETWEEN :start_dt AND :end_dt');
    $cashStmt->execute([
        'start_dt' => $startDateTime,
        'end_dt' => $endDateTime,
    ]);
    $cash = $cashStmt->fetch();
}

$projectStmt = $pdo->prepare('SELECT
    COALESCE(SUM(CASE WHEN entry_type IN ("income", "payment", "harvest") THEN amount ELSE 0 END), 0) AS project_income,
    COALESCE(SUM(CASE WHEN entry_type = "expense" THEN amount ELSE 0 END), 0) AS project_expense
    FROM project_entries
    WHERE entry_datetime BETWEEN :start_dt AND :end_dt');
$projectStmt->execute([
    'start_dt' => $startDateTime,
    'end_dt' => $endDateTime,
]);
$projects = $projectStmt->fetch();

$topProductsStmt = $pdo->prepare('SELECT p.name, p.sku, p.type, COALESCE(SUM(s.quantity), 0) AS sold_qty, COALESCE(SUM(s.total_amount), 0) AS revenue, COALESCE(SUM(s.total_profit), 0) AS profit
    FROM sales s
    INNER JOIN products p ON p.id = s.product_id
    WHERE s.sale_date BETWEEN :start_dt AND :end_dt
    GROUP BY p.id, p.name, p.sku, p.type
    ORDER BY profit DESC');
$topProductsStmt->execute([
    'start_dt' => $startDateTime,
    'end_dt' => $endDateTime,
]);
$topProducts = $topProductsStmt->fetchAll();

$categoryStmt = $pdo->prepare('SELECT pc.name,
    COALESCE(SUM(CASE WHEN pe.entry_type IN ("income", "payment", "harvest") THEN pe.amount ELSE 0 END), 0) AS income,
    COALESCE(SUM(CASE WHEN pe.entry_type = "expense" THEN pe.amount ELSE 0 END), 0) AS expense
    FROM project_categories pc
    LEFT JOIN project_entries pe
        ON pe.category_id = pc.id
        AND pe.entry_datetime BETWEEN :start_dt AND :end_dt
    WHERE pc.is_active = 1
    GROUP BY pc.id, pc.name
    ORDER BY pc.name');
$categoryStmt->execute([
    'start_dt' => $startDateTime,
    'end_dt' => $endDateTime,
]);
$categoryRows = $categoryStmt->fetchAll();

$inventoryLowStock = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE type = 'item' AND is_active = 1 AND stock_qty <= low_stock_threshold")->fetchColumn();
$overdueRenewals = (int) $pdo->query('SELECT COUNT(*)
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    WHERE pa.status = "active"
        AND pa.next_due_date IS NOT NULL
        AND pa.next_due_date < CURDATE()')->fetchColumn();
$inventoryRows = $pdo->query('SELECT name, sku, product_group, category, type, stock_qty, low_stock_threshold, selling_price,
        CASE WHEN type = "item" THEN stock_qty * selling_price ELSE 0 END AS stock_value
    FROM products
    WHERE is_active = 1 AND type = "item"
    ORDER BY FIELD(product_group, "product", "igp", "service"), name
    LIMIT 100')->fetchAll();

$logbookCountStmt = $pdo->prepare('SELECT COUNT(*) FROM office_logbook WHERE log_date BETWEEN :start_date AND :end_date');
$logbookCountStmt->execute([
    'start_date' => $start->format('Y-m-d'),
    'end_date' => $end->format('Y-m-d'),
]);
$logbookCount = (int) $logbookCountStmt->fetchColumn();

$togaSummaryStmt = $pdo->prepare('SELECT
    COALESCE(SUM(CASE WHEN COALESCE(status_meta.meta_value, CASE WHEN pa.status = "active" THEN "released" ELSE "returned" END) = "released" THEN 1 ELSE 0 END), 0) AS released,
    COALESCE(SUM(CASE WHEN COALESCE(status_meta.meta_value, CASE WHEN pa.status = "active" THEN "released" ELSE "returned" END) = "returned" THEN 1 ELSE 0 END), 0) AS returned_count,
    COALESCE(SUM(CASE WHEN COALESCE(status_meta.meta_value, CASE WHEN pa.status = "active" THEN "released" ELSE "returned" END) = "forfeited" THEN 1 ELSE 0 END), 0) AS forfeited,
    COALESCE(SUM(pa.expected_amount), 0) AS collected
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN project_account_meta status_meta
        ON status_meta.account_id = pa.id AND status_meta.meta_key = "toga_status"
    WHERE pc.slug = "toga"
        AND pa.start_date BETWEEN :start_date AND :end_date');
$togaSummaryStmt->execute([
    'start_date' => $start->format('Y-m-d'),
    'end_date' => $end->format('Y-m-d'),
]);
$toga = $togaSummaryStmt->fetch();

$cashByDateStmt = $pdo->prepare('SELECT DATE(txn_date) AS report_date,
    COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE 0 END), 0) AS cash_in,
    COALESCE(SUM(CASE WHEN direction = "out" THEN amount ELSE 0 END), 0) AS cash_out
    FROM cash_transactions
    WHERE txn_date BETWEEN :start_dt AND :end_dt
    GROUP BY DATE(txn_date)
    ORDER BY DATE(txn_date) ASC');
$cashByDateStmt->execute([
    'start_dt' => $startDateTime,
    'end_dt' => $endDateTime,
]);
$cashByDate = $cashByDateStmt->fetchAll();
$reportTabs = [
    'sales' => 'Sales',
    'cash' => 'Cash Flow',
    'projects' => 'Projects',
    'inventory' => 'Inventory',
];
$org = organization_profile($pdo);
$preparedByDefault = app_setting($pdo, 'reports.prepared_by_default', '');
$reviewedByDefault = app_setting($pdo, 'reports.reviewed_by_default', 'Department Head / Supervisor');
$approvedByDefault = app_setting($pdo, 'reports.approved_by_default', 'System Administrator');
$footerNotes = app_setting($pdo, 'reports.footer_notes', 'Generated by the Production and Business Operation Record Management System.');
$confidentialityNote = app_setting($pdo, 'reports.confidentiality_note', 'This document contains sensitive institutional information. Handle with appropriate care.');
audit_log($pdo, $user, 'view_report', 'reports', $period, null, [
    'reference_date' => $referenceDate,
    'start' => $startDateTime,
    'end' => $endDateTime,
]);

render_header('Reports', $user);
?>

<link rel="stylesheet" href="assets/print-styles.css">

<p class="page-intro">Generate period summaries and print official report output.</p>

<!-- PROFESSIONAL PRINT REPORT TEMPLATE (A4 Format) -->
<section class="print-report hidden" style="display: none;">
    <div class="print-report-header">
        <div class="institution-name"><?= h($org['campus_display_name']) ?></div>
        <div class="system-name"><?= h($org['system_name']) ?></div>
        <h1 class="report-title"><?= h(ucfirst($period)) ?> <?= h($reportTabs[$reportTab] ?? 'Report') ?></h1>
    </div>

    <!-- METADATA SECTION -->
    <div class="print-metadata">
        <table>
            <tr>
                <td class="print-metadata-label">Report Period:</td>
                <td class="print-metadata-value"><?= h($start->format('Y-m-d')) ?> to <?= h($end->format('Y-m-d')) ?></td>
                <td class="print-metadata-label">Report Type:</td>
                <td class="print-metadata-value"><?= h($reportTabs[$reportTab] ?? 'Report') ?></td>
            </tr>
            <tr>
                <td class="print-metadata-label">Generated Date:</td>
                <td class="print-metadata-value"><?= h(date('Y-m-d H:i:s')) ?></td>
                <td class="print-metadata-label">Generated By:</td>
                <td class="print-metadata-value"><?= h((string) ($user['full_name'] ?? $user['username'] ?? '')) ?></td>
            </tr>
        </table>
    </div>

    <!-- SUMMARY TOTALS SECTION -->
    <div class="print-summary print-section">
        <h3>Summary Totals</h3>
        <div class="print-summary-grid">
            <div class="print-summary-item">
                <div class="print-summary-item-label">Revenue</div>
                <div class="print-summary-item-value"><?= h(money((float) $sales['revenue'])) ?></div>
            </div>
            <div class="print-summary-item">
                <div class="print-summary-item-label">Profit</div>
                <div class="print-summary-item-value"><?= h(money((float) $sales['profit'])) ?></div>
            </div>
            <div class="print-summary-item">
                <div class="print-summary-item-label">Net Cash</div>
                <div class="print-summary-item-value"><?= h(money((float) $cash['cash_in'] - (float) $cash['cash_out'])) ?></div>
            </div>
            <div class="print-summary-item">
                <div class="print-summary-item-label">Project Net</div>
                <div class="print-summary-item-value"><?= h(money((float) $projects['project_income'] - (float) $projects['project_expense'])) ?></div>
            </div>
        </div>
    </div>

    <div class="print-data-section print-section">
        <h3>Detailed Data</h3>
        <div id="print-report-content"></div>
    </div>

    <div class="print-notes print-section">
        <div class="note-row">
            <strong>Report Notes:</strong>
            <span><?= h($footerNotes) ?></span>
        </div>
        <div class="note-row">
            <strong>Data Accuracy:</strong>
            <span>All figures are based on records entered into the system as of the report generation date.</span>
        </div>
        <div class="note-row">
            <strong>Confidentiality:</strong>
            <span><?= h($confidentialityNote) ?></span>
        </div>
    </div>

    <div class="print-signatures print-section">
        <h4>Approval and Signatures</h4>
        <div class="signature-grid">
            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="signature-title">Prepared By</div>
                <div class="signature-subtitle"><?= h($preparedByDefault !== '' ? $preparedByDefault : (string) ($user['full_name'] ?? $user['username'] ?? '')) ?></div>
                <div class="signature-subtitle"><?= h((string) $user['role']) ?></div>
                <div class="signature-date">Date: _______________</div>
            </div>
            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="signature-title">Reviewed By</div>
                <div class="signature-subtitle"><?= h($reviewedByDefault) ?></div>
                <div class="signature-date">Date: _______________</div>
            </div>
            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="signature-title">Approved By</div>
                <div class="signature-subtitle"><?= h($approvedByDefault) ?></div>
                <div class="signature-date">Date: _______________</div>
            </div>
        </div>
    </div>

    <div class="print-footer">
        Generated on <?= h(date('Y-m-d H:i:s')) ?> by <?= h((string) ($user['full_name'] ?? $user['username'] ?? APP_NAME)) ?>
    </div>
</section>

<!-- WEB INTERFACE SECTION (NOT PRINTED) -->
<section class="data-panel-filters">
    <div class="print-actions">
        <h3>Report Controls</h3>
        <button type="button" class="btn print-button" onclick="openPrintReport()">Print Report</button>
    </div>
    <form method="get" class="form-grid">
        <input type="hidden" name="tab" value="<?= h($reportTab) ?>">
        <div>
            <label for="period">Period</label>
            <select id="period" name="period">
                <option value="daily" <?= $period === 'daily' ? 'selected' : '' ?>>Daily</option>
                <option value="weekly" <?= $period === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="annual" <?= $period === 'annual' ? 'selected' : '' ?>>Annual</option>
            </select>
        </div>
        <div>
            <label for="reference_date">Reference Date</label>
            <input id="reference_date" type="date" name="reference_date" value="<?= h($referenceDate) ?>" required>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Generate</button>
        </div>
    </form>
    <p class="muted" style="margin-top:8px;">
        Coverage: <?= h($start->format('Y-m-d H:i:s')) ?> to <?= h($end->format('Y-m-d H:i:s')) ?>
    </p>
</section>

<?php
$reportBaseQuery = [
    'period' => $period,
    'reference_date' => $referenceDate,
];
?>
<nav class="tabs mt-4" aria-label="Report sections">
    <?php foreach ($reportTabs as $tabKey => $tabLabel): ?>
        <a class="tab-link <?= $reportTab === $tabKey ? 'active' : '' ?>" href="reports.php?<?= h(http_build_query(array_merge($reportBaseQuery, ['tab' => $tabKey]))) ?>"><?= h($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>

<div class="metric-grid">
    <div class="card">
        <h3>Revenue</h3>
        <div class="stat"><?= h(money((float) $sales['revenue'])) ?></div>
        <div class="muted">Transactions: <?= h((string) $sales['total_sales']) ?></div>
    </div>
    <div class="card">
        <h3>Profit</h3>
        <div class="stat"><?= h(money((float) $sales['profit'])) ?></div>
        <div class="muted">Cost: <?= h(money((float) $sales['cost'])) ?></div>
    </div>
    <div class="card">
        <h3>Net Cash</h3>
        <div class="stat"><?= h(money((float) $cash['cash_in'] - (float) $cash['cash_out'])) ?></div>
        <div class="muted">In: <?= h(money((float) $cash['cash_in'])) ?> | Out: <?= h(money((float) $cash['cash_out'])) ?></div>
    </div>
    <div class="card">
        <h3>Project Net</h3>
        <div class="stat"><?= h(money((float) $projects['project_income'] - (float) $projects['project_expense'])) ?></div>
        <div class="muted">Income: <?= h(money((float) $projects['project_income'])) ?> | Expense: <?= h(money((float) $projects['project_expense'])) ?></div>
    </div>
</div>

<?php if ($reportTab === 'inventory'): ?>
<div class="metric-grid mt-4">
    <div class="card">
        <h3>Low Stock Items</h3>
        <div class="stat"><?= h((string) $inventoryLowStock) ?></div>
    </div>
    <div class="card">
        <h3>Overdue Renewals</h3>
        <div class="stat"><?= h((string) $overdueRenewals) ?></div>
    </div>
    <div class="card">
        <h3>Office Visits</h3>
        <div class="stat"><?= h((string) $logbookCount) ?></div>
    </div>
    <div class="card">
        <h3>Toga Collected</h3>
        <div class="stat"><?= h(money((float) $toga['collected'])) ?></div>
        <div class="muted">Released: <?= h((string) $toga['released']) ?> | Returned: <?= h((string) $toga['returned_count']) ?> | Forfeited: <?= h((string) $toga['forfeited']) ?></div>
    </div>
</div>
<?php endif; ?>

<?php if ($reportTab === 'sales'): ?>
<section class="table-card data-panel mt-4">
    <div class="section-heading">
        <h3>Item Profit</h3>
        <span class="badge"><?= h((string) count($topProducts)) ?> item<?= count($topProducts) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Item</th>
                <th>Type</th>
                <th>Sold Qty</th>
                <th>Revenue</th>
                <th>Profit</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$topProducts): ?>
                <tr>
                    <td colspan="5" class="muted">No sales data in this period.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($topProducts as $row): ?>
                <tr>
                    <td>
                        <div class="font-semibold text-slate-950"><?= h(product_display_name($row)) ?></div>
                        <div class="mt-1 text-xs text-slate-500"><?= h(product_sku_text($row)) ?></div>
                    </td>
                    <td><span class="status-pill <?= $row['type'] === 'service' ? 'pending' : 'active' ?>"><?= h(product_type_label((string) $row['type'])) ?></span></td>
                    <td><?= h((string) $row['sold_qty']) ?></td>
                    <td><?= h(money((float) $row['revenue'])) ?></td>
                    <td><?= h(money((float) $row['profit'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="data-panel-footer">
        Revenue <?= h(money((float) $sales['revenue'])) ?> | Profit <?= h(money((float) $sales['profit'])) ?>
    </div>
</section>
<?php endif; ?>

<?php if ($reportTab === 'projects'): ?>
<section class="table-card data-panel mt-4">
    <div class="section-heading">
        <h3>Project Category Performance</h3>
        <span class="badge"><?= h((string) count($categoryRows)) ?> categor<?= count($categoryRows) === 1 ? 'y' : 'ies' ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Category</th>
                <th>Income</th>
                <th>Expense</th>
                <th>Net</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($categoryRows as $row): ?>
                <tr>
                    <td><?= h($row['name']) ?></td>
                    <td><?= h(money((float) $row['income'])) ?></td>
                    <td><?= h(money((float) $row['expense'])) ?></td>
                    <td><?= h(money((float) $row['income'] - (float) $row['expense'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="data-panel-footer">
        Project Net <?= h(money((float) $projects['project_income'] - (float) $projects['project_expense'])) ?>
    </div>
</section>
<?php endif; ?>

<?php if ($reportTab === 'cash'): ?>
<section class="table-card data-panel mt-4">
    <div class="section-heading">
        <h3>Cash Trend by Date</h3>
        <span class="badge"><?= h((string) count($cashByDate)) ?> date<?= count($cashByDate) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Date</th>
                <th>Cash In</th>
                <th>Cash Out</th>
                <th>Net</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$cashByDate): ?>
                <tr>
                    <td colspan="4" class="muted">No cash transactions in this period.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($cashByDate as $row): ?>
                <tr>
                    <td><?= h($row['report_date']) ?></td>
                    <td><?= h(money((float) $row['cash_in'])) ?></td>
                    <td><?= h(money((float) $row['cash_out'])) ?></td>
                    <td><?= h(money((float) $row['cash_in'] - (float) $row['cash_out'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="data-panel-footer">
        Net Cash <?= h(money((float) $cash['cash_in'] - (float) $cash['cash_out'])) ?>
    </div>
</section>
<?php endif; ?>

<?php if ($reportTab === 'inventory'): ?>
<section class="table-card data-panel mt-4">
    <div class="section-heading">
        <h3>Inventory Status</h3>
        <span class="badge"><?= h((string) count($inventoryRows)) ?> item<?= count($inventoryRows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Item</th>
                <th>Type</th>
                <th>Category</th>
                <th>Stock</th>
                <th>Status</th>
                <th>Stock Value</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$inventoryRows): ?>
                <tr>
                    <td colspan="6" class="muted">No inventory records found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($inventoryRows as $row): ?>
                <?php
                $isLowStock = (int) $row['stock_qty'] <= (int) $row['low_stock_threshold'];
                ?>
                <tr>
                    <td>
                        <div class="font-semibold text-slate-950"><?= h(product_display_name($row)) ?></div>
                        <div class="mt-1 text-xs text-slate-500"><?= h(product_sku_text($row)) ?></div>
                    </td>
                    <td><span class="status-pill active">Product</span></td>
                    <td><?= h(product_category_label((string) $row['category'])) ?></td>
                    <td><?= h((string) $row['stock_qty']) ?></td>
                    <td>
                        <?php if ($isLowStock): ?>
                            <span class="status-pill low-stock">Low Stock</span>
                        <?php else: ?>
                            <span class="status-pill active">In Stock</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h(money((float) $row['stock_value'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="data-panel-footer">
        Low Stock <?= h((string) $inventoryLowStock) ?> | Overdue Renewals <?= h((string) $overdueRenewals) ?> | Office Visits <?= h((string) $logbookCount) ?>
    </div>
</section>
<?php endif; ?>

<script>
/**
 * Handle Print Report Display and Print Dialog
 */
function openPrintReport() {
    const printReport = document.querySelector('.print-report');
    
    if (printReport) {
        printReport.style.display = 'block';
        printReport.classList.remove('hidden');

        const printContent = document.getElementById('print-report-content');

        if (printContent) {
            const hasReportData = <?= json_encode(match ($reportTab) {
                'sales' => count($topProducts) > 0,
                'cash' => count($cashByDate) > 0,
                'projects' => count($categoryRows) > 0,
                'inventory' => count($inventoryRows) > 0,
                default => false,
            }) ?>;
            const reportTable = Array.from(document.querySelectorAll('.table-card.data-panel table'))
                .find(table => table.offsetParent !== null);

            printContent.innerHTML = '';

            if (!hasReportData || !reportTable) {
                printContent.innerHTML = '<table><tbody><tr><td>No records found for this report period.</td></tr></tbody></table>';
            } else {
                printContent.appendChild(reportTable.cloneNode(true));
            }
        }

        setTimeout(() => {
            window.print();
        }, 100);
    }
}

/**
 * Handle Print Style Loading
 */
document.addEventListener('DOMContentLoaded', function() {
    // Ensure print CSS is loaded
    if (!document.querySelector('link[href*="print-styles"]')) {
        const printCSS = document.createElement('link');
        printCSS.rel = 'stylesheet';
        printCSS.href = 'assets/print-styles.css';
        document.head.appendChild(printCSS);
    }
});

window.addEventListener('afterprint', function() {
    const printReport = document.querySelector('.print-report');
    if (printReport) {
        printReport.style.display = 'none';
        printReport.classList.add('hidden');
    }
});
</script>

<?php render_footer();
