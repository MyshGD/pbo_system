<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

$categoryStmt = $pdo->query('SELECT id, slug, name, description FROM project_categories WHERE is_active = 1 ORDER BY name ASC');
$categories = $categoryStmt->fetchAll();

$categoryById = [];
$categoryBySlug = [];
foreach ($categories as $cat) {
    $categoryById[(int) $cat['id']] = $cat;
    $categoryBySlug[(string) $cat['slug']] = $cat;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect('projects.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    handle_person_post($pdo, $user, 'projects.php');

    if ($action === 'add_category') {
        require_permission($user, 'manage_projects', 'projects.php');
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $slug = slugify($slugInput !== '' ? $slugInput : $name);

        if ($name === '' || $slug === '') {
            set_flash('error', 'Category name is required.');
            redirect('projects.php');
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO project_categories (slug, name, description, is_active) VALUES (:slug, :name, :description, 1)');
            $stmt->execute([
                'slug' => $slug,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
            ]);
            audit_log($pdo, $user, 'create_category', 'projects', 'project_category', (int) $pdo->lastInsertId(), [
                'name' => $name,
                'slug' => $slug,
            ]);
            set_flash('success', 'Project category added.');
            redirect('projects.php?category=' . urlencode($slug));
        } catch (PDOException $e) {
            log_system_issue($pdo, 'error', 'Could not add project category.', ['error' => $e->getMessage(), 'slug' => $slug], $user);
            set_flash('error', 'Category slug already exists. Try another category name.');
            redirect('projects.php');
        }
    }

    if ($action === 'add_project') {
        require_permission($user, 'manage_projects', 'projects.php');
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $accountName = trim((string) ($_POST['account_name'] ?? ''));
        $accountPersonId = (int) ($_POST['account_person_id'] ?? 0);
        $code = trim((string) ($_POST['code'] ?? ''));
        $contact = trim((string) ($_POST['contact_name'] ?? ''));
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $nextDueDate = trim((string) ($_POST['next_due_date'] ?? ''));
        $expectedAmountRaw = trim((string) ($_POST['expected_amount'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $slug = slugify($slugInput !== '' ? $slugInput : $name);

        if ($name === '' || $slug === '') {
            set_flash('error', 'Project name is required.');
            redirect('projects.php');
        }

        $expectedAmount = null;
        if ($expectedAmountRaw !== '') {
            $expectedAmount = (float) $expectedAmountRaw;
            if ($expectedAmount < 0) {
                set_flash('error', 'Expected amount cannot be negative.');
                redirect('projects.php');
            }
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO project_categories (slug, name, description, is_active) VALUES (:slug, :name, :description, 1)');
            $stmt->execute([
                'slug' => $slug,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
            ]);
            $categoryId = (int) $pdo->lastInsertId();

            $accountId = null;
            if ($accountName !== '') {
                $accountPerson = $accountPersonId > 0 ? find_person($pdo, $accountPersonId, true) : null;
                if ($accountPerson) {
                    $accountName = (string) $accountPerson['full_name'];
                    $contact = $contact !== '' ? $contact : (string) ($accountPerson['department'] ?: $accountPerson['role_or_position'] ?: '');
                }
                $accountStmt = $pdo->prepare('INSERT INTO project_accounts (category_id, person_id, account_name, code, contact_name, start_date, next_due_date, expected_amount, notes)
                    VALUES (:category_id, :person_id, :account_name, :code, :contact_name, :start_date, :next_due_date, :expected_amount, :notes)');
                $accountStmt->execute([
                    'category_id' => $categoryId,
                    'person_id' => $accountPerson ? (int) $accountPerson['id'] : null,
                    'account_name' => $accountName,
                    'code' => $code !== '' ? $code : null,
                    'contact_name' => $contact !== '' ? $contact : null,
                    'start_date' => $startDate !== '' ? $startDate : null,
                    'next_due_date' => $nextDueDate !== '' ? $nextDueDate : null,
                    'expected_amount' => $expectedAmount,
                    'notes' => $notes !== '' ? $notes : null,
                ]);
                $accountId = (int) $pdo->lastInsertId();
            }

            audit_log($pdo, $user, 'create_project', 'projects', 'project_category', $categoryId, [
                'name' => $name,
                'slug' => $slug,
                'account_id' => $accountId,
            ]);

            $pdo->commit();
            set_flash('success', $accountId ? 'Project and first account added.' : 'Project added.');
            redirect('projects.php?category=' . urlencode($slug));
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Could not add project.', ['error' => $e->getMessage(), 'slug' => $slug], $user);
            set_flash('error', 'Project slug already exists. Try another project name.');
            redirect('projects.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Could not add project.', ['error' => $e->getMessage(), 'slug' => $slug], $user);
            set_flash('error', 'Could not add project.');
            redirect('projects.php');
        }
    }

    if ($action === 'add_account') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $accountPersonId = (int) ($_POST['account_person_id'] ?? 0);
        $accountName = trim((string) ($_POST['account_name'] ?? ''));
        $code = trim((string) ($_POST['code'] ?? ''));
        $contact = trim((string) ($_POST['contact_name'] ?? ''));
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $nextDueDate = trim((string) ($_POST['next_due_date'] ?? ''));
        $expectedAmountRaw = trim((string) ($_POST['expected_amount'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($categoryId <= 0 || !isset($categoryById[$categoryId])) {
            set_flash('error', 'Please choose a valid category.');
            redirect('projects.php');
        }

        $categorySlug = (string) $categoryById[$categoryId]['slug'];
        $accountPerson = $accountPersonId > 0 ? find_person($pdo, $accountPersonId, true) : null;
        if (in_array($categorySlug, ['rental', 'toga'], true) && !$accountPerson) {
            set_flash('error', 'Select an approved person for rental and toga accounts.');
            redirect('projects.php?category=' . urlencode($categorySlug));
        }
        if ($accountPerson) {
            $accountName = (string) $accountPerson['full_name'];
            $contact = $contact !== '' ? $contact : (string) ($accountPerson['department'] ?: $accountPerson['role_or_position'] ?: '');
        }

        if ($accountName === '') {
            set_flash('error', 'Account name is required (example: Stall 1, Pond A).');
            redirect('projects.php?category=' . urlencode((string) $categoryById[$categoryId]['slug']));
        }

        $expectedAmount = null;
        if ($expectedAmountRaw !== '') {
            $expectedAmount = (float) $expectedAmountRaw;
            if ($expectedAmount < 0) {
                set_flash('error', 'Expected amount cannot be negative.');
                redirect('projects.php?category=' . urlencode((string) $categoryById[$categoryId]['slug']));
            }
        }

        $stmt = $pdo->prepare('INSERT INTO project_accounts (category_id, person_id, account_name, code, contact_name, start_date, next_due_date, expected_amount, notes)
            VALUES (:category_id, :person_id, :account_name, :code, :contact_name, :start_date, :next_due_date, :expected_amount, :notes)');
        $stmt->execute([
            'category_id' => $categoryId,
            'person_id' => $accountPerson ? (int) $accountPerson['id'] : null,
            'account_name' => $accountName,
            'code' => $code !== '' ? $code : null,
            'contact_name' => $contact !== '' ? $contact : null,
            'start_date' => $startDate !== '' ? $startDate : null,
            'next_due_date' => $nextDueDate !== '' ? $nextDueDate : null,
            'expected_amount' => $expectedAmount,
            'notes' => $notes !== '' ? $notes : null,
        ]);
        audit_log($pdo, $user, 'create_account', 'projects', 'project_account', (int) $pdo->lastInsertId(), [
            'category_id' => $categoryId,
            'account_name' => $accountName,
        ]);

        set_flash('success', 'Project account added.');
        redirect('projects.php?category=' . urlencode((string) $categoryById[$categoryId]['slug']));
    }

    if ($action === 'record_entry') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $entryDateTime = normalize_datetime_input((string) ($_POST['entry_datetime'] ?? ''));
        $entryType = (string) ($_POST['entry_type'] ?? 'monitoring');
        $quantityRaw = trim((string) ($_POST['quantity'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $amount = (float) ($_POST['amount'] ?? 0);
        $referenceNo = trim((string) ($_POST['reference_no'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $syncCash = $entryType === 'expense' || (isset($_POST['sync_cash']) && $_POST['sync_cash'] === '1');
        $nextDueDate = trim((string) ($_POST['update_next_due_date'] ?? ''));

        $validEntryTypes = ['income', 'expense', 'production', 'harvest', 'payment', 'monitoring', 'other'];

        if ($categoryId <= 0 || !isset($categoryById[$categoryId])) {
            set_flash('error', 'Please choose a valid category.');
            redirect('projects.php');
        }

        if (!in_array($entryType, $validEntryTypes, true)) {
            set_flash('error', 'Invalid entry type.');
            redirect('projects.php?category=' . urlencode((string) $categoryById[$categoryId]['slug']));
        }

        if ($amount < 0) {
            set_flash('error', 'Amount cannot be negative.');
            redirect('projects.php?category=' . urlencode((string) $categoryById[$categoryId]['slug']));
        }

        $quantity = null;
        if ($quantityRaw !== '') {
            $quantity = (float) $quantityRaw;
        }

        if ($accountId > 0) {
            $accountCheck = $pdo->prepare('SELECT id FROM project_accounts WHERE id = :id AND category_id = :category_id');
            $accountCheck->execute([
                'id' => $accountId,
                'category_id' => $categoryId,
            ]);
            if (!$accountCheck->fetch()) {
                set_flash('error', 'The selected account does not belong to the chosen category.');
                redirect('projects.php?category=' . urlencode((string) $categoryById[$categoryId]['slug']));
            }
        } else {
            $accountId = 0;
        }

        $slug = (string) $categoryById[$categoryId]['slug'];
        $module = $slug !== '' ? $slug : 'other';

        try {
            $pdo->beginTransaction();

            $insertEntry = $pdo->prepare('INSERT INTO project_entries (category_id, account_id, entry_datetime, entry_type, quantity, unit, amount, reference_no, notes, created_by)
                VALUES (:category_id, :account_id, :entry_datetime, :entry_type, :quantity, :unit, :amount, :reference_no, :notes, :created_by)');
            $insertEntry->execute([
                'category_id' => $categoryId,
                'account_id' => $accountId > 0 ? $accountId : null,
                'entry_datetime' => $entryDateTime,
                'entry_type' => $entryType,
                'quantity' => $quantity,
                'unit' => $unit !== '' ? $unit : null,
                'amount' => $amount,
                'reference_no' => $referenceNo !== '' ? $referenceNo : null,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => (int) $user['id'],
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
                        'txn_date' => $entryDateTime,
                        'direction' => $direction,
                        'source_module' => $module,
                        'project_entry_id' => $entryId,
                        'amount' => $amount,
                        'or_number' => $referenceNo !== '' ? $referenceNo : null,
                        'description' => $categoryById[$categoryId]['name'] . ' - ' . $entryType,
                        'created_by' => (int) $user['id'],
                    ]);
                }
            }

            if ($accountId > 0 && $nextDueDate !== '') {
                $updateDue = $pdo->prepare('UPDATE project_accounts SET next_due_date = :next_due_date WHERE id = :id');
                $updateDue->execute([
                    'next_due_date' => $nextDueDate,
                    'id' => $accountId,
                ]);
            }

            $pdo->commit();
            audit_log($pdo, $user, 'record_entry', 'projects', 'project_entry', $entryId, [
                'category_id' => $categoryId,
                'account_id' => $accountId > 0 ? $accountId : null,
                'entry_type' => $entryType,
                'amount' => $amount,
            ]);
            set_flash('success', 'Project entry saved.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to save project entry.', ['error' => $e->getMessage(), 'category_id' => $categoryId], $user);
            set_flash('error', 'Failed to save project entry.');
        }

        redirect('projects.php?category=' . urlencode((string) $categoryById[$categoryId]['slug']));
    }

    if ($action === 'edit_category') {
        require_permission($user, 'manage_projects', 'projects.php');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $slug = slugify($slugInput !== '' ? $slugInput : $name);

        if ($categoryId <= 0 || $name === '' || $slug === '') {
            set_flash('error', 'Valid project category details are required.');
            redirect('projects.php');
        }

        try {
            $stmt = $pdo->prepare('UPDATE project_categories
                SET slug = :slug, name = :name, description = :description
                WHERE id = :id');
            $stmt->execute([
                'id' => $categoryId,
                'slug' => $slug,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
            ]);
            audit_log($pdo, $user, 'edit_category', 'projects', 'project_category', $categoryId, [
                'name' => $name,
                'slug' => $slug,
            ]);
            set_flash('success', 'Project category updated.');
            redirect('projects.php?category=' . urlencode($slug));
        } catch (PDOException $e) {
            log_system_issue($pdo, 'error', 'Could not edit project category.', ['error' => $e->getMessage(), 'category_id' => $categoryId], $user);
            set_flash('error', 'Could not update category. Slug may already exist.');
            redirect('projects.php');
        }
    }

    if ($action === 'add_toga') {
        $togaCategory = $categoryBySlug['toga'] ?? null;
        $personId = (int) ($_POST['person_id'] ?? 0);
        $person = find_person($pdo, $personId, true);
        $studentName = $person ? (string) $person['full_name'] : trim((string) ($_POST['student_name'] ?? ''));
        $studentId = $person ? (string) ($person['person_code'] ?? '') : trim((string) ($_POST['student_id'] ?? ''));
        $program = $person ? (string) ($person['department'] ?? '') : trim((string) ($_POST['program'] ?? ''));
        $releaseDate = trim((string) ($_POST['release_date'] ?? date('Y-m-d')));
        $depositAmount = (float) ($_POST['deposit_amount'] ?? 0);
        $feeAmount = (float) ($_POST['fee_amount'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $syncCash = isset($_POST['sync_cash']) && $_POST['sync_cash'] === '1';

        if (!$togaCategory) {
            set_flash('error', 'Toga category is not available.');
            redirect('projects.php');
        }

        if (!$person || $studentName === '') {
            set_flash('error', 'Select an approved person for the toga release.');
            redirect('projects.php?category=toga');
        }

        if ($depositAmount < 0 || $feeAmount < 0) {
            set_flash('error', 'Amounts cannot be negative.');
            redirect('projects.php?category=toga');
        }

        try {
            $pdo->beginTransaction();

            $totalAmount = $depositAmount + $feeAmount;
            $insertAccount = $pdo->prepare('INSERT INTO project_accounts (category_id, person_id, account_name, code, contact_name, start_date, expected_amount, status, notes)
                VALUES (:category_id, :person_id, :account_name, :code, :contact_name, :start_date, :expected_amount, "active", :notes)');
            $insertAccount->execute([
                'category_id' => (int) $togaCategory['id'],
                'person_id' => (int) $person['id'],
                'account_name' => $studentName,
                'code' => $studentId !== '' ? $studentId : null,
                'contact_name' => $program !== '' ? $program : null,
                'start_date' => $releaseDate,
                'expected_amount' => $totalAmount,
                'notes' => $notes !== '' ? $notes : null,
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
                'category_id' => (int) $togaCategory['id'],
                'account_id' => $accountId,
                'entry_datetime' => $releaseDate . ' 00:00:00',
                'amount' => $totalAmount,
                'notes' => $notes !== '' ? $notes : 'Toga release fee/deposit',
                'created_by' => (int) $user['id'],
            ]);
            $entryId = (int) $pdo->lastInsertId();
            project_entry_meta_set($pdo, $entryId, 'entry_event', 'toga_release');

            if ($syncCash && $totalAmount > 0) {
                $cashStmt = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, project_entry_id, amount, description, created_by)
                    VALUES (:txn_date, "in", "toga", :project_entry_id, :amount, :description, :created_by)');
                $cashStmt->execute([
                    'txn_date' => $releaseDate . ' 00:00:00',
                    'project_entry_id' => $entryId,
                    'amount' => $totalAmount,
                    'description' => 'Toga release fee/deposit - ' . $studentName,
                    'created_by' => (int) $user['id'],
                ]);
            }

            $pdo->commit();
            audit_log($pdo, $user, 'create_toga_release', 'projects', 'project_account', $accountId, [
                'student_name' => $studentName,
                'deposit_amount' => $depositAmount,
                'fee_amount' => $feeAmount,
            ]);
            set_flash('success', 'Toga release saved under the Toga project category.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to save toga release.', ['error' => $e->getMessage(), 'student_name' => $studentName], $user);
            set_flash('error', 'Failed to save toga release.');
        }

        redirect('projects.php?category=toga');
    }

    if ($action === 'mark_toga_returned') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $returnDate = trim((string) ($_POST['return_date'] ?? date('Y-m-d')));
        $refundAmount = (float) ($_POST['refund_amount'] ?? 0);

        if ($accountId <= 0 || $refundAmount < 0) {
            set_flash('error', 'Invalid toga return details.');
            redirect('projects.php?category=toga');
        }

        $accountStmt = $pdo->prepare('SELECT pa.id, pa.category_id, pa.account_name
            FROM project_accounts pa
            INNER JOIN project_categories pc ON pc.id = pa.category_id
            WHERE pa.id = :id AND pc.slug = "toga"
            ');
        $accountStmt->execute(['id' => $accountId]);
        $account = $accountStmt->fetch();
        if (!$account) {
            set_flash('error', 'Invalid toga record.');
            redirect('projects.php?category=toga');
        }

        try {
            $pdo->beginTransaction();

            $update = $pdo->prepare('UPDATE project_accounts SET status = "inactive" WHERE id = :id');
            $update->execute(['id' => $accountId]);
            project_account_meta_set($pdo, $accountId, 'toga_status', 'returned');
            project_account_meta_set($pdo, $accountId, 'return_date', $returnDate);

            $entryType = $refundAmount > 0 ? 'expense' : 'monitoring';
            $insertEntry = $pdo->prepare('INSERT INTO project_entries (category_id, account_id, entry_datetime, entry_type, amount, notes, created_by)
                VALUES (:category_id, :account_id, :entry_datetime, :entry_type, :amount, :notes, :created_by)');
            $insertEntry->execute([
                'category_id' => (int) $account['category_id'],
                'account_id' => $accountId,
                'entry_datetime' => $returnDate . ' 00:00:00',
                'entry_type' => $entryType,
                'amount' => $refundAmount,
                'notes' => $refundAmount > 0 ? 'Toga returned with deposit refund' : 'Toga returned',
                'created_by' => (int) $user['id'],
            ]);
            $entryId = (int) $pdo->lastInsertId();
            project_entry_meta_set($pdo, $entryId, 'entry_event', 'toga_returned');

            if ($refundAmount > 0) {
                $cashStmt = $pdo->prepare('INSERT INTO cash_transactions (txn_date, direction, source_module, project_entry_id, amount, description, created_by)
                    VALUES (:txn_date, "out", "toga", :project_entry_id, :amount, :description, :created_by)');
                $cashStmt->execute([
                    'txn_date' => $returnDate . ' 00:00:00',
                    'project_entry_id' => $entryId,
                    'amount' => $refundAmount,
                    'description' => 'Toga deposit refund - ' . (string) $account['account_name'],
                    'created_by' => (int) $user['id'],
                ]);
            }

            $pdo->commit();
            audit_log($pdo, $user, 'mark_toga_returned', 'projects', 'project_account', $accountId, [
                'return_date' => $returnDate,
                'refund_amount' => $refundAmount,
            ]);
            set_flash('success', 'Toga marked as returned.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_system_issue($pdo, 'error', 'Failed to mark toga as returned.', ['error' => $e->getMessage(), 'account_id' => $accountId], $user);
            set_flash('error', 'Failed to mark toga as returned.');
        }

        redirect('projects.php?category=toga');
    }

    if ($action === 'mark_toga_forfeited') {
        $accountId = (int) ($_POST['account_id'] ?? 0);

        if ($accountId <= 0) {
            set_flash('error', 'Invalid toga record.');
            redirect('projects.php?category=toga');
        }

        $accountStmt = $pdo->prepare('SELECT pa.id, pa.category_id
            FROM project_accounts pa
            INNER JOIN project_categories pc ON pc.id = pa.category_id
            WHERE pa.id = :id AND pc.slug = "toga"
            ');
        $accountStmt->execute(['id' => $accountId]);
        $account = $accountStmt->fetch();
        if (!$account) {
            set_flash('error', 'Invalid toga record.');
            redirect('projects.php?category=toga');
        }

        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE project_accounts SET status = "inactive" WHERE id = :id');
            $update->execute(['id' => $accountId]);
            project_account_meta_set($pdo, $accountId, 'toga_status', 'forfeited');

            $insertEntry = $pdo->prepare('INSERT INTO project_entries (category_id, account_id, entry_datetime, entry_type, amount, notes, created_by)
                VALUES (:category_id, :account_id, NOW(), "monitoring", 0, "Toga deposit forfeited", :created_by)');
            $insertEntry->execute([
                'category_id' => (int) $account['category_id'],
                'account_id' => $accountId,
                'created_by' => (int) $user['id'],
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
            log_system_issue($pdo, 'error', 'Failed to mark toga as forfeited.', ['error' => $e->getMessage(), 'account_id' => $accountId], $user);
            set_flash('error', 'Failed to mark toga as forfeited.');
        }

        redirect('projects.php?category=toga');
    }
}

$requestedCategorySlug = trim((string) ($_GET['category'] ?? 'all'));
$rentalType = (string) ($_GET['rental_type'] ?? ($requestedCategorySlug === 'toga' ? 'toga' : 'stall'));
if (!in_array($rentalType, ['stall', 'toga'], true)) {
    $rentalType = 'stall';
}
$isRentalManagement = in_array($requestedCategorySlug, ['rental', 'toga'], true);
$selectedCategorySlug = $isRentalManagement
    ? ($rentalType === 'toga' ? 'toga' : 'rental')
    : $requestedCategorySlug;
$selectedCategory = $selectedCategorySlug !== 'all' && isset($categoryBySlug[$selectedCategorySlug])
    ? $categoryBySlug[$selectedCategorySlug]
    : null;

$selectedCategoryId = $selectedCategory ? (int) $selectedCategory['id'] : null;
$isTogaView = $selectedCategorySlug === 'toga';
$isFishpondManagement = $selectedCategorySlug === 'fishpond';
$isProjectOverview = $selectedCategory === null;
$accountStatusFilter = (string) ($_GET['account_status'] ?? 'all');
$accountSearch = trim((string) ($_GET['q'] ?? ''));
$projectTab = (string) ($_GET['tab'] ?? 'accounts');
if (!in_array($projectTab, ['accounts', 'entries', 'overdue', 'performance'], true)) {
    $projectTab = 'accounts';
}
$validAccountStatuses = ['active', 'inactive', 'released', 'returned', 'forfeited'];

$autoOpenAddProject = (isset($_GET['view']) && (string) $_GET['view'] === 'add-project' && user_can($user, 'manage_projects'));

$accountsSql = 'SELECT pa.id, pa.category_id, pa.person_id, pa.account_name, pa.code, pa.contact_name, pa.start_date, pa.next_due_date, pa.expected_amount, pa.status, pa.notes,
        pc.name AS category_name, pc.slug AS category_slug,
        person.full_name AS person_full_name,
        person.person_code AS person_code,
        person.department AS person_department,
        person.role_or_position AS person_role,
        status_meta.meta_value AS toga_status,
        return_meta.meta_value AS return_date,
        deposit_meta.meta_value AS deposit_amount,
        fee_meta.meta_value AS fee_amount
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN people person ON person.id = pa.person_id
    LEFT JOIN project_account_meta status_meta
        ON status_meta.account_id = pa.id AND status_meta.meta_key = "toga_status"
    LEFT JOIN project_account_meta return_meta
        ON return_meta.account_id = pa.id AND return_meta.meta_key = "return_date"
    LEFT JOIN project_account_meta deposit_meta
        ON deposit_meta.account_id = pa.id AND deposit_meta.meta_key = "deposit_amount"
    LEFT JOIN project_account_meta fee_meta
        ON fee_meta.account_id = pa.id AND fee_meta.meta_key = "fee_amount"
    WHERE pc.is_active = 1';
$accountsParams = [];
if ($selectedCategoryId !== null) {
    $accountsSql .= ' AND pa.category_id = :category_id';
    $accountsParams['category_id'] = $selectedCategoryId;
}
if (in_array($accountStatusFilter, $validAccountStatuses, true)) {
    if (in_array($accountStatusFilter, ['released', 'returned', 'forfeited'], true)) {
        if ($accountStatusFilter === 'released') {
            $accountsSql .= ' AND (status_meta.meta_value = :account_status OR (pc.slug = "toga" AND status_meta.meta_value IS NULL AND pa.status = "active"))';
        } elseif ($accountStatusFilter === 'returned') {
            $accountsSql .= ' AND (status_meta.meta_value = :account_status OR (pc.slug = "toga" AND status_meta.meta_value IS NULL AND pa.status = "inactive"))';
        } else {
            $accountsSql .= ' AND status_meta.meta_value = :account_status';
        }
    } else {
        $accountsSql .= ' AND pa.status = :account_status';
    }
    $accountsParams['account_status'] = $accountStatusFilter;
}
if ($accountSearch !== '') {
    $accountsSql .= ' AND (pa.account_name LIKE :account_search OR pa.code LIKE :account_search OR pa.contact_name LIKE :account_search OR pa.notes LIKE :account_search OR person.full_name LIKE :account_search OR person.person_code LIKE :account_search OR person.department LIKE :account_search OR person.role_or_position LIKE :account_search)';
    $accountsParams['account_search'] = prefix_search_param($accountSearch);
}
$accountsSql .= ' ORDER BY pa.category_id, pa.account_name';
$accountsStmt = $pdo->prepare($accountsSql);
$accountsStmt->execute($accountsParams);
$accounts = $accountsStmt->fetchAll();

$entryAccountsStmt = $pdo->query('SELECT pa.id, pa.category_id, COALESCE(person.full_name, pa.account_name) AS account_name, pc.name AS category_name
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN people person ON person.id = pa.person_id
    WHERE pc.is_active = 1
    ORDER BY pc.name, pa.account_name');
$entryAccounts = $entryAccountsStmt->fetchAll();

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
[$fromDateTime, $toDateTimeExclusive] = date_filter_bounds($from, $to);

$entryWhere = ['pe.entry_datetime >= :from_dt AND pe.entry_datetime < :to_dt'];
$entryParams = ['from_dt' => $fromDateTime, 'to_dt' => $toDateTimeExclusive];
if ($selectedCategoryId !== null) {
    $entryWhere[] = 'pe.category_id = :category_id';
    $entryParams['category_id'] = $selectedCategoryId;
}

$entriesCountSql = 'SELECT COUNT(*)
    FROM project_entries pe
    INNER JOIN project_categories pc ON pc.id = pe.category_id
    LEFT JOIN project_accounts pa ON pa.id = pe.account_id
    WHERE ' . implode(' AND ', $entryWhere);
$entriesCountStmt = $pdo->prepare($entriesCountSql);
$entriesCountStmt->execute($entryParams);
$entriesPagination = pagination_meta((int) $entriesCountStmt->fetchColumn(), page_param(), 30);

$entriesSql = 'SELECT id, entry_datetime, entry_type, quantity, unit, amount, reference_no, notes, category_name, category_slug, account_name
    FROM (
        SELECT pe.id, pe.entry_datetime, pe.entry_type, pe.quantity, pe.unit, pe.amount, pe.reference_no, pe.notes,
            pc.name AS category_name, pc.slug AS category_slug, COALESCE(person.full_name, pa.account_name) AS account_name,
            ROW_NUMBER() OVER (ORDER BY pe.entry_datetime DESC, pe.id DESC) AS row_num
        FROM project_entries pe
        INNER JOIN project_categories pc ON pc.id = pe.category_id
        LEFT JOIN project_accounts pa ON pa.id = pe.account_id
        LEFT JOIN people person ON person.id = pa.person_id
        WHERE ' . implode(' AND ', $entryWhere) . '
    ) ranked_entries
    WHERE row_num BETWEEN :first_row AND :last_row
    ORDER BY row_num';
$entriesStmt = $pdo->prepare($entriesSql);
foreach ($entryParams as $key => $value) {
    $entriesStmt->bindValue(':' . ltrim((string) $key, ':'), $value);
}
[$firstRow, $lastRow] = pagination_row_bounds($entriesPagination);
$entriesStmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
$entriesStmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
$entriesStmt->execute();
$entries = $entriesStmt->fetchAll();

$summarySql = 'SELECT
        COALESCE(SUM(CASE WHEN pe.entry_type IN ("income", "payment", "harvest") THEN pe.amount ELSE 0 END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN pe.entry_type = "expense" THEN pe.amount ELSE 0 END), 0) AS total_expense
    FROM project_entries pe
    WHERE ' . implode(' AND ', $entryWhere);
$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($entryParams);
$summary = $summaryStmt->fetch();

$incomeByCategorySql = 'SELECT pc.name,
        COALESCE(SUM(CASE WHEN pe.entry_type IN ("income", "payment", "harvest") THEN pe.amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN pe.entry_type = "expense" THEN pe.amount ELSE 0 END), 0) AS expense
    FROM project_categories pc
    LEFT JOIN project_entries pe
        ON pe.category_id = pc.id
        AND pe.entry_datetime >= :from_dt AND pe.entry_datetime < :to_dt
    WHERE pc.is_active = 1
    GROUP BY pc.id, pc.name
    ORDER BY pc.name';
$incomeByCategoryStmt = $pdo->prepare($incomeByCategorySql);
$incomeByCategoryStmt->execute([
    'from_dt' => $fromDateTime,
    'to_dt' => $toDateTimeExclusive,
]);
$incomeByCategory = $incomeByCategoryStmt->fetchAll();

$overdueSql = 'SELECT COALESCE(person.full_name, pa.account_name) AS account_name, pa.code, pa.next_due_date, pa.expected_amount, pc.name AS category_name
    FROM project_accounts pa
    INNER JOIN project_categories pc ON pc.id = pa.category_id
    LEFT JOIN people person ON person.id = pa.person_id
    WHERE pa.status = "active" AND pa.next_due_date IS NOT NULL AND pa.next_due_date < CURDATE()';
$overdueParams = [];
if ($selectedCategoryId !== null) {
    $overdueSql .= ' AND pa.category_id = :category_id';
    $overdueParams['category_id'] = $selectedCategoryId;
}
$overdueSql .= ' ORDER BY pa.next_due_date ASC, pa.account_name ASC';
$overdueStmt = $pdo->prepare($overdueSql);
$overdueStmt->execute($overdueParams);
$overdues = $overdueStmt->fetchAll();

$pendingProposals = (int) $pdo->query('SELECT COUNT(*) FROM proposals WHERE status IN ("submitted", "under_review")')->fetchColumn();
// Include both approved and pending people so newly added people appear immediately in the selector
$people = people_options($pdo, false);

$dashboardCards = [];
if ($isProjectOverview) {
    $dashboardStmt = $pdo->prepare('SELECT
            pc.slug,
            pc.name,
            COALESCE(SUM(CASE WHEN pe.entry_type IN ("income", "payment", "harvest") THEN pe.amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN pe.entry_type = "expense" THEN pe.amount ELSE 0 END), 0) AS expense,
            (SELECT COUNT(*) FROM project_accounts pa WHERE pa.category_id = pc.id AND pa.status = "active") AS active_records,
            (SELECT COUNT(*) FROM project_accounts pa WHERE pa.category_id = pc.id AND pa.status = "active" AND pa.next_due_date IS NOT NULL AND pa.next_due_date < CURDATE()) AS overdue_count
        FROM project_categories pc
        LEFT JOIN project_entries pe
            ON pe.category_id = pc.id
            AND pe.entry_datetime >= :from_dt AND pe.entry_datetime < :to_dt
        WHERE pc.slug IN ("fishpond", "rental", "toga") AND pc.is_active = 1
        GROUP BY pc.id, pc.slug, pc.name
        ORDER BY FIELD(pc.slug, "fishpond", "rental", "toga")');
    $dashboardStmt->execute([
        'from_dt' => $fromDateTime,
        'to_dt' => $toDateTimeExclusive,
    ]);
    $dashboardRows = $dashboardStmt->fetchAll();
    foreach ($dashboardRows as $row) {
        $slug = (string) $row['slug'];
        if ($slug === 'toga') {
            continue;
        }
        $dashboardCards[$slug] = $row;
    }

    $proposalCount = (int) $pdo->query('SELECT COUNT(*) FROM proposals')->fetchColumn();
    $dashboardCards['proposals'] = [
        'slug' => 'proposals',
        'name' => 'Proposal Requests',
        'income' => 0,
        'expense' => 0,
        'active_records' => $proposalCount,
        'overdue_count' => $pendingProposals,
    ];
}

$pageTitle = $isRentalManagement ? 'Rental Operations' : ($selectedCategory ? ((string) $selectedCategory['slug'] === 'fishpond' ? 'Fishpond Operations' : (string) $selectedCategory['name'] . ' Monitoring') : 'Project Dashboard');
render_header($pageTitle, $user);
?>

<?php if ($isProjectOverview): ?>
    <p class="page-intro">A consolidated view of project income, expenses, active records, overdue records, and proposal requests.</p>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3 mb-6">
        <div class="card"><h3 class="text-sm font-semibold text-slate-600">Total Project Income</h3><div class="stat"><?= h(money((float) $summary['total_income'])) ?></div></div>
        <div class="card"><h3 class="text-sm font-semibold text-slate-600">Total Project Expense</h3><div class="stat"><?= h(money((float) $summary['total_expense'])) ?></div></div>
        <div class="card"><h3 class="text-sm font-semibold text-slate-600">Net Project Income</h3><div class="stat"><?= h(money((float) $summary['total_income'] - (float) $summary['total_expense'])) ?></div></div>
        <div class="card"><h3 class="text-sm font-semibold text-slate-600">Active Project Records</h3><div class="stat"><?= h((string) count($accounts)) ?></div></div>
        <div class="card"><h3 class="text-sm font-semibold text-slate-600">Overdue Records</h3><div class="stat"><?= h((string) count($overdues)) ?></div></div>
        <div class="card"><h3 class="text-sm font-semibold text-slate-600">Pending Proposals</h3><div class="stat"><?= h((string) $pendingProposals) ?></div></div>
    </div>

    <section class="grid gap-4 lg:grid-cols-3">
        <?php foreach ([
            'fishpond' => ['title' => 'Fishpond Operations', 'href' => 'projects.php?category=fishpond'],
            'rental' => ['title' => 'Rental Operations', 'href' => 'projects.php?category=rental&rental_type=stall'],
            'proposals' => ['title' => 'Proposal Requests', 'href' => 'proposals.php'],
        ] as $key => $meta): ?>
            <?php $row = $dashboardCards[$key] ?? ['income' => 0, 'expense' => 0, 'active_records' => 0, 'overdue_count' => 0]; ?>
            <?php $income = (float) $row['income']; $expense = (float) $row['expense']; ?>
            <article class="card">
                <div class="section-heading">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-950"><?= h($meta['title']) ?></h3>
                        <p class="muted"><?= $key === 'proposals' ? 'Project request review and approval.' : 'Operational monitoring and financial activity.' ?></p>
                    </div>
                </div>
                <dl class="grid gap-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Income</dt><dd class="font-semibold"><?= h(money($income)) ?></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Expense</dt><dd class="font-semibold"><?= h(money($expense)) ?></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Net income</dt><dd class="font-semibold"><?= h(money($income - $expense)) ?></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Active records</dt><dd class="font-semibold"><?= h((string) $row['active_records']) ?></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500"><?= $key === 'proposals' ? 'Pending' : 'Overdue' ?></dt><dd class="font-semibold"><?= h((string) $row['overdue_count']) ?></dd></div>
                </dl>
                <a class="btn mt-5" href="<?= h($meta['href']) ?>">View</a>
            </article>
        <?php endforeach; ?>
    </section>

    <?php render_footer(); ?>
    <?php return; ?>
<?php endif; ?>

<div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
    <div>
        <p class="page-intro mb-0">
            <?= $isRentalManagement
                ? 'Manage stall rental accounts and toga rental releases in one focused workspace.'
                : ($isProjectOverview ? 'Monitor all university production and business operation categories in one place.' : h((string) ($selectedCategory['description'] ?? 'Monitor accounts, entries, renewals, and performance for this category.'))) ?>
        </p>
    </div>
    <div class="actions-row lg:justify-end">
        <?php if ($isRentalManagement): ?>
            <?php if ($rentalType === 'toga'): ?>
                <button type="button" data-open-modal="toga-modal">Add Toga Release</button>
                <details class="relative inline-block text-left">
                    <summary class="inline-flex cursor-pointer list-none items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">More Actions</summary>
                    <div class="absolute right-0 z-20 mt-2 w-44 rounded-lg border border-slate-200 bg-white p-1 shadow-xl">
                        <button type="button" class="btn alt w-full justify-start" data-open-modal="person-modal">Add Person</button>
                        <button type="button" class="btn alt mt-1 w-full justify-start" data-open-modal="account-modal">Add Rental Account</button>
                    </div>
                </details>
            <?php else: ?>
                <button type="button" data-open-modal="entry-modal">Add Payment</button>
                <details class="relative inline-block text-left">
                    <summary class="inline-flex cursor-pointer list-none items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">More Actions</summary>
                    <div class="absolute right-0 z-20 mt-2 w-44 rounded-lg border border-slate-200 bg-white p-1 shadow-xl">
                        <button type="button" class="btn alt w-full justify-start" data-open-modal="person-modal">Add Person</button>
                        <button type="button" class="btn alt mt-1 w-full justify-start" data-open-modal="account-modal">Add Stall Account</button>
                        <?php if (user_can($user, 'manage_projects')): ?>
                            <button type="button" class="btn alt mt-1 w-full justify-start" data-open-modal="edit-category-modal">Edit Account</button>
                            <button type="button" class="btn alt mt-1 w-full justify-start" data-open-modal="category-modal">Add Rental Account</button>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
        <?php else: ?>
            <?php if (!$isProjectOverview): ?>
                <button type="button" data-open-modal="entry-modal">Record Entry</button>
                <details class="relative inline-block text-left">
                    <summary class="inline-flex cursor-pointer list-none items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">More Actions</summary>
                    <div class="absolute right-0 z-20 mt-2 w-44 rounded-lg border border-slate-200 bg-white p-1 shadow-xl">
                        <button type="button" class="btn alt w-full justify-start" data-open-modal="person-modal">Add Person</button>
                        <button type="button" class="btn alt mt-1 w-full justify-start" data-open-modal="account-modal"><?= $isFishpondManagement ? 'Add Pond Account' : 'New Account' ?></button>
                        <?php if (user_can($user, 'manage_projects')): ?>
                            <button type="button" class="btn alt mt-1 w-full justify-start" data-open-modal="category-modal">Add Project</button>
                            <button type="button" class="btn alt mt-1 w-full justify-start" data-open-modal="edit-category-modal">Edit Project</button>
                        <?php endif; ?>
                    </div>
                </details>
            <?php elseif (user_can($user, 'manage_projects')): ?>
                <button type="button" data-open-modal="category-modal">Add Project</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($isRentalManagement): ?>
    <?php
    $rentalSwitcherBase = [
        'category' => 'rental',
        'from' => $from,
        'to' => $to,
        'account_status' => $accountStatusFilter,
        'q' => $accountSearch,
    ];
    ?>
    <nav class="mb-3 inline-flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm" aria-label="Rental type">
        <a class="rounded-md px-3 py-1.5 text-sm font-semibold transition <?= $rentalType === 'stall' ? 'bg-brand-100 text-brand-900' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' ?>" href="projects.php?<?= h(http_build_query(array_merge($rentalSwitcherBase, ['rental_type' => 'stall', 'tab' => 'accounts']))) ?>">Stall Rentals</a>
        <a class="rounded-md px-3 py-1.5 text-sm font-semibold transition <?= $rentalType === 'toga' ? 'bg-brand-100 text-brand-900' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' ?>" href="projects.php?<?= h(http_build_query(array_merge($rentalSwitcherBase, ['rental_type' => 'toga', 'tab' => 'accounts']))) ?>">Toga Rentals</a>
    </nav>
<?php endif; ?>

<section class="page-toolbar table-card data-panel mb-4">
    <div class="section-heading">
        <div>
            <h3 class="text-base font-bold text-slate-950">Filters</h3>
        </div>
    </div>

    <form method="get" class="data-panel-filters grid gap-3 p-4 pt-0">
        <input type="hidden" name="tab" value="<?= h($projectTab) ?>">
        <?php if ($isRentalManagement): ?>
            <input type="hidden" name="category" value="rental">
            <input type="hidden" name="rental_type" value="<?= h($rentalType) ?>">
        <?php endif; ?>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <?php if (!$selectedCategory): ?>
                <div>
                    <label for="filter_category">Category</label>
                    <select id="filter_category" name="category">
                        <option value="all">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= h($cat['slug']) ?>" <?= $selectedCategorySlug === $cat['slug'] ? 'selected' : '' ?>>
                                <?= h($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div>
                <label for="filter_from">From</label>
                <input id="filter_from" type="date" name="from" value="<?= h($from) ?>">
            </div>
            <div>
                <label for="filter_to">To</label>
                <input id="filter_to" type="date" name="to" value="<?= h($to) ?>">
            </div>
            <div>
                <label for="filter_account_status">Account Status</label>
                <select id="filter_account_status" name="account_status">
                    <option value="all" <?= $accountStatusFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="active" <?= $accountStatusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $accountStatusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="released" <?= $accountStatusFilter === 'released' ? 'selected' : '' ?>>Toga Released</option>
                    <option value="returned" <?= $accountStatusFilter === 'returned' ? 'selected' : '' ?>>Toga Returned</option>
                    <option value="forfeited" <?= $accountStatusFilter === 'forfeited' ? 'selected' : '' ?>>Toga Forfeited</option>
                </select>
            </div>
        </div>
        <div class="grid gap-3 md:grid-cols-[minmax(220px,1fr)_auto_auto] md:items-end">
            <div>
                <label for="filter_q">Search</label>
                <input id="filter_q" name="q" value="<?= h($accountSearch) ?>" placeholder="Name, code, contact">
            </div>
            <button type="submit">Apply Filters</button>
            <a class="btn alt" href="<?= h($isRentalManagement ? 'projects.php?category=rental&rental_type=' . urlencode($rentalType) : 'projects.php' . ($selectedCategorySlug !== 'all' ? '?category=' . urlencode($selectedCategorySlug) : '')) ?>">Reset</a>
        </div>
    </form>
</section>

<?php
$projectBaseQuery = [
    'category' => $isRentalManagement ? 'rental' : $selectedCategorySlug,
    'rental_type' => $isRentalManagement ? $rentalType : null,
    'from' => $from,
    'to' => $to,
    'account_status' => $accountStatusFilter,
    'q' => $accountSearch,
];
$projectBaseQuery = array_filter($projectBaseQuery, static fn($value): bool => $value !== null && $value !== '');
$projectTabs = $isRentalManagement
    ? [
        'accounts' => $isTogaView ? 'Toga Releases' : 'Stall Accounts',
        'entries' => $isTogaView ? 'Rental Activity' : 'Payments',
        'overdue' => 'Overdue',
    ]
    : [
        'accounts' => $isTogaView ? 'Toga Releases' : 'Accounts',
        'entries' => 'Entries',
        'overdue' => 'Overdue',
        'performance' => 'Performance',
    ];
$recordLabel = $isTogaView ? 'toga records' : 'accounts';
$emptyEntriesMessage = $isRentalManagement
    ? 'No rental activity found.'
    : ($isFishpondManagement ? 'No fishpond entries found.' : 'No entries found.');
$emptyAccountsMessage = $isRentalManagement
    ? ($isTogaView ? 'No toga releases found.' : 'No stall accounts found.')
    : ($isFishpondManagement ? 'No fishpond accounts found.' : 'No accounts found.');
$summaryParts = [
    count($accounts) . ' ' . $recordLabel,
    'Income ' . money((float) $summary['total_income']),
    'Expense ' . money((float) $summary['total_expense']),
    'Net ' . money((float) $summary['total_income'] - (float) $summary['total_expense']),
    count($overdues) . ' overdue',
];
$tableTitles = [
    'accounts' => $isRentalManagement
        ? ($isTogaView ? 'Toga Releases' : 'Stall Accounts')
        : ($isFishpondManagement ? 'Fishpond Accounts' : 'Project Accounts'),
    'entries' => $isRentalManagement
        ? ($isTogaView ? 'Rental Activity' : 'Rental Payments')
        : ($isFishpondManagement ? 'Fishpond Entries' : 'Project Entries'),
    'overdue' => $isRentalManagement
        ? 'Overdue Rental Accounts'
        : ($isFishpondManagement ? 'Overdue Fishpond Accounts' : 'Overdue Accounts'),
    'performance' => $isFishpondManagement ? 'Fishpond Performance' : 'Category Performance',
];
?>
<nav class="tabs" aria-label="Project sections">
    <?php foreach ($projectTabs as $tabKey => $tabLabel): ?>
        <a class="tab-link <?= $projectTab === $tabKey ? 'active' : '' ?>" href="projects.php?<?= h(http_build_query(array_merge($projectBaseQuery, ['tab' => $tabKey]))) ?>"><?= h($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>

<p class="mb-4 text-sm text-slate-600"><?= implode(' &bull; ', array_map('h', $summaryParts)) ?></p>

<dialog id="category-modal" class="modal">
    <div class="modal-header">
        <h3>Add Project</h3>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_project">

        <div class="form-grid">
            <div>
                <label for="category_name">Project Name</label>
                <input id="category_name" name="name" placeholder="Example: Livelihood Farm" required>
            </div>
            <div>
                <label for="category_slug">Slug (optional)</label>
                <input id="category_slug" name="slug" placeholder="Example: livelihood-farm">
            </div>
            <div class="field-wide">
                <label for="category_description">Description</label>
                <textarea id="category_description" name="description" placeholder="Short description"></textarea>
            </div>
            <div>
                <label for="project_account_name">First Account (optional)</label>
                <input id="project_account_name" name="account_name" placeholder="Example: Pond A / Stall 1">
            </div>
            <?php render_person_selector($people, 'account_person_id', 'project_account_person_id', null, 'First Account Person', false, ['name_target' => 'project_account_name', 'department_target' => 'project_contact_name']); ?>
            <div>
                <label for="project_code">Code</label>
                <input id="project_code" name="code" placeholder="Optional">
            </div>
            <div>
                <label for="project_contact_name">Contact/Owner</label>
                <input id="project_contact_name" name="contact_name" placeholder="Optional">
            </div>
            <div>
                <label for="project_start_date">Start Date</label>
                <input id="project_start_date" type="date" name="start_date">
            </div>
            <div>
                <label for="project_next_due_date">Next Due Date</label>
                <input id="project_next_due_date" type="date" name="next_due_date">
            </div>
            <div>
                <label for="project_expected_amount">Expected Amount</label>
                <input id="project_expected_amount" name="expected_amount" type="number" min="0" step="0.01" placeholder="Optional">
            </div>
            <div class="field-wide">
                <label for="project_notes">Account Notes</label>
                <textarea id="project_notes" name="notes" placeholder="Optional notes for the first account"></textarea>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <button type="submit">Add Project</button>
        </div>
    </form>
</dialog>

<?php if ($selectedCategory && user_can($user, 'manage_projects')): ?>
    <dialog id="edit-category-modal" class="modal">
        <div class="modal-header">
            <h3>Edit Project Category</h3>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="category_id" value="<?= (int) $selectedCategory['id'] ?>">

            <div class="form-grid">
                <div>
                    <label for="edit_category_name">Project Name</label>
                    <input id="edit_category_name" name="name" value="<?= h($selectedCategory['name']) ?>" required>
                </div>
                <div>
                    <label for="edit_category_slug">Slug</label>
                    <input id="edit_category_slug" name="slug" value="<?= h($selectedCategory['slug']) ?>" required>
                </div>
                <div class="field-wide">
                    <label for="edit_category_description">Description</label>
                    <textarea id="edit_category_description" name="description"><?= h($selectedCategory['description']) ?></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn alt" data-close-modal>Cancel</button>
                <button type="submit">Save Project</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<dialog id="account-modal" class="modal">
    <div class="modal-header">
        <h3><?= $isRentalManagement ? 'Add Rental Account' : ($isFishpondManagement ? 'Add Pond Account' : 'Add Account') ?></h3>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_account">

        <div class="form-grid">
            <div>
                <label for="account_category_id">Category</label>
                <select id="account_category_id" name="category_id" required>
                    <option value="">Select...</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>" <?= $selectedCategoryId === (int) $cat['id'] ? 'selected' : '' ?>>
                            <?= h($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php render_person_selector($people, 'account_person_id', 'account_person_id', null, 'Person', false, ['name_target' => 'account_name', 'department_target' => 'contact_name']); ?>
            <div>
                <label for="account_name">Account Name</label>
                <input id="account_name" name="account_name" placeholder="Stall 1 / Pond A" required>
            </div>
            <div>
                <label for="account_code">Code/Reference</label>
                <input id="account_code" name="code" placeholder="Optional">
            </div>
            <div>
                <label for="contact_name">Contact/Owner</label>
                <input id="contact_name" name="contact_name" placeholder="Optional">
            </div>
            <div>
                <label for="start_date">Start Date</label>
                <input id="start_date" type="date" name="start_date">
            </div>
            <div>
                <label for="next_due_date">Next Due Date</label>
                <input id="next_due_date" type="date" name="next_due_date">
            </div>
            <div>
                <label for="expected_amount">Expected Amount</label>
                <input id="expected_amount" name="expected_amount" type="number" min="0" step="0.01" placeholder="Optional">
            </div>
            <div class="field-wide">
                <label for="account_notes">Notes</label>
                <textarea id="account_notes" name="notes"></textarea>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <button type="submit"><?= $isRentalManagement ? 'Add Rental Account' : ($isFishpondManagement ? 'Add Pond Account' : 'Add Account') ?></button>
        </div>
    </form>
</dialog>

<dialog id="entry-modal" class="modal modal-wide">
    <div class="modal-header">
        <h3><?= $isFishpondManagement ? 'Add Fishpond Entry' : ($isRentalManagement ? 'Add Rental Entry' : 'Add Project Entry') ?></h3>
        <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="record_entry">

        <div class="form-grid">
            <div>
                <label for="entry_category_id">Category</label>
                <select id="entry_category_id" name="category_id" required>
                    <option value="">Select...</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>" <?= $selectedCategoryId === (int) $cat['id'] ? 'selected' : '' ?>>
                            <?= h($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="entry_account_id">Account</label>
                <select id="entry_account_id" name="account_id">
                    <option value="0">General (No Specific Account)</option>
                    <?php foreach ($entryAccounts as $account): ?>
                        <option value="<?= (int) $account['id'] ?>" data-category-id="<?= (int) $account['category_id'] ?>">
                            <?= h($account['category_name']) ?> - <?= h($account['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="entry_datetime">Date and Time</label>
                <input id="entry_datetime" type="datetime-local" name="entry_datetime" value="<?= date('Y-m-d\\TH:i') ?>">
            </div>
            <div>
                <label for="entry_type">Entry Type</label>
                <select id="entry_type" name="entry_type" required>
                    <option value="monitoring" selected>Monitoring</option>
                    <option value="harvest">Harvest Income</option>
                    <option value="income">Income</option>
                    <option value="expense">Expense</option>
                    <option value="payment">Payment</option>
                    <option value="production">Maintenance</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div>
                <label for="quantity">Quantity</label>
                <input id="quantity" name="quantity" type="number" step="0.01" min="0" placeholder="Optional">
            </div>
            <div>
                <label for="unit">Unit</label>
                <input id="unit" name="unit" placeholder="pcs, kg, sacks...">
            </div>
            <div>
                <label for="amount">Amount</label>
                <input id="amount" name="amount" type="number" min="0" step="0.01" value="0" required>
            </div>
            <div>
                <label for="reference_no">Reference/OR No.</label>
                <input id="reference_no" name="reference_no" placeholder="Optional">
            </div>
            <div>
                <label for="update_next_due_date">Update Next Due Date</label>
                <input id="update_next_due_date" type="date" name="update_next_due_date">
            </div>
            <div class="checkbox-field">
                <label>
                    <input type="checkbox" name="sync_cash" value="1" checked>
                    Also post to Cash Flow
                </label>
            </div>
            <div class="field-wide">
                <label for="entry_notes">Notes</label>
                <textarea id="entry_notes" name="notes"></textarea>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn alt" data-close-modal>Cancel</button>
            <button type="submit">Save Entry</button>
        </div>
    </form>
</dialog>

<?php if (isset($categoryBySlug['toga'])): ?>
    <dialog id="toga-modal" class="modal">
        <div class="modal-header">
            <h3>Add Toga Release</h3>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_toga">

            <div class="form-grid">
                <?php render_person_selector($people, 'person_id', 'toga_person_id', null, 'Student', true, ['name_target' => 'student_name', 'code_target' => 'student_id', 'department_target' => 'program']); ?>
                <div>
                    <label for="student_name">Student Name</label>
                    <input id="student_name" name="student_name" readonly required>
                </div>
                <div>
                    <label for="student_id">Student ID</label>
                    <input id="student_id" name="student_id" readonly>
                </div>
                <div>
                    <label for="program">Program/Course</label>
                    <input id="program" name="program" readonly>
                </div>
                <div>
                    <label for="release_date">Release Date</label>
                    <input id="release_date" type="date" name="release_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div>
                    <label for="deposit_amount">Deposit Amount</label>
                    <input id="deposit_amount" type="number" min="0" step="0.01" name="deposit_amount" value="0" required>
                </div>
                <div>
                    <label for="fee_amount">Fee Amount</label>
                    <input id="fee_amount" type="number" min="0" step="0.01" name="fee_amount" value="0" required>
                </div>
                <div class="field-wide">
                    <label for="toga_notes">Notes</label>
                    <textarea id="toga_notes" name="notes"></textarea>
                </div>
                <div class="checkbox-field">
                    <label>
                        <input type="checkbox" name="sync_cash" value="1" checked>
                        Also post to Cash Flow
                    </label>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn alt" data-close-modal>Cancel</button>
                <button type="submit">Save Toga Release</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<div class="content-grid">
    <?php if ($projectTab === 'overdue'): ?>
    <details class="collapse-card table-card" open>
        <summary>
            <span>
                <strong><?= h($tableTitles['overdue']) ?></strong>
                <small>Renewal monitoring</small>
            </span>
            <span class="badge"><?= h((string) count($overdues)) ?></span>
        </summary>
        <?php if (!$overdues): ?>
            <p class="muted empty-state"><?= h($isRentalManagement ? 'No overdue rental accounts found.' : ($isFishpondManagement ? 'No overdue fishpond accounts found.' : 'No overdue accounts found.')) ?></p>
        <?php else: ?>
            <div class="table-wrap compact-table">
                <table>
                    <thead>
                    <tr>
                        <th>Category</th>
                        <th>Account</th>
                        <th>Code</th>
                        <th>Next Due</th>
                        <th>Expected</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($overdues as $row): ?>
                        <tr>
                            <td><?= h($row['category_name']) ?></td>
                            <td><?= h($row['account_name']) ?></td>
                            <td><?= h($row['code']) ?></td>
                            <td><?= h($row['next_due_date']) ?></td>
                            <td><?= h(money((float) $row['expected_amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <?php if ($projectTab === 'performance'): ?>
    <details class="collapse-card table-card" open>
        <summary>
            <span>
                <strong><?= h($tableTitles['performance']) ?></strong>
                <small>Income and expense summary</small>
            </span>
            <span class="badge"><?= h((string) count($incomeByCategory)) ?></span>
        </summary>
        <div class="table-wrap compact-table">
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
                <?php foreach ($incomeByCategory as $row): ?>
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
            Income <?= h(money((float) $summary['total_income'])) ?> | Expense <?= h(money((float) $summary['total_expense'])) ?> | Net <?= h(money((float) $summary['total_income'] - (float) $summary['total_expense'])) ?>
        </div>
    </details>
    <?php endif; ?>
</div>

<?php if ($projectTab === 'entries'): ?>
<details class="collapse-card table-card">
    <summary>
        <span>
            <strong><?= h($tableTitles['entries']) ?></strong>
            <small><?= h($from) ?> to <?= h($to) ?></small>
        </span>
        <span class="badge"><?= h((string) $entriesPagination['total_rows']) ?></span>
    </summary>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Date and Time</th>
                <th>Category</th>
                <th>Account</th>
                <th>Type</th>
                <th>Qty/Unit</th>
                <th>Amount</th>
                <th>Reference</th>
                <th>Notes</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$entries): ?>
                <tr>
                    <td colspan="8" class="muted"><?= h($emptyEntriesMessage) ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= h($entry['entry_datetime']) ?></td>
                    <td><?= h($entry['category_name']) ?></td>
                    <td><?= h($entry['account_name'] ?: '-') ?></td>
                    <td><?= h($entry['entry_type']) ?></td>
                    <td>
                        <?php if ($entry['quantity'] !== null): ?>
                            <?= h((string) $entry['quantity']) ?> <?= h((string) $entry['unit']) ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><?= h(money((float) $entry['amount'])) ?></td>
                    <td><?= h($entry['reference_no']) ?></td>
                    <td><?= h($entry['notes']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($entriesPagination); ?>
    <div class="data-panel-footer">
        Income <?= h(money((float) $summary['total_income'])) ?> | Expense <?= h(money((float) $summary['total_expense'])) ?> | Net <?= h(money((float) $summary['total_income'] - (float) $summary['total_expense'])) ?>
    </div>
</details>
<?php endif; ?>

<?php if ($projectTab === 'accounts'): ?>
<details class="collapse-card table-card" <?= !$isProjectOverview ? 'open' : '' ?>>
    <summary>
        <span>
            <strong><?= h($tableTitles['accounts']) ?></strong>
            <small><?= $isTogaView ? 'Release and return status' : 'Trackable units by category' ?></small>
        </span>
        <span class="badge"><?= h((string) count($accounts)) ?></span>
    </summary>
    <div class="table-wrap">
        <table>
            <thead>
            <?php if ($isTogaView): ?>
                <tr>
                    <th>Student</th>
                    <th>Student ID</th>
                    <th>Program</th>
                    <th>Released</th>
                    <th>Returned</th>
                    <th>Deposit</th>
                    <th>Fee</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            <?php else: ?>
                <tr>
                    <th>Category</th>
                    <th>Account</th>
                    <th>Code</th>
                    <th>Contact</th>
                    <th>Start Date</th>
                    <th>Next Due</th>
                    <th>Expected Amount</th>
                    <th>Status</th>
                </tr>
            <?php endif; ?>
            </thead>
            <tbody>
            <?php if (!$accounts): ?>
                <tr>
                    <td colspan="<?= $isTogaView ? '9' : '8' ?>" class="muted"><?= h($emptyAccountsMessage) ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($accounts as $account): ?>
                <?php
                $displayStatus = $account['category_slug'] === 'toga'
                    ? (string) ($account['toga_status'] ?: ($account['status'] === 'active' ? 'released' : 'returned'))
                    : (string) $account['status'];
                $statusClass = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($displayStatus)) ?: 'active';
                $accountDisplayName = (string) ($account['person_full_name'] ?: $account['account_name']);
                $accountDisplayCode = (string) ($account['person_code'] ?: $account['code']);
                $accountDisplayDepartment = (string) ($account['person_department'] ?: $account['contact_name']);
                ?>
                <?php if ($isTogaView): ?>
                    <tr>
                        <td><?= h($accountDisplayName) ?></td>
                        <td><?= h($accountDisplayCode ?: '-') ?></td>
                        <td><?= h($accountDisplayDepartment ?: '-') ?></td>
                        <td><?= h($account['start_date']) ?></td>
                        <td><?= h($account['return_date'] ?: '-') ?></td>
                        <td><?= h(money(project_meta_decimal($account['deposit_amount']))) ?></td>
                        <td><?= h(money(project_meta_decimal($account['fee_amount']))) ?></td>
                        <td><span class="status-pill <?= h($statusClass) ?>"><?= h($displayStatus) ?></span></td>
                        <td>
                            <?php if ($displayStatus === 'released'): ?>
                                <details class="action-menu">
                                    <summary>Actions</summary>
                                    <div class="action-menu-panel w-36">
                                        <button type="button" class="btn alt action-menu-item" data-open-modal="return-toga-<?= (int) $account['id'] ?>">Return</button>
                                        <button type="button" class="btn alt action-menu-item mt-1" data-open-modal="forfeit-toga-<?= (int) $account['id'] ?>">Forfeit</button>
                                    </div>
                                </details>
                            <?php else: ?>
                                <span class="muted">No action</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td><?= h($account['category_name']) ?></td>
                        <td><?= h($accountDisplayName) ?></td>
                        <td><?= h($account['code']) ?></td>
                        <td><?= h($accountDisplayDepartment) ?></td>
                        <td><?= h($account['start_date']) ?></td>
                        <td><?= h($account['next_due_date']) ?></td>
                        <td><?= h(money((float) $account['expected_amount'])) ?></td>
                        <td><span class="status-pill <?= h($statusClass) ?>"><?= h($displayStatus) ?></span></td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</details>
<?php endif; ?>

<?php if ($isTogaView): ?>
    <?php foreach ($accounts as $account): ?>
        <?php
        $displayStatus = $account['category_slug'] === 'toga'
            ? (string) ($account['toga_status'] ?: ($account['status'] === 'active' ? 'released' : 'returned'))
            : (string) $account['status'];
        if ($displayStatus !== 'released') {
            continue;
        }
        ?>
        <dialog id="return-toga-<?= (int) $account['id'] ?>" class="modal">
            <div class="modal-header">
                <h3>Return Toga</h3>
                <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_toga_returned">
                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                <p class="muted">Mark <?= h($account['account_name']) ?> as returned and record any deposit refund.</p>
                <div class="form-grid">
                    <div>
                        <label for="return_date_<?= (int) $account['id'] ?>">Return Date</label>
                        <input id="return_date_<?= (int) $account['id'] ?>" type="date" name="return_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label for="refund_amount_<?= (int) $account['id'] ?>">Refund Amount</label>
                        <input id="refund_amount_<?= (int) $account['id'] ?>" type="number" min="0" step="0.01" name="refund_amount" value="0">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn alt" data-close-modal>Cancel</button>
                    <button type="submit">Mark Returned</button>
                </div>
            </form>
        </dialog>

        <dialog id="forfeit-toga-<?= (int) $account['id'] ?>" class="modal">
            <div class="modal-header">
                <h3>Forfeit Toga Deposit</h3>
                <button type="button" class="modal-close" data-close-modal aria-label="Close">Close</button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_toga_forfeited">
                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                <p class="muted">This will close <?= h($account['account_name']) ?> as forfeited while keeping the history in project entries.</p>
                <div class="modal-actions">
                    <button type="button" class="btn alt" data-close-modal>Cancel</button>
                    <button type="submit">Mark Forfeited</button>
                </div>
            </form>
        </dialog>
    <?php endforeach; ?>
<?php endif; ?>

<?php render_add_person_modal($user, 'projects.php'); ?>

<script>
    (function () {
        const autoOpenAddProject = <?= $autoOpenAddProject ? 'true' : 'false' ?>;
        if (autoOpenAddProject) {
            const modal = document.getElementById('category-modal');
            if (modal && typeof modal.showModal === 'function' && !modal.open) {
                modal.showModal();
            }
        }
    })();

    (function () {
        const categorySelect = document.getElementById('entry_category_id');
        const accountSelect = document.getElementById('entry_account_id');
        if (!categorySelect || !accountSelect) {
            return;
        }

        const baseOption = accountSelect.options[0];

        function filterAccounts() {
            const selectedCategoryId = categorySelect.value;
            for (let i = 1; i < accountSelect.options.length; i += 1) {
                const option = accountSelect.options[i];
                const categoryId = option.getAttribute('data-category-id');
                option.hidden = selectedCategoryId !== '' && categoryId !== selectedCategoryId;
            }
            accountSelect.value = baseOption.value;
        }

        categorySelect.addEventListener('change', filterAccounts);
        filterAccounts();

    })();
</script>

<?php render_footer();
