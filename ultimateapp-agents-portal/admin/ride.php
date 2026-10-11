<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/uride.php';
$admin = admin_require('rides.view');
$id = (int) q('id');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    try {
        if (p('action') === 'claim') {
            if (!admin_can('payments.refund')) throw new InvalidArgumentException('Your role cannot decide deposit claims.');
            $amt = trim(p('approve'));
            if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $amt)) throw new InvalidArgumentException('Enter the amount to pay the owner, e.g. 1500 or 0.');
            $ride = uride_deposit_decide($pdo, $id, (int) round((float) $amt * 100), p('note'), (int) $admin['id']);
            admin_audit('selfdrive_claim', 'ride', $id, ['code' => $ride['code'], 'approved' => $amt, 'note' => p('note')]);
            flash('success', 'Claim decided: ₱' . peso((int) $ride['claim_approved_centavos']) . ' to the owner; the rest of the deposit was returned to the customer.');
            redirect('ride.php?id=' . $id);
        }
        if (!admin_can('rides.cancel')) throw new InvalidArgumentException('Your role cannot cancel rides.');
        $ride = uride_admin_cancel($pdo, $id, p('reason'));
        admin_audit('ride_cancelled', 'ride', $id, ['code' => $ride['code'], 'reason' => p('reason')]);
        flash('success', 'Ride cancelled.' . ((int) $ride['held_centavos'] > 0 ? ' ₱' . peso((int) $ride['held_centavos']) . ' Credits returned to the passenger.' : ''));
    } catch (InvalidArgumentException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('ride.php?id=' . $id);
}
$stmt = $pdo->prepare('SELECT r.*, u.full_name passenger, u.mobile passenger_mobile, u.email passenger_email, d.full_name driver, d.mobile driver_mobile, d.plate_no, d.code driver_code FROM uride_requests r JOIN users u ON u.id = r.user_id LEFT JOIN uride_drivers d ON d.id = r.driver_id WHERE r.id = ?');
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) { flash('error', 'Ride not found.'); redirect('rides.php'); }
$labels = uride_ride_statuses();
$fare = (int) $r['fare_centavos'];
$bd = json_decode((string) $r['fare_breakdown'], true) ?: [];
$ledger = $pdo->prepare('SELECT * FROM ledger_entries WHERE entry_group IN (?, ?, ?, ?, ?, ?) ORDER BY id');
$sfx = substr($r['code'], 3);
$ledger->execute([$r['code'], 'RF-' . $sfx, 'DP-' . $sfx, 'DR-' . $sfx, 'LF-' . $sfx, 'DC-' . $sfx]);
$ledger = $ledger->fetchAll();
$active = in_array($r['status'], uride_active_statuses(), true);
admin_header('Ride ' . $r['code'], 'rides');
?>
<?php if (uride_is_self_drive($r)):
    $lic = null;
    if ($r['license_id']) { $ls = $pdo->prepare('SELECT * FROM user_driver_licenses WHERE id = ?'); $ls->execute([$r['license_id']]); $lic = $ls->fetch() ?: null; } ?>
<section class="card mb"><h2>Self-drive <small>customer drives</small> <?= $r['deposit_status'] === 'claimed' ? '<span class="badge warn">Damage claim to decide</span>' : '' ?></h2>
    <div class="kv"><span>Customer license</span><b><?= $lic ? e(uride_license_label($lic)) . ' · ' . status_badge($lic['status']) . ' <a href="license-photo.php?id=' . (int) $lic['id'] . '" target="_blank" rel="noopener">photo</a> · <a href="uride-licenses.php">review</a>' : '—' ?></b></div>
    <div class="kv"><span>Deposit</span><b>₱<?= peso((int) $r['deposit_centavos']) ?> · <?= e(uride_deposit_status_label($r) ?: $r['deposit_status']) ?></b></div>
    <?php if ($r['handover_note']): ?><div class="kv"><span>At hand-over</span><b><?= e($r['handover_note']) ?></b></div><?php endif; ?>
    <?php if ($r['returned_at']): ?><div class="kv"><span>Returned</span><b><?= e($r['returned_at']) ?><?= (int) $r['late_fee_centavos'] ? ' · late fee ₱' . peso((int) $r['late_fee_centavos']) : ' · on time' ?></b></div><?php endif; ?>
    <?php if ((int) $r['claim_centavos'] > 0): ?><div class="kv"><span>Damage claim</span><b>₱<?= peso((int) $r['claim_centavos']) ?> · <?= e((string) $r['claim_note']) ?><?= $r['claim_approved_centavos'] !== null ? ' · approved ₱' . peso((int) $r['claim_approved_centavos']) : '' ?></b></div><?php endif; ?>
    <?php if ($r['deposit_status'] === 'claimed' && admin_can('payments.refund')): $max = min((int) $r['claim_centavos'], (int) $r['deposit_centavos'] - (int) $r['late_fee_centavos']); ?>
    <form method="post" class="form-grid mt" data-confirm="Decide this claim? The money moves right away."><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="claim">
        <div class="field"><label for="approve">Pay the owner (PHP)</label><input id="approve" name="approve" inputmode="decimal" value="<?= e(centavos_to_decimal($max)) ?>"><small>0 to ₱<?= peso($max) ?>. The rest of the deposit goes back to the customer.</small></div>
        <div class="field"><label for="note">Reason</label><input id="note" name="note" required placeholder="e.g. photos confirm cracked mirror; repair quote ₱1,500"></div>
        <div class="form-actions"><button class="btn primary" type="submit">Decide claim</button></div>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>
<div class="grid two">
    <div class="stack">
        <section class="card"><h2><?= e($r['code']) ?> <small><?= e(uride_vehicles()[$r['vehicle_type']]['label'] ?? '') ?></small></h2>
            <div class="kv"><span>Status</span><b><?= e($labels[$r['status']] ?? $r['status']) ?></b></div>
            <?php if (($r['trip_type'] ?? 'ride') === 'rental'): ?><div class="kv"><span>Rental</span><b><?= e(uride_rental_label($r)) ?><?= $r['scheduled_at'] ? ' · starts ' . e(date('M j, g:i A', strtotime((string) $r['scheduled_at']))) : '' ?><?= $r['ends_at'] ? ' · return by ' . e(date('M j, g:i A', strtotime((string) $r['ends_at']))) : '' ?></b></div><?php endif; ?>
            <div class="kv"><span>Passenger</span><b><a href="customer.php?id=<?= (int) $r['user_id'] ?>"><?= e($r['passenger']) ?></a> · <?= e($r['passenger_mobile'] ?? '') ?></b></div>
            <div class="kv"><span>Driver</span><b><?= $r['driver'] ? '<a href="driver.php?id=' . (int) $r['driver_id'] . '">' . e($r['driver']) . '</a> · ' . e($r['plate_no']) . ' · ' . e($r['driver_mobile']) : '—' ?></b></div>
            <div class="kv"><span>Pick-up</span><b><?= e($r['pickup_address']) ?></b></div>
            <div class="kv"><span>Drop-off</span><b><?= e($r['dropoff_address']) ?></b></div>
            <div class="kv"><span>Distance</span><b><?= e(number_format((float) $r['distance_km'], 2)) ?> km direct · <?= $r['road_km'] !== null ? e(number_format((float) $r['road_km'], 2)) . ' km est. road' : '—' ?></b></div>
            <?php if ($r['passenger_note']): ?><div class="kv"><span>Passenger note</span><b><?= e($r['passenger_note']) ?></b></div><?php endif; ?>
            <?php if ($r['rating'] !== null): ?><div class="kv"><span>Rating</span><b><?= str_repeat('★', (int) $r['rating']) ?><?= $r['rating_comment'] ? ' “' . e($r['rating_comment']) . '”' : '' ?></b></div><?php endif; ?>
            <?php if ($r['cancel_reason'] || $r['cancelled_by']): ?><div class="kv"><span>Cancelled</span><b><?= e(ucfirst((string) $r['cancelled_by'])) ?><?= $r['cancel_reason'] ? ' · ' . e($r['cancel_reason']) : '' ?></b></div><?php endif; ?>
        </section>
        <section class="card" <?= google_maps_attrs() ?>><h2>Route <a href="https://www.google.com/maps/dir/?api=1&amp;origin=<?= e($r['pickup_lat'] . ',' . $r['pickup_lng']) ?>&amp;destination=<?= e($r['dropoff_lat'] . ',' . $r['dropoff_lng']) ?>" target="_blank" rel="noopener">Open in Google Maps</a></h2>
            <div class="map-box" data-map-view data-markers="<?= e(json_encode([['lat' => (float) $r['pickup_lat'], 'lng' => (float) $r['pickup_lng'], 'title' => 'Pick-up', 'sub' => $r['pickup_address']], ['lat' => (float) $r['dropoff_lat'], 'lng' => (float) $r['dropoff_lng'], 'title' => 'Drop-off', 'sub' => $r['dropoff_address']]])) ?>">Map shows here once a Google Maps key is set in Settings.</div><p class="table-meta" data-map-status></p>
        </section>
        <section class="card"><h2>Ledger entries</h2>
            <div class="table-wrap"><table><thead><tr><th>Group</th><th>Event</th><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
            <?php if (!$ledger): ?><tr><td colspan="5" class="muted">No money movement (cash ride not completed, or no commission).</td></tr><?php endif; ?>
            <?php foreach ($ledger as $l): ?><tr><td><?= e($l['entry_group']) ?></td><td><?= e($l['event']) ?></td><td><?= e(ledger_account_label($l['account'])) ?></td><td class="num"><?= $l['debit_centavos'] ? peso((int) $l['debit_centavos']) : '' ?></td><td class="num"><?= $l['credit_centavos'] ? peso((int) $l['credit_centavos']) : '' ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
    </div>
    <div class="stack">
        <section class="card"><h2>Fare</h2>
            <div class="kv"><span>Base fare</span><b>₱<?= peso((int) ($bd['base'] ?? 0)) ?></b></div>
            <div class="kv"><span>Distance charge</span><b>₱<?= peso((int) ($bd['distance'] ?? 0)) ?></b></div>
            <?php if (!empty($bd['night'])): ?><div class="kv"><span>Night surcharge</span><b>₱<?= peso((int) $bd['night']) ?></b></div><?php endif; ?>
            <div class="kv"><span>Fare (upfront, rounded up)</span><b>₱<?= peso($fare) ?></b></div>
            <div class="kv"><span>Payment</span><b><?= e(ucfirst($r['payment_method'])) ?><?= (int) $r['held_centavos'] > 0 ? ' · ₱' . peso((int) $r['held_centavos']) . ' on hold' : '' ?></b></div>
            <div class="kv"><span>Commission</span><b><?= $r['commission_centavos'] !== null ? '₱' . peso((int) $r['commission_centavos']) : '—' ?></b></div>
        </section>
        <section class="card"><h2>Timeline</h2>
            <ul class="timeline">
                <?php foreach (['created_at' => 'Requested', 'accepted_at' => 'Driver accepted', 'arrived_at' => 'Driver arrived', 'started_at' => 'Trip started', 'completed_at' => 'Completed', 'cancelled_at' => $r['status'] === 'expired' ? 'Expired — no driver' : 'Cancelled'] as $col => $label): if (!$r[$col]) continue; ?>
                    <li><b><?= e($label) ?></b><small class="sub"><?= e(date('M j, Y g:i:s A', strtotime((string) $r[$col]))) ?></small></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php if ($active && admin_can('rides.cancel')): ?>
        <section class="card"><h2>Cancel ride</h2>
            <form method="post" data-confirm="Cancel this ride? Held Credits go back to the passenger.">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <div class="field"><label for="reason">Reason (shown to driver and passenger)</label><input type="text" id="reason" name="reason" required maxlength="200" placeholder="e.g. Passenger reported a problem"></div>
                <div class="form-actions"><button class="btn danger" type="submit">Cancel ride</button></div>
            </form>
        </section>
        <?php endif; ?>
    </div>
</div>
<?php admin_footer(['../assets/js/gmaps.js?v=2']);
