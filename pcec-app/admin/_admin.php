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
        'members' => ['admin/members.php', 'users', 'Members'],
        'inbox' => ['admin/inbox.php', 'mail', 'Inbox'],
        'community' => ['admin/community.php', 'comment', 'Community'],
        'announcements' => ['admin/announcements.php', 'bell', 'Announcements'],
        'events' => ['admin/events.php', 'calendar', 'Events'],
        'ndc' => ['admin/ndc.php', 'shield', 'NDC Posts'],
        'payments' => ['admin/payments.php', 'receipt', 'Donations'],
        'paymongo' => ['admin/paymongo.php', 'qr', 'QR Ph & Giving'],
        'registrations' => ['admin/registrations.php', 'check', 'Registrations'],
        'about' => ['admin/about.php', 'church', 'About & Office'],
        'email' => ['admin/email.php', 'send', 'Email'],
    ];
    echo '<nav class="admin-tabs chips-scroll" aria-label="Admin sections">';
    foreach ($tabs as $k => [$href, $ic, $label]) {
        echo '<a href="' . e(url($href)) . '" class="' . ($k === $active ? 'on' : '') . '"' . ($k === $active ? ' aria-current="page"' : '') . '>' . icon($ic) . e($label) . '</a>';
    }
    echo '</nav>';
}

/** Send the same notification to every confirmed member (one INSERT … SELECT). */
function notify_all_members(string $type, string $message, string $link): void
{
    q("INSERT INTO notifications (user_id, actor_id, type, message, link)
       SELECT id, NULL, ?, ?, ? FROM users WHERE status = 'approved'", [$type, mb_substr($message, 0, 255), $link]);
}

/** Save an optional image upload; returns the stored path, the existing one, or null if removed. */
function admin_image(string $field, ?string $current, string $folder = 'events'): ?string
{
    if (!empty($_POST['remove_' . $field])) return null;
    $up = handle_upload($field, $folder, ['image']);
    return $up[0] ?? $current;
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
