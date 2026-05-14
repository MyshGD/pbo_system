<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

// ── Helpers ──────────────────────────────────────────────────
function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

// ── Load categories ──────────────────────────────────────────
$categoryStmt = $pdo->query('SELECT id, slug, name, description FROM project_categories WHERE is_active = 1 ORDER BY name ASC');
$categories   = $categoryStmt->fetchAll();

$categoryById   = [];
$categoryBySlug = [];
foreach ($categories as $cat) {
    $categoryById[(int) $cat['id']]     = $cat;
    $categoryBySlug[(string) $cat['slug']] = $cat;
}

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect('projects.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    handle_person_post($pdo, $user, 'projects.php');

    // ── Add account ──────────────────────────────────────────
    if ($action === 'add_account') {
        $categoryId         = (int) ($_POST['category_id'] ?? 0);
        $accountPersonId    = (int) ($_POST['account_person_id'] ?? 0);
        $accountName        = trim((string) ($_POST['account_name'] ?? ''));
        $code               = trim((string) ($_POST['code'] ?? ''));
        $contact            = trim((string) ($_POST['contact_name'] ?? ''));
        $startDate          = trim((string) ($_POST['start_date'] ?? ''));
        $nextDueDate        = trim((string) ($_POST['next_due_date'] ?? ''));
        $expectedAmountRaw  = trim((string) ($_POST['expected_amount'] ?? ''));
        $notes              = trim((string) ($_POST['notes'] ?? ''));

        if ($categoryId <= 0 || !isset($categoryById[$categoryId])) {
            set_flash('error', 'Please choose a valid category.');
            redirect('projects.php');
        }

        $categorySlug  = (string) $categoryById[$categoryId]['slug'];
        $accountPerson = $accountPersonId > 0 ? find_person($pdo, $accountPersonId, true) : null;

        if (in_array($categorySlug, ['rental', 'toga'], true) && !$accountPerson) {
            set_flash('error', 'Select an approved person for rental and toga accounts.');
            redirect('projects.php?category=' . urlencode($categorySlug) . '&tab=accounts');
        }
        if ($accountPerson) {
            $accountName = (string) $accountPerson['full_name'];
            if ($contact === '') {
                $contact = (string) ($accountPerson['department'] ?: $accountPerson['role_or_position'] ?: '');
            }
        }
        if ($accountName === '') {
            set_flash('error', 'Account name is required.');
            redirect('projects.php?category=' . urlencode($categorySlug) . '&tab=accounts');
        }

        $expectedAmount = null;
        if ($expectedAmountRaw !== '') {
            $expectedAmount = (float) $expectedAmountRaw;
            if ($expectedAmount < 0) {
                set_flash('error', 'Expected amount cannot be negative.');
                redirect('projects.php?category=' . urlencode($categorySlug) . '&tab=accounts');
            }
        }

        $stmt = $pdo->prepare('INSERT INTO project_accounts (category_id, person_id, account_name, code, contact_name, start_date, next_due_date, expected_amount, notes)
            VALUES (:category_id, :person_id, :account_name, :code, :contact_name, :start_date, :next_due_date, :expected_amount, :notes)');
        $stmt->execute([
            'category_id'     => $categoryId,
            'person_id'       => $accountPerson ? (int) $accountPerson['id'] : null,
            'account_name'    => $accountName,
            'code'            => $code !== '' ? $code : null,
            'contact_name'    => $contact !== '' ? $contact : null,
            'start_date'      => $startDate !== '' ? $startDate : null,
            'next_due_date'   => $nextDueDate !== '' ? $nextDueDate : null,
            'expected_amount' => $expectedAmount,
            'notes'           => $notes !== '' ? $notes : null,
        ]);
        audit_log($pdo, $user, 'create_account', 'projects', 'project_account', (int) $pdo->lastInsertId(), [
            'category_id'  => $categoryId,
            'account_name' => $accountName,
        ]);

        set_flash('success', 'Account added.');
        // Always redirect to accounts tab so the new entry is visible
        redirect('projects.php?category=' . urlencode($categorySlug) . '&tab=accounts');
    }

    // ── Record entry ─────────────────────────────────────────
    if ($action === 'record_entry') {
        $categoryId    = (int) ($_POST['category_id'] ?? 0);
        $accountId     = (int) ($_POST['account_id'] ?? 0);
        $entryDateTime = normalize_datetime_input((string) ($_POST['entry_datetime'] ?? ''));
        $entryType     = (string) ($_POST['entry_type'] ?? 'monitoring');
        $quantityRaw   = trim((string) ($_POST['quantity'] ?? ''));
        $unit          = trim((string) ($_POST['unit'] ?? ''));
        $amount        = (float) ($_POST['amount'] ?? 0);
        $referenceNo   = trim((string) ($_POST['reference_no'] ?? ''));
        $notes         = trim((string) ($_POST['notes'] ?? ''));
        $syncCash      = isset($_POST['sync_cash']) && $_POST['sync_cash'] === '1';
        $nextDueDate   = trim((string) ($_POST['update_next_due_date'] ?? ''));

        $validEntryTypes = ['income', 'expense', 'production', 'harvest', 'payment', 'monitoring', 'other'];

        if ($categoryId <= 0 || !isset($categoryById[$categoryId])) {
            set_flash('error', 'Please choose a valid category.');
            redirect('projects.php');
        }
        if (!in_array($entryType, $validEntryTypes, true)) {
            set_flash('error', 'Invalid entry type.');
            redirect('projects.php?category=' . urlencode((string) $categoryById[$categoryId]['slug']) . '&tab=entries');
        }
        if ($amount < 0) {
            set_flash('error', 'Amount cannot be negative.');
            redirect('projects.php?category=' . urlencode((string) $categoryById[$categoryId]['slug']) . '&tab=entries');
        }

        $quantity = $quantityRaw !== '' ? (float) $quantityRaw : null;
        $slug     = (string) $categoryById[$categoryId]['slug'];
        $module   = $slug !== '' ? $slug : 'other';

        // Validate account belongs to category
        if ($accountId > 0) {
            $accountCheck = $pdo->prepare('SELECT id FROM project_accounts WHERE id = :id AND category_id = :category_id');
            $accountCheck->execute(['id' => $accountId, 'category_id' => $categoryId]);
            if (!$accountCheck->fetch()) {
                $accountId = 0; // silently reset — don't error, just use no account
            }
        }

        try {
            $pdo->beginTransaction();

            $insertEntry = $pdo->prepare('INSERT INTO project_entries (category_id, account_id, entry_datetime, entry_type, quantity, unit, amount, reference_no, notes, created_by)
                VALUES (:category_id, :account_id, :entry_datetime, :entry_type, :quantity, :unit, :amount, :reference_no, :notes, :created_by)');
            $insertEntry->execute([
                'category_id'   => $categoryId,
                'account_id'    => $accountId > 0 ? $accountId : null,
                'entry_datetime'=> $entryDateTime,
                'entry_type'    => $entryType,
                'quantity'      => $quantity,
                'unit'          => $unit !== '' ? $unit : null,
                'amount'        => $amount,
                'reference_no'  => $referenceNo !== '' ? $referenceNo : null,
                'notes'         => $notes !== '' ? $notes : null,
                'created_by'    => (int) $user['id'],
            ]);
            $entryId = (int) $pdo->lastInsertId();

            if ($syncCash && $amount > 0) {
                $direction = null;
                if (in_array($entryType, ['income', 'payment', 'harvest'], true)) {
                    $direction = 'in';
                } elseif ($entryType === 'expense') {
                    $direction = 'out';
                }
                if ($direction !== null) {
                    $insertCash = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, project_entry_id, amount, or_number, description, created_by)
                        VALUES (:txn_date, :direction, :source_module, :project_entry_id, :amount, :or_number, :description, :created_by)');
                    $insertCash->execute([
                        'txn_date'         => $entryDateTime,
                        'direction'        => $direction,
                        'source_module'    => $module,
                        'project_entry_id' => $entryId,
                        'amount'           => $amount,
                        'or_number'        => $referenceNo !== '' ? $referenceNo : null,
                        'description'      => $categoryById[$categoryId]['name'] . ' - ' . $entryType,
                        'created_by'       => (int) $user['id'],
                    ]);
                }
            }

            if ($accountId > 0 && $nextDueDate !== '') {
                $updateDue = $pdo->prepare('UPDATE project_accounts SET next_due_date = :next_due_date WHERE id = :id');
                $updateDue->execute(['next_due_date' => $nextDueDate, 'id' => $accountId]);
            }

            $pdo->commit();
            audit_log($pdo, $user, 'record_entry', 'projects', 'project_entry', $entryId, [
                'category_id' => $categoryId,
                'entry_type'  => $entryType,
                'amount'      => $amount,
            ]);
            set_flash('success', 'Entry saved.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to save project entry.', ['error' => $e->getMessage()], $user);
            set_flash('error', 'Failed to save entry.');
        }

        redirect('projects.php?category=' . urlencode($slug) . '&tab=entries');
    }

    // ── Toga: add release ────────────────────────────────────
    if ($action === 'add_toga') {
        $togaCategory  = $categoryBySlug['toga'] ?? null;
        $personId      = (int) ($_POST['person_id'] ?? 0);
        $person        = find_person($pdo, $personId, true);
        $studentName   = $person ? (string) $person['full_name'] : trim((string) ($_POST['student_name'] ?? ''));
        $studentId     = $person ? (string) ($person['person_code'] ?? '') : trim((string) ($_POST['student_id'] ?? ''));
        $program       = $person ? (string) ($person['department'] ?? '') : trim((string) ($_POST['program'] ?? ''));
        $releaseDate   = trim((string) ($_POST['release_date'] ?? date('Y-m-d')));
        $depositAmount = (float) ($_POST['deposit_amount'] ?? 0);
        $feeAmount     = (float) ($_POST['fee_amount'] ?? 0);
        $notes         = trim((string) ($_POST['notes'] ?? ''));
        $syncCash      = isset($_POST['sync_cash']) && $_POST['sync_cash'] === '1';

        if (!$togaCategory) {
            set_flash('error', 'Toga category is not available.');
            redirect('projects.php');
        }
        if (!$person || $studentName === '') {
            set_flash('error', 'Select an approved person for the toga release.');
            redirect('projects.php?category=toga&tab=accounts');
        }
        if ($depositAmount < 0 || $feeAmount < 0) {
            set_flash('error', 'Amounts cannot be negative.');
            redirect('projects.php?category=toga&tab=accounts');
        }

        try {
            $pdo->beginTransaction();

            $totalAmount = $depositAmount + $feeAmount;
            $insertAccount = $pdo->prepare('INSERT INTO project_accounts (category_id, person_id, account_name, code, contact_name, start_date, expected_amount, status, notes)
                VALUES (:category_id, :person_id, :account_name, :code, :contact_name, :start_date, :expected_amount, "active", :notes)');
            $insertAccount->execute([
                'category_id'   => (int) $togaCategory['id'],
                'person_id'     => (int) $person['id'],
                'account_name'  => $studentName,
                'code'          => $studentId !== '' ? $studentId : null,
                'contact_name'  => $program !== '' ? $program : null,
                'start_date'    => $releaseDate,
                'expected_amount' => $totalAmount,
                'notes'         => $notes !== '' ? $notes : null,
            ]);
            $accountId = (int) $pdo->lastInsertId();

            project_account_meta_set($pdo, $accountId, 'toga_status', 'released');
            project_account_meta_set($pdo, $accountId, 'deposit_amount', number_format($depositAmount, 2, '.', ''));
            project_account_meta_set($pdo, $accountId, 'fee_amount', number_format($feeAmount, 2, '.', ''));
            project_account_meta_set($pdo, $accountId, 'program', $program !== '' ? $program : null);
            project_account_meta_set($pdo, $accountId, 'return_date', null);

            $insertEntry = $pdo->prepare('INSERT INTO project_entries (category_id, account_id, entry_datetime, entry_type, amount, notes, created_by)
                VALUES (:category_id, :account_id, :entry_datetime, "payment", :amount, :notes, :created_by)');
            $insertEntry->execute([
                'category_id'    => (int) $togaCategory['id'],
                'account_id'     => $accountId,
                'entry_datetime' => $releaseDate . ' 00:00:00',
                'amount'         => $totalAmount,
                'notes'          => $notes !== '' ? $notes : 'Toga release fee/deposit',
                'created_by'     => (int) $user['id'],
            ]);
            $entryId = (int) $pdo->lastInsertId();
            project_entry_meta_set($pdo, $entryId, 'entry_event', 'toga_release');

            if ($syncCash && $totalAmount > 0) {
                $cashStmt = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, project_entry_id, amount, description, created_by)
                    VALUES (:txn_date, "in", "toga", :project_entry_id, :amount, :description, :created_by)');
                $cashStmt->execute([
                    'txn_date'         => $releaseDate . ' 00:00:00',
                    'project_entry_id' => $entryId,
                    'amount'           => $totalAmount,
                    'description'      => 'Toga release fee/deposit - ' . $studentName,
                    'created_by'       => (int) $user['id'],
                ]);
            }

            $pdo->commit();
            audit_log($pdo, $user, 'create_toga_release', 'projects', 'project_account', $accountId, [
                'student_name'   => $studentName,
                'deposit_amount' => $depositAmount,
                'fee_amount'     => $feeAmount,
            ]);
            set_flash('success', 'Toga release saved.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to save toga release.', ['error' => $e->getMessage()], $user);
            set_flash('error', 'Failed to save toga release.');
        }

        redirect('projects.php?category=toga&tab=accounts');
    }

    // ── Toga: mark returned ──────────────────────────────────
    if ($action === 'mark_toga_returned') {
        $accountId    = (int) ($_POST['account_id'] ?? 0);
        $returnDate   = trim((string) ($_POST['return_date'] ?? date('Y-m-d')));
        $refundAmount = (float) ($_POST['refund_amount'] ?? 0);

        if ($accountId <= 0 || $refundAmount < 0) {
            set_flash('error', 'Invalid toga return details.');
            redirect('projects.php?category=toga&tab=accounts');
        }

        $accountStmt = $pdo->prepare('SELECT pa.id, pa.category_id, pa.account_name
            FROM project_accounts pa
            INNER JOIN project_categories pc ON pc.id = pa.category_id
            WHERE pa.id = :id AND pc.slug = "toga"');
        $accountStmt->execute(['id' => $accountId]);
        $account = $accountStmt->fetch();
        if (!$account) {
            set_flash('error', 'Invalid toga record.');
            redirect('projects.php?category=toga&tab=accounts');
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE project_accounts SET status = "inactive" WHERE id = :id')->execute(['id' => $accountId]);
            project_account_meta_set($pdo, $accountId, 'toga_status', 'returned');
            project_account_meta_set($pdo, $accountId, 'return_date', $returnDate);

            $entryType = $refundAmount > 0 ? 'expense' : 'monitoring';
            $insertEntry = $pdo->prepare('INSERT INTO project_entries (category_id, account_id, entry_datetime, entry_type, amount, notes, created_by)
                VALUES (:category_id, :account_id, :entry_datetime, :entry_type, :amount, :notes, :created_by)');
            $insertEntry->execute([
                'category_id'    => (int) $account['category_id'],
                'account_id'     => $accountId,
                'entry_datetime' => $returnDate . ' 00:00:00',
                'entry_type'     => $entryType,
                'amount'         => $refundAmount,
                'notes'          => $refundAmount > 0 ? 'Toga returned with deposit refund' : 'Toga returned',
                'created_by'     => (int) $user['id'],
            ]);
            $entryId = (int) $pdo->lastInsertId();
            project_entry_meta_set($pdo, $entryId, 'entry_event', 'toga_returned');

            if ($refundAmount > 0) {
                $cashStmt = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, project_entry_id, amount, description, created_by)
                    VALUES (:txn_date, "out", "toga", :project_entry_id, :amount, :description, :created_by)');
                $cashStmt->execute([
                    'txn_date'         => $returnDate . ' 00:00:00',
                    'project_entry_id' => $entryId,
                    'amount'           => $refundAmount,
                    'description'      => 'Toga deposit refund - ' . (string) $account['account_name'],
                    'created_by'       => (int) $user['id'],
                ]);
            }

            $pdo->commit();
            audit_log($pdo, $user, 'mark_toga_returned', 'projects', 'project_account', $accountId);
            set_flash('success', 'Toga marked as returned.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Failed to mark toga as returned.');
        }

        redirect('projects.php?category=toga&tab=accounts');
    }

    // ── Toga: mark forfeited ─────────────────────────────────
    if ($action === 'mark_toga_forfeited') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        if ($accountId <= 0) {
            set_flash('error', 'Invalid toga record.');
            redirect('projects.php?category=toga&tab=accounts');
        }

        $accountStmt = $pdo->prepare('SELECT pa.id, pa.category_id FROM project_accounts pa
            INNER JOIN project_categories pc ON pc.id = pa.category_id
            WHERE pa.id = :id AND pc.slug = "toga"');
        $accountStmt->execute(['id' => $accountId]);
        $account = $accountStmt->fetch();
        if (!$account) {
            set_flash('error', 'Invalid toga record.');
            redirect('projects.php?category=toga&tab=accounts');
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE project_accounts SET status = "inactive" WHERE id = :id')->execute(['id' => $accountId]);
            project_account_meta_set($pdo, $accountId, 'toga_status', 'forfeited');

            $insertEntry = $pdo->prepare('INSERT INTO project_entries (category_id, account_id, entry_datetime, entry_type, amount, notes, created_by)
                VALUES (:category_id, :account_id, NOW(), "monitoring", 0, "Toga deposit forfeited", :created_by)');
            $insertEntry->execute([
                'category_id' => (int) $account['category_id'],
                'account_id'  => $accountId,
                'created_by'  => (int) $user['id'],
            ]);
            $entryId = (int) $pdo->lastInsertId();
            project_entry_meta_set($pdo, $entryId, 'entry_event', 'toga_forfeited');

            $pdo->commit();
            audit_log($pdo, $user, 'mark_toga_forfeited', 'projects', 'project_account', $accountId);
            set_flash('success', 'Toga marked as forfeited.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Failed to mark toga as forfeited.');
        }

        redirect('projects.php?category=toga&tab=accounts');
    }
}

// ── Page parameters ──────────────────────────────────────────
$selectedCategorySlug = trim((string) ($_GET['category'] ?? ''));
$projectTab           = (string) ($_GET['tab'] ?? 'accounts');
$accountSearch        = trim((string) ($_GET['q'] ?? ''));
$from                 = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to                   = trim((string) ($_GET['to'] ?? date('Y-m-d')));

if (!in_array($projectTab, ['accounts', 'entries', 'overdue', 'performance'], true)) {
    $projectTab = 'accounts';
}

// Normalize rental/toga slug
if ($selectedCategorySlug === 'rental' || $selectedCategorySlug === 'toga') {
    // keep as-is
} elseif ($selectedCategorySlug !== '' && !isset($categoryBySlug[$selectedCategorySlug])) {
    $selectedCategorySlug = '';
}

$selectedCategory   = $selectedCategorySlug !== '' ? ($categoryBySlug[$selectedCategorySlug] ?? null) : null;
$selectedCategoryId = $selectedCategory ? (int) $selectedCategory['id'] : null;
$isTogaView         = $selectedCategorySlug === 'toga';
$isFishpondView     = $selectedCategorySlug === 'fishpond';
$isRentalView       = in_array($selectedCategorySlug, ['rental', 'toga'], true);
$isOverview         = $selectedCategory === null;

[$fromDateTime, $toDateTimeExclusive] = date_filter_bounds($from, $to);

// ── People (for selectors — include pending so new additions appear) ──
$people = people_options($pdo, false);

// ── Accounts query ───────────────────────────────────────────
$accWhere  = ['pc.is_active = 1'];
$accParams = [];

if ($selectedCategoryId !== null) {
    $accWhere[]                = 'pa.category_id = :category_id';
    $accParams['category_id'] = $selectedCategoryId;
}
if ($accountSearch !== '') {
    $accWhere[]                    = '(pa.account_name LIKE :q OR pa.code LIKE :q OR pa.contact_name LIKE :q OR person.full_name LIKE :q OR person.person_code LIKE :q)';
    $accParams['q']                = prefix_search_param($accountSearch);
}

$accountsSql = 'SELECT pa.id, pa.category_id, pa.person_id, pa.account_name, pa.code, pa.contact_name,
        pa.start_date, pa.next_due_date, pa.expected_amount, pa.status, pa.notes,
        pc.name AS category_name, pc.slug AS category_slug,
        person.full_name AS person_full_name,
        person.person_code AS person_code,
        person.department AS person_department,
        status_meta.meta_value AS toga_status,
        return_meta.meta_value AS return_date,
        deposit_meta.meta_value AS deposit_amount,
        fee_meta.meta_value AS fee_amount
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN people person ON person.id = pa.person_id
    LEFT JOIN project_account_meta status_meta  ON status_meta.account_id  = pa.id AND status_meta.meta_key  = "toga_status"
    LEFT JOIN project_account_meta return_meta   ON return_meta.account_id   = pa.id AND return_meta.meta_key   = "return_date"
    LEFT JOIN project_account_meta deposit_meta  ON deposit_meta.account_id  = pa.id AND deposit_meta.meta_key  = "deposit_amount"
    LEFT JOIN project_account_meta fee_meta      ON fee_meta.account_id      = pa.id AND fee_meta.meta_key      = "fee_amount"
    WHERE ' . implode(' AND ', $accWhere) . '
    ORDER BY pa.category_id, pa.account_name ASC';
$accountsStmt = $pdo->prepare($accountsSql);
$accountsStmt->execute($accParams);
$accounts = $accountsStmt->fetchAll();

// ── Entries query (paginated) ─────────────────────────────────
$entWhere  = ['pe.entry_datetime >= :from_dt AND pe.entry_datetime < :to_dt'];
$entParams = ['from_dt' => $fromDateTime, 'to_dt' => $toDateTimeExclusive];

if ($selectedCategoryId !== null) {
    $entWhere[]                = 'pe.category_id = :category_id';
    $entParams['category_id'] = $selectedCategoryId;
}

$entCountSql  = 'SELECT COUNT(*) FROM project_entries pe WHERE ' . implode(' AND ', $entWhere);
$entCountStmt = $pdo->prepare($entCountSql);
$entCountStmt->execute($entParams);
$entriesPagination = pagination_meta((int) $entCountStmt->fetchColumn(), page_param(), 25);

$entriesSql = 'SELECT pe.id, pe.entry_datetime, pe.entry_type, pe.quantity, pe.unit, pe.amount,
        pe.reference_no, pe.notes, pc.name AS category_name, pc.slug AS category_slug,
        COALESCE(person.full_name, pa.account_name) AS account_name
    FROM project_entries pe
    INNER JOIN project_categories pc ON pc.id = pe.category_id
    LEFT JOIN project_accounts pa   ON pa.id  = pe.account_id
    LEFT JOIN people person          ON person.id = pa.person_id
    WHERE ' . implode(' AND ', $entWhere) . '
    ORDER BY pe.entry_datetime DESC, pe.id DESC
    LIMIT :limit OFFSET :offset';
$entriesStmt = $pdo->prepare($entriesSql);
foreach ($entParams as $k => $v) {
    $entriesStmt->bindValue(':' . ltrim($k, ':'), $v);
}
$entriesStmt->bindValue(':limit',  $entriesPagination['per_page'], PDO::PARAM_INT);
$entriesStmt->bindValue(':offset', $entriesPagination['offset'],   PDO::PARAM_INT);
$entriesStmt->execute();
$entries = $entriesStmt->fetchAll();

// ── Entries summary ───────────────────────────────────────────
$sumSql  = 'SELECT
        COALESCE(SUM(CASE WHEN pe.entry_type IN ("income","payment","harvest") THEN pe.amount ELSE 0 END),0) AS total_income,
        COALESCE(SUM(CASE WHEN pe.entry_type = "expense" THEN pe.amount ELSE 0 END),0) AS total_expense
    FROM project_entries pe
    WHERE ' . implode(' AND ', $entWhere);
$sumStmt = $pdo->prepare($sumSql);
$sumStmt->execute($entParams);
$summary = $sumStmt->fetch();

// ── Overdue accounts ─────────────────────────────────────────
$overdueSql  = 'SELECT COALESCE(person.full_name, pa.account_name) AS account_name,
        pa.code, pa.next_due_date, pa.expected_amount, pc.name AS category_name
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN people person ON person.id = pa.person_id
    WHERE pa.status = "active" AND pa.next_due_date IS NOT NULL AND pa.next_due_date < CURDATE()';
$overdueParams = [];
if ($selectedCategoryId !== null) {
    $overdueSql           .= ' AND pa.category_id = :category_id';
    $overdueParams['category_id'] = $selectedCategoryId;
}
$overdueSql .= ' ORDER BY pa.next_due_date ASC';
$overdueStmt = $pdo->prepare($overdueSql);
$overdueStmt->execute($overdueParams);
$overdues = $overdueStmt->fetchAll();

// ── Performance: income by category ──────────────────────────
$perfSql  = 'SELECT pc.name,
        COALESCE(SUM(CASE WHEN pe.entry_type IN ("income","payment","harvest") THEN pe.amount ELSE 0 END),0) AS income,
        COALESCE(SUM(CASE WHEN pe.entry_type = "expense" THEN pe.amount ELSE 0 END),0) AS expense
    FROM project_categories pc
    LEFT JOIN project_entries pe ON pe.category_id = pc.id
        AND pe.entry_datetime >= :from_dt AND pe.entry_datetime < :to_dt
    WHERE pc.is_active = 1' . ($selectedCategoryId ? ' AND pc.id = :cat_id' : '') . '
    GROUP BY pc.id, pc.name ORDER BY pc.name';
$perfStmt = $pdo->prepare($perfSql);
$perfStmt->bindValue(':from_dt', $fromDateTime);
$perfStmt->bindValue(':to_dt',   $toDateTimeExclusive);
if ($selectedCategoryId) {
    $perfStmt->bindValue(':cat_id', $selectedCategoryId, PDO::PARAM_INT);
}
$perfStmt->execute();
$perfRows = $perfStmt->fetchAll();

// ── Accounts for entry modal dropdown ────────────────────────
// All accounts for selected category (or all if overview)
$modalAccountsSql = 'SELECT pa.id, pa.category_id, COALESCE(person.full_name, pa.account_name) AS display_name
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN people person ON person.id = pa.person_id
    WHERE pc.is_active = 1 AND pa.status = "active"'
    . ($selectedCategoryId ? ' AND pa.category_id = :cat_id' : '') .
    ' ORDER BY pc.name, display_name ASC';
$modalAccountsStmt = $pdo->prepare($modalAccountsSql);
if ($selectedCategoryId) {
    $modalAccountsStmt->bindValue(':cat_id', $selectedCategoryId, PDO::PARAM_INT);
}
$modalAccountsStmt->execute();
$modalAccounts = $modalAccountsStmt->fetchAll();

// ── Overview summary cards ────────────────────────────────────
$overviewCards = [];
if ($isOverview) {
    foreach (['fishpond', 'rental'] as $slug) {
        $cat = $categoryBySlug[$slug] ?? null;
        if (!$cat) continue;
        $r = $pdo->prepare('SELECT
                COALESCE(SUM(CASE WHEN pe.entry_type IN ("income","payment","harvest") THEN pe.amount ELSE 0 END),0) AS income,
                COALESCE(SUM(CASE WHEN pe.entry_type = "expense" THEN pe.amount ELSE 0 END),0) AS expense,
                (SELECT COUNT(*) FROM project_accounts pa2 WHERE pa2.category_id = :cat_id AND pa2.status = "active") AS active_count,
                (SELECT COUNT(*) FROM project_accounts pa3
                 INNER JOIN project_categories pc3 ON pc3.id = pa3.category_id
                 WHERE pa3.category_id = :cat_id2 AND pa3.status = "active"
                   AND pa3.next_due_date IS NOT NULL AND pa3.next_due_date < CURDATE()) AS overdue_count
            FROM project_entries pe WHERE pe.category_id = :cat_id3
              AND pe.entry_datetime >= :from_dt AND pe.entry_datetime < :to_dt');
        $r->execute([
            ':cat_id' => $cat['id'], ':cat_id2' => $cat['id'], ':cat_id3' => $cat['id'],
            ':from_dt' => $fromDateTime, ':to_dt' => $toDateTimeExclusive,
        ]);
        $overviewCards[$slug] = array_merge($cat, $r->fetch() ?: []);
    }
}

// ── Page title ────────────────────────────────────────────────
$pageTitle = $isOverview
    ? 'Income Projects'
    : ($isRentalView
        ? ($isTogaView ? 'Toga Rentals' : 'Rental Operations')
        : ($isFishpondView ? 'Fishpond Operations' : h((string) ($selectedCategory['name'] ?? 'Projects'))));

$baseUrl = 'projects.php?category=' . urlencode($selectedCategorySlug);

render_header($pageTitle, $user);
?>

<?php if ($isOverview): ?>
<!-- ════════════════════════════════════════════════════════
     PROJECT OVERVIEW
════════════════════════════════════════════════════════ -->
<p class="page-intro">Monitor all campus income projects, rental accounts, fishpond activity, and proposal requests.</p>

<div class="grid gap-4 md:grid-cols-3 mb-6">
    <?php foreach ([
        'fishpond' => ['href' => 'projects.php?category=fishpond', 'title' => 'Fishpond', 'icon' => '🐟'],
        'rental'   => ['href' => 'projects.php?category=rental',   'title' => 'Rentals',  'icon' => '🏪'],
    ] as $slug => $meta):
        $card = $overviewCards[$slug] ?? [];
        $income  = (float) ($card['income']  ?? 0);
        $expense = (float) ($card['expense'] ?? 0);
    ?>
    <a class="card dashboard-link no-underline" href="<?= h($meta['href']) ?>">
        <h3 class="font-bold text-slate-950 mb-2"><?= $meta['icon'] ?> <?= h($meta['title']) ?></h3>
        <dl class="text-sm space-y-1">
            <div class="flex justify-between"><dt class="text-slate-500">Income</dt><dd class="font-semibold"><?= h(money($income)) ?></dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Expense</dt><dd class="font-semibold"><?= h(money($expense)) ?></dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Net</dt><dd class="font-semibold"><?= h(money($income - $expense)) ?></dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Active accounts</dt><dd class="font-semibold"><?= h((string) ($card['active_count'] ?? 0)) ?></dd></div>
            <?php if ((int) ($card['overdue_count'] ?? 0) > 0): ?>
                <div class="flex justify-between text-red-700"><dt>Overdue</dt><dd class="font-semibold"><?= h((string) $card['overdue_count']) ?></dd></div>
            <?php endif; ?>
        </dl>
        <span class="text-xs font-bold text-brand-700 mt-3 block">View →</span>
    </a>
    <?php endforeach; ?>

    <a class="card dashboard-link no-underline" href="proposals.php">
        <h3 class="font-bold text-slate-950 mb-2">📋 Proposals</h3>
        <?php $pendingCount = (int) $pdo->query('SELECT COUNT(*) FROM proposals WHERE status IN ("submitted","under_review")')->fetchColumn(); ?>
        <dl class="text-sm space-y-1">
            <div class="flex justify-between"><dt class="text-slate-500">Pending</dt><dd class="font-semibold"><?= h((string) $pendingCount) ?></dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Total</dt><dd class="font-semibold"><?= h((string) $pdo->query('SELECT COUNT(*) FROM proposals')->fetchColumn()) ?></dd></div>
        </dl>
        <span class="text-xs font-bold text-brand-700 mt-3 block">View →</span>
    </a>
</div>

<div class="flex flex-wrap gap-2 mb-2">
    <?php foreach ($categories as $cat): ?>
        <a class="btn alt text-sm" href="projects.php?category=<?= h($cat['slug']) ?>">
            <?= h($cat['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php render_footer(); return; ?>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════
     CATEGORY PAGE
════════════════════════════════════════════════════════ -->

<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <p class="page-intro mb-0"><?= h((string) ($selectedCategory['description'] ?? 'Manage accounts, entries, renewals, and performance.')) ?></p>
    <div class="flex flex-wrap gap-2">
        <?php if ($isTogaView): ?>
            <button type="button" data-open-modal="toga-modal">Add Toga Release</button>
            <button type="button" class="btn alt" data-open-modal="account-modal">Add Account</button>
        <?php elseif ($isRentalView): ?>
            <button type="button" data-open-modal="entry-modal">Add Payment</button>
            <button type="button" class="btn alt" data-open-modal="account-modal">Add Stall Account</button>
        <?php else: ?>
            <button type="button" data-open-modal="entry-modal">Add Entry</button>
            <button type="button" class="btn alt" data-open-modal="account-modal">Add Account</button>
        <?php endif; ?>
        <button type="button" class="btn alt" data-open-modal="person-modal">Add Person</button>
    </div>
</div>

<!-- ── Tabs ──────────────────────────────────────────────────── -->
<?php
$tabLabels = [
    'accounts'    => $isTogaView ? 'Toga Releases' : ($isFishpondView ? 'Pond Accounts' : 'Accounts'),
    'entries'     => $isRentalView ? 'Payments' : 'Activity Entries',
    'overdue'     => 'Overdue',
    'performance' => 'Performance',
];
?>
<nav class="tabs" aria-label="Project sections">
    <?php foreach ($tabLabels as $tabKey => $tabLabel): ?>
        <a class="tab-link <?= $projectTab === $tabKey ? 'active' : '' ?>"
           href="<?= h($baseUrl . '&tab=' . $tabKey) ?>">
            <?= h($tabLabel) ?>
            <?php if ($tabKey === 'overdue' && count($overdues) > 0): ?>
                <span class="ml-1 inline-flex rounded-full bg-red-100 px-1.5 text-xs font-bold text-red-700"><?= count($overdues) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<!-- ── Date filter strip (entries & performance tabs) ────────── -->
<?php if (in_array($projectTab, ['entries', 'performance'], true)): ?>
<form method="get" class="mb-4 flex flex-wrap gap-2 items-end">
    <input type="hidden" name="category" value="<?= h($selectedCategorySlug) ?>">
    <input type="hidden" name="tab" value="<?= h($projectTab) ?>">
    <div><label class="text-xs font-semibold text-slate-600">From</label>
         <input type="date" name="from" value="<?= h($from) ?>" class="block rounded border border-slate-300 px-2 py-1 text-sm"></div>
    <div><label class="text-xs font-semibold text-slate-600">To</label>
         <input type="date" name="to" value="<?= h($to) ?>" class="block rounded border border-slate-300 px-2 py-1 text-sm"></div>
    <button type="submit" class="btn">Apply</button>
    <a class="btn alt" href="<?= h($baseUrl . '&tab=' . $projectTab) ?>">Reset</a>
</form>
<?php endif; ?>

<!-- ── Search strip (accounts tab) ───────────────────────────── -->
<?php if ($projectTab === 'accounts'): ?>
<form method="get" class="mb-4 flex gap-2">
    <input type="hidden" name="category" value="<?= h($selectedCategorySlug) ?>">
    <input type="hidden" name="tab" value="accounts">
    <input type="search" name="q" value="<?= h($accountSearch) ?>" placeholder="Search accounts…"
           class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm">
    <button type="submit" class="btn">Search</button>
    <?php if ($accountSearch !== ''): ?>
        <a class="btn alt" href="<?= h($baseUrl . '&tab=accounts') ?>">Clear</a>
    <?php endif; ?>
</form>
<?php endif; ?>

<!-- ════════ TAB: ACCOUNTS ══════════════════════════════════ -->
<?php if ($projectTab === 'accounts'): ?>
<section class="table-card">
    <div class="section-heading">
        <h3><?= $tabLabels['accounts'] ?></h3>
        <span class="badge"><?= count($accounts) ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <?php if ($isTogaView): ?>
            <tr>
                <th>Student</th><th>ID</th><th>Program</th>
                <th>Released</th><th>Returned</th>
                <th>Deposit</th><th>Fee</th><th>Status</th><th>Action</th>
            </tr>
            <?php else: ?>
            <tr>
                <th>Category</th><th>Account</th><th>Code</th>
                <th>Contact</th><th>Start</th><th>Next Due</th>
                <th>Expected</th><th>Status</th>
            </tr>
            <?php endif; ?>
            </thead>
            <tbody>
            <?php if (!$accounts): ?>
                <tr><td colspan="<?= $isTogaView ? 9 : 8 ?>">
                    <?php render_empty_state('No accounts yet.', 'Add the first account using the button above.'); ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($accounts as $account):
                $displayName  = (string) ($account['person_full_name'] ?: $account['account_name']);
                $displayCode  = (string) ($account['person_code']      ?: $account['code']);
                $displayDept  = (string) ($account['person_department'] ?: $account['contact_name']);
                $togaStatus   = $account['toga_status'] ?: ($account['status'] === 'active' ? 'released' : 'returned');
                $displayStatus= $account['category_slug'] === 'toga' ? $togaStatus : $account['status'];
                $pillClass    = match($displayStatus) {
                    'active','released' => 'active',
                    'returned'          => 'returned',
                    'forfeited'         => 'rejected',
                    'inactive'          => '',
                    default             => '',
                };
            ?>
            <?php if ($isTogaView): ?>
            <tr>
                <td class="font-semibold text-slate-950"><?= h($displayName) ?></td>
                <td><?= h($displayCode ?: '—') ?></td>
                <td><?= h($displayDept ?: '—') ?></td>
                <td><?= h($account['start_date'] ?: '—') ?></td>
                <td><?= h($account['return_date'] ?: '—') ?></td>
                <td><?= h(money(project_meta_decimal($account['deposit_amount']))) ?></td>
                <td><?= h(money(project_meta_decimal($account['fee_amount']))) ?></td>
                <td><span class="status-pill <?= h($pillClass) ?>"><?= h($displayStatus) ?></span></td>
                <td>
                    <?php if ($displayStatus === 'released'): ?>
                    <details class="action-menu">
                        <summary>Actions</summary>
                        <div class="action-menu-panel">
                            <button type="button" class="action-menu-item" data-open-modal="return-toga-<?= (int) $account['id'] ?>">Return</button>
                            <button type="button" class="action-menu-item mt-1" data-open-modal="forfeit-toga-<?= (int) $account['id'] ?>">Forfeit</button>
                        </div>
                    </details>
                    <?php else: ?>
                        <span class="text-slate-400 text-xs">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php else: ?>
            <tr>
                <td><?= h($account['category_name']) ?></td>
                <td class="font-semibold text-slate-950"><?= h($displayName) ?></td>
                <td><?= h($account['code'] ?: '—') ?></td>
                <td><?= h($displayDept ?: '—') ?></td>
                <td><?= h($account['start_date'] ?: '—') ?></td>
                <td class="<?= ($account['next_due_date'] && $account['next_due_date'] < date('Y-m-d')) ? 'text-red-700 font-semibold' : '' ?>">
                    <?= h($account['next_due_date'] ?: '—') ?>
                </td>
                <td><?= h(money((float) $account['expected_amount'])) ?></td>
                <td><span class="status-pill <?= h($pillClass) ?>"><?= h($displayStatus) ?></span></td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="data-panel-footer">
        <?= count($accounts) ?> account<?= count($accounts) !== 1 ? 's' : '' ?> found<?= $accountSearch ? ' for "' . h($accountSearch) . '"' : '' ?>.
    </div>
</section>

<!-- Toga return / forfeit modals -->
<?php if ($isTogaView):
    foreach ($accounts as $account):
        if (($account['toga_status'] ?: ($account['status'] === 'active' ? 'released' : 'returned')) !== 'released') continue;
?>
<dialog id="return-toga-<?= (int) $account['id'] ?>" class="modal">
    <div class="modal-header"><h3>Return Toga — <?= h($account['account_name']) ?></h3>
        <button type="button" class="modal-close" data-close-modal>Close</button></div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="mark_toga_returned">
        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
        <div class="form-grid">
            <div><label>Return Date</label><input type="date" name="return_date" value="<?= date('Y-m-d') ?>" required></div>
            <div><label>Refund Amount</label><input type="number" min="0" step="0.01" name="refund_amount" value="0"></div>
        </div>
        <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Mark Returned</button></div>
    </form>
</dialog>
<dialog id="forfeit-toga-<?= (int) $account['id'] ?>" class="modal">
    <div class="modal-header"><h3>Forfeit Toga Deposit</h3>
        <button type="button" class="modal-close" data-close-modal>Close</button></div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="mark_toga_forfeited">
        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
        <p class="muted mb-4">Mark <?= h($account['account_name']) ?> as forfeited. This closes the account and logs the action.</p>
        <div class="modal-actions"><button type="button" class="btn alt" data-close-modal>Cancel</button><button type="submit">Mark Forfeited</button></div>
    </form>
</dialog>
<?php endforeach; endif; ?>
<?php endif; ?>

<!-- ════════ TAB: ENTRIES ════════════════════════════════════ -->
<?php if ($projectTab === 'entries'): ?>
<section class="table-card">
    <div class="section-heading">
        <div>
            <h3><?= $tabLabels['entries'] ?></h3>
            <p class="muted text-xs mt-0.5">
                Income <?= h(money((float) $summary['total_income'])) ?> |
                Expense <?= h(money((float) $summary['total_expense'])) ?> |
                Net <?= h(money((float) $summary['total_income'] - (float) $summary['total_expense'])) ?>
            </p>
        </div>
        <span class="badge"><?= (int) $entriesPagination['total_rows'] ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Date</th><th>Category</th><th>Account</th>
                <th>Type</th><th>Qty / Unit</th><th>Amount</th>
                <th>Reference</th><th>Notes</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$entries): ?>
                <tr><td colspan="8"><?php render_empty_state('No entries in this period.', 'Change the date range or add an entry.'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($entries as $entry): ?>
            <tr>
                <td><?= h($entry['entry_datetime']) ?></td>
                <td><?= h($entry['category_name']) ?></td>
                <td><?= h($entry['account_name'] ?: '—') ?></td>
                <td><span class="status-pill <?= in_array($entry['entry_type'], ['income','payment','harvest'], true) ? 'active' : ($entry['entry_type'] === 'expense' ? 'rejected' : '') ?>"><?= h($entry['entry_type']) ?></span></td>
                <td><?= $entry['quantity'] !== null ? h((string) $entry['quantity']) . ' ' . h((string) $entry['unit']) : '—' ?></td>
                <td class="font-semibold"><?= h(money((float) $entry['amount'])) ?></td>
                <td><?= h($entry['reference_no'] ?: '—') ?></td>
                <td><?= h($entry['notes'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($entriesPagination); ?>
    <div class="data-panel-footer">
        Period <?= h($from) ?> to <?= h($to) ?>.
    </div>
</section>
<?php endif; ?>

<!-- ════════ TAB: OVERDUE ════════════════════════════════════ -->
<?php if ($projectTab === 'overdue'): ?>
<section class="table-card">
    <div class="section-heading">
        <h3><?= $tabLabels['overdue'] ?></h3>
        <span class="badge <?= count($overdues) > 0 ? 'bg-red-100 text-red-700' : '' ?>"><?= count($overdues) ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Category</th><th>Account</th><th>Code</th><th>Due Date</th><th>Expected</th></tr></thead>
            <tbody>
            <?php if (!$overdues): ?>
                <tr><td colspan="5"><?php render_empty_state('No overdue accounts.', 'All active accounts are up to date.'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($overdues as $row): ?>
            <tr class="low-stock">
                <td><?= h($row['category_name']) ?></td>
                <td class="font-semibold text-slate-950"><?= h($row['account_name']) ?></td>
                <td><?= h($row['code'] ?: '—') ?></td>
                <td class="text-red-700 font-semibold"><?= h($row['next_due_date']) ?></td>
                <td><?= h(money((float) $row['expected_amount'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="data-panel-footer"><?= count($overdues) ?> overdue account<?= count($overdues) !== 1 ? 's' : '' ?>.</div>
</section>
<?php endif; ?>

<!-- ════════ TAB: PERFORMANCE ═══════════════════════════════ -->
<?php if ($projectTab === 'performance'): ?>
<section class="table-card">
    <div class="section-heading">
        <h3><?= $tabLabels['performance'] ?></h3>
        <span class="badge"><?= count($perfRows) ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Category</th><th>Income</th><th>Expense</th><th>Net</th></tr></thead>
            <tbody>
            <?php if (!$perfRows): ?>
                <tr><td colspan="4"><?php render_empty_state('No data.', 'No entries found for this period.'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($perfRows as $row):
                $net = (float) $row['income'] - (float) $row['expense'];
            ?>
            <tr>
                <td><?= h($row['name']) ?></td>
                <td><?= h(money((float) $row['income'])) ?></td>
                <td><?= h(money((float) $row['expense'])) ?></td>
                <td class="font-semibold <?= $net >= 0 ? 'text-brand-700' : 'text-red-700' ?>"><?= h(money($net)) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="data-panel-footer">
        Period <?= h($from) ?> to <?= h($to) ?>.
    </div>
</section>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════════ -->

<!-- Add Account -->
<dialog id="account-modal" class="modal">
    <div class="modal-header">
        <h3><?= $isTogaView ? 'Add Toga Account' : ($isFishpondView ? 'Add Pond Account' : 'Add Account') ?></h3>
        <button type="button" class="modal-close" data-close-modal>Close</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_account">
        <div class="form-grid">
            <div>
                <label>Category</label>
                <select name="category_id" required>
                    <option value="">Select…</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"
                            <?= $selectedCategoryId === (int) $cat['id'] ? 'selected' : '' ?>>
                            <?= h($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php render_person_selector($people, 'account_person_id', 'acc_person_id', null, 'Person (optional)', false, ['name_target' => 'acc_account_name', 'department_target' => 'acc_contact']); ?>
            <div><label>Account Name</label><input id="acc_account_name" name="account_name" placeholder="Stall 1 / Pond A" required></div>
            <div><label>Code / Reference</label><input name="code" placeholder="Optional"></div>
            <div><label>Contact / Owner</label><input id="acc_contact" name="contact_name" placeholder="Optional"></div>
            <div><label>Start Date</label><input type="date" name="start_date"></div>
            <div><label>Next Due Date</label><input type="date" name="next_due_date"></div>
            <div><label>Expected Amount</label><input type="number" min="0" step="0.01" name="expected_amount" placeholder="Optional"></div>
            <div class="field-wide"><label>Notes</label><textarea name="notes"></textarea></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <button type="submit">Save Account</button>
        </div>
    </form>
</dialog>

<!-- Add Entry -->
<dialog id="entry-modal" class="modal modal-wide">
    <div class="modal-header">
        <h3><?= $isFishpondView ? 'Add Fishpond Entry' : ($isRentalView ? 'Add Payment' : 'Add Entry') ?></h3>
        <button type="button" class="modal-close" data-close-modal>Close</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="record_entry">
        <div class="form-grid">
            <div>
                <label>Category</label>
                <select name="category_id" id="entry_cat_select" required>
                    <option value="">Select…</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"
                            <?= $selectedCategoryId === (int) $cat['id'] ? 'selected' : '' ?>>
                            <?= h($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Account</label>
                <select name="account_id" id="entry_acc_select">
                    <option value="0">— No specific account —</option>
                    <?php foreach ($modalAccounts as $ma): ?>
                        <option value="<?= (int) $ma['id'] ?>" data-cat="<?= (int) $ma['category_id'] ?>">
                            <?= h($ma['display_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Date and Time</label><input type="datetime-local" name="entry_datetime" value="<?= date('Y-m-d\TH:i') ?>"></div>
            <div>
                <label>Entry Type</label>
                <select name="entry_type" required>
                    <option value="monitoring">Monitoring</option>
                    <option value="harvest">Harvest Income</option>
                    <option value="income">Income</option>
                    <option value="payment" <?= $isRentalView ? 'selected' : '' ?>>Payment</option>
                    <option value="expense">Expense</option>
                    <option value="production">Maintenance</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div><label>Quantity</label><input type="number" step="0.01" min="0" name="quantity" placeholder="Optional"></div>
            <div><label>Unit</label><input name="unit" placeholder="kg, sacks, pcs…"></div>
            <div><label>Amount</label><input type="number" min="0" step="0.01" name="amount" value="0" required></div>
            <div><label>Reference / OR No.</label><input name="reference_no" placeholder="Optional"></div>
            <div><label>Update Next Due Date</label><input type="date" name="update_next_due_date"></div>
            <div class="checkbox-field">
                <label><input type="checkbox" name="sync_cash" value="1" checked> Also post to Cash Flow</label>
            </div>
            <div class="field-wide"><label>Notes</label><textarea name="notes"></textarea></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <button type="submit">Save Entry</button>
        </div>
    </form>
</dialog>

<!-- Add Toga Release -->
<?php if (isset($categoryBySlug['toga'])): ?>
<dialog id="toga-modal" class="modal">
    <div class="modal-header"><h3>Add Toga Release</h3><button type="button" class="modal-close" data-close-modal>Close</button></div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_toga">
        <div class="form-grid">
            <?php render_person_selector($people, 'person_id', 'toga_person_id', null, 'Student', true, ['name_target' => 'toga_student_name', 'code_target' => 'toga_student_id', 'department_target' => 'toga_program']); ?>
            <div><label>Student Name</label><input id="toga_student_name" name="student_name" readonly required></div>
            <div><label>Student ID</label><input id="toga_student_id" name="student_id" readonly></div>
            <div><label>Program / Course</label><input id="toga_program" name="program" readonly></div>
            <div><label>Release Date</label><input type="date" name="release_date" value="<?= date('Y-m-d') ?>" required></div>
            <div><label>Deposit Amount</label><input type="number" min="0" step="0.01" name="deposit_amount" value="0" required></div>
            <div><label>Fee Amount</label><input type="number" min="0" step="0.01" name="fee_amount" value="0" required></div>
            <div class="field-wide"><label>Notes</label><textarea name="notes"></textarea></div>
            <div class="checkbox-field"><label><input type="checkbox" name="sync_cash" value="1" checked> Also post to Cash Flow</label></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <button type="submit">Save Toga Release</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php render_add_person_modal($user, 'projects.php?category=' . urlencode($selectedCategorySlug) . '&tab=accounts'); ?>

<script>
// ── Entry modal: filter accounts by selected category ────────
(function () {
    var catSelect = document.getElementById('entry_cat_select');
    var accSelect = document.getElementById('entry_acc_select');
    if (!catSelect || !accSelect) return;

    var allOptions = Array.from(accSelect.querySelectorAll('option[data-cat]'));

    function filterAccounts() {
        var catId = catSelect.value;
        allOptions.forEach(function (opt) {
            opt.hidden = catId !== '' && opt.getAttribute('data-cat') !== catId;
        });
        // Reset to "no account" when category changes
        if (accSelect.value !== '0') {
            var selected = accSelect.querySelector('option[value="' + accSelect.value + '"]');
            if (selected && selected.hidden) accSelect.value = '0';
        }
    }

    catSelect.addEventListener('change', filterAccounts);
    filterAccounts(); // run on load so modal opens pre-filtered
})();
</script>

<?php render_footer();
