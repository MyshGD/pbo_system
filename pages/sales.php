<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

function create_logbook_from_sale(PDO $pdo, string $saleDate, string $studentName, string $studentId, string $purpose, array $user, ?int $personId = null): int
{
    $studentName = trim($studentName);
    if ($studentName === '') {
        return 0;
    }

    $timestamp = strtotime($saleDate) ?: time();
    $stmt = $pdo->prepare('INSERT INTO office_logbook (person_id, log_date, time_in, time_out, student_name, student_id, purpose, created_by)
        VALUES (:person_id, :log_date, :time_in, NULL, :student_name, :student_id, :purpose, :created_by)');
    $stmt->execute([
        'person_id' => $personId,
        'log_date' => date('Y-m-d', $timestamp),
        'time_in' => date('H:i:s', $timestamp),
        'student_name' => $studentName,
        'student_id' => trim($studentId) !== '' ? trim($studentId) : null,
        'purpose' => $purpose,
        'created_by' => (int) $user['id'],
    ]);

    return (int) $pdo->lastInsertId();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect('sales.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'record_sale') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $saleDate = normalize_datetime_input((string) ($_POST['sale_date'] ?? ''));
        $orNumber = trim((string) ($_POST['or_number'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $studentName = trim((string) ($_POST['student_name'] ?? ''));
        $studentId = trim((string) ($_POST['student_id'] ?? ''));

        if ($productId <= 0 || $quantity <= 0) {
            set_flash('error', 'Item and quantity are required.');
            redirect('sales.php');
        }
        if ($studentName === '') {
            set_flash('error', 'Student name is required for the sale record.');
            redirect('sales.php');
        }

        $stmt = $pdo->prepare('SELECT id, name, sku, type, stock_qty, cost_price, selling_price FROM products WHERE id = :id AND is_active = 1');
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch();

        if (!$product) {
            set_flash('error', 'Selected item does not exist.');
            redirect('sales.php');
        }

        if ($product['type'] === 'item' && (int) $product['stock_qty'] < $quantity) {
            set_flash('error', 'Insufficient stock for this sale.');
            redirect('sales.php');
        }

        $unitPrice = (float) $product['selling_price'];
        $totalAmount = $unitPrice * $quantity;

        try {
            $pdo->beginTransaction();

            if ($product['type'] === 'item') {
                $fifo = inventory_fifo_issue($pdo, $productId, $quantity, $saleDate, 'sale', $orNumber, 'POS sale', $user);
                $totalCost = (float) $fifo['total_cost'];
                $unitCost = (float) $fifo['unit_cost'];
                $movementIds = $fifo['movement_ids'];
            } else {
                $unitCost = (float) $product['cost_price'];
                $totalCost = $unitCost * $quantity;
                $movementIds = [];
            }
            $totalProfit = $totalAmount - $totalCost;

            $insertSale = $pdo->prepare('INSERT INTO sales (sale_date, product_id, quantity, unit_price, unit_cost, total_amount, total_cost, total_profit, or_number, notes, created_by)
                VALUES (:sale_date, :product_id, :quantity, :unit_price, :unit_cost, :total_amount, :total_cost, :total_profit, :or_number, :notes, :created_by)');
            $insertSale->execute([
                'sale_date' => $saleDate,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'total_amount' => $totalAmount,
                'total_cost' => $totalCost,
                'total_profit' => $totalProfit,
                'or_number' => $orNumber !== '' ? $orNumber : null,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => (int) $user['id'],
            ]);
            $saleId = (int) $pdo->lastInsertId();

            if ($movementIds) {
                $placeholders = implode(',', array_fill(0, count($movementIds), '?'));
                $linkMovements = $pdo->prepare('UPDATE inventory_stock_movements SET sale_id = ? WHERE id IN (' . $placeholders . ')');
                $linkMovements->execute(array_merge([$saleId], $movementIds));
            }

            $insertCash = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, amount, or_number, description, created_by)
                VALUES (:txn_date, "in", "sales", :amount, :or_number, :description, :created_by)');
            $insertCash->execute([
                'txn_date' => $saleDate,
                'amount' => $totalAmount,
                'or_number' => $orNumber !== '' ? $orNumber : null,
                'description' => 'Sale: ' . $product['name'] . ' x ' . $quantity,
                'created_by' => (int) $user['id'],
            ]);

            $logbookId = create_logbook_from_sale(
                $pdo,
                $saleDate,
                $studentName,
                $studentId,
                'POS sale: ' . $product['name'] . ' x ' . $quantity . ($orNumber !== '' ? ' | OR ' . $orNumber : ''),
                $user,
                null
            );

            $pdo->commit();
            audit_log($pdo, $user, 'record_sale', 'sales', 'sale', $saleId, [
                'product_id' => $productId,
                'quantity' => $quantity,
                'amount' => $totalAmount,
                'profit' => $totalProfit,
                'logbook_id' => $logbookId,
            ]);
            set_flash('success', 'Sale recorded. Revenue: ' . money($totalAmount) . ' | Profit: ' . money($totalProfit));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to record sale.', ['error' => $e->getMessage(), 'product_id' => $productId], $user);
            set_flash('error', 'Failed to record sale.');
        }

        redirect('sales.php');
    }

    if ($action === 'batch_sale') {
        $itemsJson = (string) ($_POST['items_json'] ?? '{}');
        $orNumber = trim((string) ($_POST['or_number'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $studentName = trim((string) ($_POST['student_name'] ?? ''));
        $studentId = trim((string) ($_POST['student_id'] ?? ''));
        $saleDate = date('Y-m-d H:i:s');

        $items = json_decode($itemsJson, true) ?: [];
        
        if (empty($items)) {
            set_flash('error', 'No items in checkout.');
            redirect('sales.php');
        }
        if ($studentName === '') {
            set_flash('error', 'Student name is required for the sale record.');
            redirect('sales.php');
        }

        $totalRevenue = 0;
        $totalProfit = 0;
        $saleBatches = [];

        try {
            $pdo->beginTransaction();

            foreach ($items as $productId => $itemData) {
                $productId = (int) $productId;
                $quantity = (int) ($itemData['quantity'] ?? 0);

                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                $stmt = $pdo->prepare('SELECT id, name, sku, type, stock_qty, cost_price, selling_price FROM products WHERE id = :id AND is_active = 1');
                $stmt->execute(['id' => $productId]);
                $product = $stmt->fetch();

                if (!$product) {
                    throw new RuntimeException('Item ' . $productId . ' does not exist.');
                }

                if ($product['type'] === 'item' && (int) $product['stock_qty'] < $quantity) {
                    throw new RuntimeException('Insufficient stock for ' . $product['name']);
                }

                $unitPrice = (float) $product['selling_price'];
                $totalAmount = $unitPrice * $quantity;

                if ($product['type'] === 'item') {
                    $fifo = inventory_fifo_issue($pdo, $productId, $quantity, $saleDate, 'sale', $orNumber, 'POS sale (batch)', $user);
                    $totalCost = (float) $fifo['total_cost'];
                    $unitCost = (float) $fifo['unit_cost'];
                    $movementIds = $fifo['movement_ids'];
                } else {
                    $unitCost = (float) $product['cost_price'];
                    $totalCost = $unitCost * $quantity;
                    $movementIds = [];
                }
                
                $itemProfit = $totalAmount - $totalCost;
                $totalRevenue += $totalAmount;
                $totalProfit += $itemProfit;

                $insertSale = $pdo->prepare('INSERT INTO sales (sale_date, product_id, quantity, unit_price, unit_cost, total_amount, total_cost, total_profit, or_number, notes, created_by)
                    VALUES (:sale_date, :product_id, :quantity, :unit_price, :unit_cost, :total_amount, :total_cost, :total_profit, :or_number, :notes, :created_by)');
                $insertSale->execute([
                    'sale_date' => $saleDate,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'total_amount' => $totalAmount,
                    'total_cost' => $totalCost,
                    'total_profit' => $itemProfit,
                    'or_number' => $orNumber !== '' ? $orNumber : null,
                    'notes' => ($notes !== '' ? $notes . ' | ' : '') . $product['name'] . ' x' . $quantity,
                    'created_by' => (int) $user['id'],
                ]);
                $saleId = (int) $pdo->lastInsertId();

                if ($movementIds) {
                    $placeholders = implode(',', array_fill(0, count($movementIds), '?'));
                    $linkMovements = $pdo->prepare('UPDATE inventory_stock_movements SET sale_id = ? WHERE id IN (' . $placeholders . ')');
                    $linkMovements->execute(array_merge([$saleId], $movementIds));
                }

                $saleBatches[] = $saleId;
            }

            $insertCash = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, amount, or_number, description, created_by)
                VALUES (:txn_date, "in", "sales", :amount, :or_number, :description, :created_by)');
            $insertCash->execute([
                'txn_date' => $saleDate,
                'amount' => $totalRevenue,
                'or_number' => $orNumber !== '' ? $orNumber : null,
                'description' => 'Batch POS sale (' . count($saleBatches) . ' items)',
                'created_by' => (int) $user['id'],
            ]);

            $logbookId = create_logbook_from_sale(
                $pdo,
                $saleDate,
                $studentName,
                $studentId,
                'POS sale: ' . count($saleBatches) . ' item' . (count($saleBatches) === 1 ? '' : 's') . ($orNumber !== '' ? ' | OR ' . $orNumber : ''),
                $user,
                null
            );

            $pdo->commit();
            audit_log($pdo, $user, 'batch_sale', 'sales', 'sale', implode(',', $saleBatches), [
                'item_count' => count($saleBatches),
                'total_revenue' => $totalRevenue,
                'total_profit' => $totalProfit,
                'logbook_id' => $logbookId,
            ]);
            set_flash('success', 'Batch sale completed. ' . count($saleBatches) . ' item(s) | Revenue: ' . money($totalRevenue) . ' | Profit: ' . money($totalProfit));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to record batch sale.', ['error' => $e->getMessage(), 'items_count' => count($items)], $user);
            set_flash('error', 'Failed to record sale: ' . $e->getMessage());
        }

        redirect('sales.php');
    }
}

$products = $pdo->query("SELECT id, name, sku, category, product_group, type, stock_qty, cost_price, selling_price
    FROM products
    WHERE is_active = 1
    ORDER BY product_group ASC, name ASC")->fetchAll();
$people = people_options($pdo, true);
$receiptHeader = app_setting($pdo, 'reports.receipt_header', APP_CAMPUS_NAME);
$orNumberFormat = app_setting($pdo, 'reports.or_number_format', 'OR-{YYYY}-{0000}');

render_header('Point of Sale', $user);
?>

<div class="section-heading mb-4">
    <div>
        <p class="page-intro">Select items or services, add quantity, then checkout.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?php if ($user['role'] === 'admin'): ?>
            <button type="button" data-open-modal="add-product-pos-modal">Add Item</button>
        <?php endif; ?>
        <a class="btn alt" href="sales-reports.php">View Sales Records</a>
    </div>
</div>

<section class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
    <div class="card">
        <div class="section-heading">
            <div>
                <h3>Items and Services</h3>
                <p class="muted">Search by item name or SKU, then add the needed quantity.</p>
            </div>
        </div>

        <div class="mb-4 grid gap-3 md:grid-cols-[minmax(0,1fr)_220px]">
            <input id="pos_search" type="search" placeholder="Search item or scan SKU">
            <select id="pos_group">
                <option value="all">All Items</option>
                <option value="product">Products</option>
                <option value="service">Services</option>
            </select>
        </div>

        <div id="pos_grid" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <?php if (!$products): ?>
                <div class="md:col-span-2 xl:col-span-3">
                    <?php render_empty_state('No items available.', 'Add a product or service before recording a sale.', $user['role'] === 'admin' ? 'Add Item' : null, $user['role'] === 'admin' ? 'add-product-pos-modal' : null); ?>
                </div>
            <?php endif; ?>
            <?php foreach ($products as $product): ?>
                <?php
                $isItem = $product['type'] === 'item';
                $stock = (int) $product['stock_qty'];
                $disabled = $isItem && $stock <= 0;
                $name = product_display_name($product);
                $searchText = strtolower(trim($name . ' ' . (string) $product['sku'] . ' ' . product_type_label((string) $product['type']) . ' ' . product_category_label((string) $product['category'])));
                ?>
                <article
                    class="pos-option rounded-lg border border-slate-200 bg-white p-4 shadow-sm <?= $disabled ? 'opacity-60' : '' ?>"
                    data-product-id="<?= (int) $product['id'] ?>"
                    data-name="<?= h($name) ?>"
                    data-sku="<?= h((string) $product['sku']) ?>"
                    data-price="<?= h((string) $product['selling_price']) ?>"
                    data-stock="<?= h((string) $stock) ?>"
                    data-type="<?= h((string) $product['type']) ?>"
                    data-group="<?= h((string) $product['product_group']) ?>"
                    data-search="<?= h($searchText) ?>"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="truncate text-sm font-bold text-slate-950" title="<?= h($name) ?>"><?= h($name) ?></h3>
                            <p class="mt-1 text-xs text-slate-500"><?= h(product_sku_text($product)) ?></p>
                        </div>
                        <span class="status-pill <?= $isItem ? 'active' : 'pending' ?>"><?= h(product_type_label((string) $product['type'])) ?></span>
                    </div>
                    <div class="mt-4 flex items-end justify-between gap-3">
                        <div>
                            <p class="text-lg font-bold text-slate-950"><?= h(money((float) $product['selling_price'])) ?></p>
                            <p class="mt-1 text-xs text-slate-500"><?= $isItem ? 'Stock: ' . h((string) $stock) : 'Service' ?></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <input class="pos-quantity h-10 w-16 rounded-md border border-slate-300 px-2 text-sm" type="number" min="1" <?= $isItem ? 'max="' . h((string) $stock) . '"' : '' ?> value="1" <?= $disabled ? 'disabled' : '' ?>>
                            <button type="button" class="pos-add-btn h-10 rounded-md px-3 text-sm" <?= $disabled ? 'disabled' : '' ?>>Add</button>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>

    <aside class="card h-fit lg:sticky lg:top-24">
        <div class="section-heading">
            <div>
                <h3>Checkout</h3>
                <p class="muted">Items selected for this sale.</p>
            </div>
        </div>
        <div class="rounded-lg bg-slate-50 p-4">
            <p class="text-sm font-semibold text-slate-500">Total Amount</p>
            <p id="checkout-total" class="mt-1 text-3xl font-bold text-slate-950">PHP 0.00</p>
        </div>
        <div id="checkout-items" class="mt-4 max-h-72 space-y-2 overflow-y-auto rounded-lg border border-slate-200 p-3">
            <div class="empty-state">
                <strong>No items added.</strong>
                <p>Select a product or service to begin checkout.</p>
            </div>
        </div>
        <form method="post" id="batch-sale-form" class="mt-4 space-y-3">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="batch_sale">
            <input type="hidden" id="batch_items_json" name="items_json" value="{}">
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="mb-3 text-sm font-semibold text-slate-700">Customer or Student Details</p>
                <div class="space-y-3">
                    <div>
                        <label for="batch_student_name">Student Name</label>
                        <input id="batch_student_name" name="student_name" placeholder="Required" required>
                    </div>
                    <div>
                        <label for="batch_student_id">Student ID</label>
                        <input id="batch_student_id" name="student_id" placeholder="Optional">
                    </div>
                </div>
            </div>
            <div>
                <label for="batch_or_number">OR Number</label>
                <input id="batch_or_number" name="or_number" placeholder="<?= h($orNumberFormat) ?>">
                <p class="mt-1 text-xs text-slate-500"><?= h($receiptHeader) ?></p>
            </div>
            <div>
                <label for="batch_notes">Notes</label>
                <textarea id="batch_notes" name="notes" placeholder="Optional" class="text-sm"></textarea>
            </div>
            <button type="submit" id="confirm-sale-btn" class="w-full" disabled>Confirm Sale</button>
            <button type="button" id="clear-checkout-btn" class="btn alt w-full">Clear Checkout</button>
        </form>
    </aside>
</section>

<?php if ($user['role'] === 'admin'): ?>
<dialog id="add-product-pos-modal" class="modal">
    <div class="modal-header">
        <h3>Add Item</h3>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
    </div>
    <form method="post" action="products.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_product">

        <div class="form-grid">
            <div>
                <label for="pos_name">Item Name</label>
                <input id="pos_name" name="name" required>
            </div>
            <div>
                <label for="pos_sku">SKU (Optional)</label>
                <input id="pos_sku" name="sku">
            </div>
            <div>
                <label for="pos_category">Category</label>
                <select id="pos_category" name="category" required>
                    <?php foreach (product_category_options() as $value => $label): ?>
                        <option value="<?= h($value) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="pos_group">Group</label>
                <select id="pos_group_add" name="product_group" required>
                    <option value="product">Products</option>
                    <option value="service">Services</option>
                </select>
            </div>
            <div>
                <label for="pos_type">Type</label>
                <select id="pos_type" name="type" required>
                    <option value="item">Product (with stock)</option>
                    <option value="service">Service (no stock)</option>
                </select>
            </div>
            <div>
                <label for="pos_cost_price">Capital/Cost Price</label>
                <input id="pos_cost_price" name="cost_price" type="number" step="0.01" min="0" value="0" required>
            </div>
            <div>
                <label for="pos_selling_price">Selling Price</label>
                <input id="pos_selling_price" name="selling_price" type="number" step="0.01" min="0" value="0" required>
            </div>
            <div>
                <label for="pos_stock_qty">Current Stock</label>
                <input id="pos_stock_qty" name="stock_qty" type="number" min="0" value="0" required>
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <button type="submit">Save Item</button>
        </div>
    </form>
</dialog>

<?php foreach ($products as $product): ?>
<dialog id="modify-product-<?= (int) $product['id'] ?>" class="modal">
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
                <label for="modify_name_<?= (int) $product['id'] ?>">Item Name</label>
                <input id="modify_name_<?= (int) $product['id'] ?>" name="name" value="<?= h($product['name']) ?>" required>
            </div>
            <div>
                <label for="modify_sku_<?= (int) $product['id'] ?>">SKU</label>
                <input id="modify_sku_<?= (int) $product['id'] ?>" name="sku" value="<?= h((string) $product['sku']) ?>">
            </div>
            <div>
                <label for="modify_cost_<?= (int) $product['id'] ?>">Cost Price</label>
                <input id="modify_cost_<?= (int) $product['id'] ?>" name="cost_price" type="number" step="0.01" min="0" value="<?= h((string) $product['cost_price']) ?>" required>
            </div>
            <div>
                <label for="modify_selling_<?= (int) $product['id'] ?>">Selling Price</label>
                <input id="modify_selling_<?= (int) $product['id'] ?>" name="selling_price" type="number" step="0.01" min="0" value="<?= h((string) $product['selling_price']) ?>" required>
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <button type="submit">Update Item</button>
        </div>
    </form>
</dialog>
<?php endforeach; ?>
<?php endif; ?>

<script>
    (function () {
        return;
        const searchInput = document.getElementById('pos_search');
        const groupSelect = document.getElementById('pos_group');
        const options = Array.from(document.querySelectorAll('.pos-option'));
        const modalTitle = document.getElementById('sale_modal_title');
        const modalMeta = document.getElementById('sale_modal_meta');
        const quantityInput = document.getElementById('sale_quantity');
        const totalPreview = document.getElementById('sale_total_preview');
        const addBtn = document.getElementById('sale_add_btn');
        const checkoutTotal = document.getElementById('checkout-total');
        const checkoutItems = document.getElementById('checkout-items');
        const checkoutControls = document.getElementById('checkout-controls');
        const batchItemsJson = document.getElementById('batch_items_json');
        const clearCheckoutBtn = document.getElementById('clear-checkout-btn');
        const batchSaleForm = document.getElementById('batch-sale-form');
        
        let selectedPrice = 0;
        let selectedStock = 0;
        let selectedType = 'service';
        let selectedProductId = 0;
        let selectedProductName = '';
        let checkoutData = {};

        function money(value) {
            return 'PHP ' + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function updateCheckoutDisplay() {
            let total = 0;
            const itemsList = [];
            
            Object.entries(checkoutData).forEach(function([productId, item]) {
                const itemTotal = item.price * item.quantity;
                total += itemTotal;
                itemsList.push({
                    id: productId,
                    name: item.name,
                    quantity: item.quantity,
                    price: item.price,
                    total: itemTotal
                });
            });

            checkoutTotal.textContent = money(total);
            batchItemsJson.value = JSON.stringify(checkoutData);
            
            if (itemsList.length === 0) {
                checkoutItems.innerHTML = '<p class="text-sm text-slate-400">No items added</p>';
                checkoutControls.classList.add('hidden');
            } else {
                checkoutItems.innerHTML = itemsList.map(function(item) {
                    return '<div class="flex items-center justify-between rounded-lg bg-slate-50 p-2 text-sm"><div class="flex-1"><span class="font-medium text-slate-900">' + item.quantity + 'x ' + item.name + '</span><br><span class="text-xs text-slate-500">' + money(item.price) + ' = ' + money(item.total) + '</span></div><button type="button" class="remove-item-btn shrink-0 ml-2 text-red-600 hover:text-red-800 font-bold" data-product-id="' + item.id + '">✕</button></div>';
                }).join('');
                
                document.querySelectorAll('.remove-item-btn').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        const productId = this.getAttribute('data-product-id');
                        delete checkoutData[productId];
                        updateCheckoutDisplay();
                    });
                });
                
                checkoutControls.classList.remove('hidden');
            }
        }

        function filterOptions() {
            const term = (searchInput.value || '').trim().toLowerCase();
            const group = groupSelect.value;
            options.forEach(function (option) {
                const matchesSearch = term === '' || (option.dataset.search || '').includes(term);
                const matchesGroup = group === 'all' || option.dataset.group === group || (group === 'product' && option.dataset.group === 'igp');
                option.hidden = !matchesSearch || !matchesGroup;
            });
        }

        function updateTotal() {
            const qty = Math.max(1, parseInt(quantityInput.value || '1', 10));
            totalPreview.value = money(selectedPrice * qty);
            if (selectedType === 'item') {
                addBtn.disabled = qty > selectedStock;
            }
        }

        options.forEach(function (option) {
            option.addEventListener('click', function () {
                if (!option.hasAttribute('data-open-modal')) {
                    return;
                }
                selectedPrice = parseFloat(option.dataset.price || '0');
                selectedStock = parseInt(option.dataset.stock || '0', 10);
                selectedType = option.dataset.type || 'service';
                selectedProductId = option.dataset.productId || '';
                selectedProductName = option.dataset.name || 'Unknown Item';
                quantityInput.value = '1';
                quantityInput.max = selectedType === 'item' ? String(selectedStock) : '';
                modalTitle.textContent = 'Add to Checkout';
                modalMeta.textContent = (option.dataset.sku || 'No SKU') + ' | ' + money(selectedPrice) + (selectedType === 'item' ? ' | Stock: ' + selectedStock : ' | Service');
                addBtn.disabled = false;
                updateTotal();
            });
            option.addEventListener('keydown', function (event) {
                if ((event.key === 'Enter' || event.key === ' ') && option.hasAttribute('data-open-modal')) {
                    event.preventDefault();
                    option.click();
                }
            });
        });

        addBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const quantity = parseInt(quantityInput.value || '1', 10);
            
            if (selectedProductId && quantity > 0) {
                if (checkoutData[selectedProductId]) {
                    checkoutData[selectedProductId].quantity += quantity;
                } else {
                    checkoutData[selectedProductId] = {
                        name: selectedProductName,
                        quantity: quantity,
                        price: selectedPrice
                    };
                }
                updateCheckoutDisplay();
                document.querySelector('[data-close-modal="sale-modal"]')?.click() || 
                document.querySelector('[data-close-modal]')?.click();
            }
        });

        clearCheckoutBtn.addEventListener('click', function() {
            if (confirm('Clear all items from checkout?')) {
                checkoutData = {};
                updateCheckoutDisplay();
            }
        });

        searchInput.addEventListener('input', filterOptions);
        groupSelect.addEventListener('change', filterOptions);
        quantityInput.addEventListener('input', updateTotal);
        filterOptions();
        updateCheckoutDisplay();
    })();
</script>

<script>
    (function () {
        const searchInput = document.getElementById('pos_search');
        const groupSelect = document.getElementById('pos_group');
        const options = Array.from(document.querySelectorAll('.pos-option'));
        const checkoutTotal = document.getElementById('checkout-total');
        const checkoutItems = document.getElementById('checkout-items');
        const batchItemsJson = document.getElementById('batch_items_json');
        const clearCheckoutBtn = document.getElementById('clear-checkout-btn');
        const batchSaleForm = document.getElementById('batch-sale-form');
        const confirmSaleBtn = document.getElementById('confirm-sale-btn');
        let checkoutData = {};

        function money(value) {
            return 'PHP ' + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function updateCheckoutDisplay() {
            let total = 0;
            const itemsList = [];

            Object.entries(checkoutData).forEach(function([productId, item]) {
                const itemTotal = item.price * item.quantity;
                total += itemTotal;
                itemsList.push({ id: productId, name: item.name, quantity: item.quantity, price: item.price, total: itemTotal });
            });

            checkoutTotal.textContent = money(total);
            batchItemsJson.value = JSON.stringify(checkoutData);
            confirmSaleBtn.disabled = itemsList.length === 0;

            if (itemsList.length === 0) {
                checkoutItems.innerHTML = '<div class="empty-state"><strong>No items added.</strong><p>Select a product or service to begin checkout.</p></div>';
                return;
            }

            checkoutItems.innerHTML = itemsList.map(function(item) {
                return '<div class="rounded-lg bg-slate-50 p-3 text-sm"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate font-semibold text-slate-950" title="' + item.name + '">' + item.name + '</p><p class="mt-1 text-xs text-slate-500">Qty ' + item.quantity + ' x ' + money(item.price) + '</p></div><button type="button" class="remove-item-btn text-xs font-semibold text-red-700 hover:text-red-900" data-product-id="' + item.id + '">Remove</button></div><p class="mt-2 font-bold text-slate-950">' + money(item.total) + '</p></div>';
            }).join('');

            document.querySelectorAll('.remove-item-btn').forEach(function(btn) {
                btn.addEventListener('click', function(event) {
                    event.preventDefault();
                    delete checkoutData[this.getAttribute('data-product-id')];
                    updateCheckoutDisplay();
                });
            });
        }

        function filterOptions() {
            const term = (searchInput.value || '').trim().toLowerCase();
            const group = groupSelect.value;
            options.forEach(function(option) {
                const matchesSearch = term === '' || (option.dataset.search || '').includes(term);
                const matchesGroup = group === 'all' || option.dataset.group === group;
                option.hidden = !matchesSearch || !matchesGroup;
            });
        }

        document.querySelectorAll('.pos-add-btn').forEach(function(button) {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                const option = button.closest('.pos-option');
                const quantityInput = option.querySelector('.pos-quantity');
                const productId = option.dataset.productId || '';
                const productName = option.dataset.name || 'Unknown Item';
                const price = parseFloat(option.dataset.price || '0');
                const stock = parseInt(option.dataset.stock || '0', 10);
                const type = option.dataset.type || 'service';
                const quantity = Math.max(1, parseInt(quantityInput.value || '1', 10));

                if (type === 'item' && quantity > stock) {
                    alert('Quantity exceeds available stock.');
                    quantityInput.value = stock > 0 ? String(stock) : '1';
                    return;
                }

                if (!productId || quantity <= 0) {
                    return;
                }

                if (checkoutData[productId]) {
                    checkoutData[productId].quantity += quantity;
                } else {
                    checkoutData[productId] = { name: productName, quantity: quantity, price: price };
                }
                updateCheckoutDisplay();
            });
        });

        clearCheckoutBtn.addEventListener('click', function() {
            if (Object.keys(checkoutData).length === 0 || confirm('Clear all items from checkout?')) {
                checkoutData = {};
                updateCheckoutDisplay();
            }
        });

        batchSaleForm.addEventListener('submit', function(event) {
            if (Object.keys(checkoutData).length === 0) {
                event.preventDefault();
            }
        });

        searchInput.addEventListener('input', filterOptions);
        groupSelect.addEventListener('change', filterOptions);
        filterOptions();
        updateCheckoutDisplay();
    })();
</script>

<?php render_footer();
