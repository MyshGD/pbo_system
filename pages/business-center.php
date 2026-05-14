<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);
require_permission($user, 'manage_business_center');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        set_flash('error', 'Invalid form token.');
        redirect('business-center.php');
    }

    $sectionKey = trim((string) ($_POST['section_key'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($sectionKey === '' || $title === '') {
        set_flash('error', 'Section and title are required.');
        redirect('business-center.php');
    }

    $stmt = $pdo->prepare('INSERT INTO business_center_content (section_key, title, body, is_active, updated_by)
        VALUES (:section_key, :title, :body, :is_active, :updated_by)
        ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), is_active = VALUES(is_active), updated_by = VALUES(updated_by)');
    $stmt->execute([
        'section_key' => substr($sectionKey, 0, 80),
        'title' => $title,
        'body' => $body !== '' ? $body : null,
        'is_active' => $isActive,
        'updated_by' => (int) $user['id'],
    ]);
    audit_log($pdo, $user, 'update_landing_content', 'business_center', 'section', $sectionKey);
    set_flash('success', 'Landing page content updated.');
    redirect('business-center.php');
}

$sections = $pdo->query('SELECT section_key, title, body, is_active, updated_at FROM business_center_content ORDER BY FIELD(section_key, "hero", "mission_vision", "services", "features", "contact", "footer"), section_key')->fetchAll();
$byKey = [];
foreach ($sections as $section) {
    $byKey[(string) $section['section_key']] = $section;
}

// Ensure all expected sections exist
$requiredSections = [
    'hero' => 'Hero Section',
    'mission_vision' => 'Mission and Vision',
    'services' => 'Services Section',
    'features' => 'Features Section',
    'contact' => 'Visit Us Section',
    'footer' => 'Footer',
];

foreach ($requiredSections as $key => $label) {
    if (!isset($byKey[$key])) {
        $byKey[$key] = [
            'section_key' => $key,
            'title' => $label,
            'body' => '',
            'is_active' => 1,
            'updated_at' => null,
        ];
    }
}

$selectedSection = (string) ($_GET['section'] ?? 'hero');
if (!isset($byKey[$selectedSection])) {
    $selectedSection = 'hero';
}
$selected = $byKey[$selectedSection];

render_header('Landing Page Content', $user);
?>

<style>
    :root {
        --color-primary: #1B5E20;
        --color-primary-dark: #0D3818;
        --color-accent: #FFB81C;
        --color-success-light: #C8E6C9;
        --color-bg-light: #F7F9F6;
        --color-text-primary: #1B3A26;
        --color-text-secondary: #5A6B63;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 2rem;
        flex-wrap: wrap;
    }

    .page-header > div {
        flex: 1;
        min-width: 250px;
    }

    .page-header h1 {
        margin: 0 0 0.5rem 0;
        color: var(--color-primary);
        font-size: 1.875rem;
        font-weight: 700;
    }

    .page-header p {
        margin: 0;
        color: var(--color-text-secondary);
        font-size: 0.9375rem;
    }

    .page-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .btn {
        padding: 0.625rem 1.25rem;
        border: none;
        border-radius: 0.375rem;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
        white-space: nowrap;
    }

    .btn-primary {
        background-color: var(--color-primary);
        color: white;
    }

    .btn-primary:hover {
        background-color: var(--color-primary-dark);
    }

    .btn-secondary {
        background-color: white;
        color: var(--color-primary);
        border: 1px solid var(--color-primary);
    }

    .btn-secondary:hover {
        background-color: var(--color-bg-light);
    }

    .content-manager {
        display: grid;
        grid-template-columns: minmax(200px, 220px) 1fr;
        gap: 2rem;
    }

    .section-list {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 0.5rem;
        padding: 0;
        height: fit-content;
        overflow-y: auto;
        overflow-x: hidden;
        max-height: 500px;
    }

    .section-item {
        padding: 1rem;
        border-bottom: 1px solid #E5E7EB;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        text-decoration: none;
        color: var(--color-text-primary);
        transition: all 0.2s ease;
    }

    .section-item:last-child {
        border-bottom: none;
    }

    .section-item:hover {
        background-color: var(--color-bg-light);
    }

    .section-item.active {
        background-color: #E8F5E9;
        border-left: 3px solid var(--color-accent);
        padding-left: calc(1rem - 3px);
        color: var(--color-primary);
        font-weight: 600;
    }

    .section-item-icon {
        width: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.125rem;
        flex-shrink: 0;
    }

    .section-item-content {
        flex: 1;
        min-width: 0;
    }

    .section-item-name {
        font-size: 0.875rem;
        font-weight: 500;
        margin: 0;
        word-break: break-word;
        overflow-wrap: break-word;
    }

    .section-item-badge {
        font-size: 0.65rem;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-weight: 600;
        white-space: nowrap;
    }

    .section-item-badge.active {
        background-color: var(--color-success-light);
        color: var(--color-primary);
    }

    .section-item-badge.inactive {
        background-color: #FEE2E2;
        color: #991B1B;
    }

    .editor-panel {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 0.5rem;
        padding: 2rem;
    }

    .editor-header {
        margin-bottom: 1.5rem;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid #E5E7EB;
    }

    .editor-header h2 {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--color-primary);
        margin: 0 0 0.5rem 0;
    }

    .editor-header p {
        font-size: 0.875rem;
        color: var(--color-text-secondary);
        margin: 0;
    }

    .form-field {
        margin-bottom: 1.5rem;
    }

    .form-field label {
        display: block;
        margin-bottom: 0.5rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--color-text-primary);
    }

    .form-field input,
    .form-field textarea {
        width: 100%;
        padding: 0.625rem 0.75rem;
        border: 1px solid #D1D5DB;
        border-radius: 0.375rem;
        font-size: 0.875rem;
        background: white;
        color: var(--color-text-primary);
        font-family: inherit;
        transition: all 0.2s ease;
    }

    .form-field input:focus,
    .form-field textarea:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(27, 94, 32, 0.1);
    }

    .form-field textarea {
        min-height: clamp(80px, 20vh, 200px);
        resize: vertical;
    }

    .form-field-hint {
        font-size: 0.75rem;
        color: var(--color-text-secondary);
        margin-top: 0.375rem;
    }

    .form-actions {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid #E5E7EB;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }

    .checkbox-field {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .checkbox-field input {
        width: auto;
        margin: 0;
    }

    .checkbox-field label {
        margin: 0;
        font-weight: 500;
    }

    .flash-message {
        margin-bottom: 1rem;
        padding: 0.875rem;
        border-radius: 0.375rem;
        font-size: 0.875rem;
        font-weight: 500;
        border: 1px solid;
    }

    .flash-message.success {
        background-color: #DCFCE7;
        border-color: #BBF7D0;
        color: #166534;
    }

    .flash-message.error {
        background-color: #FEE2E2;
        border-color: #FECACA;
        color: #B91C1C;
    }

    @media (max-width: 1024px) {
        .content-manager {
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        .section-list {
            display: flex;
            overflow-x: auto;
            overflow-y: hidden;
            max-height: auto;
            max-width: 100%;
            scrollbar-width: thin;
        }

        .section-item {
            flex: 0 0 auto;
            border-bottom: 2px solid #E5E7EB;
            border-right: 1px solid #E5E7EB;
            padding: 0.75rem 1rem;
            border-left: none;
            min-width: clamp(100px, calc(100% / 3), 150px);
            text-align: center;
            justify-content: center;
            flex-direction: column;
        }

        .section-item:last-child {
            border-right: none;
        }

        .section-item.active {
            border-left: none;
            border-bottom: 2px solid var(--color-accent);
            padding-left: 1rem;
        }

        .section-item-icon {
            display: block;
        }
    }

    @media (max-width: 768px) {
        .content-manager {
            gap: 1rem;
        }
        
        .editor-panel {
            padding: 1.5rem;
        }
    }

    @media (max-width: 640px) {
        .page-header {
            flex-direction: column;
        }

        .page-actions {
            width: 100%;
        }

        .page-actions .btn {
            flex: 1;
            text-align: center;
        }

        .content-manager {
            gap: 0.75rem;
        }

        .editor-panel {
            padding: 1rem;
        }

        .section-item {
            padding: 0.5rem 0.625rem;
            min-width: clamp(80px, 20vw, 120px);
            font-size: 0.75rem;
        }

        .section-item-name {
            font-size: 0.7rem;
        }

        .form-field textarea {
            min-height: clamp(60px, 30vh, 150px);
        }

        .preview-content {
            max-width: 100%;
        }
    }
</style>

<div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
    <p class="page-intro mb-0">Manage public landing page text, sections, and visibility.</p>
    <div class="actions-row lg:justify-end">
        <button class="btn alt" onclick="openPreview()">Preview Landing Page</button>
    </div>
</div>

<?php if ($flash = get_flash()): ?>
    <div class="flash-message <?= h($flash['type']) ?>">
        <?= h($flash['message']) ?>
    </div>
<?php endif; ?>

<div class="content-manager">
    <!-- Section List -->
    <div class="section-list">
        <?php $sectionIcons = [
            'hero' => 'HM',
            'mission_vision' => 'MV',
            'services' => 'SV',
            'features' => '✓',
            'contact' => 'CT',
            'footer' => 'FT',
        ]; ?>
        <?php foreach ($byKey as $key => $section): ?>
            <a href="?section=<?= h($key) ?>" class="section-item <?= $selectedSection === $key ? 'active' : '' ?>">
                <div class="section-item-icon"><?= h($sectionIcons[$key] ?? '•') ?></div>
                <div class="section-item-content">
                    <p class="section-item-name"><?= h(ucwords(str_replace('_', ' ', (string) $key))) ?></p>
                    <span class="section-item-badge <?= ((int) $section['is_active']) === 1 ? 'active' : 'inactive' ?>">
                        <?= ((int) $section['is_active']) === 1 ? 'Active' : 'Hidden' ?>
                    </span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Editor Panel -->
    <div class="editor-panel">
        <div class="editor-header">
            <h2><?= h(ucwords(str_replace('_', ' ', (string) $selectedSection))) ?></h2>
            <p>Edit and manage this section's content and visibility.</p>
        </div>

        <form method="post" id="editor-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="section_key" value="<?= h($selectedSection) ?>">

            <?php if ($selectedSection === 'hero'): ?>
                <div class="form-field">
                    <label for="title">Main Title</label>
                    <input id="title" type="text" name="title" value="<?= h($selected['title']) ?>" placeholder="e.g., Business Center" required>
                </div>
                <div class="form-field">
                    <label for="body">Description</label>
                    <textarea id="body" name="body" placeholder="Hero section description..."><?= h($selected['body'] ?? '') ?></textarea>
                    <p class="form-field-hint">Displayed under the main title on the hero section.</p>
                </div>

            <?php elseif ($selectedSection === 'mission_vision'): ?>
                <div class="form-field">
                    <label for="title">Section Title</label>
                    <input id="title" type="text" name="title" value="<?= h($selected['title']) ?>" placeholder="e.g., Mission and Vision" required>
                </div>
                <div class="form-field">
                    <label for="body">Mission and Vision Text</label>
                    <textarea id="body" name="body" placeholder="Vision: Enter the official vision statement&#10;&#10;Mission: Enter the official mission statement"><?= h($selected['body'] ?? '') ?></textarea>
                    <p class="form-field-hint">Use the format Vision: ... then Mission: ... so the landing page can display them in separate panels.</p>
                </div>

            <?php elseif ($selectedSection === 'services'): ?>
                <div class="form-field">
                    <label for="title">Section Title</label>
                    <input id="title" type="text" name="title" value="<?= h($selected['title']) ?>" placeholder="e.g., Services" required>
                </div>
                <div class="form-field">
                    <label for="body">Section Description</label>
                    <textarea id="body" name="body" placeholder="Brief description of the services section..."><?= h($selected['body'] ?? '') ?></textarea>
                    <p class="form-field-hint">Services displayed: POS and Sales, Printing and Photocopying, Toga and Rentals, Proposals and Projects.</p>
                </div>

            <?php elseif ($selectedSection === 'features'): ?>
                <div class="form-field">
                    <label for="title">Section Title</label>
                    <input id="title" type="text" name="title" value="<?= h($selected['title']) ?>" placeholder="e.g., What the system helps manage" required>
                </div>
                <div class="form-field">
                    <label for="body">Features List</label>
                    <textarea id="body" name="body" placeholder="One feature per line:&#10;Sales records&#10;Inventory tracking&#10;Cash flow monitoring&#10;Rental operations&#10;Fishpond operations&#10;Proposal requests&#10;Logbook records&#10;Report generation"><?= h($selected['body'] ?? '') ?></textarea>
                    <p class="form-field-hint">Enter one feature per line. Each displays with a checkmark.</p>
                </div>

            <?php elseif ($selectedSection === 'contact'): ?>
                <div class="form-field">
                    <label for="title">Section Title</label>
                    <input id="title" type="text" name="title" value="<?= h($selected['title']) ?>" placeholder="e.g., Visit Us" required>
                </div>
                <div class="form-field">
                    <label for="body">Contact Information</label>
                    <textarea id="body" name="body" placeholder="Campus location, office address, contact details..."><?= h($selected['body'] ?? '') ?></textarea>
                    <p class="form-field-hint">Contact details displayed on the landing page. Leave empty for minimal contact section.</p>
                </div>

            <?php elseif ($selectedSection === 'footer'): ?>
                <div class="form-field">
                    <label for="title">Footer Text</label>
                    <input id="title" type="text" name="title" value="<?= h($selected['title']) ?>" placeholder="e.g., Production and Business Operation Record Management System" required>
                </div>
                <div class="form-field">
                    <label for="body">Footer Description</label>
                    <textarea id="body" name="body" placeholder="Additional footer text or copyright information..."><?= h($selected['body'] ?? '') ?></textarea>
                    <p class="form-field-hint">Displayed in the footer section of the landing page.</p>
                </div>

            <?php endif; ?>

            <div class="form-actions">
                <div class="checkbox-field">
                    <input type="checkbox" id="is_active" name="is_active" value="1" <?= ((int) $selected['is_active']) === 1 ? 'checked' : '' ?>>
                    <label for="is_active">Active on landing page</label>
                </div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>



<script>
    function openPreview() {
        window.open('index.php', '_blank', 'width=1200,height=800');
    }

    function closePreview() {
        // Window opened with target="_blank" closes with user action
    }

    function setPreviewSize(size) {
        // Preview size is handled by the window open() dimensions
    }

    // Legacy function calls for compatibility
    document.addEventListener('DOMContentLoaded', function() {
        const previewBtn = document.querySelector('[onclick="openPreview()"]');
        if (previewBtn) {
            previewBtn.onclick = function() { openPreview(); return false; };
        }
    });
</script>

<?php render_footer();
