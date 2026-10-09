<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/kyc.php';
$admin = admin_require('kyc.view');
$types = kyc_subject_types();

if (!kyc_table_ready()) {
    admin_header('KYC verification', 'kyc');
    echo '<div class="flash error" role="status">Import <b>database/kyc_migration.sql</b> once in phpMyAdmin, then reload this page.</div>';
    admin_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    $back = 'kyc.php' . (q('id') ? '?id=' . (int) q('id') : '');
    try {
        if (p('action') === 'settings') {
            if (!admin_can('settings')) throw new InvalidArgumentException('Only a Super Admin can change KYC rules.');
            foreach (array_keys($types) as $t) setting_save($pdo, 'kyc.required_' . $t, p('req_' . $t) === '1' ? '1' : '0', (int) $admin['id']);
            admin_audit('kyc_settings', 'settings', 'kyc', array_diff_key($_POST, ['csrf' => 1]));
            flash('success', 'KYC rules saved.');
            redirect('kyc.php?view=settings');
        }
        if (!admin_can('kyc.review')) throw new InvalidArgumentException('Your role can view KYC but not decide.');
        $k = kyc_review($pdo, (int) p('id'), p('decision'), p('note'), (int) $admin['id']);
        admin_audit('kyc_' . p('decision'), 'kyc', (int) p('id'), ['reference' => $k['reference'], 'subject' => $k['subject_type'] . ':' . $k['subject_id'], 'note' => p('note')]);
        flash('success', $k['reference'] . ': ' . kyc_statuses()[p('decision')] . '.');
        // Go to the next one waiting, if any.
        $next = $pdo->query("SELECT id FROM kyc_submissions WHERE status = 'pending' ORDER BY id LIMIT 1")->fetchColumn();
        redirect($next ? 'kyc.php?id=' . (int) $next : 'kyc.php');
    } catch (InvalidArgumentException $ex) {
        flash('error', $ex->getMessage());
        redirect($back);
    }
}

$img = static fn(string $file): string => 'kyc-file.php?f=' . rawurlencode($file);

/* ---------- one submission */
if (q('id')) {
    $s = $pdo->prepare('SELECT k.*, a.name reviewer FROM kyc_submissions k LEFT JOIN admin_users a ON a.id = k.reviewed_by WHERE k.id = ?');
    $s->execute([(int) q('id')]);
    $k = $s->fetch();
    if (!$k) { http_response_code(404); admin_header('KYC not found', 'kyc'); echo '<div class="empty"><h2>Not found</h2></div>'; admin_footer(); exit; }
    $who = kyc_subject_info($pdo, $k['subject_type'], (int) $k['subject_id']);
    $frames = json_decode((string) $k['liveness_frames'], true) ?: [];
    $log = json_decode((string) $k['liveness_log'], true) ?: [];
    $hist = $pdo->prepare('SELECT id, reference, status, created_at FROM kyc_submissions WHERE subject_type = ? AND subject_id = ? AND id <> ? ORDER BY id DESC');
    $hist->execute([$k['subject_type'], $k['subject_id'], $k['id']]); $hist = $hist->fetchAll();
    admin_header('KYC ' . $k['reference'], 'kyc');
    ?>
    <p><a href="kyc.php">&larr; KYC queue</a></p>
    <div class="grid two">
        <section class="card"><h2>Valid ID · <?= e(kyc_id_types()[$k['id_type']] ?? $k['id_type']) ?> <?= kyc_badge($k['status']) ?></h2>
            <div class="grid half">
                <a href="<?= e($img($k['id_front'])) ?>" target="_blank" rel="noopener"><img src="<?= e($img($k['id_front'])) ?>" alt="ID front" style="width:100%;border-radius:10px;border:1px solid #e4e8ee"></a>
                <a href="<?= e($img($k['id_back'])) ?>" target="_blank" rel="noopener"><img src="<?= e($img($k['id_back'])) ?>" alt="ID back" style="width:100%;border-radius:10px;border:1px solid #e4e8ee"></a>
            </div>
            <h2 class="mt">Selfie liveness <small><?= $k['liveness_mode'] === 'auto' ? 'Automatic face check passed on the phone' : 'Manual photos (no automatic face check): look carefully' ?></small></h2>
            <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:8px">
            <?php foreach (KYC_STEPS as $step): $f = $frames[$step] ?? null; $m = $log['steps'][$step] ?? []; ?>
                <figure style="margin:0"><?php if ($f): ?><a href="<?= e($img($f)) ?>" target="_blank" rel="noopener"><img src="<?= e($img($f)) ?>" alt="<?= e(kyc_step_labels()[$step]) ?>" style="width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:8px;transform:scaleX(-1)"></a><?php endif; ?>
                    <figcaption style="font-size:12px;text-align:center"><b><?= e(kyc_step_labels()[$step]) ?></b><br><small><?= isset($m['t']) ? e(number_format($m['t'] / 1000, 1)) . 's' : '' ?><?= isset($m['yaw']) && $m['yaw'] !== null ? ' · y ' . e((string) $m['yaw']) . ' p ' . e((string) $m['pitch']) : '' ?></small></figcaption></figure>
            <?php endforeach; ?>
            </div>
            <p class="table-meta">Check: same person in every photo and on the ID, a real head turning (not a printed photo or screen), eyes closed in the Blink photo.</p>
        </section>
        <div class="stack">
            <section class="card"><h2>Account</h2>
                <dl class="facts">
                    <div><dt>Type</dt><dd><?= e($types[$k['subject_type']]) ?></dd></div>
                    <div><dt>Account</dt><dd><a class="row-link" href="<?= e($who['link']) ?>"><?= e($who['name']) ?></a></dd></div>
                    <div><dt>Contact</dt><dd><?= e($who['email']) ?><br><?= e($who['mobile']) ?></dd></div>
                    <div><dt>Name on ID</dt><dd><b><?= e($k['full_name']) ?></b></dd></div>
                    <div><dt>ID number</dt><dd><?= e($k['id_number'] ?: '—') ?></dd></div>
                    <div><dt>Birthdate</dt><dd><?= $k['birthdate'] ? e(date('M j, Y', strtotime($k['birthdate']))) : '—' ?></dd></div>
                    <div><dt>Submitted</dt><dd><?= e(date('M j, Y g:i A', strtotime($k['created_at']))) ?><br><small><?= e((string) $k['ip']) ?></small></dd></div>
                    <?php if ($k['reviewed_at']): ?><div><dt>Reviewed</dt><dd><?= e((string) $k['reviewer']) ?> · <?= e(date('M j, g:i A', strtotime($k['reviewed_at']))) ?><?= $k['review_note'] ? '<br>' . e($k['review_note']) : '' ?></dd></div><?php endif; ?>
                </dl>
            </section>
            <?php if ($k['status'] === 'pending' && admin_can('kyc.review')): ?>
            <form class="card stack" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                <h2>Decision</h2>
                <div class="field"><label for="decision">Result</label><select id="decision" name="decision"><option value="approved">Approve: identity verified</option><option value="needs_info">Ask to resubmit (blurry, wrong side, glare…)</option><option value="rejected">Reject (fake or not the same person)</option></select></div>
                <div class="field"><label for="note">Message to the person</label><input id="note" name="note" type="text" maxlength="255" placeholder="Required when asking to resubmit or rejecting"></div>
                <button class="btn primary" type="submit">Save decision</button>
            </form>
            <?php endif; ?>
            <?php if ($hist): ?><section class="card"><h2>Earlier submissions</h2><ul><?php foreach ($hist as $h): ?><li><a href="?id=<?= (int) $h['id'] ?>"><?= e($h['reference']) ?></a> · <?= kyc_badge($h['status']) ?> · <?= e(date('M j, Y', strtotime($h['created_at']))) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
        </div>
    </div>
    <?php admin_footer(); exit;
}

/* ---------- settings */
if (q('view') === 'settings') {
    $ro = admin_can('settings') ? '' : ' disabled';
    admin_header('KYC verification', 'kyc');
    ?>
    <div class="tabs"><a href="kyc.php">Queue</a><a class="active" href="kyc.php?view=settings">Rules</a></div>
    <form class="card" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="settings">
        <h2>When is a verified ID required?</h2>
        <label><input type="checkbox" name="req_merchant" value="1"<?= kyc_required('merchant') ? ' checked' : '' ?><?= $ro ?>> <b>Merchants</b>: before Admin can approve the merchant</label><br>
        <label><input type="checkbox" name="req_driver" value="1"<?= kyc_required('driver') ? ' checked' : '' ?><?= $ro ?>> <b>URide drivers</b>: before Admin can approve the driver</label><br>
        <label><input type="checkbox" name="req_agent" value="1"<?= kyc_required('agent') ? ' checked' : '' ?><?= $ro ?>> <b>Agents</b>: before approval and before cashing out</label><br>
        <label><input type="checkbox" name="req_user" value="1"<?= kyc_required('user') ? ' checked' : '' ?><?= $ro ?>> <b>Customers</b>: before sending Credits to another person and before UCash In</label>
        <p class="table-meta">Everyone can verify at any time from their Profile / Verify ID page, whether or not it is required.</p>
        <?php if (!$ro): ?><div class="form-actions"><button class="btn primary" type="submit">Save rules</button></div><?php endif; ?>
    </form>
    <?php admin_footer(); exit;
}

/* ---------- queue */
$status = isset(kyc_statuses()[q('status')]) && q('status') !== 'none' ? q('status') : 'pending';
$type = isset($types[q('type')]) ? q('type') : '';
$counts = $pdo->query('SELECT status, COUNT(*) FROM kyc_submissions GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$where = 'k.status = ?'; $params = [$status];
if ($type) { $where .= ' AND k.subject_type = ?'; $params[] = $type; }
[$page, $per, $offset] = paging();
$c = $pdo->prepare("SELECT COUNT(*) FROM kyc_submissions k WHERE $where"); $c->execute($params); $total = (int) $c->fetchColumn();
$s = $pdo->prepare("SELECT k.* FROM kyc_submissions k WHERE $where ORDER BY k.id " . ($status === 'pending' ? 'ASC' : 'DESC') . " LIMIT $per OFFSET $offset");
$s->execute($params); $rows = $s->fetchAll();
admin_header('KYC verification', 'kyc');
?>
<div class="tabs"><a class="active" href="kyc.php">Queue</a><a href="kyc.php?view=settings">Rules</a></div>
<div class="tabs mt"><?php foreach (['pending' => 'To review', 'needs_info' => 'Asked to resubmit', 'approved' => 'Verified', 'rejected' => 'Rejected'] as $k => $l): ?><a class="<?= $status === $k ? 'active' : '' ?>" href="?<?= e(http_build_query(array_filter(['status' => $k, 'type' => $type]))) ?>"><?= $l ?><span><?= (int) ($counts[$k] ?? 0) ?></span></a><?php endforeach; ?></div>
<form class="toolbar" method="get"><input type="hidden" name="status" value="<?= e($status) ?>"><select name="type" data-autosubmit aria-label="Account type"><option value="">All account types</option><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></form>
<div class="table-wrap"><table>
    <thead><tr><th>Reference</th><th>Account</th><th>Name on ID</th><th>ID</th><th>Selfie</th><th>Liveness</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">Nothing here.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $who = kyc_subject_info($pdo, $r['subject_type'], (int) $r['subject_id']); ?>
        <tr><td><a class="row-link" href="?id=<?= (int) $r['id'] ?>"><?= e($r['reference']) ?></a><small><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></small></td>
            <td><?= e($types[$r['subject_type']]) ?><small><?= e($who['name']) ?></small></td>
            <td><?= e($r['full_name']) ?></td>
            <td><?= e(kyc_id_types()[$r['id_type']] ?? $r['id_type']) ?></td>
            <td><img src="<?= e($img($r['selfie'])) ?>" alt="" style="width:44px;height:58px;object-fit:cover;border-radius:6px;transform:scaleX(-1)"></td>
            <td><?= $r['liveness_mode'] === 'auto' ? status_badge('active') : '<span class="badge warn">Manual</span>' ?></td>
            <td><?= kyc_badge($r['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?= pager($page, $per, $total) ?>
<?php admin_footer();
