<?php
declare(strict_types=1);

// ──────────────────────────────────────────────────────────────
// STATUS BADGE
// ──────────────────────────────────────────────────────────────
function render_status_badge(string $status): void
{
    $normalized = strtolower(trim(str_replace('_', ' ', $status)));
    $label = match ($normalized) {
        'submitted', 'under review' => 'Pending',
        'needs revision'            => 'Needs Revision',
        default                     => ucwords($normalized),
    };
    $class = preg_replace('/[^a-z0-9_-]+/', '-', $normalized) ?: 'status';
    echo '<span class="status-pill ' . h($class) . '">' . h($label) . '</span>';
}

// ──────────────────────────────────────────────────────────────
// EMPTY STATE  (H4 – consistency)
// ──────────────────────────────────────────────────────────────
function render_empty_state(string $title, string $message, ?string $buttonLabel = null, ?string $modalId = null): void
{
    echo '<div class="empty-state">';
    echo '<p class="empty-title">' . h($title) . '</p>';
    echo '<p class="empty-body">' . h($message) . '</p>';
    if ($buttonLabel !== null && $modalId !== null) {
        echo '<button type="button" class="btn mt-4" data-open-modal="' . h($modalId) . '">' . h($buttonLabel) . '</button>';
    }
    echo '</div>';
}

// ──────────────────────────────────────────────────────────────
// PAGE HEADER HELPER
// ──────────────────────────────────────────────────────────────
function render_page_header(string $subtitle = '', ?string $primaryLabel = null, ?string $modalId = null, ?string $href = null): void
{
    echo '<div class="page-header-bar">';
    if ($subtitle !== '') {
        echo '<p class="page-intro">' . h($subtitle) . '</p>';
    } else {
        echo '<span></span>';
    }
    if ($primaryLabel !== null) {
        if ($modalId !== null) {
            echo '<button type="button" class="btn" data-open-modal="' . h($modalId) . '">' . h($primaryLabel) . '</button>';
        } elseif ($href !== null) {
            echo '<a class="btn" href="' . h($href) . '">' . h($primaryLabel) . '</a>';
        }
    }
    echo '</div>';
}

function render_record_count_badge(int $count, string $singular = 'record', ?string $plural = null): void
{
    $label = $count === 1 ? $singular : ($plural ?? $singular . 's');
    echo '<span class="badge">' . h((string) $count) . ' ' . h($label) . '</span>';
}

function render_table_header(string $title, ?int $count = null, string $singular = 'record', ?string $plural = null): void
{
    echo '<div class="section-heading">';
    echo '<h3>' . h($title) . '</h3>';
    if ($count !== null) {
        render_record_count_badge($count, $singular, $plural);
    }
    echo '</div>';
}

// ──────────────────────────────────────────────────────────────
// NAV ICON  (outline SVG; no emoji, no filled icons — H8)
// ──────────────────────────────────────────────────────────────
function nav_icon(string $name): string
{
    $paths = [
        'dashboard'  => '<rect x="3" y="3" width="7" height="8" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="15" width="7" height="6" rx="1.5"/>',
        'sales'      => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h8.95a2 2 0 0 0 1.96-1.6L21 7H5.12"/>',
        'inventory'  => '<path d="m21 8-9-5-9 5 9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'projects'   => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
        'records'    => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M8 13h8"/><path d="M8 17h5"/>',
        'admin'      => '<path d="M12 20a8 8 0 0 0 8-8V6l-8-3-8 3v6a8 8 0 0 0 8 8Z"/><path d="m9 12 2 2 4-4"/>',
        'pos'        => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'receipt'    => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 7h8"/><path d="M8 11h8"/><path d="M8 15h5"/>',
        'wallet'     => '<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5"/><path d="M18 12h.01"/>',
        'fishpond'   => '<path d="M6.5 12c2-3 5.5-4 9-2l4.5 2-4.5 2c-3.5 2-7 1-9-2Z"/><path d="m4 10 2.5 2L4 14"/><circle cx="14" cy="12" r=".5" fill="currentColor"/>',
        'rental'     => '<path d="M3 21h18"/><path d="M5 21V7l8-4 6 4v14"/><path d="M9 21v-6h6v6"/><path d="M9 9h.01"/><path d="M15 9h.01"/>',
        'proposal'   => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'logbook'    => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/>',
        'report'     => '<path d="M3 3v18h18"/><path d="M8 17V9"/><path d="M13 17V5"/><path d="M18 17v-4"/>',
        'landing'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M8 15h4"/>',
        'users'      => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'security'   => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'backup'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
        'settings'   => '<circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>',
        'approvals'  => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    ];
    $body = $paths[$name] ?? $paths['dashboard'];
    return '<svg class="nav-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}

// ──────────────────────────────────────────────────────────────
// RENDER HEADER
//
// HCI improvements applied:
//  H1  – Skip link so keyboard users can jump past nav immediately
//  H1  – Flash messages rendered in PHP (not deferred JS) — instant visibility
//  H3  – Mobile: dedicated hamburger in top bar, backdrop dismissal
//  H4  – Single attribute convention: data-open-modal / data-close-modal everywhere
//  H6  – Nav labels always visible; collapse only on desktop ≥1024px
//  H7  – aria-current="page" on active link for screen readers
//  H8  – Sidebar uses semantic <nav><ul><li><a>, not <details>/<summary>
//  H10 – Tooltips on collapsed items via CSS :hover, no JS portal needed
// ──────────────────────────────────────────────────────────────
function render_header(string $title, ?array $user = null): void
{
    $current  = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
    $queryStr = $_SERVER['QUERY_STRING'] ?? '';
    parse_str($queryStr, $currentQuery);

    // ── Heuristic H7: active-link detection ─────────────────
    $isActive = static function (string $href) use ($current, $currentQuery): bool {
        $path  = parse_url($href, PHP_URL_PATH) ?: $href;
        if ($current !== $path) {
            return false;
        }
        $q = parse_url($href, PHP_URL_QUERY) ?: '';
        if ($q === '') {
            // bare page link — only active when no special query keys
            return !isset($currentQuery['category']) && !isset($currentQuery['rental_type']);
        }
        parse_str($q, $hq);
        return array_intersect_assoc($hq, $currentQuery) === $hq;
    };

    // ── Navigation groups ────────────────────────────────────
    $navGroups = [
        ['key' => 'overview', 'label' => 'Overview', 'icon' => 'dashboard', 'items' => [
            ['href' => 'dashboard.php',   'label' => 'Dashboard',   'icon' => 'dashboard'],
        ]],
        ['key' => 'sales', 'label' => 'Sales', 'icon' => 'sales', 'items' => [
            ['href' => 'sales.php',        'label' => 'POS',          'icon' => 'pos'],
            ['href' => 'sales-reports.php','label' => 'Transactions', 'icon' => 'receipt'],
            ['href' => 'cashflow.php',     'label' => 'Cash Flow',    'icon' => 'wallet'],
        ]],
        ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'inventory', 'items' => [
            ['href' => 'products.php',    'label' => 'Inventory',    'icon' => 'inventory'],
        ]],
        ['key' => 'projects', 'label' => 'Income Projects', 'icon' => 'projects', 'items' => [
            ['href' => 'projects.php',                                   'label' => 'Summary',   'icon' => 'projects'],
            ['href' => 'projects.php?category=fishpond',                 'label' => 'Fishpond',  'icon' => 'fishpond'],
            ['href' => 'projects.php?category=rental&rental_type=stall', 'label' => 'Rentals',   'icon' => 'rental'],
            ['href' => 'proposals.php',                                  'label' => 'Proposals', 'icon' => 'proposal'],
        ]],
        ['key' => 'records', 'label' => 'Records', 'icon' => 'records', 'items' => [
            ['href' => 'logbook.php', 'label' => 'Logbook', 'icon' => 'logbook'],
            ['href' => 'reports.php', 'label' => 'Reports',  'icon' => 'report'],
        ]],
        ['key' => 'admin', 'label' => 'Admin', 'icon' => 'admin', 'items' => [
            ['href' => 'approvals.php',      'label' => 'Approvals',      'icon' => 'approvals',
             'permission' => 'manage_approvals'],
            ['href' => 'business-center.php','label' => 'Landing Content','icon' => 'landing',
             'permission' => 'manage_business_center'],
            ['href' => 'users.php',          'label' => 'Users',          'icon' => 'users',
             'permission' => 'manage_users'],
            ['href' => 'settings.php',       'label' => 'Settings',       'icon' => 'settings',
             'permission' => 'manage_settings'],
            ['href' => 'security.php',       'label' => 'Security Logs',  'icon' => 'security',
             'permission' => 'view_security_logs'],
            ['href' => 'backup.php',         'label' => 'Backup',         'icon' => 'backup',
             'permission' => 'manage_backups'],
        ]],
    ];

    // Filter by permission, mark active, find active group
    $activeGroup = '';
    $visibleGroups = [];
    foreach ($navGroups as $group) {
        $items = [];
        foreach ($group['items'] as $item) {
            if ($user && isset($item['permission']) && !user_can($user, $item['permission'])) {
                continue;
            }
            $item['active'] = $isActive($item['href']);
            if ($item['active']) {
                $activeGroup = $group['key'];
            }
            $items[] = $item;
        }
        if (!$items) {
            continue;
        }
        $group['items']  = $items;
        $group['active'] = $activeGroup === $group['key'];
        $visibleGroups[] = $group;
    }

    // ── Flash message (rendered immediately — H1) ─────────────
    $flash = get_flash();

    // ── Organization profile ─────────────────────────────────
    $org = organization_profile($GLOBALS['pdo'] ?? null);

    // ── User initials for avatar ─────────────────────────────
    $initials = '';
    if ($user) {
        $fullName = trim((string) ($user['full_name'] ?? ''));
        $username = trim((string) ($user['username'] ?? 'U'));
        foreach ((preg_split('/\s+/', $fullName) ?: []) as $part) {
            if ($part !== '') {
                $initials .= strtoupper(substr($part, 0, 1));
            }
            if (strlen($initials) >= 2) {
                break;
            }
        }
        if ($initials === '') {
            $initials = strtoupper(substr($username, 0, 1));
        }
    }

    // ── Sidebar default collapsed state ──────────────────────
    $defaultCollapsed = app_setting($GLOBALS['pdo'] ?? null, 'display.sidebar_default_state', 'expanded') === 'collapsed' ? 'true' : 'false';

    // ════════════════════════════════════════════════════════
    //  HTML OUTPUT
    // ════════════════════════════════════════════════════════
    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' | ' . h(APP_NAME) . '</title>';
    echo '<script src="https://cdn.tailwindcss.com"></script>';

    // Tailwind config (brand palette only — keep minimal)
    echo '<script>
tailwind.config = {
  theme: {
    extend: {
      colors: {
        brand: {
          50:"#F7F9F6", 100:"#E7F1E8", 200:"#CFE6D2",
          600:"#1F6B3A", 700:"#14532D", 800:"#0F3F24", 900:"#0B2F1A"
        },
        gold: { 50:"#FFF8E1", 100:"#F8E8A6", 400:"#E5B83E", 500:"#D6A51F", 700:"#8A650B" }
      }
    }
  }
};
</script>';

    // Chart.js
    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

    // ── Global CSS  ──────────────────────────────────────────
    echo '<style>
/* ── Custom properties ─────────────────────────────────── */
:root {
  --brand-bg:     #F7F9F6;
  --brand-surface:#FFFFFF;
  --brand-primary:#14532D;
  --brand-deep:   #0B2F1A;
  --brand-soft:   #E7F1E8;
  --brand-border: #CFE6D2;
  --brand-gold:   #D6A51F;
  --brand-gold-bg:#FFF8E1;
  --brand-text:   #102018;
  --brand-muted:  #4B5C50;
  --brand-danger: #991B1B;
  --sidebar-w:    15rem;
  --sidebar-w-sm: 4.5rem;
  --topbar-h:     4rem;
}

/* ── Skip link (H1 – visibility for keyboard users) ──────── */
.skip-link {
  position: absolute;
  left: -9999px;
  top: 0;
  z-index: 9999;
  background: var(--brand-gold);
  color: var(--brand-deep);
  padding: .5rem 1rem;
  font-weight: 700;
  font-size: .875rem;
  border-radius: 0 0 .375rem .375rem;
}
.skip-link:focus { left: 1rem; }

/* ── Flash / Toast (H1 – instant status feedback) ────────── */
#flash-area {
  position: fixed;
  top: calc(var(--topbar-h) + .75rem);
  right: 1rem;
  z-index: 200;
  display: flex;
  flex-direction: column;
  gap: .5rem;
  width: min(26rem, calc(100vw - 2rem));
  pointer-events: none;
}
.flash-toast {
  display: flex;
  align-items: flex-start;
  gap: .75rem;
  padding: .875rem 1rem;
  border-radius: .5rem;
  font-size: .875rem;
  font-weight: 500;
  pointer-events: auto;
  animation: slideIn .22s ease forwards;
  border: 1px solid;
}
.flash-toast.success { background:#F0FDF4; border-color:#BBF7D0; color:#166534; }
.flash-toast.error   { background:#FEF2F2; border-color:#FECACA; color:#991B1B; }
.flash-dismiss {
  margin-left: auto;
  flex-shrink: 0;
  cursor: pointer;
  opacity: .65;
  background: none;
  border: none;
  line-height: 1;
  font-size: 1rem;
  padding: 0;
  color: inherit;
}
.flash-dismiss:hover { opacity: 1; }
@keyframes slideIn {
  from { opacity: 0; transform: translateX(1rem); }
  to   { opacity: 1; transform: translateX(0); }
}

/* ── Top bar ────────────────────────────────────────────── */
.topbar {
  position: sticky;
  top: 0;
  z-index: 50;
  height: var(--topbar-h);
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0 1.25rem;
  background: rgba(255,255,255,.96);
  border-bottom: 1px solid var(--brand-border);
  backdrop-filter: blur(8px);
}
.topbar-brand {
  display: flex;
  align-items: center;
  gap: .75rem;
  text-decoration: none;
  flex-shrink: 0;
}
.topbar-logo {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 50%;
  object-fit: contain;
  border: 1px solid var(--brand-border);
  background: #fff;
  padding: .2rem;
  flex-shrink: 0;
}
.topbar-org { display: flex; flex-direction: column; }
.topbar-name  { font-size: .9rem; font-weight: 700; color: var(--brand-primary); line-height: 1.2; }
.topbar-sub   { font-size: .73rem; color: var(--brand-muted); line-height: 1.2; }
.topbar-spacer { flex: 1; }

/* H3 – Hamburger always accessible, labelled clearly */
.hamburger {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: .5rem;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--brand-primary);
  flex-shrink: 0;
}
.hamburger:hover { background: var(--brand-soft); }
.hamburger:focus-visible { outline: 2px solid var(--brand-gold); outline-offset: 2px; }
.hamburger svg { display: block; }

/* Page title in topbar on mobile */
.topbar-pagetitle {
  font-size: .9375rem;
  font-weight: 700;
  color: var(--brand-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1;
  min-width: 0;
}

/* User avatar / menu */
.user-menu-wrap { position: relative; flex-shrink: 0; }
.user-avatar-btn {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 50%;
  background: var(--brand-primary);
  color: #fff;
  font-size: .8125rem;
  font-weight: 700;
  border: 2px solid var(--brand-gold);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.user-avatar-btn:focus-visible { outline: 2px solid var(--brand-gold); outline-offset: 2px; }
.user-dropdown {
  position: absolute;
  right: 0;
  top: calc(100% + .5rem);
  width: 14rem;
  background: #fff;
  border: 1px solid var(--brand-border);
  border-radius: .75rem;
  padding: .75rem;
  box-shadow: 0 8px 24px rgba(16,32,24,.12);
  display: none;
  z-index: 100;
}
.user-dropdown.open { display: block; }
.user-dropdown-name  { font-weight: 700; font-size: .9rem; color: var(--brand-text); }
.user-dropdown-uname { font-size: .8rem; color: var(--brand-muted); }
.user-dropdown-role  {
  display: inline-block;
  margin-top: .4rem;
  font-size: .7rem;
  font-weight: 700;
  background: var(--brand-soft);
  color: var(--brand-primary);
  border-radius: 9999px;
  padding: .2rem .6rem;
}
.user-dropdown-links { margin-top: .75rem; padding-top: .75rem; border-top: 1px solid var(--brand-border); }
.user-dropdown-link {
  display: block;
  padding: .45rem .5rem;
  border-radius: .375rem;
  font-size: .875rem;
  font-weight: 600;
  color: var(--brand-text);
  text-decoration: none;
}
.user-dropdown-link:hover { background: var(--brand-soft); }
.user-dropdown-link.danger { color: var(--brand-danger); }
.user-dropdown-link.danger:hover { background: #FEF2F2; }

/* ── Backdrop (mobile sidebar — H3) ──────────────────────── */
.sidebar-backdrop {
  display: none;
  position: fixed;
  inset: var(--topbar-h) 0 0;
  background: rgba(11,47,26,.45);
  z-index: 39;
}
.sidebar-backdrop.open { display: block; }

/* ── Sidebar (H4 – consistent nav structure) ─────────────── */
.app-sidebar {
  position: fixed;
  top: var(--topbar-h);
  left: 0;
  bottom: 0;
  width: var(--sidebar-w);
  background: var(--brand-deep);
  color: #fff;
  overflow-y: auto;
  overflow-x: hidden;
  z-index: 40;
  display: flex;
  flex-direction: column;
  gap: 0;
  transition: transform .22s ease, width .22s ease;
  scrollbar-width: thin;
  scrollbar-color: rgba(255,255,255,.15) transparent;
}

/* Mobile: sidebar slides in from left */
@media (max-width: 1023px) {
  .app-sidebar { transform: translateX(-100%); }
  .app-sidebar.mobile-open { transform: translateX(0); }
  .sidebar-collapse-btn { display: none; }
}

/* Desktop: sidebar always visible, can collapse to icon rail */
@media (min-width: 1024px) {
  .app-sidebar { transform: translateX(0); position: sticky; top: var(--topbar-h); height: calc(100vh - var(--topbar-h)); }
  .app-sidebar.collapsed { width: var(--sidebar-w-sm); }
  .app-sidebar.collapsed .nav-label,
  .app-sidebar.collapsed .nav-group-label,
  .app-sidebar.collapsed .nav-chevron { display: none; }
  .app-sidebar.collapsed .nav-item-link,
  .app-sidebar.collapsed .nav-group-btn { justify-content: center; padding-left: 0; padding-right: 0; }
  /* H10 – tooltip on collapsed items */
  .app-sidebar.collapsed .has-tooltip { position: relative; }
  .app-sidebar.collapsed .has-tooltip:hover .nav-tooltip { display: block; }
  .nav-tooltip {
    display: none;
    position: absolute;
    left: calc(var(--sidebar-w-sm) + .5rem);
    top: 50%;
    transform: translateY(-50%);
    background: var(--brand-deep);
    color: #fff;
    font-size: .8125rem;
    font-weight: 600;
    white-space: nowrap;
    padding: .35rem .75rem;
    border-radius: .375rem;
    box-shadow: 0 4px 12px rgba(0,0,0,.25);
    pointer-events: none;
    z-index: 99;
  }
}

.sidebar-inner { padding: .5rem .625rem 1.5rem; flex: 1; }

/* ── Nav group ───────────────────────────────────────────── */
.nav-group { margin-bottom: .25rem; }
.nav-group-btn {
  display: flex;
  align-items: center;
  gap: .625rem;
  width: 100%;
  padding: .4rem .625rem;
  border: none;
  background: none;
  color: rgba(255,255,255,.55);
  font-size: .7rem;
  font-weight: 800;
  letter-spacing: .06em;
  text-transform: uppercase;
  cursor: pointer;
  border-radius: .375rem;
  text-align: left;
}
.nav-group-btn:hover  { color: rgba(255,255,255,.85); background: rgba(255,255,255,.06); }
.nav-group-label { flex: 1; }
.nav-chevron {
  transition: transform .2s;
  opacity: .55;
  flex-shrink: 0;
}
.nav-group-btn[aria-expanded="true"] .nav-chevron { transform: rotate(90deg); }

/* ── Nav items ──────────────────────────────────────────── */
.nav-items {
  list-style: none;
  margin: 0;
  padding: 0;
  overflow: hidden;
  max-height: 0;
  transition: max-height .22s ease;
}
.nav-items.open { max-height: 32rem; }

/* Single-item group (no collapsible) */
.nav-items.always-open { max-height: none; }

.nav-item-link {
  display: flex;
  align-items: center;
  gap: .625rem;
  padding: .5rem .625rem;
  border-radius: .5rem;
  color: rgba(255,255,255,.78);
  text-decoration: none;
  font-size: .875rem;
  font-weight: 600;
  transition: background .15s, color .15s;
}
.nav-item-link:hover {
  background: rgba(255,255,255,.1);
  color: #fff;
}
/* H7 – Keyboard focus ring visible in dark sidebar */
.nav-item-link:focus-visible {
  outline: 2px solid var(--brand-gold);
  outline-offset: 2px;
  color: #fff;
}
/* H1 – Active page is unmistakably highlighted (gold = attention) */
.nav-item-link[aria-current="page"] {
  background: var(--brand-gold);
  color: var(--brand-deep);
  font-weight: 700;
}
.nav-icon {
  width: 1.125rem;
  height: 1.125rem;
  flex-shrink: 0;
}

/* Collapse toggle button (desktop) */
.sidebar-collapse-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 .625rem .625rem auto;
  width: 2rem;
  height: 2rem;
  border-radius: .375rem;
  background: rgba(255,255,255,.08);
  border: none;
  color: rgba(255,255,255,.7);
  cursor: pointer;
  flex-shrink: 0;
}
.sidebar-collapse-btn:hover { background: rgba(255,255,255,.16); color: #fff; }
.sidebar-collapse-btn:focus-visible { outline: 2px solid var(--brand-gold); }

/* ── Main layout wrapper ────────────────────────────────── */
.app-body { display: flex; align-items: flex-start; min-height: calc(100vh - var(--topbar-h)); }
.main-content { flex: 1; min-width: 0; padding: 1.5rem 1.5rem 3rem; }
@media (min-width: 1024px) { .main-content { padding: 2rem 2.5rem 3rem; } }
.page-container { max-width: 80rem; margin: 0 auto; }

/* ── Loading overlay (H1 – status feedback on navigation) ── */
.page-loading {
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: rgba(247,249,246,.85);
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  visibility: hidden;
  transition: opacity .16s, visibility .16s;
  pointer-events: none;
  backdrop-filter: blur(3px);
}
.page-loading.active { opacity: 1; visibility: visible; pointer-events: auto; }
.loading-box {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: .875rem 1.25rem;
  background: #fff;
  border: 1px solid var(--brand-border);
  border-radius: .625rem;
  font-size: .9375rem;
  font-weight: 700;
  color: var(--brand-text);
  box-shadow: 0 12px 32px rgba(16,32,24,.15);
}
.loading-spinner {
  width: 1.5rem;
  height: 1.5rem;
  border: 3px solid var(--brand-soft);
  border-top-color: var(--brand-gold);
  border-radius: 50%;
  animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Page title ─────────────────────────────────────────── */
.page-title { font-size: 1.75rem; font-weight: 700; color: var(--brand-text); margin: 0 0 1.25rem; line-height: 1.2; }

/* ── Shared component classes (kept lean) ────────────────── */
.page-intro  { font-size: .9375rem; color: var(--brand-muted); margin: -.75rem 0 1.25rem; max-width: 54rem; line-height: 1.6; }
.page-header-bar { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: .75rem; margin-bottom: 1.25rem; }
.section-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; margin-bottom: .75rem; }
.actions-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
.card { background: #fff; border: 1px solid var(--brand-border); border-radius: .625rem; padding: 1.25rem; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
.table-card { background: #fff; border: 1px solid var(--brand-border); border-radius: .625rem; overflow: hidden; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
.table-card .section-heading { padding: .875rem 1rem; margin: 0; border-bottom: 1px solid var(--brand-border); }
.data-panel-filters { padding: .875rem 1rem; border-bottom: 1px solid var(--brand-border); background: var(--brand-bg); display: grid; gap: .625rem; grid-template-columns: repeat(auto-fill, minmax(10rem,1fr)); align-items: end; }
.data-panel-footer  { padding: .75rem 1rem; border-top: 1px solid var(--brand-border); font-size: .875rem; color: var(--brand-muted); }
.table-wrap { width: 100%; overflow-x: auto; }
.table-wrap table { width: 100%; min-width: max-content; border-collapse: collapse; font-size: .875rem; }
.table-wrap thead { background: var(--brand-soft); }
.table-wrap th { padding: .625rem .75rem; text-align: left; font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--brand-primary); white-space: nowrap; }
.table-wrap td { padding: .625rem .75rem; vertical-align: top; color: var(--brand-text); border-top: 1px solid var(--brand-border); }
.table-wrap tbody tr:hover { background: var(--brand-bg); }
.badge { display: inline-flex; align-items: center; border-radius: 9999px; background: var(--brand-soft); padding: .2rem .6rem; font-size: .75rem; font-weight: 700; color: var(--brand-primary); }
.status-pill { display: inline-flex; border-radius: 9999px; padding: .2rem .6rem; font-size: .75rem; font-weight: 700; background: #f1f5f9; color: #475569; text-transform: capitalize; }
.status-pill.active, .status-pill.approved, .status-pill.cash-in, .status-pill.released { background: var(--brand-soft); color: var(--brand-primary); }
.status-pill.pending, .status-pill.submitted, .status-pill.under-review, .status-pill.needs-revision { background: #FFF8E1; color: #8A650B; }
.status-pill.rejected, .status-pill.danger, .status-pill.cash-out, .status-pill.forfeited { background: #FEF2F2; color: var(--brand-danger); }
.stat { font-size: 1.625rem; font-weight: 700; color: var(--brand-text); margin-top: .2rem; }
.muted { font-size: .875rem; color: var(--brand-muted); }
.metric-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(11rem,1fr)); }
.form-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(12rem,1fr)); align-items: end; }
.field-wide { grid-column: 1/-1; }
.tabs { display: flex; gap: .25rem; border-bottom: 1px solid var(--brand-border); overflow-x: auto; margin-bottom: 1rem; }
.tab-link { padding: .625rem 1rem; font-size: .875rem; font-weight: 600; color: var(--brand-muted); text-decoration: none; border-bottom: 2px solid transparent; white-space: nowrap; }
.tab-link:hover { color: var(--brand-primary); border-bottom-color: var(--brand-gold); }
.tab-link.active { color: var(--brand-primary); border-bottom-color: var(--brand-gold); background: var(--brand-gold-bg); }
.tab-link:focus-visible { outline: 2px solid var(--brand-gold); outline-offset: -2px; }
.pagination { display: flex; flex-wrap: wrap; gap: .375rem; align-items: center; padding: .75rem 1rem; }
.page-link { padding: .375rem .75rem; border: 1px solid var(--brand-border); border-radius: .375rem; font-size: .8125rem; font-weight: 600; color: var(--brand-primary); text-decoration: none; background: #fff; }
.page-link:hover { background: var(--brand-soft); }
.page-link.active { background: var(--brand-primary); color: #fff; border-color: var(--brand-primary); }
.page-link.disabled { opacity: .4; pointer-events: none; }
.empty-state { padding: 2.5rem 1rem; text-align: center; }
.empty-title { font-weight: 700; font-size: .9375rem; color: var(--brand-text); margin: 0 0 .375rem; }
.empty-body  { font-size: .875rem; color: var(--brand-muted); margin: 0; }
.checkbox-field { display: flex; align-items: center; gap: .5rem; padding-top: 1.5rem; }
.action-menu { position: relative; display: inline-block; }
.action-menu summary { list-style: none; cursor: pointer; display: inline-flex; align-items: center; padding: .375rem .75rem; border: 1px solid var(--brand-border); border-radius: .375rem; font-size: .8125rem; font-weight: 600; color: var(--brand-primary); background: #fff; }
.action-menu summary::-webkit-details-marker { display: none; }
.action-menu summary:hover { background: var(--brand-soft); }
.action-menu-panel { position: absolute; right: 0; top: calc(100% + .25rem); min-width: 10rem; background: #fff; border: 1px solid var(--brand-border); border-radius: .5rem; padding: .25rem; box-shadow: 0 8px 24px rgba(16,32,24,.12); z-index: 60; }
.action-menu-item { display: block; width: 100%; text-align: left; padding: .4rem .625rem; font-size: .875rem; font-weight: 600; border-radius: .375rem; background: none; border: none; cursor: pointer; color: var(--brand-text); }
.action-menu-item:hover { background: var(--brand-soft); color: var(--brand-primary); }
.modal { border: none; border-radius: .75rem; padding: 0; box-shadow: 0 24px 64px rgba(0,0,0,.2); max-width: min(46rem, calc(100vw - 2rem)); width: 100%; }
.modal::backdrop { background: rgba(11,47,26,.45); }
.modal-wide { max-width: min(62rem, calc(100vw - 2rem)); }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid var(--brand-border); }
.modal-header h3 { margin: 0; font-size: 1rem; font-weight: 700; color: var(--brand-text); }
.modal-close { background: none; border: 1px solid var(--brand-border); border-radius: .375rem; cursor: pointer; padding: .25rem .625rem; font-size: .8125rem; font-weight: 600; color: var(--brand-muted); }
.modal-close:hover { background: var(--brand-soft); }
.modal form { padding: 1.25rem; }
.modal-actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--brand-border); }
.btn { display: inline-flex; align-items: center; justify-content: center; padding: .5rem .875rem; border-radius: .375rem; font-size: .875rem; font-weight: 700; cursor: pointer; border: none; background: var(--brand-primary); color: #fff; text-decoration: none; transition: background .15s; }
.btn:hover { background: var(--brand-deep); }
.btn:focus-visible { outline: 2px solid var(--brand-gold); outline-offset: 2px; }
.btn.alt { background: #fff; color: var(--brand-primary); border: 1px solid var(--brand-primary); }
.btn.alt:hover { background: var(--brand-soft); }
.btn:disabled { opacity: .5; cursor: not-allowed; }
label { display: block; font-size: .8125rem; font-weight: 600; color: var(--brand-text); margin-bottom: .3rem; }
input:not([type=hidden]):not([type=checkbox]):not([type=radio]),
select, textarea {
  display: block;
  width: 100%;
  padding: .5rem .75rem;
  border: 1.5px solid #d1d5db;
  border-radius: .375rem;
  font-size: .9375rem;
  font-family: inherit;
  background: #fff;
  color: var(--brand-text);
  transition: border-color .15s;
}
input:not([type=hidden]):not([type=checkbox]):not([type=radio]):focus,
select:focus, textarea:focus { outline: none; border-color: var(--brand-primary); box-shadow: 0 0 0 3px rgba(20,83,45,.1); }
textarea { min-height: 5rem; resize: vertical; }
.low-stock td { background: #FEF9F0; }
.no-print { }

/* ── Dashboard specific ──────────────────────────────────── */
.dashboard-card-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(13.5rem,1fr)); }
.dashboard-card { min-height: 7rem; padding: 1rem; }
.dashboard-link { cursor: pointer; text-decoration: none; transition: box-shadow .15s, border-color .15s, transform .15s; }
.dashboard-link:hover { border-color: var(--brand-gold); box-shadow: 0 4px 16px rgba(16,32,24,.1); transform: translateY(-1px); }
.dashboard-link:focus-visible { outline: 2px solid var(--brand-gold); outline-offset: 2px; }
.dashboard-card-cta { display: block; margin-top: .75rem; font-size: .75rem; font-weight: 700; color: var(--brand-primary); }
.dashboard-pos-card { border-color: var(--brand-gold) !important; background: var(--brand-gold-bg) !important; }
.dashboard-chart-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(20rem,1fr)); }
.dashboard-activity-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(22rem,1fr)); }
.chart-panel { border: 1px solid var(--brand-border); border-radius: .5rem; background: var(--brand-bg); padding: .875rem; }
.chart-panel h4 { font-size: .875rem; font-weight: 700; color: var(--brand-text); margin: 0 0 .625rem; }
.chart-frame { height: 16rem; }
.content-grid { display: grid; gap: 1rem; }
@media (min-width:1024px) { .content-grid { grid-template-columns: 1fr 1fr; } }

/* ── Print (H8 – only what's needed) ────────────────────── */
@media print {
  .topbar, .app-sidebar, .sidebar-backdrop, .flash-area, .page-loading, .no-print, .btn, button { display: none !important; }
  .app-body { display: block; }
  .main-content { padding: 0; }
  body { background: #fff !important; color: #000 !important; }
  table { border-collapse: collapse !important; font-size: 10pt !important; }
  th, td { border: 1px solid #000 !important; padding: 5px !important; }
}
</style>';

    echo '</head>';
    echo '<body class="bg-[var(--brand-bg)] text-[var(--brand-text)] antialiased">';

    // H1 – Skip link (first focusable element on page)
    echo '<a class="skip-link" href="#main-content">Skip to main content</a>';

    // H1 – Flash messages rendered immediately (not via deferred JS)
    echo '<div id="flash-area" aria-live="polite" aria-atomic="false">';
    if ($flash) {
        $typeClass = $flash['type'] === 'error' ? 'error' : 'success';
        $icon      = $flash['type'] === 'error' ? '✕' : '✓';
        echo '<div class="flash-toast ' . $typeClass . '" role="alert">';
        echo '<span>' . $icon . ' ' . h($flash['message']) . '</span>';
        echo '<button class="flash-dismiss" onclick="this.closest(\'.flash-toast\').remove()" aria-label="Dismiss">✕</button>';
        echo '</div>';
    }
    echo '</div>';

    // Page-loading overlay (H1 – navigation status)
    echo '<div class="page-loading" id="page-loading" aria-hidden="true" role="status"><div class="loading-box"><span class="loading-spinner"></span><span>Loading…</span></div></div>';

    // ── Top bar ──────────────────────────────────────────────
    echo '<header class="topbar" role="banner">';

    if ($user) {
        // H3 – Hamburger in topbar, not inside sidebar
        echo '<button class="hamburger" id="sidebar-toggle" aria-label="Open navigation menu" aria-controls="app-sidebar" aria-expanded="false" type="button">';
        echo '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>';
        echo '</button>';
    }

    echo '<a class="topbar-brand" href="index.php" aria-label="' . h($org['campus_display_name']) . ' home">';
    echo '<img class="topbar-logo" src="' . h($org['logo_path']) . '" alt="' . h($org['campus_display_name']) . ' logo">';
    echo '<span class="topbar-org hidden sm:flex flex-col">';
    echo '<span class="topbar-name">' . h($org['campus_display_name']) . '</span>';
    echo '<span class="topbar-sub hidden lg:block">' . h($org['system_name']) . '</span>';
    echo '</span>';
    echo '</a>';

    // Current page title in topbar on small screens
    if ($user) {
        echo '<span class="topbar-pagetitle lg:hidden" aria-hidden="true">' . h($title) . '</span>';
        echo '<span class="topbar-spacer hidden lg:block"></span>';
    }

    if ($user) {
        $uFullName = trim((string) ($user['full_name'] ?? ''));
        $uUsername = trim((string) ($user['username'] ?? 'user'));
        $uRole     = trim((string) ($user['role'] ?? ''));

        echo '<div class="user-menu-wrap">';
        echo '<button class="user-avatar-btn" id="user-avatar-btn" aria-label="Account menu for ' . h($uFullName ?: $uUsername) . '" aria-haspopup="true" aria-expanded="false" type="button">' . h($initials) . '</button>';
        echo '<div class="user-dropdown" id="user-dropdown" role="menu">';
        echo '<div class="user-dropdown-name">' . h($uFullName ?: $uUsername) . '</div>';
        echo '<div class="user-dropdown-uname">@' . h($uUsername) . '</div>';
        if ($uRole) {
            echo '<span class="user-dropdown-role">' . h(ucfirst($uRole)) . '</span>';
        }
        echo '<div class="user-dropdown-links">';
        echo '<a class="user-dropdown-link" href="profile.php" role="menuitem">Account settings</a>';
        echo '<a class="user-dropdown-link danger" href="logout.php" role="menuitem">Log out</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    echo '</header>';

    // ════════════════════════════════════════════════════════
    //  SIDEBAR (H4, H6, H7, H8)
    // ════════════════════════════════════════════════════════
    if ($user) {
        // Backdrop for mobile
        echo '<div class="sidebar-backdrop" id="sidebar-backdrop" aria-hidden="true"></div>';

        echo '<div class="app-body">';

        // H8 – Sidebar uses semantic nav > ul > li > a
        echo '<nav class="app-sidebar" id="app-sidebar" aria-label="Main navigation" data-active-group="' . h($activeGroup) . '" data-default-collapsed="' . h($defaultCollapsed) . '">';

        // Desktop collapse toggle
        echo '<div style="display:flex;justify-content:flex-end;padding:.5rem .625rem 0;">';
        echo '<button class="sidebar-collapse-btn" id="sidebar-collapse-btn" aria-label="Collapse sidebar" title="Collapse sidebar" type="button">';
        echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>';
        echo '</button>';
        echo '</div>';

        echo '<div class="sidebar-inner">';

        foreach ($visibleGroups as $group) {
            $gKey     = h($group['key']);
            $gId      = 'nav-group-' . $gKey;
            $isOpen   = $group['active'] || count($group['items']) === 1;
            $expanded = $isOpen ? 'true' : 'false';
            $single   = count($group['items']) === 1;

            echo '<div class="nav-group">';

            if (!$single) {
                echo '<button class="nav-group-btn has-tooltip" type="button" aria-expanded="' . $expanded . '" aria-controls="' . $gId . '" data-group="' . $gKey . '">';
                echo nav_icon($group['icon']);
                echo '<span class="nav-group-label">' . h($group['label']) . '</span>';
                echo '<svg class="nav-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>';
                echo '<span class="nav-tooltip">' . h($group['label']) . '</span>';
                echo '</button>';
            }

            echo '<ul class="nav-items' . ($single ? ' always-open' : ($isOpen ? ' open' : '')) . '" id="' . $gId . '" role="list">';
            foreach ($group['items'] as $item) {
                $iActive  = !empty($item['active']);
                $ariaCurr = $iActive ? ' aria-current="page"' : '';
                echo '<li class="has-tooltip">';
                echo '<a class="nav-item-link"' . $ariaCurr . ' href="' . h($item['href']) . '">';
                echo nav_icon($item['icon']);
                echo '<span class="nav-label">' . h($item['label']) . '</span>';
                echo '</a>';
                echo '<span class="nav-tooltip">' . h($item['label']) . '</span>';
                echo '</li>';
            }
            echo '</ul>';

            echo '</div>'; // .nav-group
        }

        echo '</div>'; // .sidebar-inner
        echo '</nav>'; // app-sidebar

        // ── Main content ─────────────────────────────────────
        echo '<main class="main-content" id="main-content" tabindex="-1">';
        echo '<div class="page-container">';
        echo '<h1 class="page-title">' . h($title) . '</h1>';

    } else {
        // Unauthenticated: no sidebar
        echo '<main id="main-content" style="padding:1.5rem;" tabindex="-1">';
        echo '<div>';
    }
}

// ──────────────────────────────────────────────────────────────
// RENDER FOOTER
// ──────────────────────────────────────────────────────────────
function render_footer(): void
{
    // Close main content containers
    echo '</div>'; // .page-container
    echo '</main>';
    echo '</div>'; // .app-body

    // Chart.js script
    echo '<script src="' . h(app_base_path() . 'assets/charts.js') . '" defer></script>';

    // ── Navigation JavaScript (H3, H4, H6, H7) ──────────────
    echo '<script>
(function () {
"use strict";

// ── Sidebar toggle (mobile — H3) ─────────────────────────
var sidebar   = document.getElementById("app-sidebar");
var backdrop  = document.getElementById("sidebar-backdrop");
var toggleBtn = document.getElementById("sidebar-toggle");

function openMobileSidebar() {
  if (!sidebar) return;
  sidebar.classList.add("mobile-open");
  backdrop.classList.add("open");
  if (toggleBtn) { toggleBtn.setAttribute("aria-expanded","true"); toggleBtn.setAttribute("aria-label","Close navigation menu"); }
  document.body.style.overflow = "hidden";
  // move focus to first nav link — H7 keyboard efficiency
  var first = sidebar.querySelector(".nav-item-link");
  if (first) first.focus();
}
function closeMobileSidebar() {
  if (!sidebar) return;
  sidebar.classList.remove("mobile-open");
  backdrop.classList.remove("open");
  if (toggleBtn) { toggleBtn.setAttribute("aria-expanded","false"); toggleBtn.setAttribute("aria-label","Open navigation menu"); }
  document.body.style.overflow = "";
}

if (toggleBtn) toggleBtn.addEventListener("click", function() {
  if (sidebar && sidebar.classList.contains("mobile-open")) closeMobileSidebar();
  else openMobileSidebar();
});
if (backdrop) backdrop.addEventListener("click", closeMobileSidebar);
// H7 – Escape key closes sidebar
document.addEventListener("keydown", function(e) {
  if (e.key === "Escape") closeMobileSidebar();
});

// ── Sidebar collapse (desktop — H6, H8) ──────────────────
var collapseBtn = document.getElementById("sidebar-collapse-btn");
var COLLAPSE_KEY = "bpNavCollapsed";

function applyCollapsed(collapsed) {
  if (!sidebar) return;
  sidebar.classList.toggle("collapsed", collapsed);
  if (collapseBtn) {
    collapseBtn.setAttribute("aria-label", collapsed ? "Expand sidebar" : "Collapse sidebar");
    collapseBtn.title = collapsed ? "Expand sidebar" : "Collapse sidebar";
    // rotate chevron icon
    var icon = collapseBtn.querySelector("svg");
    if (icon) icon.style.transform = collapsed ? "rotate(180deg)" : "";
  }
}
if (sidebar) {
  var defaultCollapsed = sidebar.getAttribute("data-default-collapsed") === "true";
  var saved = localStorage.getItem(COLLAPSE_KEY);
  var isCollapsed = saved !== null ? saved === "1" : defaultCollapsed;
  applyCollapsed(isCollapsed);

  if (collapseBtn) collapseBtn.addEventListener("click", function() {
    isCollapsed = !sidebar.classList.contains("collapsed");
    applyCollapsed(isCollapsed);
    localStorage.setItem(COLLAPSE_KEY, isCollapsed ? "1" : "0");
  });
}

// ── Nav group expand/collapse (H3 — user control) ────────
var GROUP_KEY = "bpNavGroups";
function readGroups()  { try { return JSON.parse(localStorage.getItem(GROUP_KEY) || "{}"); } catch(e){ return {}; } }
function saveGroups(g) { localStorage.setItem(GROUP_KEY, JSON.stringify(g)); }

var activeGroup = sidebar ? sidebar.getAttribute("data-active-group") : "";
var groupBtns   = document.querySelectorAll("[data-group]");
var savedGroups = readGroups();

groupBtns.forEach(function(btn) {
  var key     = btn.getAttribute("data-group");
  var listId  = btn.getAttribute("aria-controls");
  var list    = listId ? document.getElementById(listId) : null;
  // determine initial open state
  var shouldOpen = key === activeGroup || (!activeGroup && savedGroups[key]);
  if (list) list.classList.toggle("open", !!shouldOpen);
  btn.setAttribute("aria-expanded", shouldOpen ? "true" : "false");

  btn.addEventListener("click", function() {
    var open = list && list.classList.contains("open");
    var next = !open;
    // H4 – only one group open at a time (accordion pattern)
    groupBtns.forEach(function(b) {
      var bList = document.getElementById(b.getAttribute("aria-controls"));
      var bKey  = b.getAttribute("data-group");
      var bOpen = bKey === key ? next : false;
      if (bList) bList.classList.toggle("open", bOpen);
      b.setAttribute("aria-expanded", bOpen ? "true" : "false");
    });
    var g = {};
    groupBtns.forEach(function(b) { g[b.getAttribute("data-group")] = b.getAttribute("aria-expanded") === "true"; });
    saveGroups(g);
  });
});

// ── User dropdown (H4 – consistent pattern) ──────────────
var avatarBtn  = document.getElementById("user-avatar-btn");
var userDrop   = document.getElementById("user-dropdown");
function toggleDropdown() {
  if (!userDrop) return;
  var open = userDrop.classList.toggle("open");
  if (avatarBtn) avatarBtn.setAttribute("aria-expanded", open ? "true" : "false");
}
if (avatarBtn) avatarBtn.addEventListener("click", function(e) { e.stopPropagation(); toggleDropdown(); });
document.addEventListener("click", function(e) {
  if (userDrop && !userDrop.contains(e.target) && e.target !== avatarBtn) {
    userDrop.classList.remove("open");
    if (avatarBtn) avatarBtn.setAttribute("aria-expanded","false");
  }
});

// ── Modal system (H4 – single convention: data-open-modal / data-close-modal) ──
document.addEventListener("click", function(e) {
  var opener = e.target.closest("[data-open-modal]");
  if (opener) {
    var id = opener.getAttribute("data-open-modal");
    var modal = document.getElementById(id);
    if (modal && typeof modal.showModal === "function") {
      modal.showModal();
      // H7 – focus first interactive element in modal
      var focusable = modal.querySelectorAll("input,select,textarea,button:not([data-close-modal])");
      if (focusable.length) focusable[0].focus();
    }
    e.preventDefault();
    return;
  }
  var closer = e.target.closest("[data-close-modal]");
  if (closer) {
    var modal = closer.closest("dialog");
    if (modal) modal.close();
  }
});
// Click outside modal to close (H3 – freedom to exit)
document.querySelectorAll("dialog").forEach(function(dlg) {
  dlg.addEventListener("click", function(e) {
    if (e.target === dlg) dlg.close();
  });
  // H7 – Escape already closes native <dialog>; no override needed
});

// ── Page loading indicator (H1 — system status) ──────────
var loader = document.getElementById("page-loading");
var loaderTimer = null;
function showLoader() {
  if (!loader) return;
  loaderTimer = setTimeout(function() {
    loader.classList.add("active");
    loader.setAttribute("aria-hidden","false");
  }, 150);
}
function hideLoader() {
  clearTimeout(loaderTimer);
  if (!loader) return;
  loader.classList.remove("active");
  loader.setAttribute("aria-hidden","true");
}
window.addEventListener("pageshow", hideLoader);
document.addEventListener("click", function(e) {
  var link = e.target.closest("a[href]");
  if (!link || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  if (link.target && link.target !== "_self") return;
  if (link.getAttribute("href").startsWith("#")) return;
  try { var u = new URL(link.href, location.href); if (u.origin !== location.origin) return; } catch(x) { return; }
  showLoader();
});
document.addEventListener("submit", function(e) {
  if (!e.target.target || e.target.target === "_self") showLoader();
});

// ── Auto-dismiss flash toast after 5s ────────────────────
document.querySelectorAll(".flash-toast").forEach(function(toast) {
  setTimeout(function() {
    toast.style.opacity = "0";
    toast.style.transition = "opacity .3s";
    setTimeout(function() { toast.remove(); }, 350);
  }, 5000);
});

// ── Form field & table styling (progressive enhancement) ─
// Buttons
document.querySelectorAll("button:not(.nav-group-btn):not(.hamburger):not(.user-avatar-btn):not(.flash-dismiss):not(.sidebar-collapse-btn):not(.modal-close):not(.page-link):not(.tab-link):not(.action-menu-item)").forEach(function(b) {
  if (b.classList.contains("btn") || b.classList.contains("btn-auth")) return;
  b.classList.add("btn");
  if (b.classList.contains("alt")) {
    b.classList.remove("bg-brand-700","text-white","hover:bg-brand-900");
  }
});

// Person selector autocomplete
document.querySelectorAll("[data-person-selector]").forEach(function(wrap) {
  var searchInput  = wrap.querySelector("[data-person-search]");
  var hiddenInput  = wrap.querySelector("[data-person-id]");
  var options      = Array.from(wrap.querySelectorAll("datalist option"));
  var deptTarget   = wrap.getAttribute("data-department-target");
  var nameTarget   = wrap.getAttribute("data-name-target");
  var codeTarget   = wrap.getAttribute("data-code-target");
  var roleTarget   = wrap.getAttribute("data-role-target");
  function fill(id, val, overwrite) {
    if (!id) return;
    var el = document.getElementById(id);
    if (el && (overwrite || !el.value.trim())) el.value = val || "";
  }
  function sync() {
    if (!searchInput || !hiddenInput) return;
    var matched = options.find(function(o) { return o.value === searchInput.value; });
    if (!matched) { hiddenInput.value = ""; return; }
    hiddenInput.value = matched.getAttribute("data-person-id") || "";
    fill(deptTarget, matched.getAttribute("data-department") || "", true);
    fill(nameTarget, matched.getAttribute("data-full-name") || "", true);
    fill(codeTarget, matched.getAttribute("data-person-code") || "", true);
    fill(roleTarget, matched.getAttribute("data-role") || "", true);
  }
  if (searchInput) { searchInput.addEventListener("input", sync); searchInput.addEventListener("change", sync); sync(); }
});

// Action menus — close others when one opens
document.querySelectorAll(".action-menu").forEach(function(menu) {
  menu.addEventListener("toggle", function() {
    if (!menu.open) return;
    document.querySelectorAll(".action-menu[open]").forEach(function(other) {
      if (other !== menu) other.removeAttribute("open");
    });
  });
});
document.addEventListener("click", function(e) {
  document.querySelectorAll(".action-menu[open]").forEach(function(menu) {
    if (!menu.contains(e.target)) menu.removeAttribute("open");
  });
});

})();
</script>';

    echo '</body>';
    echo '</html>';
}

// ──────────────────────────────────────────────────────────────
// PAGINATION
// ──────────────────────────────────────────────────────────────
function render_pagination(array $pagination, string $param = 'page'): void
{
    if ((int) $pagination['total_pages'] <= 1) {
        return;
    }
    $page       = (int) $pagination['page'];
    $totalPages = (int) $pagination['total_pages'];
    $start      = max(1, $page - 2);
    $end        = min($totalPages, $page + 2);

    echo '<nav class="pagination" aria-label="Pagination">';
    echo '<a class="page-link' . ($page <= 1 ? ' disabled' : '') . '" href="' . h(pagination_url(max(1, $page - 1), $param)) . '" aria-label="Previous page">Previous</a>';
    for ($i = $start; $i <= $end; $i++) {
        $current = $i === $page ? ' active" aria-current="page"' : '"';
        echo '<a class="page-link' . $current . ' href="' . h(pagination_url($i, $param)) . '">' . h((string) $i) . '</a>';
    }
    echo '<a class="page-link' . ($page >= $totalPages ? ' disabled' : '') . '" href="' . h(pagination_url(min($totalPages, $page + 1), $param)) . '" aria-label="Next page">Next</a>';
    echo '</nav>';
}
