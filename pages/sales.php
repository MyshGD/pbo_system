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
        'person_id'    => $personId,
        'log_date'     => date('Y-m-d', $timestamp),
        'time_in'      => date('H:i:s', $timestamp),
        'student_name' => $studentName,
        'student_id'   => trim($studentId) !== '' ? trim($studentId) : null,
        'purpose'      => $purpose,
        'created_by'   => (int) $user['id'],
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
        $productId   = (int) ($_POST['product_id'] ?? 0);
        $quantity    = (int) ($_POST['quantity'] ?? 0);
        $saleDate    = normalize_datetime_input((string) ($_POST['sale_date'] ?? ''));
        $orNumber    = trim((string) ($_POST['or_number'] ?? ''));
        $notes       = trim((string) ($_POST['notes'] ?? ''));
        $studentName = trim((string) ($_POST['student_name'] ?? ''));
        $studentId   = trim((string) ($_POST['student_id'] ?? ''));

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

        $unitPrice   = (float) $product['selling_price'];
        $totalAmount = $unitPrice * $quantity;

        try {
            $pdo->beginTransaction();

            if ($product['type'] === 'item') {
                $fifo        = inventory_fifo_issue($pdo, $productId, $quantity, $saleDate, 'sale', $orNumber, 'POS sale', $user);
                $totalCost   = (float) $fifo['total_cost'];
                $unitCost    = (float) $fifo['unit_cost'];
                $movementIds = $fifo['movement_ids'];
            } else {
                $unitCost    = (float) $product['cost_price'];
                $totalCost   = $unitCost * $quantity;
                $movementIds = [];
            }
            $totalProfit = $totalAmount - $totalCost;

            $insertSale = $pdo->prepare('INSERT INTO sales (sale_date, product_id, quantity, unit_price, unit_cost, total_amount, total_cost, total_profit, or_number, notes, created_by)
                VALUES (:sale_date, :product_id, :quantity, :unit_price, :unit_cost, :total_amount, :total_cost, :total_profit, :or_number, :notes, :created_by)');
            $insertSale->execute([
                'sale_date'    => $saleDate,
                'product_id'   => $productId,
                'quantity'     => $quantity,
                'unit_price'   => $unitPrice,
                'unit_cost'    => $unitCost,
                'total_amount' => $totalAmount,
                'total_cost'   => $totalCost,
                'total_profit' => $totalProfit,
                'or_number'    => $orNumber !== '' ? $orNumber : null,
                'notes'        => $notes !== '' ? $notes : null,
                'created_by'   => (int) $user['id'],
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
                'txn_date'    => $saleDate,
                'amount'      => $totalAmount,
                'or_number'   => $orNumber !== '' ? $orNumber : null,
                'description' => 'Sale: ' . $product['name'] . ' x ' . $quantity,
                'created_by'  => (int) $user['id'],
            ]);

            $logbookId = create_logbook_from_sale(
                $pdo, $saleDate, $studentName, $studentId,
                'POS sale: ' . $product['name'] . ' x ' . $quantity . ($orNumber !== '' ? ' | OR ' . $orNumber : ''),
                $user, null
            );

            $pdo->commit();
            audit_log($pdo, $user, 'record_sale', 'sales', 'sale', $saleId, [
                'product_id' => $productId, 'quantity' => $quantity,
                'amount' => $totalAmount, 'profit' => $totalProfit,
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
        $itemsJson   = (string) ($_POST['items_json'] ?? '{}');
        $orNumber    = trim((string) ($_POST['or_number'] ?? ''));
        $notes       = trim((string) ($_POST['notes'] ?? ''));
        $studentName = trim((string) ($_POST['student_name'] ?? ''));
        $studentId   = trim((string) ($_POST['student_id'] ?? ''));
        $saleDate    = date('Y-m-d H:i:s');

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
        $totalProfit  = 0;
        $saleBatches  = [];

        try {
            $pdo->beginTransaction();

            foreach ($items as $productId => $itemData) {
                $productId = (int) $productId;
                $quantity  = (int) ($itemData['quantity'] ?? 0);

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
                    throw new RuntimeException('Insufficient stock for ' . $product['name'] . '. Only ' . $product['stock_qty'] . ' left.');
                }

                $unitPrice   = (float) $product['selling_price'];
                $totalAmount = $unitPrice * $quantity;

                if ($product['type'] === 'item') {
                    $fifo        = inventory_fifo_issue($pdo, $productId, $quantity, $saleDate, 'sale', $orNumber, 'POS sale (batch)', $user);
                    $totalCost   = (float) $fifo['total_cost'];
                    $unitCost    = (float) $fifo['unit_cost'];
                    $movementIds = $fifo['movement_ids'];
                } else {
                    $unitCost    = (float) $product['cost_price'];
                    $totalCost   = $unitCost * $quantity;
                    $movementIds = [];
                }

                $itemProfit    = $totalAmount - $totalCost;
                $totalRevenue += $totalAmount;
                $totalProfit  += $itemProfit;

                $insertSale = $pdo->prepare('INSERT INTO sales (sale_date, product_id, quantity, unit_price, unit_cost, total_amount, total_cost, total_profit, or_number, notes, created_by)
                    VALUES (:sale_date, :product_id, :quantity, :unit_price, :unit_cost, :total_amount, :total_cost, :total_profit, :or_number, :notes, :created_by)');
                $insertSale->execute([
                    'sale_date'    => $saleDate,
                    'product_id'   => $productId,
                    'quantity'     => $quantity,
                    'unit_price'   => $unitPrice,
                    'unit_cost'    => $unitCost,
                    'total_amount' => $totalAmount,
                    'total_cost'   => $totalCost,
                    'total_profit' => $itemProfit,
                    'or_number'    => $orNumber !== '' ? $orNumber : null,
                    'notes'        => ($notes !== '' ? $notes . ' | ' : '') . $product['name'] . ' x' . $quantity,
                    'created_by'   => (int) $user['id'],
                ]);
                $saleId = (int) $pdo->lastInsertId();

                if ($movementIds) {
                    $placeholders  = implode(',', array_fill(0, count($movementIds), '?'));
                    $linkMovements = $pdo->prepare('UPDATE inventory_stock_movements SET sale_id = ? WHERE id IN (' . $placeholders . ')');
                    $linkMovements->execute(array_merge([$saleId], $movementIds));
                }

                $saleBatches[] = $saleId;
            }

            $insertCash = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, amount, or_number, description, created_by)
                VALUES (:txn_date, "in", "sales", :amount, :or_number, :description, :created_by)');
            $insertCash->execute([
                'txn_date'    => $saleDate,
                'amount'      => $totalRevenue,
                'or_number'   => $orNumber !== '' ? $orNumber : null,
                'description' => 'Batch POS sale (' . count($saleBatches) . ' items)',
                'created_by'  => (int) $user['id'],
            ]);

            $logbookId = create_logbook_from_sale(
                $pdo, $saleDate, $studentName, $studentId,
                'POS sale: ' . count($saleBatches) . ' item' . (count($saleBatches) === 1 ? '' : 's') . ($orNumber !== '' ? ' | OR ' . $orNumber : ''),
                $user, null
            );

            $pdo->commit();
            audit_log($pdo, $user, 'batch_sale', 'sales', 'sale', implode(',', $saleBatches), [
                'item_count'    => count($saleBatches),
                'total_revenue' => $totalRevenue,
                'total_profit'  => $totalProfit,
                'logbook_id'    => $logbookId,
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

$receiptHeader  = app_setting($pdo, 'reports.receipt_header', APP_CAMPUS_NAME);
$orNumberFormat = app_setting($pdo, 'reports.or_number_format', 'OR-{YYYY}-{0000}');

render_header('Point of Sale', $user);
?>

<div class="section-heading mb-4">
    <div>
        <p class="page-intro">Select items or services, set quantity, then confirm the sale.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a class="btn alt" href="sales-reports.php">View Sales Records</a>
    </div>
</div>

<section class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">

    <!-- ── Product grid ─────────────────────────────────────── -->
    <div>
        <div class="mb-3 flex flex-wrap gap-2">
            <input id="pos_search" type="search" placeholder="Search item or SKU…"
                   class="flex-1 min-w-[180px] rounded-md border border-slate-300 px-3 py-2 text-sm">
            <select id="pos_group" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option value="all">All types</option>
                <option value="product">Products</option>
                <option value="igp">Project Items</option>
                <option value="service">Services</option>
            </select>
        </div>

        <div id="pos_grid" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <?php if (!$products): ?>
                <div class="md:col-span-2 xl:col-span-3">
                    <?php render_empty_state('No items available.', 'Add a product or service in Inventory before recording a sale.'); ?>
                </div>
            <?php endif; ?>

            <?php foreach ($products as $product):
                $isItem   = $product['type'] === 'item';
                $stock    = (int) $product['stock_qty'];
                $disabled = $isItem && $stock <= 0;
                $name     = product_display_name($product);
                $searchText = strtolower($name . ' ' . (string) $product['sku'] . ' ' . product_category_label((string) $product['category']));
            ?>
            <article
                class="pos-card rounded-lg border border-slate-200 bg-white p-4 shadow-sm flex flex-col gap-3 <?= $disabled ? 'opacity-55' : '' ?>"
                data-id="<?= (int) $product['id'] ?>"
                data-name="<?= h($name) ?>"
                data-price="<?= h((string) $product['selling_price']) ?>"
                data-stock="<?= h((string) $stock) ?>"
                data-type="<?= h((string) $product['type']) ?>"
                data-group="<?= h((string) $product['product_group']) ?>"
                data-search="<?= h($searchText) ?>"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-slate-950" title="<?= h($name) ?>"><?= h($name) ?></p>
                        <p class="mt-0.5 text-xs text-slate-500"><?= h(product_sku_text($product)) ?></p>
                    </div>
                    <span class="status-pill <?= $isItem ? 'active' : 'pending' ?> shrink-0"><?= $isItem ? 'Product' : 'Service' ?></span>
                </div>

                <div class="flex items-end justify-between gap-2 mt-auto">
                    <div>
                        <p class="text-lg font-bold text-slate-950"><?= h(money((float) $product['selling_price'])) ?></p>
                        <?php if ($isItem): ?>
                            <p class="text-xs text-slate-500 pos-avail-label">
                                Available: <span class="pos-avail-count font-semibold <?= $stock <= 0 ? 'text-red-600' : ($stock <= (int) $product['low_stock_threshold'] ? 'text-amber-600' : 'text-slate-700') ?>"><?= h((string) $stock) ?></span>
                            </p>
                        <?php else: ?>
                            <p class="text-xs text-slate-500">Service</p>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <input
                            type="number"
                            class="pos-qty-input h-9 w-16 rounded-md border border-slate-300 px-2 text-sm text-center"
                            min="1"
                            <?= $isItem ? 'max="' . h((string) $stock) . '"' : '' ?>
                            value="1"
                            <?= $disabled ? 'disabled' : '' ?>
                            aria-label="Quantity for <?= h($name) ?>"
                        >
                        <button
                            type="button"
                            class="pos-add-btn h-9 rounded-md px-3 text-sm font-semibold <?= $disabled ? 'cursor-not-allowed opacity-40' : '' ?>"
                            <?= $disabled ? 'disabled' : '' ?>
                            aria-label="Add <?= h($name) ?> to checkout"
                        >Add</button>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <!-- No-results message (hidden by JS) -->
        <p id="pos_no_results" class="hidden mt-6 text-center text-sm text-slate-500">No items match your search.</p>
    </div>

    <!-- ── Checkout panel ───────────────────────────────────── -->
    <aside class="h-fit lg:sticky lg:top-24">
        <div class="card flex flex-col gap-4">
            <div class="section-heading">
                <h3>Checkout</h3>
                <span class="badge" id="cart-count">0 items</span>
            </div>

            <!-- Total -->
            <div class="rounded-lg bg-slate-50 px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total Amount</p>
                <p id="checkout-total" class="mt-1 text-3xl font-bold text-slate-950">PHP 0.00</p>
            </div>

            <!-- Cart items list -->
            <div id="checkout-items" class="max-h-56 overflow-y-auto space-y-2 rounded-lg border border-slate-200 p-2">
                <p class="text-center text-sm text-slate-400 py-4">No items added yet.</p>
            </div>

            <!-- Checkout form -->
            <form method="post" id="batch-sale-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="batch_sale">
                <input type="hidden" id="batch_items_json" name="items_json" value="{}">

                <div class="space-y-3">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-2">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Customer</p>
                        <div>
                            <label for="batch_student_name" class="mb-1 block text-xs font-semibold text-slate-700">Name <span class="text-red-500">*</span></label>
                            <input id="batch_student_name" name="student_name" placeholder="Student / customer name" required
                                   class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        </div>
                        <div>
                            <label for="batch_student_id" class="mb-1 block text-xs font-semibold text-slate-700">ID</label>
                            <input id="batch_student_id" name="student_id" placeholder="Student ID (optional)"
                                   class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        </div>
                    </div>

                    <div>
                        <label for="batch_or_number" class="mb-1 block text-xs font-semibold text-slate-700">OR Number</label>
                        <input id="batch_or_number" name="or_number" placeholder="<?= h($orNumberFormat) ?>"
                               class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                    </div>
                    <div>
                        <label for="batch_notes" class="mb-1 block text-xs font-semibold text-slate-700">Notes</label>
                        <input id="batch_notes" name="notes" placeholder="Optional"
                               class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                    </div>
                </div>

                <div class="mt-4 flex gap-2">
                    <button type="button" id="clear-checkout-btn" class="btn alt flex-1">Clear</button>
                    <button type="submit" id="confirm-sale-btn" class="btn flex-1" disabled>Confirm Sale</button>
                </div>
                <p class="mt-2 text-center text-xs text-slate-400"><?= h($receiptHeader) ?></p>
            </form>
        </div>
    </aside>
</section>

<script>
(function () {
    'use strict';

    // ── State ────────────────────────────────────────────────
    // { productId: { name, quantity, price } }
    var cart = {};

    // ── DOM refs ─────────────────────────────────────────────
    var searchInput    = document.getElementById('pos_search');
    var groupSelect    = document.getElementById('pos_group');
    var checkoutTotal  = document.getElementById('checkout-total');
    var checkoutItems  = document.getElementById('checkout-items');
    var cartCountBadge = document.getElementById('cart-count');
    var batchItemsJson = document.getElementById('batch_items_json');
    var clearBtn       = document.getElementById('clear-checkout-btn');
    var confirmBtn     = document.getElementById('confirm-sale-btn');
    var noResults      = document.getElementById('pos_no_results');
    var cards          = Array.from(document.querySelectorAll('.pos-card'));

    function fmt(v) {
        return 'PHP ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // ── Returns how many of productId are available after cart ─
    function effectiveStock(card) {
        var id    = card.getAttribute('data-id');
        var stock = parseInt(card.getAttribute('data-stock'), 10) || 0;
        var type  = card.getAttribute('data-type');
        if (type !== 'item') return Infinity;
        var inCart = cart[id] ? cart[id].quantity : 0;
        return Math.max(0, stock - inCart);
    }

    // ── Refresh each card's available label, input max, disabled state ──
    function refreshCards() {
        cards.forEach(function (card) {
            var id      = card.getAttribute('data-id');
            var type    = card.getAttribute('data-type');
            var avail   = effectiveStock(card);
            var qInput  = card.querySelector('.pos-qty-input');
            var addBtn  = card.querySelector('.pos-add-btn');
            var label   = card.querySelector('.pos-avail-count');

            if (type === 'item') {
                // Update available label
                if (label) {
                    label.textContent = avail;
                    label.className = 'pos-avail-count font-semibold ' + (
                        avail <= 0 ? 'text-red-600' :
                        (parseInt(card.getAttribute('data-stock'), 10) <= 5 ? 'text-amber-600' : 'text-slate-700')
                    );
                }
                // Update input max so browser prevents overrun
                if (qInput) {
                    qInput.max = avail;
                    // clamp current value to available
                    if (parseInt(qInput.value, 10) > avail) {
                        qInput.value = avail > 0 ? avail : 1;
                    }
                    qInput.disabled = avail <= 0;
                }
                // Disable add button if nothing available
                if (addBtn) {
                    var isOut = avail <= 0;
                    addBtn.disabled = isOut;
                    card.classList.toggle('opacity-55', isOut);
                }
            }
        });
    }

    // ── Render cart items list ────────────────────────────────
    function renderCart() {
        var keys  = Object.keys(cart);
        var total = 0;
        var totalQty = 0;

        batchItemsJson.value = JSON.stringify(cart);
        confirmBtn.disabled  = keys.length === 0;

        if (keys.length === 0) {
            checkoutItems.innerHTML = '<p class="text-center text-sm text-slate-400 py-4">No items added yet.</p>';
            checkoutTotal.textContent = fmt(0);
            cartCountBadge.textContent = '0 items';
            return;
        }

        var html = '';
        keys.forEach(function (id) {
            var item     = cart[id];
            var lineTotal = item.price * item.quantity;
            total    += lineTotal;
            totalQty += item.quantity;
            html += '<div class="flex items-center gap-2 rounded-md bg-slate-50 px-3 py-2 text-sm">'
                  +   '<div class="flex-1 min-w-0">'
                  +     '<p class="truncate font-semibold text-slate-900">' + escHtml(item.name) + '</p>'
                  +     '<p class="text-xs text-slate-500">' + item.quantity + ' × ' + fmt(item.price) + ' = ' + fmt(lineTotal) + '</p>'
                  +   '</div>'
                  +   '<div class="flex items-center gap-1 shrink-0">'
                  +     '<button type="button" class="cart-dec h-6 w-6 rounded text-xs font-bold border border-slate-300 leading-none" data-id="' + id + '" aria-label="Decrease quantity">−</button>'
                  +     '<span class="w-6 text-center text-xs font-bold">' + item.quantity + '</span>'
                  +     '<button type="button" class="cart-inc h-6 w-6 rounded text-xs font-bold border border-slate-300 leading-none" data-id="' + id + '" aria-label="Increase quantity">+</button>'
                  +     '<button type="button" class="cart-rm ml-1 text-red-400 hover:text-red-700 text-xs font-bold" data-id="' + id + '" aria-label="Remove">✕</button>'
                  +   '</div>'
                  + '</div>';
        });

        checkoutItems.innerHTML = html;
        checkoutTotal.textContent = fmt(total);
        cartCountBadge.textContent = totalQty + ' item' + (totalQty === 1 ? '' : 's');

        // Bind cart controls
        checkoutItems.querySelectorAll('.cart-rm').forEach(function (btn) {
            btn.addEventListener('click', function () {
                delete cart[btn.getAttribute('data-id')];
                renderCart();
                refreshCards();
            });
        });
        checkoutItems.querySelectorAll('.cart-dec').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-id');
                if (!cart[id]) return;
                cart[id].quantity--;
                if (cart[id].quantity <= 0) delete cart[id];
                renderCart();
                refreshCards();
            });
        });
        checkoutItems.querySelectorAll('.cart-inc').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id   = btn.getAttribute('data-id');
                if (!cart[id]) return;
                // find the card to check effective stock
                var card = cards.find(function (c) { return c.getAttribute('data-id') === id; });
                var avail = card ? effectiveStock(card) : Infinity;
                // effectiveStock already accounts for current cart quantity;
                // incrementing adds 1 more, so check avail > 0
                if (avail > 0) {
                    cart[id].quantity++;
                    renderCart();
                    refreshCards();
                }
            });
        });
    }

    // ── Filter cards ─────────────────────────────────────────
    function filterCards() {
        var term  = searchInput.value.trim().toLowerCase();
        var group = groupSelect.value;
        var shown = 0;

        cards.forEach(function (card) {
            var matchSearch = term === '' || (card.getAttribute('data-search') || '').includes(term);
            var matchGroup  = group === 'all' || card.getAttribute('data-group') === group;
            var visible     = matchSearch && matchGroup;
            card.hidden = !visible;
            if (visible) shown++;
        });

        noResults.classList.toggle('hidden', shown > 0);
    }

    // ── Add-to-cart logic ────────────────────────────────────
    cards.forEach(function (card) {
        var addBtn = card.querySelector('.pos-add-btn');
        var qInput = card.querySelector('.pos-qty-input');
        if (!addBtn || !qInput) return;

        addBtn.addEventListener('click', function () {
            var id    = card.getAttribute('data-id');
            var name  = card.getAttribute('data-name');
            var price = parseFloat(card.getAttribute('data-price')) || 0;
            var qty   = Math.max(1, parseInt(qInput.value, 10) || 1);
            var avail = effectiveStock(card);

            if (qty > avail) {
                qty = avail;
            }
            if (qty <= 0) {
                return; // out of stock
            }

            if (cart[id]) {
                cart[id].quantity += qty;
            } else {
                cart[id] = { name: name, quantity: qty, price: price };
            }

            // Reset quantity input back to 1 (or max available after adding)
            qInput.value = 1;

            renderCart();
            refreshCards();
        });

        // Clamp input value to effective stock on change
        qInput.addEventListener('change', function () {
            var avail = effectiveStock(card);
            var v     = parseInt(qInput.value, 10) || 1;
            if (avail < Infinity && v > avail) qInput.value = avail;
            if (v < 1) qInput.value = 1;
        });
    });

    // ── Clear cart ───────────────────────────────────────────
    clearBtn.addEventListener('click', function () {
        if (Object.keys(cart).length === 0 || confirm('Clear all items from checkout?')) {
            cart = {};
            renderCart();
            refreshCards();
        }
    });

    // ── Prevent submitting empty cart ────────────────────────
    document.getElementById('batch-sale-form').addEventListener('submit', function (e) {
        if (Object.keys(cart).length === 0) {
            e.preventDefault();
        }
    });

    // ── Search / filter ──────────────────────────────────────
    searchInput.addEventListener('input', filterCards);
    groupSelect.addEventListener('change', filterCards);

    // ── Tiny HTML escape helper ──────────────────────────────
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Initial render
    renderCart();
    refreshCards();
    filterCards();
})();
</script>

<?php render_footer();
