<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_cms();

$types = cms_item_types();
$type = array_key_exists(input('type'), $types) ? input('type') : 'faq';
$def = $types[$type];

if (is_post()) {
    csrf_check();
    $id = input_int('id');
    try {
        $action = input('action');
        if ($action === 'delete') {
            q('DELETE FROM cms_items WHERE id = ? AND type = ?', [$id, $type]);
            flash('success', 'Item deleted.');
        } elseif ($action === 'move') {
            $item = q_row('SELECT * FROM cms_items WHERE id = ? AND type = ?', [$id, $type]);
            if ($item) {
                $dir = input('dir') === 'up' ? -1 : 1;
                $rows = q_all('SELECT id FROM cms_items WHERE type = ? ORDER BY sort_order, id', [$type]);
                $ids = array_map('intval', array_column($rows, 'id'));
                $pos = array_search($id, $ids, true);
                $swap = $pos + $dir;
                if ($pos !== false && isset($ids[$swap])) {
                    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                }
                foreach ($ids as $i => $iid) {
                    q('UPDATE cms_items SET sort_order = ? WHERE id = ?', [$i + 1, $iid]);
                }
            }
        } elseif ($action === 'save') {
            $title = input('title');
            if ($title === '') {
                throw new RuntimeException($def['fields']['title'] . ' is required.');
            }
            $icon = in_array(input('icon'), cms_icon_choices(), true) ? input('icon') : null;
            $status = input('status') === 'draft' ? 'draft' : 'published';
            $image = isset($def['fields']['image']) ? (cms_upload('img', 'item', (int)$me['id']) ?? (input('image') ?: null)) : null;
            $vals = [mb_substr($title, 0, 160), mb_substr(input('subtitle'), 0, 160) ?: null, input('body') ?: null, $image, $icon, $status];
            if ($id) {
                q('UPDATE cms_items SET title = ?, subtitle = ?, body = ?, image = ?, icon = ?, status = ? WHERE id = ? AND type = ?', array_merge($vals, [$id, $type]));
                flash('success', 'Item updated.');
            } else {
                $next = (int)q_val('SELECT COALESCE(MAX(sort_order),0) + 1 FROM cms_items WHERE type = ?', [$type]);
                q('INSERT INTO cms_items (title, subtitle, body, image, icon, status, type, sort_order) VALUES (?,?,?,?,?,?,?,?)', array_merge($vals, [$type, $next]));
                flash('success', 'Item added.');
            }
        }
        log_activity((int)$me['id'], "CMS: {$action} {$type} item #{$id}");
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
        redirect('admin/cms_items.php?type=' . $type . ($id ? '&edit=' . $id : ''));
    }
    redirect('admin/cms_items.php?type=' . $type);
}

$items = cms_items($type, false);
$edit = input_int('edit') ? q_row('SELECT * FROM cms_items WHERE id = ? AND type = ?', [input_int('edit'), $type]) : null;
$f = $def['fields'];

page_header('Lists & Testimonials', $me, 'Stats, Why XGEN items, testimonials and FAQs');
?>
<div class="tabs">
  <?php foreach ($types as $k => $t): ?><a href="?type=<?= $k ?>" class="<?= $type === $k ? 'active' : '' ?>"><?= e($t['label']) ?></a><?php endforeach; ?>
</div>
<div class="grid grid-main">
  <div class="card">
    <div class="card-header"><h3><?= icon('list') ?> <?= e($def['label']) ?></h3>
      <div class="actions"><a class="btn btn-light btn-sm" href="<?= e(url('admin/cms_section.php?s=' . $def['section'])) ?>">Section settings</a></div></div>
    <div class="card-body p-0 table-wrap">
      <table class="table stack">
        <thead><tr><th>Order</th><th><?= e($f['title']) ?></th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $i => $it): ?>
          <tr>
            <td class="nowrap">
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                <button class="btn btn-xs btn-light" name="dir" value="up" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Move up">↑</button>
                <button class="btn btn-xs btn-light" name="dir" value="down" <?= $i === count($items) - 1 ? 'disabled' : '' ?> aria-label="Move down">↓</button></form>
            </td>
            <td>
              <div class="user-cell">
                <?php if ($type === 'feature'): ?><div class="cms-ic"><?= icon($it['icon'] ?: 'shield', 16) ?></div><?php endif; ?>
                <?php if ($type === 'testimonial' && $it['image']): ?><img class="thumb" src="<?= e(cms_img($it['image'])) ?>" alt=""><?php endif; ?>
                <div><strong><?= e($it['title']) ?></strong><?php if ($it['subtitle'] || $it['body']): ?><small><?= e(mb_strimwidth((string)($it['subtitle'] ?: $it['body']), 0, 90, '…')) ?></small><?php endif; ?></div>
              </div>
            </td>
            <td><?= badge($it['status']) ?></td>
            <td class="nowrap"><a class="btn btn-xs btn-outline" href="?type=<?= $type ?>&edit=<?= (int)$it['id'] ?>">Edit</a>
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><button class="btn btn-xs btn-light" data-confirm="Delete this item?">Delete</button></form></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items) echo empty_row(4, 'No items yet. Add your first one.'); ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon($edit ? 'settings' : 'plus') ?> <?= $edit ? 'Edit item' : 'Add item' ?></h3></div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <?php if (isset($f['icon'])): ?>
          <div class="form-group"><label>Icon</label>
            <div class="icon-pick"><?php foreach (cms_icon_choices() as $ic): ?><label><input type="radio" name="icon" value="<?= $ic ?>" <?= ($edit['icon'] ?? 'shield') === $ic ? 'checked' : '' ?>><span title="<?= $ic ?>"><?= icon($ic, 18) ?></span></label><?php endforeach; ?></div></div>
        <?php endif; ?>
        <div class="form-group"><label><?= e($f['title']) ?></label><input class="input" name="title" value="<?= e($edit['title'] ?? '') ?>" maxlength="160" required></div>
        <?php if (isset($f['subtitle'])): ?><div class="form-group"><label><?= e($f['subtitle']) ?></label><input class="input" name="subtitle" value="<?= e($edit['subtitle'] ?? '') ?>" maxlength="160"></div><?php endif; ?>
        <?php if (isset($f['body'])): ?><div class="form-group"><label><?= e($f['body']) ?></label><textarea class="input" name="body" rows="5"><?= e($edit['body'] ?? '') ?></textarea></div><?php endif; ?>
        <?php if (isset($f['image'])): ?><div class="form-group"><label><?= e($f['image']) ?></label><?= cms_image_field('image', 'item', (string)($edit['image'] ?? '')) ?></div><?php endif; ?>
        <div class="form-group"><label>Status</label><select class="input" name="status"><option value="published">Published</option><option value="draft" <?= ($edit['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft (hidden)</option></select></div>
        <?php if ($type === 'testimonial'): ?><p class="help">Only publish genuine member testimonials with their permission.</p><?php endif; ?>
        <button class="btn btn-primary" type="submit">Save</button>
        <?php if ($edit): ?><a class="btn btn-light" href="?type=<?= $type ?>">Cancel</a><?php endif; ?>
      </form>
    </div>
  </div>
</div>
<?php page_footer();
