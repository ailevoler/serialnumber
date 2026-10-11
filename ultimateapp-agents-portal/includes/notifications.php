<?php
declare(strict_types=1);

function user_notifications(PDO $pdo, int $userId): array
{
    $items = [];
    $stmt = $pdo->prepare('SELECT id, title, amount, created_at FROM transactions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 15');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'key' => 'transaction:' . $row['id'],
            'title' => $row['title'],
            'detail' => ($row['amount'] >= 0 ? '+' : '') . number_format((float) $row['amount'], 2) . ' Credits',
            'date' => $row['created_at'],
            'url' => 'transactions.php',
            'icon' => 'receipt',
        ];
    }
    $stmt = $pdo->prepare("SELECT reference, credits, method, created_at FROM credit_topups WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC, id DESC LIMIT 15");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'key' => 'topup:' . $row['reference'],
            'title' => ($row['method'] === 'qrph' ? 'QR Ph' : 'MCTC') . ' top-up pending',
            'detail' => number_format((float) $row['credits'], 2) . ' Credits',
            'date' => $row['created_at'],
            'url' => 'payment-status.php?reference=' . rawurlencode($row['reference']),
            'icon' => 'wallet',
        ];
    }
    $stmt = $pdo->prepare('SELECT reference, service_code, intent, status, created_at FROM service_requests WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 15');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'key' => 'service:' . $row['reference'],
            'title' => $row['service_code'] . ' ' . $row['intent'] . ' request',
            'detail' => ucfirst($row['status']) . ' - ' . $row['reference'],
            'date' => $row['created_at'],
            'url' => 'service-request-status.php?reference=' . rawurlencode($row['reference']),
            'icon' => 'receipt',
        ];
    }
    // UBarangay decisions (needs info / approved / rejected).
    try {
        $stmt = $pdo->prepare("SELECT a.reference, a.status, a.reviewed_at, t.name FROM brgy_applications a JOIN brgy_permit_types t ON t.id = a.permit_type_id WHERE a.user_id = ? AND a.status IN ('needs_info','approved','rejected') AND a.reviewed_at IS NOT NULL ORDER BY a.reviewed_at DESC LIMIT 10");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'key' => 'brgy:' . $row['reference'] . ':' . $row['status'],
                'title' => $row['name'] . ' ' . ['needs_info' => 'needs more info', 'approved' => 'approved', 'rejected' => 'rejected'][$row['status']],
                'detail' => $row['status'] === 'approved' ? 'Your certificate is ready to print' : 'Open UBarangay for details',
                'date' => $row['reviewed_at'],
                'url' => 'brgy-application.php?ref=' . rawurlencode($row['reference']),
                'icon' => $row['status'] === 'approved' ? 'check-circle' : 'receipt',
            ];
        }
    } catch (Throwable $e) {}
    // UGo / ULocal bookings and UEat orders: each status change is a new notification.
    try {
        $stmt = $pdo->prepare("SELECT reference, service, title, status, updated_at FROM mk_bookings WHERE user_id = ? AND status <> 'pending' ORDER BY updated_at DESC LIMIT 10");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'key' => 'mk:' . $row['reference'] . ':' . $row['status'],
                'title' => (['ugo' => 'UGo', 'ulocal' => 'ULocal', 'upass' => 'UPass'][$row['service']] ?? 'Booking') . ' booking ' . $row['status'],
                'detail' => $row['title'] . ' · ' . $row['reference'],
                'date' => $row['updated_at'],
                'url' => 'mk-booking.php?ref=' . rawurlencode($row['reference']),
                'icon' => in_array($row['status'], ['confirmed', 'completed'], true) ? 'check-circle' : 'receipt',
            ];
        }
        $stmt = $pdo->prepare("SELECT o.reference, o.status, COALESCE(o.updated_at, o.created_at) changed, s.display_name FROM ueat_orders o JOIN mk_stores s ON s.merchant_id = o.merchant_id WHERE o.user_id = ? AND o.status NOT IN ('pending','sample') ORDER BY changed DESC LIMIT 10");
        $stmt->execute([$userId]);
        $label = ['accepted' => 'accepted your order', 'preparing' => 'is preparing your food', 'on_the_way' => 'Your food is on the way', 'ready' => 'Your order is ready for pick-up', 'completed' => 'Order completed', 'declined' => 'declined your order (refunded)', 'cancelled' => 'Order cancelled (refunded)'];
        foreach ($stmt->fetchAll() as $row) {
            $t = $label[$row['status']] ?? $row['status'];
            $items[] = [
                'key' => 'ueat:' . $row['reference'] . ':' . $row['status'],
                'title' => 'UEat · ' . (in_array($row['status'], ['accepted', 'preparing', 'declined'], true) ? $row['display_name'] . ' ' . $t : $t),
                'detail' => $row['display_name'] . ' · ' . $row['reference'],
                'date' => $row['changed'],
                'url' => 'ueat-order.php?reference=' . rawurlencode($row['reference']),
                'icon' => 'utensils',
            ];
        }
    } catch (Throwable $e) {}
    usort($items, static fn(array $a, array $b): int => strcmp($b['date'], $a['date']));
    $items = array_slice($items, 0, 30);
    if (!$items) return [];
    $keys = array_column($items, 'key');
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT notification_key FROM notification_reads WHERE user_id = ? AND notification_key IN ($placeholders)");
    $stmt->execute([$userId, ...$keys]);
    $read = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
    foreach ($items as &$item) $item['read'] = isset($read[$item['key']]);
    unset($item);
    return $items;
}

function unread_notification_count(array $items): int
{
    return count(array_filter($items, static fn(array $item): bool => !$item['read']));
}

/** Published News/Updates posts this user has not opened yet (0 if the news table is missing). */
function unread_news_count(PDO $pdo, int $userId): int
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM news_posts p WHERE p.published = 1 AND p.published_at <= NOW() AND NOT EXISTS (SELECT 1 FROM notification_reads r WHERE r.user_id = ? AND r.notification_key = CONCAT('news:', p.id))");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $error) {
        error_log('Unread news count unavailable: ' . $error->getMessage());
        return 0;
    }
}

/** Mark the given News/Updates post ids as read for this user. */
function mark_news_read(PDO $pdo, int $userId, array $postIds): void
{
    if (!$postIds) return;
    $stmt = $pdo->prepare('INSERT IGNORE INTO notification_reads (user_id, notification_key) VALUES (?, ?)');
    foreach ($postIds as $id) $stmt->execute([$userId, 'news:' . (int) $id]);
}
