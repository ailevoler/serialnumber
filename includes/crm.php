<?php
declare(strict_types=1);

/* CRM — company leads, member prospects, activities and member 360 notes. */

function crm_statuses(): array
{
    return [
        'new'       => ['label' => 'New',        'badge' => 'info'],
        'contacted' => ['label' => 'Contacted',  'badge' => 'muted'],
        'presented' => ['label' => 'Presented',  'badge' => 'gold'],
        'follow_up' => ['label' => 'Follow-up',  'badge' => 'warning'],
        'converted' => ['label' => 'Converted',  'badge' => 'success'],
        'lost'      => ['label' => 'Lost',       'badge' => 'danger'],
    ];
}

function crm_badge(string $status): string
{
    $s = crm_statuses()[$status] ?? ['label' => $status, 'badge' => 'muted'];
    return '<span class="badge badge-' . $s['badge'] . '">' . e($s['label']) . '</span>';
}

function crm_activity_types(): array
{
    return ['note' => 'Note', 'call' => 'Call', 'sms' => 'SMS / Chat', 'email' => 'Email', 'meeting' => 'Meeting / Presentation', 'task' => 'Task'];
}

function crm_sources(): array
{
    return setting_list('crm_sources') ?: ['Referral', 'Facebook', 'Other'];
}

/** Validate and save a lead from POST data. $ownerId = null for company leads. Returns lead id. */
function crm_save_lead(array $d, ?int $ownerId, int $leadId = 0): int
{
    $name = trim($d['name'] ?? '');
    if ($name === '') {
        throw new RuntimeException('Name is required.');
    }
    $email = trim($d['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid email address.');
    }
    $status = array_key_exists($d['status'] ?? '', crm_statuses()) ? $d['status'] : 'new';
    $interest = in_array($d['interest'] ?? '', ['product', 'business', 'both'], true) ? $d['interest'] : 'both';
    $follow = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['next_follow_up'] ?? '') ? $d['next_follow_up'] : null;
    $vals = [$name, trim($d['mobile'] ?? ''), $email, trim($d['location'] ?? ''), trim($d['source'] ?? 'Referral') ?: 'Referral',
        $interest, $status, $follow, trim($d['notes'] ?? '')];
    if ($leadId) {
        q('UPDATE crm_leads SET name = ?, mobile = ?, email = ?, location = ?, source = ?, interest = ?, status = ?, next_follow_up = ?, notes = ? WHERE id = ?',
            array_merge($vals, [$leadId]));
        return $leadId;
    }
    q('INSERT INTO crm_leads (name, mobile, email, location, source, interest, status, next_follow_up, notes, owner_user_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
        array_merge($vals, [$ownerId]));
    return (int)db()->lastInsertId();
}

function crm_add_activity(?int $leadId, ?int $memberId, ?int $authorId, string $type, string $body, ?string $due = null): void
{
    $body = trim($body);
    if ($body === '') {
        throw new RuntimeException('Activity details are required.');
    }
    $type = array_key_exists($type, crm_activity_types()) ? $type : 'note';
    $due = $due && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null;
    q('INSERT INTO crm_activities (lead_id, member_id, author_id, type, body, due_date) VALUES (?,?,?,?,?,?)',
        [$leadId, $memberId, $authorId, $type, mb_substr($body, 0, 5000), $due]);
    if ($leadId) {
        q('UPDATE crm_leads SET updated_at = NOW() WHERE id = ?', [$leadId]);
    }
}

function crm_activities(string $field, int $id, int $limit = 50): array
{
    $field = $field === 'member_id' ? 'member_id' : 'lead_id';
    return q_all("SELECT a.*, u.first_name, u.last_name, u.role FROM crm_activities a LEFT JOIN users u ON u.id = a.author_id
                  WHERE a.{$field} = ? ORDER BY a.id DESC LIMIT {$limit}", [$id]);
}

/** Timeline HTML for activities. */
function crm_timeline(array $acts): string
{
    if (!$acts) {
        return '<p class="text-muted mb-0">No activities yet.</p>';
    }
    $types = crm_activity_types();
    $h = '<ul class="timeline">';
    foreach ($acts as $a) {
        $h .= '<li class="tl-' . e($a['type']) . '"><div class="tl-head"><span class="badge badge-muted">' . e($types[$a['type']] ?? $a['type']) . '</span> '
            . '<strong>' . e(full_name($a) ?: 'System') . '</strong><small>' . fdatetime($a['created_at']) . '</small></div>'
            . '<p>' . nl2br(e($a['body'])) . '</p>'
            . ($a['due_date'] ? '<small class="text-gold">Due ' . fdate($a['due_date']) . ($a['done'] ? ' · done' : '') . '</small>' : '')
            . '</li>';
    }
    return $h . '</ul>';
}
