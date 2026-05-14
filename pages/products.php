<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect('products.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_product') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $sku = trim((string) ($_POST['sku'] ?? ''));
        $category = (string) ($_POST['category'] ?? 'school_supply');
        $groupInput = (string) ($_POST['product_group'] ?? 'product');
        $type = product_type_for_category($category, (string) ($_POST['type'] ?? 'item'));
        if ($groupInput === 'service') {
            $type = 'service';
        }
        $productGroup = product_group_for_type($category, $type, $groupInput);
        $cost = (float) ($_POST['cost_price'] ?? 0);
        $selling = (float) ($_POST['selling_price'] ?? 0);
        $stock = (int) ($_POST['stock_qty'] ?? 0);
        $threshold = (int) ($_POST['low_stock_threshold'] ?? 5);
        $stockDate = normalize_datetime_input((string) ($_POST['stock_date'] ?? ''));
        $stockReference = trim((string) ($_POST['stock_reference'] ?? ''));
        $stockNotes = trim((string) ($_POST['stock_notes'] ?? ''));
        $postCashOut = isset($_POST['post_cash_out']) && $_POST['post_cash_out'] === '1';

        $validCategories = array_keys(product_category_options());
        $validTypes = ['item', 'service'];
        $validGroups = ['product', 'igp', 'service'];

        if ($name === '') {
            set_flash('error', 'Item name is required.');
            redirect('products.php');
        }
        if (!in_array($category, $validCategories, true) || !in_array($type, $validTypes, true) || !in_array($productGroup, $validGroups, true)) {
            set_flash('error', 'Invalid category, group, or type.');
            redirect('products.php');
        }

        if ($cost < 0 || $selling < 0 || $stock < 0 || $threshold < 0) {
            set_flash('error', 'Numeric fields cannot be negative.');
            redirect('products.php');
        }

        if ($type === 'service') {
            $stock = 0;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO products (sku, name, category, product_group, type, cost_price, selling_price, stock_qty, low_stock_threshold) VALUES (:sku, :name, :category, :product_group, :type, :cost, :selling, 0, :threshold)');
            $stmt->execute([
                'sku' => $sku !== '' ? $sku : null,
                'name' => $name,
                'category' => $category,
                'product_group' => $productGroup,
                'type' => $type,
                'cost' => $cost,
                'selling' => $selling,
                'threshold' => $threshold,
            ]);
            $productId = (int) $pdo->lastInsertId();

            if ($type === 'item' && $stock > 0) {
                inventory_stock_in($pdo, $productId, $stock, $cost, $stockDate, $stockReference, $stockNotes !== '' ? $stockNotes : 'Initial stock', $user, 'initial_stock');

                if ($postCashOut && $cost > 0) {
                    $cashStmt = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, amount, or_number, description, created_by)
                        VALUES (:txn_date, "out", "inventory", :amount, :or_number, :description, :created_by)');
                    $cashStmt->execute([
                        'txn_date' => $stockDate,
                        'amount' => $cost * $stock,
                        'or_number' => $stockReference !== '' ? $stockReference : null,
                        'description' => 'Initial stock purchase: ' . $name,
                        'created_by' => (int) $user['id'],
                    ]);
                }
            }

            $pdo->commit();
            audit_log($pdo, $user, 'create', 'inventory', 'product', $productId, [
                'name' => $name,
                'category' => $category,
                'product_group' => $productGroup,
                'type' => $type,
            ]);
            set_flash('success', 'Item added successfully.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Could not add product.', ['error' => $e->getMessage(), 'name' => $name], $user);
            set_flash('error', 'Could not add product. SKU might already exist.');
        }

        redirect('products.php');
    }

    if ($action === 'stock_movement' || $action === 'adjust_stock') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $direction = (string) ($_POST['direction'] ?? 'in');
        $quantity = abs((int) ($_POST['quantity'] ?? ($_POST['adjustment'] ?? 0)));
        $unitCost = (float) ($_POST['unit_cost'] ?? 0);
        $movementDate = normalize_datetime_input((string) ($_POST['movement_date'] ?? ''));
        $referenceNo = trim((string) ($_POST['reference_no'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $postCashOut = isset($_POST['post_cash_out']) && $_POST['post_cash_out'] === '1';

        if ($productId <= 0 || $quantity <= 0 || !in_array($direction, ['in', 'out'], true) || $unitCost < 0) {
            set_flash('error', 'Invalid stock adjustment.');
            redirect('products.php');
        }

        try {
            $pdo->beginTransaction();

            $productStmt = $pdo->prepare('SELECT name, cost_price FROM products WHERE id = :id AND type = "item" AND is_active = 1');
            $productStmt->execute(['id' => $productId]);
            $product = $productStmt->fetch();
            if (!$product) {
                throw new RuntimeException('Product is not an active stock item.');
            }

            if ($direction === 'in') {
                $effectiveCost = $unitCost > 0 ? $unitCost : (float) $product['cost_price'];
                inventory_stock_in($pdo, $productId, $quantity, $effectiveCost, $movementDate, $referenceNo, $notes, $user);

                if ($postCashOut && $effectiveCost > 0) {
                    $cashStmt = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, amount, or_number, description, created_by)
                        VALUES (:txn_date, "out", "inventory", :amount, :or_number, :description, :created_by)');
                    $cashStmt->execute([
                        'txn_date' => $movementDate,
                        'amount' => $effectiveCost * $quantity,
                        'or_number' => $referenceNo !== '' ? $referenceNo : null,
                        'description' => 'Stock purchase: ' . $product['name'],
                        'created_by' => (int) $user['id'],
                    ]);
                }
            } else {
                inventory_fifo_issue($pdo, $productId, $quantity, $movementDate, 'stock_out', $referenceNo, $notes !== '' ? $notes : 'Stock out', $user);
            }

            $pdo->commit();
            audit_log($pdo, $user, 'stock_movement', 'inventory', 'product', $productId, [
                'direction' => $direction,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ]);
            set_flash('success', 'Stock movement saved.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Stock movement failed.', ['error' => $e->getMessage(), 'product_id' => $productId], $user);
            set_flash('error', 'Stock movement failed: ' . $e->getMessage());
        }

        redirect('products.php');
    }

    if ($action === 'update_product') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $sku = trim((string) ($_POST['sku'] ?? ''));
        $category = (string) ($_POST['category'] ?? 'school_supply');
        $groupInput = (string) ($_POST['product_group'] ?? 'product');
        $type = product_type_for_category($category, (string) ($_POST['type'] ?? 'item'));
        if ($groupInput === 'service') {
            $type = 'service';
        }
        $productGroup = product_group_for_type($category, $type, $groupInput);
        $cost = (float) ($_POST['cost_price'] ?? 0);
        $selling = (float) ($_POST['selling_price'] ?? 0);
        $threshold = (int) ($_POST['low_stock_threshold'] ?? 5);

        $validCategories = array_keys(product_category_options());
        $validTypes = ['item', 'service'];
        $validGroups = ['product', 'igp', 'service'];

        if ($productId <= 0 || $name === '' || !in_array($category, $validCategories, true) || !in_array($type, $validTypes, true) || !in_array($productGroup, $validGroups, true) || $cost < 0 || $selling < 0 || $threshold < 0) {
            set_flash('error', 'Valid product details are required.');
            redirect('products.php');
        }

        if ($type === 'service') {
            $stockStmt = $pdo->prepare('SELECT stock_qty FROM products WHERE id = :id AND is_active = 1');
            $stockStmt->execute(['id' => $productId]);
            if ((int) $stockStmt->fetchColumn() > 0) {
                set_flash('error', 'Move item stock out before converting it to a service.');
                redirect('products.php');
            }
        }

        try {
            $stmt = $pdo->prepare('UPDATE products
                SET sku = :sku, name = :name, category = :category, product_group = :product_group, type = :type, cost_price = :cost, selling_price = :selling, low_stock_threshold = :threshold
                WHERE id = :id AND is_active = 1');
            $stmt->execute([
                'id' => $productId,
                'sku' => $sku !== '' ? $sku : null,
                'name' => $name,
                'category' => $category,
                'product_group' => $productGroup,
                'type' => $type,
                'cost' => $cost,
                'selling' => $selling,
                'threshold' => $threshold,
            ]);
            audit_log($pdo, $user, 'update', 'inventory', 'product', $productId, ['name' => $name]);
            set_flash('success', 'Item updated successfully.');
        } catch (PDOException $e) {
            log_system_issue($pdo, 'error', 'Could not update product.', ['error' => $e->getMessage(), 'product_id' => $productId], $user);
            set_flash('error', 'Could not update item. SKU might already exist.');
        }

        redirect('products.php');
    }

    if ($action === 'archive_product') {
        require_permission($user, 'archive_records', 'products.php');
        $productId = (int) ($_POST['product_id'] ?? 0);
        if ($productId <= 0) {
            set_flash('error', 'Invalid product archive request.');
            redirect('products.php');
        }

        $productStmt = $pdo->prepare('SELECT id, sku, name, category, product_group, type, cost_price, selling_price, stock_qty, low_stock_threshold, is_active, created_at
            FROM products
            WHERE id = :id AND is_active = 1');
        $productStmt->execute(['id' => $productId]);
        $product = $productStmt->fetch();
        if (!$product) {
            set_flash('error', 'Product not found or already archived.');
            redirect('products.php');
        }

        try {
            $pdo->beginTransaction();

            $archiveStmt = $pdo->prepare('INSERT INTO archived_records (source_table, source_id, record_data, archived_by)
                VALUES (:source_table, :source_id, :record_data, :archived_by)');
            $archiveStmt->execute([
                'source_table' => 'products',
                'source_id' => (string) $productId,
                'record_data' => json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'archived_by' => (int) $user['id'],
            ]);

            $deleteStmt = $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = :id');
            $deleteStmt->execute(['id' => $productId]);

            $pdo->commit();
            audit_log($pdo, $user, 'archive', 'inventory', 'product', $productId, ['name' => $product['name']]);
            set_flash('success', 'Product archived. Historical records were preserved.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to archive product.', ['error' => $e->getMessage(), 'product_id' => $productId], $user);
            set_flash('error', 'Failed to archive product.');
        }

        redirect('products.php');
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$groupFilter = (string) ($_GET['product_group'] ?? 'all');
$categoryFilter = (string) ($_GET['category'] ?? 'all');
$sort = (string) ($_GET['sort'] ?? 'name');
$order = strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$inventoryView = (string) ($_GET['view'] ?? 'inventory');
if (!in_array($inventoryView, ['inventory', 'low_stock', 'batches', 'ledger'], true)) {
    $inventoryView = 'inventory';
}

$allowedSort = [
    'name' => 'name',
    'product_group' => 'product_group',
    'category' => 'category',
    'stock_qty' => 'stock_qty',
    'selling_price' => 'selling_price',
    'cost_price' => 'cost_price',
];

$sortColumn = $allowedSort[$sort] ?? 'name';

$where = ['is_active = 1'];
$params = [];

if ($q !== '') {
    $where[] = '(name LIKE :q OR sku LIKE :q)';
    $params['q'] = prefix_search_param($q);
}

if (in_array($groupFilter, ['product', 'igp', 'service'], true)) {
    $where[] = 'product_group = :product_group';
    $params['product_group'] = $groupFilter;
}

if ($categoryFilter !== 'all') {
    $where[] = 'category = :category';
    $params['category'] = $categoryFilter;
}

if ($inventoryView === 'low_stock') {
    $where[] = 'type = "item" AND stock_qty <= low_stock_threshold';
}

$countSql = 'SELECT COUNT(*)
        FROM products
        WHERE ' . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$pagination = pagination_meta((int) $countStmt->fetchColumn(), page_param(), 20);

$sql = 'SELECT id, sku, name, category, product_group, type, cost_price, selling_price, stock_qty, low_stock_threshold, unit_profit
        FROM (
            SELECT id, sku, name, category, product_group, type, cost_price, selling_price, stock_qty, low_stock_threshold, (selling_price - cost_price) AS unit_profit,
                ROW_NUMBER() OVER (ORDER BY ' . $sortColumn . ' ' . $order . ', id ASC) AS row_num
            FROM products
            WHERE ' . implode(' AND ', $where) . '
        ) ranked_products
        WHERE row_num BETWEEN :first_row AND :last_row
        ORDER BY row_num';

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . ltrim((string) $key, ':'), $value);
}
[$firstRow, $lastRow] = pagination_row_bounds($pagination);
$stmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
$stmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

$lowStockItems = $pdo->query("SELECT id, name, stock_qty FROM products WHERE type = 'item' AND is_active = 1 AND stock_qty <= low_stock_threshold ORDER BY stock_qty ASC")->fetchAll();
$categoryAnalytics = $pdo->query('SELECT category,
        COUNT(*) AS item_count,
        COALESCE(SUM(CASE WHEN type = "item" THEN stock_qty ELSE 0 END), 0) AS stock_units,
        COALESCE(SUM(CASE WHEN type = "item" THEN stock_qty * selling_price ELSE 0 END), 0) AS stock_value
    FROM products
    WHERE is_active = 1
    GROUP BY category
    ORDER BY category')->fetchAll();
$totalInventoryRecords = array_sum(array_map(static fn (array $row): int => (int) $row['item_count'], $categoryAnalytics));
$totalStockValue = array_sum(array_map(static fn (array $row): float => (float) $row['stock_value'], $categoryAnalytics));
$lowStockCount = count($lowStockItems);
$stockBatches = $pdo->query('SELECT b.id, b.batch_code, b.received_date, b.quantity_received, b.quantity_remaining, b.unit_cost, b.reference_no, p.name AS product_name, p.sku
    FROM inventory_stock_batches b
    INNER JOIN products p ON p.id = b.product_id
    WHERE p.is_active = 1 AND b.quantity_remaining > 0
    ORDER BY b.received_date ASC, b.id ASC
    LIMIT 100')->fetchAll();
$stockMovements = $pdo->query('SELECT m.movement_date, m.movement_type, m.quantity_change, m.unit_cost, m.total_cost, m.reference_no, m.notes, p.name AS product_name
    FROM inventory_stock_movements m
    INNER JOIN products p ON p.id = m.product_id
    ORDER BY m.movement_date DESC, m.id DESC
    LIMIT 100')->fetchAll();

function inventory_status_badge(array $product): string
{
    if ((string) $product['type'] === 'service') {
        return '<span class="status-pill returned">Service</span>';
    }

    $stock = (int) $product['stock_qty'];
    if ($stock <= 0) {
        return '<span class="status-pill rejected">Out of Stock</span>';
    }

    if ($stock <= (int) $product['low_stock_threshold']) {
        return '<span class="status-pill submitted">Low Stock</span>';
    }

    return '<span class="status-pill active">In Stock</span>';
}

function movement_status_badge(string $movementType): string
{
    $label = match ($movementType) {
        'initial_stock' => 'Opening',
        'stock_in' => 'Stock In',
        'stock_out' => 'Stock Out',
        default => ucwords(str_replace('_', ' ', $movementType)),
    };
    $class = match ($movementType) {
        'sale', 'stock_out' => 'rejected',
        'stock_in', 'initial_stock' => 'active',
        'adjustment' => 'submitted',
        default => 'returned',
    };

    return '<span class="status-pill ' . h($class) . '">' . h($label) . '</span>';
}

render_header('Inventory', $user);
?>

<div class="section-heading mb-4">
    <div>
        <p class="page-intro mb-0">Manage items, services, stock, pricing, and movement history.</p>
    </div>
    <button type="button" data-open-modal="add-product-modal">Add Item</button>
</div>

<?php
$productBaseQuery = [
    'q' => $q,
    'product_group' => $groupFilter,
    'category' => $categoryFilter,
    'sort' => $sort,
    'order' => strtolower($order),
];
$productTabs = [
    'inventory' => 'Inventory',
    'low_stock' => 'Low Stock',
    'batches' => 'Stock Batches',
    'ledger' => 'Stock Ledger',
];
?>
<nav class="tabs" aria-label="Inventory sections">
    <?php foreach ($productTabs as $tabKey => $tabLabel): ?>
        <?php $tabHref = 'products.php?' . http_build_query(array_merge($productBaseQuery, ['view' => $tabKey, 'page' => 1])); ?>
        <a class="tab-link <?= $inventoryView === $tabKey ? 'active' : '' ?>" href="<?= h($tabHref) ?>"><?= h($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>

<dialog id="add-product-modal" class="modal">
    <div class="modal-header">
        <h3>Add Item</h3>
        <button type="button" class="modal-close" data-modal-close>Close</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_product">

        <div class="form-grid">
            <div>
                <label for="name">Item Name</label>
                <input id="name" name="name" required>
            </div>
            <div>
                <label for="sku">SKU (Optional)</label>
                <input id="sku" name="sku">
            </div>
            <div>
                <label for="category">Category</label>
                <select id="category" name="category" required>
                    <?php foreach (product_category_options() as $value => $label): ?>
                        <option value="<?= h($value) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="product_group">Group</label>
                <select id="product_group" name="product_group" required>
                    <option value="product">Products</option>
                    <option value="igp">Project Items</option>
                    <option value="service">Services</option>
                </select>
            </div>
            <div>
                <label for="type">Type</label>
                <select id="type" name="type" required>
                    <option value="item">Product (with stock)</option>
                    <option value="service">Service (no stock)</option>
                </select>
            </div>
            <div>
                <label for="cost_price">Capital/Cost Price</label>
                <input id="cost_price" name="cost_price" type="number" step="0.01" min="0" value="0" required>
            </div>
            <div>
                <label for="selling_price">Selling Price</label>
                <input id="selling_price" name="selling_price" type="number" step="0.01" min="0" value="0" required>
            </div>
            <div>
                <label for="stock_qty">Current Stock</label>
                <input id="stock_qty" name="stock_qty" type="number" min="0" value="0" required>
            </div>
            <div>
                <label for="low_stock_threshold">Low Stock Alert Level</label>
                <input id="low_stock_threshold" name="low_stock_threshold" type="number" min="0" value="5" required>
            </div>
            <div>
                <label for="stock_date">Stock Date</label>
                <input id="stock_date" type="datetime-local" name="stock_date" value="<?= date('Y-m-d\\TH:i') ?>">
            </div>
            <div>
                <label for="stock_reference">Stock Reference</label>
                <input id="stock_reference" name="stock_reference" placeholder="Receipt/Supplier ref">
            </div>
            <div class="field-wide">
                <label for="stock_notes">Stock Notes</label>
                <textarea id="stock_notes" name="stock_notes" placeholder="Optional starting stock details"></textarea>
            </div>
            <div class="checkbox-field">
                <label>
                    <input type="checkbox" name="post_cash_out" value="1" checked>
                    Post starting stock as Cash Out
                </label>
            </div>
        </div>
        <div class="mt-4 flex justify-end">
            <button type="submit">Save Item</button>
        </div>
    </form>
</dialog>

<?php if (in_array($inventoryView, ['inventory', 'low_stock'], true)): ?>
<?php
$workspaceTitle = $inventoryView === 'low_stock' ? 'Low Stock Items' : 'Inventory Items';
$workspaceSummary = $inventoryView === 'low_stock'
    ? $pagination['total_rows'] . ' item' . ((int) $pagination['total_rows'] === 1 ? '' : 's') . ' need restocking'
    : $totalInventoryRecords . ' active item' . ($totalInventoryRecords === 1 ? '' : 's') . ' | ' . $lowStockCount . ' low stock | Stock value ' . money((float) $totalStockValue);
?>
<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3 class="text-base font-bold text-slate-950"><?= h($workspaceTitle) ?></h3>
            <p class="text-sm text-slate-500"><?= h($workspaceSummary) ?></p>
        </div>
        <span class="badge"><?= h((string) $pagination['total_rows']) ?> item<?= (int) $pagination['total_rows'] === 1 ? '' : 's' ?></span>
    </div>

    <form method="get" class="data-panel-filters grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1.4fr)_minmax(140px,0.8fr)_minmax(160px,0.9fr)_minmax(150px,0.8fr)_minmax(140px,0.7fr)_auto_auto] xl:items-end">
        <input type="hidden" name="view" value="<?= h($inventoryView) ?>">
        <div>
            <label for="q">Search</label>
            <input id="q" name="q" value="<?= h($q) ?>" placeholder="Name or SKU">
        </div>
        <div>
            <label for="group_filter">Group</label>
            <select id="group_filter" name="product_group">
                <option value="all" <?= $groupFilter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="product" <?= $groupFilter === 'product' ? 'selected' : '' ?>>Products</option>
                <option value="igp" <?= $groupFilter === 'igp' ? 'selected' : '' ?>>Project Items</option>
                <option value="service" <?= $groupFilter === 'service' ? 'selected' : '' ?>>Services</option>
            </select>
        </div>
        <div>
            <label for="category_filter">Category</label>
            <select id="category_filter" name="category">
                <option value="all" <?= $categoryFilter === 'all' ? 'selected' : '' ?>>All</option>
                <?php foreach (product_category_options() as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $categoryFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="sort">Sort By</label>
            <select id="sort" name="sort">
                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name</option>
                <option value="product_group" <?= $sort === 'product_group' ? 'selected' : '' ?>>Group</option>
                <option value="category" <?= $sort === 'category' ? 'selected' : '' ?>>Category</option>
                <option value="stock_qty" <?= $sort === 'stock_qty' ? 'selected' : '' ?>>Stock</option>
                <option value="cost_price" <?= $sort === 'cost_price' ? 'selected' : '' ?>>Cost</option>
                <option value="selling_price" <?= $sort === 'selling_price' ? 'selected' : '' ?>>Selling Price</option>
            </select>
        </div>
        <div>
            <label for="order">Order</label>
            <select id="order" name="order">
                <option value="asc" <?= strtoupper($order) === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                <option value="desc" <?= strtoupper($order) === 'DESC' ? 'selected' : '' ?>>Descending</option>
            </select>
        </div>
        <button type="submit">Apply</button>
        <a class="btn alt" href="products.php?view=<?= h($inventoryView) ?>">Reset</a>
    </form>

    <div class="table-wrap" data-no-table-enhance>
        <table>
            <thead>
            <tr>
                <th>Item</th>
                <?php if ($inventoryView === 'low_stock'): ?>
                    <th>Category</th>
                    <th>Current Stock</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                    <th>Actions</th>
                <?php else: ?>
                <th>Type</th>
                <th>Category</th>
                <th>Cost</th>
                <th>Selling Price</th>
                <th>Profit</th>
                <th>Stock</th>
                <th>Status</th>
                <th>Actions</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if (!$products): ?>
                <tr>
                    <td colspan="<?= $inventoryView === 'low_stock' ? '6' : '9' ?>">
                        <?php if ($inventoryView === 'low_stock'): ?>
                            <?php render_empty_state('No low stock items.', 'All inventory levels are healthy.'); ?>
                        <?php else: ?>
                            <?php render_empty_state('No items found.', 'Adjust the filters or add an item to begin tracking inventory.'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($products as $product): ?>
                <?php $isLow = $product['type'] === 'item' && (int) $product['stock_qty'] <= (int) $product['low_stock_threshold']; ?>
                <tr class="<?= $isLow ? 'low-stock' : '' ?>">
                    <td>
                        <div class="font-semibold text-slate-950"><?= h(product_display_name($product)) ?></div>
                        <div class="mt-1 text-xs text-slate-500"><?= h(product_sku_text($product)) ?></div>
                    </td>
                    <?php if ($inventoryView === 'low_stock'): ?>
                        <td>
                            <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                <?= h(product_category_label((string) $product['category'])) ?>
                            </span>
                        </td>
                        <td class="font-semibold text-red-700"><?= h((string) $product['stock_qty']) ?></td>
                        <td><?= h((string) $product['low_stock_threshold']) ?></td>
                        <td><?= inventory_status_badge($product) ?></td>
                        <td>
                            <?php if ($product['type'] === 'item'): ?>
                                <button type="button" class="btn alt" data-open-modal="stock-product-<?= (int) $product['id'] ?>">Restock</button>
                            <?php else: ?>
                                <span class="muted">N/A</span>
                            <?php endif; ?>
                        </td>
                    <?php else: ?>
                    <td><span class="status-pill <?= $product['type'] === 'service' ? 'pending' : 'active' ?>"><?= h(product_type_label((string) $product['type'])) ?></span></td>
                    <td>
                        <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold capitalize text-slate-700">
                            <?= h(product_category_label((string) $product['category'])) ?>
                        </span>
                    </td>
                    <td><?= h(money((float) $product['cost_price'])) ?></td>
                    <td><?= h(money((float) $product['selling_price'])) ?></td>
                    <td class="font-semibold text-brand-700"><?= h(money((float) $product['unit_profit'])) ?></td>
                    <td>
                        <?php if ($product['type'] === 'item'): ?>
                            <span class="font-semibold <?= $isLow ? 'text-red-700' : 'text-slate-900' ?>"><?= h((string) $product['stock_qty']) ?></span>
                        <?php else: ?>
                            <span class="muted">Service</span>
                        <?php endif; ?>
                    </td>
                    <td><?= inventory_status_badge($product) ?></td>
                    <td>
                        <details class="action-menu">
                            <summary>Actions</summary>
                            <div class="action-menu-panel w-40">
                                <button type="button" class="btn alt action-menu-item" data-open-modal="edit-product-<?= (int) $product['id'] ?>">Edit Item</button>
                                <?php if ($product['type'] === 'item'): ?>
                                    <button type="button" class="btn alt action-menu-item mt-1" data-open-modal="stock-product-<?= (int) $product['id'] ?>">Manage Stock</button>
                                <?php endif; ?>
                                <?php if (user_can($user, 'archive_records')): ?>
                                    <form method="post" class="mt-1" onsubmit="return confirm('Archive this item? Historical sales will remain available.');">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="archive_product">
                                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                        <button type="submit" class="btn alt action-menu-item">Archive</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </details>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($pagination); ?>
    <div class="data-panel-footer flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <span><?= h((string) $totalInventoryRecords) ?> active record<?= $totalInventoryRecords === 1 ? '' : 's' ?> | <?= h((string) $lowStockCount) ?> low-stock item<?= $lowStockCount === 1 ? '' : 's' ?> | Stock value <?= h(money((float) $totalStockValue)) ?></span>
        <?php if ($lowStockCount > 0): ?>
            <a class="text-sm font-semibold text-brand-700 hover:text-brand-900" href="products.php?view=low_stock">View low stock</a>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php foreach ($products as $product): ?>
    <dialog id="edit-product-<?= (int) $product['id'] ?>" class="modal">
        <div class="modal-header">
            <h3>Edit Item</h3>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_product">
            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
            <div class="form-grid">
                <div>
                    <label for="edit_name_<?= (int) $product['id'] ?>">Item Name</label>
                    <input id="edit_name_<?= (int) $product['id'] ?>" name="name" value="<?= h($product['name']) ?>" required>
                </div>
                <div>
                    <label for="edit_sku_<?= (int) $product['id'] ?>">SKU</label>
                    <input id="edit_sku_<?= (int) $product['id'] ?>" name="sku" value="<?= h($product['sku']) ?>">
                </div>
                <div>
                    <label for="edit_category_<?= (int) $product['id'] ?>">Category</label>
                    <select id="edit_category_<?= (int) $product['id'] ?>" name="category" required>
                        <?php foreach (product_category_options() as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $product['category'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="edit_group_<?= (int) $product['id'] ?>">Group</label>
                    <select id="edit_group_<?= (int) $product['id'] ?>" name="product_group" required>
                        <?php foreach (['product' => 'Products', 'igp' => 'Project Items', 'service' => 'Services'] as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $product['product_group'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="edit_type_<?= (int) $product['id'] ?>">Type</label>
                    <select id="edit_type_<?= (int) $product['id'] ?>" name="type" required>
                        <option value="item" <?= $product['type'] === 'item' ? 'selected' : '' ?>>Product</option>
                        <option value="service" <?= $product['type'] === 'service' ? 'selected' : '' ?>>Service</option>
                    </select>
                </div>
                <div>
                    <label for="edit_cost_<?= (int) $product['id'] ?>">Cost Price</label>
                    <input id="edit_cost_<?= (int) $product['id'] ?>" name="cost_price" type="number" step="0.01" min="0" value="<?= h((string) $product['cost_price']) ?>" required>
                </div>
                <div>
                    <label for="edit_selling_<?= (int) $product['id'] ?>">Selling Price</label>
                    <input id="edit_selling_<?= (int) $product['id'] ?>" name="selling_price" type="number" step="0.01" min="0" value="<?= h((string) $product['selling_price']) ?>" required>
                </div>
                <div>
                    <label for="edit_threshold_<?= (int) $product['id'] ?>">Low Stock Alert Level</label>
                    <input id="edit_threshold_<?= (int) $product['id'] ?>" name="low_stock_threshold" type="number" min="0" value="<?= h((string) $product['low_stock_threshold']) ?>" required>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn alt" data-close-modal>Cancel</button>
                <button type="submit">Save Changes</button>
            </div>
        </form>
    </dialog>
    <?php if ($product['type'] === 'item'): ?>
        <dialog id="stock-product-<?= (int) $product['id'] ?>" class="modal">
            <div class="modal-header">
                <h3>Manage Stock</h3>
                <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="stock_movement">
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                <p class="muted"><?= h($product['name']) ?> has <?= h((string) $product['stock_qty']) ?> unit<?= (int) $product['stock_qty'] === 1 ? '' : 's' ?> on hand. Stock out uses FIFO automatically.</p>
                <div class="form-grid">
                    <div>
                        <label for="stock_direction_<?= (int) $product['id'] ?>">Direction</label>
                        <select id="stock_direction_<?= (int) $product['id'] ?>" name="direction" required>
                            <option value="in">Stock In</option>
                            <option value="out">Stock Out</option>
                        </select>
                    </div>
                    <div>
                        <label for="stock_quantity_<?= (int) $product['id'] ?>">Quantity</label>
                        <input id="stock_quantity_<?= (int) $product['id'] ?>" type="number" min="1" name="quantity" value="1" required>
                    </div>
                    <div>
                        <label for="stock_unit_cost_<?= (int) $product['id'] ?>">Unit Cost</label>
                        <input id="stock_unit_cost_<?= (int) $product['id'] ?>" type="number" min="0" step="0.01" name="unit_cost" value="<?= h((string) $product['cost_price']) ?>">
                    </div>
                    <div>
                        <label for="stock_date_<?= (int) $product['id'] ?>">Date and Time</label>
                        <input id="stock_date_<?= (int) $product['id'] ?>" type="datetime-local" name="movement_date" value="<?= date('Y-m-d\\TH:i') ?>">
                    </div>
                    <div>
                        <label for="stock_ref_<?= (int) $product['id'] ?>">Reference</label>
                        <input id="stock_ref_<?= (int) $product['id'] ?>" name="reference_no" placeholder="Receipt/Supplier ref">
                    </div>
                    <div class="checkbox-field">
                        <label>
                            <input type="checkbox" name="post_cash_out" value="1" checked>
                            Post stock-in cost to Cash Out
                        </label>
                    </div>
                    <div class="field-wide">
                        <label for="stock_notes_<?= (int) $product['id'] ?>">Notes</label>
                        <textarea id="stock_notes_<?= (int) $product['id'] ?>" name="notes"></textarea>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn alt" data-close-modal>Cancel</button>
                    <button type="submit">Save Stock Movement</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($inventoryView === 'batches'): ?>
<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3>Stock Batches on Hand</h3>
            <p class="muted"><?= h((string) count($stockBatches)) ?> stock batch<?= count($stockBatches) === 1 ? '' : 'es' ?> on hand. FIFO order is oldest received stock first.</p>
        </div>
        <span class="badge"><?= h((string) count($stockBatches)) ?> batch<?= count($stockBatches) === 1 ? '' : 'es' ?></span>
    </div>
    <div class="table-wrap" data-no-table-enhance>
        <table>
            <thead>
            <tr>
                <th>Received Date</th>
                <th>Item</th>
                <th>Batch</th>
                <th>Received Qty</th>
                <th>Remaining Qty</th>
                <th>Unit Cost</th>
                <th>Reference</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$stockBatches): ?>
                <tr>
                    <td colspan="7"><?php render_empty_state('No stock batches on hand.', 'Add stock to an item to begin FIFO tracking.'); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($stockBatches as $batch): ?>
                <tr>
                    <td><?= h($batch['received_date']) ?></td>
                    <td>
                        <div class="font-semibold text-slate-950"><?= h(product_display_name(['name' => $batch['product_name'], 'sku' => $batch['sku']])) ?></div>
                        <div class="mt-1 text-xs text-slate-500"><?= h(product_sku_text(['sku' => $batch['sku']])) ?></div>
                    </td>
                    <td><?= h($batch['batch_code'] ?: ('#' . $batch['id'])) ?></td>
                    <td><?= h((string) $batch['quantity_received']) ?></td>
                    <td class="font-semibold text-slate-950"><?= h((string) $batch['quantity_remaining']) ?></td>
                    <td><?= h(money((float) $batch['unit_cost'])) ?></td>
                    <td><?= h($batch['reference_no'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="data-panel-footer">
        <?= h((string) count($stockBatches)) ?> stock batch<?= count($stockBatches) === 1 ? '' : 'es' ?> on hand.
    </div>
</section>
<?php endif; ?>

<?php if ($inventoryView === 'ledger'): ?>
<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3>Stock Movement Ledger</h3>
            <p class="muted"><?= h((string) count($stockMovements)) ?> stock movement<?= count($stockMovements) === 1 ? '' : 's' ?> found. Stock in, stock out, adjustments, and sales deductions.</p>
        </div>
        <span class="badge"><?= h((string) count($stockMovements)) ?> movement<?= count($stockMovements) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-wrap" data-no-table-enhance>
        <table>
            <thead>
            <tr>
                <th>Date and Time</th>
                <th>Item</th>
                <th>Movement Type</th>
                <th>Quantity Change</th>
                <th>Unit Cost</th>
                <th>Total Cost</th>
                <th>Reference</th>
                <th>Notes</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$stockMovements): ?>
                <tr>
                    <td colspan="8"><?php render_empty_state('No stock movements yet.', 'Stock movements will appear after opening stock, restocking, sales, or adjustments.'); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($stockMovements as $movement): ?>
                <tr>
                    <td><?= h($movement['movement_date']) ?></td>
                    <td><?= h($movement['product_name']) ?></td>
                    <td><?= movement_status_badge((string) $movement['movement_type']) ?></td>
                    <td class="<?= (int) $movement['quantity_change'] < 0 ? 'text-red-700' : 'text-emerald-700' ?> font-semibold"><?= h((string) $movement['quantity_change']) ?></td>
                    <td><?= h(money((float) $movement['unit_cost'])) ?></td>
                    <td><?= h(money((float) $movement['total_cost'])) ?></td>
                    <td><?= h($movement['reference_no'] ?: '-') ?></td>
                    <td><?= h($movement['notes'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="data-panel-footer">
        <?= h((string) count($stockMovements)) ?> stock movement<?= count($stockMovements) === 1 ? '' : 's' ?> found.
    </div>
</section>
<?php endif; ?>

<?php if ($lowStockItems): ?>
<dialog id="low-stock-alerts" class="modal">
    <div class="modal-header">
        <div>
            <h3>Low Stock Alerts</h3>
            <p class="mt-1 text-sm text-slate-500"><?= h((string) count($lowStockItems)) ?> item<?= count($lowStockItems) === 1 ? '' : 's' ?> below threshold</p>
        </div>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
    </div>
    <div class="modal-content">
        <div class="space-y-2">
            <?php foreach ($lowStockItems as $item): ?>
                <button type="button" class="w-full rounded-lg border border-red-200 bg-red-50 p-3 text-left transition hover:bg-red-100" data-open-modal="stock-product-<?= (int) $item['id'] ?>" data-close-modal="low-stock-alerts">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-semibold text-slate-950"><?= h($item['name']) ?></p>
                            <p class="text-sm text-red-700">Stock: <strong><?= h((string) $item['stock_qty']) ?></strong> unit<?= (int) $item['stock_qty'] === 1 ? '' : 's' ?></p>
                        </div>
                        <?php if ((int) $item['stock_qty'] === 0): ?>
                            <span class="rounded-full bg-red-600 px-3 py-1 text-xs font-bold text-white">OUT OF STOCK</span>
                        <?php else: ?>
                            <span class="rounded-full bg-yellow-600 px-3 py-1 text-xs font-bold text-white">LOW STOCK</span>
                        <?php endif; ?>
                    </div>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="modal-actions">
        <button type="button" class="btn alt" data-close-modal>Close</button>
    </div>
</dialog>
<?php endif; ?>



<script>
    (function () {
        function bindProductType(categorySelect, groupSelect, typeSelect, stockInput) {
            if (!categorySelect || !groupSelect || !typeSelect) {
                return;
            }

            function sync() {
                const isServiceCategory = categorySelect.value === 'printing' || categorySelect.value === 'photocopy' || categorySelect.value === 'id_services';
                if (isServiceCategory) {
                    typeSelect.value = 'service';
                    groupSelect.value = 'service';
                }

                if (groupSelect.value === 'service') {
                    typeSelect.value = 'service';
                }

                const isService = typeSelect.value === 'service';
                if (isService) {
                    groupSelect.value = 'service';
                }
                if (stockInput) {
                    stockInput.disabled = isService;
                    if (isService) {
                        stockInput.value = '0';
                    }
                }
            }

            categorySelect.addEventListener('change', sync);
            groupSelect.addEventListener('change', sync);
            typeSelect.addEventListener('change', sync);
            sync();
        }

        bindProductType(
            document.getElementById('category'),
            document.getElementById('product_group'),
            document.getElementById('type'),
            document.getElementById('stock_qty')
        );

        document.querySelectorAll('dialog[id^="edit-product-"]').forEach(function (modal) {
            bindProductType(
                modal.querySelector('select[name="category"]'),
                modal.querySelector('select[name="product_group"]'),
                modal.querySelector('select[name="type"]'),
                null
            );
        });
    })();
</script>

<?php render_footer();
