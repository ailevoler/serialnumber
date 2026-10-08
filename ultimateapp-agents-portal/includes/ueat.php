<?php
declare(strict_types=1);

require_once __DIR__ . '/agents.php';

function ueat_catalog(): array
{
    return [
        'beach-kitchen' => ['name'=>'White Beach Kitchen','detail'=>'Filipino favorites by the shore','location'=>'Station 1','photo'=>10,'time'=>'25-35 min','menu'=>[
            'chicken-inasal'=>['name'=>'Chicken Inasal Plate','detail'=>'Grilled chicken, rice and atchara','credits'=>285,'photo'=>10],
            'pork-sisig'=>['name'=>'Sizzling Pork Sisig','detail'=>'Crispy pork, calamansi and rice','credits'=>320,'photo'=>11],
            'pancit-canton'=>['name'=>'Island Pancit Canton','detail'=>'Stir-fried noodles and vegetables','credits'=>245,'photo'=>12],
            'mango-shake'=>['name'=>'Fresh Mango Shake','detail'=>'Chilled island mango blend','credits'=>165,'photo'=>14],
        ]],
        'seafood' => ['name'=>'Island Seafood Grill','detail'=>'Grilled seafood and coastal classics','location'=>'Station 2','photo'=>11,'time'=>'30-45 min','menu'=>[
            'grilled-squid'=>['name'=>'Grilled Squid','detail'=>'Chargrilled squid with citrus dip','credits'=>420,'photo'=>11],
            'garlic-shrimp'=>['name'=>'Garlic Butter Shrimp','detail'=>'Shrimp, garlic butter and rice','credits'=>480,'photo'=>11],
            'fish-sinigang'=>['name'=>'Fish Sinigang','detail'=>'Sour tamarind broth with fish','credits'=>390,'photo'=>10],
            'coconut-juice'=>['name'=>'Fresh Coconut','detail'=>'Served chilled','credits'=>135,'photo'=>14],
        ]],
        'cafe' => ['name'=>'Bulabog Breakfast Cafe','detail'=>'Coffee, bowls and easy mornings','location'=>'Bulabog Beach','photo'=>12,'time'=>'20-30 min','menu'=>[
            'breakfast-bowl'=>['name'=>'Breakfast Rice Bowl','detail'=>'Egg, vegetables and house sauce','credits'=>260,'photo'=>12],
            'pancake-stack'=>['name'=>'Banana Pancake Stack','detail'=>'Banana, syrup and butter','credits'=>245,'photo'=>13],
            'iced-latte'=>['name'=>'Iced Latte','detail'=>'Espresso with chilled milk','credits'=>175,'photo'=>12],
            'fruit-bowl'=>['name'=>'Tropical Fruit Bowl','detail'=>'Seasonal island fruit','credits'=>210,'photo'=>14],
        ]],
        'bistro' => ['name'=>'Station 3 Bistro','detail'=>'Comfort meals and casual dining','location'=>'Station 3','photo'=>13,'time'=>'25-40 min','menu'=>[
            'burger'=>['name'=>'Island Burger','detail'=>'Beef patty, greens and fries','credits'=>350,'photo'=>13],
            'pasta'=>['name'=>'Creamy Seafood Pasta','detail'=>'Pasta with seafood cream sauce','credits'=>410,'photo'=>11],
            'rice-plate'=>['name'=>'Grilled Chicken Rice','detail'=>'Grilled chicken and garlic rice','credits'=>295,'photo'=>10],
            'lemonade'=>['name'=>'Calamansi Lemonade','detail'=>'Fresh citrus cooler','credits'=>145,'photo'=>14],
        ]],
    ];
}

function ueat_cart(int $userId): array
{
    $cart = $_SESSION['ueat_cart'][$userId] ?? [];
    if (!is_array($cart)) return [];
    $catalog = ueat_catalog();
    $result = [];
    foreach ($cart as $restaurantId => $items) {
        if (!isset($catalog[$restaurantId]) || !is_array($items)) continue;
        foreach ($items as $itemId => $quantity) {
            if (!isset($catalog[$restaurantId]['menu'][$itemId]) || !is_int($quantity) || $quantity < 1 || $quantity > 10) continue;
            $result[$restaurantId][$itemId] = $quantity;
        }
        if ($result) break;
    }
    return $result;
}

function ueat_cart_summary(array $cart): array
{
    $catalog = ueat_catalog();
    $restaurantId = array_key_first($cart);
    if (!$restaurantId || !isset($catalog[$restaurantId])) return ['restaurant_id'=>null,'restaurant'=>null,'lines'=>[],'count'=>0,'total'=>0];
    $restaurant = $catalog[$restaurantId];
    $lines = [];
    $count = 0;
    $total = 0;
    foreach ($cart[$restaurantId] as $itemId => $quantity) {
        if (!isset($restaurant['menu'][$itemId])) continue;
        $item = $restaurant['menu'][$itemId];
        $lines[] = ['id'=>$itemId,'name'=>$item['name'],'photo'=>$item['photo'],'quantity'=>$quantity,'unit'=>$item['credits'],'total'=>$item['credits'] * $quantity];
        $count += $quantity;
        $total += $item['credits'] * $quantity;
    }
    return ['restaurant_id'=>$restaurantId,'restaurant'=>$restaurant,'lines'=>$lines,'count'=>$count,'total'=>$total];
}

function ueat_place_order(PDO $pdo, int $userId, array $summary, string $key, string $fulfillment, string $address, string $notes, bool $paid): string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $key) || !$summary['restaurant_id'] || !$summary['lines'] || $summary['count'] > 30 || $summary['total'] < 1) {
        throw new InvalidArgumentException('Invalid order. Please review your cart.');
    }
    if (!in_array($fulfillment, ['delivery','pickup'], true) || ($fulfillment === 'delivery' && $address === '')) {
        throw new InvalidArgumentException('Enter a delivery address or choose pickup.');
    }
    if (strlen($address) > 500 || strlen($notes) > 500) {
        throw new InvalidArgumentException('Please shorten the address or note.');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) throw new UnexpectedValueException('Account not found.');
        $stmt = $pdo->prepare('SELECT reference FROM ueat_orders WHERE user_id = ? AND request_key = ?');
        $stmt->execute([$userId, $key]);
        if ($prior = $stmt->fetch()) {
            $pdo->commit();
            return $prior['reference'];
        }
        if ($paid) {
            $total = number_format($summary['total'], 2, '.', '');
            $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
            $stmt->execute([$total, $userId, $total]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Insufficient Credits. Please top up or reduce the cart.');
        }
        $reference = 'UE-' . strtoupper(bin2hex(random_bytes(8)));
        $stmt = $pdo->prepare('INSERT INTO ueat_orders (reference, request_key, user_id, restaurant_id, fulfillment, delivery_address, notes, total_credits, payment_status, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$reference, $key, $userId, $summary['restaurant_id'], $fulfillment, $fulfillment === 'delivery' ? $address : null, $notes ?: null, number_format($summary['total'], 2, '.', ''), $paid ? 'paid' : 'demo', $paid ? 'pending' : 'sample']);
        $orderId = (int) $pdo->lastInsertId();
        $lineStmt = $pdo->prepare('INSERT INTO ueat_order_items (order_id, menu_item_id, item_name, quantity, unit_credits, line_credits) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($summary['lines'] as $line) {
            $lineStmt->execute([$orderId, $line['id'], $line['name'], $line['quantity'], number_format($line['unit'], 2, '.', ''), number_format($line['total'], 2, '.', '')]);
        }
        if ($paid) {
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, ?, 'Completed')");
            $stmt->execute([$userId, 'UEat order ' . $reference, '-' . number_format($summary['total'], 2, '.', '')]);
            // Referral agent commission: pending until the holding period passes without a cancellation.
            agent_record_commission($pdo, $userId, 'UEat', 'ueat', $orderId, $reference, (int) round($summary['total'] * 100), false);
        }
        $pdo->commit();
        return $reference;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function ueat_cancel_order(PDO $pdo, int $userId, string $reference): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) throw new UnexpectedValueException('Account not found.');
        $stmt = $pdo->prepare('SELECT id, total_credits, payment_status, status FROM ueat_orders WHERE reference = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([$reference, $userId]);
        $order = $stmt->fetch();
        if (!$order) throw new InvalidArgumentException('Order not found.');
        if ($order['status'] === 'cancelled') { $pdo->commit(); return; }
        if (!in_array($order['status'], ['pending','sample'], true)) throw new InvalidArgumentException('This order cannot be cancelled here.');
        if ($order['payment_status'] === 'paid') {
            $stmt = $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');
            $stmt->execute([$order['total_credits'], $userId]);
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, ?, 'Completed')");
            $stmt->execute([$userId, 'UEat refund ' . $reference, $order['total_credits']]);
        }
        $stmt = $pdo->prepare("UPDATE ueat_orders SET status = 'cancelled', payment_status = CASE WHEN payment_status = 'paid' THEN 'refunded' ELSE payment_status END WHERE id = ?");
        $stmt->execute([$order['id']]);
        agent_reverse_source($pdo, 'ueat', (int) $order['id'], 'Order cancelled');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
