<?php
/** Shared bootstrap for admin pages: admins only. */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/partials.php';
require_login();
if (!is_admin()) {
    flash('error', 'Admins only.');
    redirect('index.php');
}

function admin_tabs(string $active): void
{
    $tabs = [
        'index' => ['admin/index.php', 'chart', 'Dashboard'],
        'paymongo' => ['admin/paymongo.php', 'settings', 'PayMongo & Giving'],
        'payments' => ['admin/payments.php', 'receipt', 'Payments'],
        'projects' => ['admin/projects.php', 'target', 'Projects'],
        'registrations' => ['admin/registrations.php', 'users', 'Registrations'],
    ];
    echo '<nav class="admin-tabs chips-scroll">';
    foreach ($tabs as $k => [$href, $ic, $label]) {
        echo '<a href="' . e(url($href)) . '" class="' . ($k === $active ? 'on' : '') . '">' . icon($ic) . e($label) . '</a>';
    }
    echo '</nav>';
}

function csv_out(string $filename, array $header, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₱ correctly
    fputcsv($out, $header);
    foreach ($rows as $r) {
        // Neutralise spreadsheet formulas in user-supplied text.
        fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r));
    }
    exit;
}
