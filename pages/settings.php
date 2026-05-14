<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);
require_permission($user, 'manage_settings');

function settings_generate_sql_backup(PDO $pdo, string $filePath): void
{
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    $org = organization_profile($pdo);

    $sql = '';
    $sql .= '-- ' . $org['campus_display_name'] . ' ' . $org['system_name'] . ' MySQL Backup' . PHP_EOL;
    $sql .= '-- Generated at: ' . date('Y-m-d H:i:s') . PHP_EOL;
    $sql .= 'SET FOREIGN_KEY_CHECKS=0;' . PHP_EOL . PHP_EOL;

    foreach ($tables as $tableRow) {
        $table = (string) ($tableRow[0] ?? '');
        if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            continue;
        }

        $createData = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_ASSOC);
        $createSql = (string) ($createData['Create Table'] ?? '');
        if ($createSql === '') {
            continue;
        }

        $sql .= '-- Table: ' . $table . PHP_EOL;
        $sql .= 'DROP TABLE IF EXISTS `' . $table . '`;' . PHP_EOL;
        $sql .= $createSql . ';' . PHP_EOL . PHP_EOL;

        $columns = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_map(static fn(array $col): string => '`' . $col['Field'] . '`', $columns);
        $rowsStmt = $pdo->query('SELECT ' . implode(', ', $columnNames) . ' FROM `' . str_replace('`', '``', $table) . '`');
        $rows = $rowsStmt ? $rowsStmt->fetchAll(PDO::FETCH_NUM) : [];

        if (!$rows) {
            continue;
        }

        $sql .= 'INSERT INTO `' . $table . '` (' . implode(', ', $columnNames) . ') VALUES' . PHP_EOL;
        $valueLines = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) {
                $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
            }
            $valueLines[] = '(' . implode(', ', $values) . ')';
        }
        $sql .= implode(',' . PHP_EOL, $valueLines) . ';' . PHP_EOL . PHP_EOL;
    }

    $sql .= 'SET FOREIGN_KEY_CHECKS=1;' . PHP_EOL;
    file_put_contents($filePath, $sql);
}

if (isset($_GET['download'])) {
    $downloadName = basename((string) $_GET['download']);
    $fullPath = dirname(__DIR__) . '/backups/' . $downloadName;

    if (!is_file($fullPath)) {
        set_flash('error', 'Backup file not found.');
        redirect('settings.php?section=backup');
    }

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string) filesize($fullPath));
    audit_log($pdo, $user, 'download_backup', 'settings', 'backup_file', $downloadName);
    readfile($fullPath);
    exit;
}

$sections = [
    'organization' => 'Organization Profile',
    'display' => 'System Display',
    'reports' => 'Receipt and Reports',
    'roles' => 'Roles and Permissions',
    'security' => 'Login Security',
    'backup' => 'Backup Settings',
];
$section = (string) ($_GET['section'] ?? $_POST['section'] ?? 'organization');
if (!isset($sections[$section])) {
    $section = 'organization';
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        set_flash('error', 'Invalid form token.');
        redirect('settings.php?section=' . urlencode($section));
    }

    $action = (string) ($_POST['action'] ?? 'save_settings');

    if ($action === 'create_backup') {
        $filename = 'backup_' . date('Ymd_His') . '.sql';
        $backupDir = dirname(__DIR__) . '/backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true)) {
            set_flash('error', 'Backup folder could not be created.');
            redirect('settings.php?section=backup');
        }
        if (!is_writable($backupDir)) {
            set_flash('error', 'Backup folder is not writable.');
            redirect('settings.php?section=backup');
        }

        try {
            settings_generate_sql_backup($pdo, $backupDir . '/' . $filename);
            audit_log($pdo, $user, 'create_backup', 'settings', 'backup_file', $filename);
            set_flash('success', 'Backup created.');
        } catch (Throwable $e) {
            log_system_issue($pdo, 'critical', 'Backup failed.', ['error' => $e->getMessage()], $user);
            set_flash('error', 'Backup failed.');
        }
        redirect('settings.php?section=backup');
    }

    $updates = [];
    if ($section === 'organization') {
        $fieldMap = [
            'university_name' => 'organization.university_name',
            'campus_name' => 'organization.campus_name',
            'office_name' => 'organization.office_name',
            'system_name' => 'organization.system_name',
            'address' => 'organization.address',
            'contact_information' => 'organization.contact_information',
        ];
        foreach ($fieldMap as $field => $key) {
            $value = trim((string) ($_POST[$field] ?? ''));
            if (in_array($field, ['university_name', 'campus_name', 'office_name', 'system_name'], true) && $value === '') {
                $errors[$field] = 'This field is required.';
            }
            $updates[$key] = $value;
        }

        if (isset($_FILES['logo_upload']) && is_array($_FILES['logo_upload']) && (int) ($_FILES['logo_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int) $_FILES['logo_upload']['error'] !== UPLOAD_ERR_OK) {
                $errors['logo_upload'] = 'Logo upload failed.';
            } else {
                $tmpName = (string) $_FILES['logo_upload']['tmp_name'];
                $mime = mime_content_type($tmpName) ?: '';
                $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
                if (!isset($extensions[$mime])) {
                    $errors['logo_upload'] = 'Use a PNG, JPG, or WEBP logo.';
                } else {
                    $target = 'assets/images/system-logo.' . $extensions[$mime];
                    if (!move_uploaded_file($tmpName, APP_PUBLIC . '/' . $target)) {
                        $errors['logo_upload'] = 'Unable to save uploaded logo.';
                    } else {
                        $updates['organization.logo_path'] = $target;
                    }
                }
            }
        }
    }

    if ($section === 'display') {
        $sidebarState = (string) ($_POST['sidebar_default_state'] ?? 'expanded');
        $tableRows = (int) ($_POST['default_table_rows'] ?? 10);
        $dashboardRange = (string) ($_POST['dashboard_default_range'] ?? 'daily');
        $themeColor = (string) ($_POST['theme_color'] ?? 'green-gold');

        if (!in_array($sidebarState, ['expanded', 'collapsed'], true)) {
            $errors['sidebar_default_state'] = 'Choose expanded or collapsed.';
        }
        if (!in_array($tableRows, [10, 25, 50, 100], true)) {
            $errors['default_table_rows'] = 'Choose a supported row count.';
        }
        if (!in_array($dashboardRange, ['daily', 'weekly', 'monthly', 'annual'], true)) {
            $errors['dashboard_default_range'] = 'Choose a supported range.';
        }

        $updates = [
            'display.sidebar_default_state' => $sidebarState,
            'display.default_table_rows' => (string) $tableRows,
            'display.dashboard_default_range' => $dashboardRange,
            'display.theme_color' => $themeColor,
        ];
    }

    if ($section === 'reports') {
        $fieldMap = [
            'receipt_header' => 'reports.receipt_header',
            'or_number_format' => 'reports.or_number_format',
            'prepared_by_default' => 'reports.prepared_by_default',
            'reviewed_by_default' => 'reports.reviewed_by_default',
            'approved_by_default' => 'reports.approved_by_default',
            'footer_notes' => 'reports.footer_notes',
            'confidentiality_note' => 'reports.confidentiality_note',
        ];
        foreach ($fieldMap as $field => $key) {
            $updates[$key] = trim((string) ($_POST[$field] ?? ''));
        }
        if ($updates['reports.receipt_header'] === '') {
            $errors['receipt_header'] = 'Receipt header is required.';
        }
    }

    if ($section === 'security') {
        $maxAttempts = (int) ($_POST['maximum_login_attempts'] ?? 5);
        $lockDuration = (int) ($_POST['account_lock_duration'] ?? 15);
        $sessionTimeout = (int) ($_POST['session_timeout'] ?? 30);
        $passwordMinimum = (int) ($_POST['password_minimum_length'] ?? 8);

        if ($maxAttempts < 3 || $maxAttempts > 20) {
            $errors['maximum_login_attempts'] = 'Use 3 to 20 attempts.';
        }
        if ($lockDuration < 1 || $lockDuration > 240) {
            $errors['account_lock_duration'] = 'Use 1 to 240 minutes.';
        }
        if ($sessionTimeout < 5 || $sessionTimeout > 480) {
            $errors['session_timeout'] = 'Use 5 to 480 minutes.';
        }
        if ($passwordMinimum < 8 || $passwordMinimum > 64) {
            $errors['password_minimum_length'] = 'Use 8 to 64 characters.';
        }

        $updates = [
            'security.maximum_login_attempts' => (string) $maxAttempts,
            'security.account_lock_duration' => (string) $lockDuration,
            'security.session_timeout' => (string) $sessionTimeout,
            'security.password_minimum_length' => (string) $passwordMinimum,
            'security.require_strong_password' => isset($_POST['require_strong_password']) ? '1' : '0',
            'security.enable_session_logs' => isset($_POST['enable_session_logs']) ? '1' : '0',
        ];
    }

    if (!$errors && $updates) {
        save_system_settings($pdo, $updates, $user);
        audit_log($pdo, $user, 'update_system_settings', 'settings', 'section', $section, ['keys' => array_keys($updates)]);
        set_flash('success', 'Settings saved.');
        redirect('settings.php?section=' . urlencode($section));
    }
}

$settings = app_settings($pdo, true);
$backupFiles = glob(dirname(__DIR__) . '/backups/*.sql') ?: [];
rsort($backupFiles);

render_header('System Settings', $user);
?>

<p class="page-intro">Manage system identity, security, reports, and admin configuration.</p>

<style>
    .settings-layout { display: grid; gap: 1rem; grid-template-columns: minmax(190px, 250px) minmax(0, 1fr); }
    .settings-menu a { display: block; border-radius: 0.5rem; padding: 0.75rem 0.875rem; font-weight: 700; color: #244236; }
    .settings-menu a.active { background: #f2c230; color: #102018; }
    .settings-menu a:not(.active):hover { background: #eef6ef; }
    .settings-card { border: 1px solid #dfe9df; border-radius: 0.625rem; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
    .permission-grid { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    .permission-card { border: 1px solid #dfe9df; border-radius: 0.5rem; padding: 1rem; }
    .permission-card li { margin-top: 0.4rem; color: #475569; font-size: 0.875rem; }
    @media (max-width: 900px) { .settings-layout { grid-template-columns: 1fr; } }
</style>

<div class="settings-layout">
    <aside class="settings-card p-3">
        <nav class="settings-menu" aria-label="Settings categories">
            <?php foreach ($sections as $key => $label): ?>
                <a class="<?= $section === $key ? 'active' : '' ?>" href="settings.php?section=<?= h($key) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <section class="settings-card">
        <div class="section-heading">
            <div>
                <h3><?= h($sections[$section]) ?></h3>
            </div>
        </div>

        <?php if ($section === 'roles'): ?>
            <div class="p-5">
                <div class="permission-grid">
                    <div class="permission-card">
                        <h4 class="font-bold text-slate-950">Admin</h4>
                        <ul>
                            <li>Full access</li>
                            <li>Approval requests</li>
                            <li>User management</li>
                            <li>Reports</li>
                            <li>Security logs</li>
                            <li>Backup</li>
                        </ul>
                    </div>
                    <div class="permission-card">
                        <h4 class="font-bold text-slate-950">Staff</h4>
                        <ul>
                            <li>Cannot delete records</li>
                            <li>Cannot manage users</li>
                            <li>Cannot access security logs</li>
                            <li>Cannot create backups</li>
                            <li>Cannot approve requests</li>
                            <li>Cannot edit approved financial records</li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php elseif ($section === 'backup'): ?>
            <div class="p-5">
                <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <p class="muted mb-0">Store downloaded backups outside the local server.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="section" value="backup">
                        <input type="hidden" name="action" value="create_backup">
                        <button type="submit">Create Backup Now</button>
                    </form>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>File Name</th><th>Created</th><th>Size</th><th>Download</th></tr></thead>
                        <tbody>
                        <?php if (!$backupFiles): ?>
                            <tr><td colspan="4" class="muted">No backups yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($backupFiles as $path): ?>
                            <?php $name = basename($path); ?>
                            <tr>
                                <td><?= h($name) ?></td>
                                <td><?= h(date('Y-m-d H:i:s', (int) filemtime($path))) ?></td>
                                <td><?= h(number_format((float) filesize($path) / 1024, 2)) ?> KB</td>
                                <td><a class="btn alt" href="settings.php?section=backup&download=<?= urlencode($name) ?>">Download</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <form method="post" enctype="multipart/form-data" class="p-5">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="section" value="<?= h($section) ?>">
                <input type="hidden" name="action" value="save_settings">

                <?php if ($section === 'organization'): ?>
                    <div class="form-grid">
                        <?php
                        $fields = [
                            'university_name' => ['University Name', 'organization.university_name'],
                            'campus_name' => ['Campus Name', 'organization.campus_name'],
                            'office_name' => ['Office Name', 'organization.office_name'],
                            'system_name' => ['System Name', 'organization.system_name'],
                            'address' => ['Address', 'organization.address'],
                            'contact_information' => ['Contact Information', 'organization.contact_information'],
                        ];
                        ?>
                        <?php foreach ($fields as $field => [$label, $key]): ?>
                            <div class="<?= in_array($field, ['address', 'contact_information'], true) ? 'field-wide' : '' ?>">
                                <label for="<?= h($field) ?>"><?= h($label) ?></label>
                                <input id="<?= h($field) ?>" name="<?= h($field) ?>" value="<?= h((string) ($settings[$key] ?? '')) ?>" <?= in_array($field, ['university_name', 'campus_name', 'office_name', 'system_name'], true) ? 'required' : '' ?>>
                                <?php if (isset($errors[$field])): ?><p class="mt-1 text-xs font-semibold text-red-700"><?= h($errors[$field]) ?></p><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="field-wide">
                            <label for="logo_upload">Logo Upload</label>
                            <input id="logo_upload" name="logo_upload" type="file" accept="image/png,image/jpeg,image/webp">
                            <p class="mt-1 text-xs text-slate-500">Current logo: <?= h((string) ($settings['organization.logo_path'] ?? APP_LOGO)) ?></p>
                            <?php if (isset($errors['logo_upload'])): ?><p class="mt-1 text-xs font-semibold text-red-700"><?= h($errors['logo_upload']) ?></p><?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($section === 'display'): ?>
                    <div class="form-grid">
                        <div>
                            <label for="sidebar_default_state">Sidebar Default State</label>
                            <select id="sidebar_default_state" name="sidebar_default_state">
                                <option value="expanded" <?= ($settings['display.sidebar_default_state'] ?? '') === 'expanded' ? 'selected' : '' ?>>Expanded</option>
                                <option value="collapsed" <?= ($settings['display.sidebar_default_state'] ?? '') === 'collapsed' ? 'selected' : '' ?>>Collapsed</option>
                            </select>
                        </div>
                        <div>
                            <label for="default_table_rows">Default Table Rows Per Page</label>
                            <select id="default_table_rows" name="default_table_rows">
                                <?php foreach ([10, 25, 50, 100] as $rows): ?>
                                    <option value="<?= $rows ?>" <?= (int) ($settings['display.default_table_rows'] ?? 10) === $rows ? 'selected' : '' ?>><?= $rows ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="dashboard_default_range">Dashboard Default Range</label>
                            <select id="dashboard_default_range" name="dashboard_default_range">
                                <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'annual' => 'Annual'] as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= ($settings['display.dashboard_default_range'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="theme_color">Theme Color</label>
                            <input id="theme_color" name="theme_color" value="<?= h((string) ($settings['display.theme_color'] ?? 'green-gold')) ?>">
                        </div>
                    </div>
                <?php elseif ($section === 'reports'): ?>
                    <div class="form-grid">
                        <?php
                        $fields = [
                            'receipt_header' => ['Receipt Header', 'reports.receipt_header'],
                            'or_number_format' => ['OR Number Format', 'reports.or_number_format'],
                            'prepared_by_default' => ['Report Prepared By default', 'reports.prepared_by_default'],
                            'reviewed_by_default' => ['Report Reviewed By default', 'reports.reviewed_by_default'],
                            'approved_by_default' => ['Report Approved By default', 'reports.approved_by_default'],
                            'footer_notes' => ['Footer Notes', 'reports.footer_notes'],
                            'confidentiality_note' => ['Confidentiality Note', 'reports.confidentiality_note'],
                        ];
                        ?>
                        <?php foreach ($fields as $field => [$label, $key]): ?>
                            <div class="<?= in_array($field, ['footer_notes', 'confidentiality_note'], true) ? 'field-wide' : '' ?>">
                                <label for="<?= h($field) ?>"><?= h($label) ?></label>
                                <input id="<?= h($field) ?>" name="<?= h($field) ?>" value="<?= h((string) ($settings[$key] ?? '')) ?>">
                                <?php if (isset($errors[$field])): ?><p class="mt-1 text-xs font-semibold text-red-700"><?= h($errors[$field]) ?></p><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($section === 'security'): ?>
                    <div class="form-grid">
                        <div>
                            <label for="maximum_login_attempts">Maximum Login Attempts</label>
                            <input id="maximum_login_attempts" name="maximum_login_attempts" type="number" min="3" max="20" value="<?= h((string) ($settings['security.maximum_login_attempts'] ?? '5')) ?>">
                            <p class="mt-1 text-xs text-slate-500">Failed attempts before temporary lock.</p>
                            <?php if (isset($errors['maximum_login_attempts'])): ?><p class="mt-1 text-xs font-semibold text-red-700"><?= h($errors['maximum_login_attempts']) ?></p><?php endif; ?>
                        </div>
                        <div>
                            <label for="account_lock_duration">Account Lock Duration</label>
                            <input id="account_lock_duration" name="account_lock_duration" type="number" min="1" max="240" value="<?= h((string) ($settings['security.account_lock_duration'] ?? '15')) ?>">
                            <p class="mt-1 text-xs text-slate-500">Minutes to wait after lockout.</p>
                        </div>
                        <div>
                            <label for="session_timeout">Session Timeout</label>
                            <input id="session_timeout" name="session_timeout" type="number" min="5" max="480" value="<?= h((string) ($settings['security.session_timeout'] ?? '30')) ?>">
                            <p class="mt-1 text-xs text-slate-500">Idle minutes before logout.</p>
                        </div>
                        <div>
                            <label for="password_minimum_length">Password Minimum Length</label>
                            <input id="password_minimum_length" name="password_minimum_length" type="number" min="8" max="64" value="<?= h((string) ($settings['security.password_minimum_length'] ?? '8')) ?>">
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input type="checkbox" name="require_strong_password" value="1" <?= ($settings['security.require_strong_password'] ?? '1') === '1' ? 'checked' : '' ?>>
                            Require strong password
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input type="checkbox" name="enable_session_logs" value="1" <?= ($settings['security.enable_session_logs'] ?? '1') === '1' ? 'checked' : '' ?>>
                            Enable session logs
                        </label>
                    </div>
                <?php endif; ?>

                <div class="mt-5 flex justify-end gap-2">
                    <a class="btn alt" href="settings.php?section=<?= h($section) ?>">Reset</a>
                    <button type="submit">Save Changes</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>

<?php render_footer();
