<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
$q = trim((string) ($_GET['q'] ?? ''));

$where = ['log_date BETWEEN :from AND :to'];
$params = [
    'from' => $from,
    'to' => $to,
];

if ($q !== '') {
    $where[] = '(student_name LIKE :q OR student_id LIKE :q OR purpose LIKE :q OR person.full_name LIKE :q OR person.person_code LIKE :q OR person.department LIKE :q OR person.role_or_position LIKE :q)';
    $params['q'] = prefix_search_param($q);
}

$countSql = 'SELECT COUNT(*)
    FROM office_logbook ol
    LEFT JOIN people person ON person.id = ol.person_id
    WHERE ' . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$pagination = pagination_meta($totalCount, page_param(), 25);

$listSql = 'SELECT id, log_date, time_in, time_out, student_name, student_id, purpose, person_name, person_code
    FROM (
        SELECT ol.id, ol.log_date, ol.time_in, ol.time_out, ol.student_name, ol.student_id, ol.purpose,
            person.full_name AS person_name, person.person_code AS person_code,
            ROW_NUMBER() OVER (ORDER BY ol.log_date DESC, ol.time_in DESC, ol.id DESC) AS row_num
        FROM office_logbook ol
        LEFT JOIN people person ON person.id = ol.person_id
        WHERE ' . implode(' AND ', $where) . '
    ) ranked_logbook
    WHERE row_num BETWEEN :first_row AND :last_row
    ORDER BY row_num';
$listStmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStmt->bindValue(':' . ltrim((string) $key, ':'), $value);
}
[$firstRow, $lastRow] = pagination_row_bounds($pagination);
$listStmt->bindValue(':first_row', $firstRow, PDO::PARAM_INT);
$listStmt->bindValue(':last_row', $lastRow, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll();

render_header('Office Logbook', $user);
?>

<?php render_page_header('Records student transactions, visits, and service requests.'); ?>

<section class="table-card data-panel">
    <div class="section-heading">
        <div>
            <h3 class="text-base font-bold text-slate-950">Logbook Records</h3>
        </div>
        <span class="badge"><?= h((string) $totalCount) ?> record<?= $totalCount === 1 ? '' : 's' ?></span>
    </div>

    <form method="get" class="data-panel-filters grid gap-3 md:grid-cols-[minmax(150px,1fr)_minmax(150px,1fr)_minmax(220px,2fr)_auto] md:items-end">
        <div>
            <label for="from">From</label>
            <input id="from" type="date" name="from" value="<?= h($from) ?>">
        </div>
        <div>
            <label for="to">To</label>
            <input id="to" type="date" name="to" value="<?= h($to) ?>">
        </div>
        <div>
            <label for="q">Search</label>
            <input id="q" name="q" value="<?= h($q) ?>" placeholder="Name, ID, or purpose">
        </div>
        <button type="submit">Apply</button>
    </form>

    <div class="table-wrap" data-no-table-enhance>
        <table>
            <thead>
            <tr>
                <th>Date</th>
                <th>Student Name</th>
                <th>Student ID</th>
                <th>Time In</th>
                <th>Time Out</th>
                <th>Purpose</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="6" class="muted">No logbook records found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= h($row['log_date']) ?></td>
                    <td><?= h($row['person_name'] ?: $row['student_name']) ?></td>
                    <td><?= h($row['person_code'] ?: $row['student_id']) ?></td>
                    <td><?= h($row['time_in']) ?></td>
                    <td><?= h($row['time_out'] ?: '-') ?></td>
                    <td><?= h($row['purpose']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($pagination); ?>
    <div class="data-panel-footer">
        Showing office log entries from <?= h($from) ?> to <?= h($to) ?>.
    </div>
</section>

<?php render_footer();
