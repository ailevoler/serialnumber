<?php
// "Add to my calendar": downloads the event as an .ics file.
require __DIR__ . '/includes/bootstrap.php';
require_login();
$ev = q_one('SELECT * FROM events WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$ev) redirect('events.php');

$esc = fn(string $s) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s);
$utc = fn(string $local) => gmdate('Ymd\THis\Z', strtotime($local));
$end = $ev['ends_at'] ?: date('Y-m-d H:i:s', strtotime($ev['starts_at']) + 2 * 3600);
$where = trim(implode(', ', array_filter([$ev['venue'], $ev['location']])));
$lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//PCEC Connect//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:event-' . $ev['id'] . '@' . (parse_url(abs_url(''), PHP_URL_HOST) ?: 'pcec-connect'),
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART:' . $utc($ev['starts_at']),
    'DTEND:' . $utc($end),
    'SUMMARY:' . $esc($ev['title']),
    $where ? 'LOCATION:' . $esc($where) : null,
    'DESCRIPTION:' . $esc(trim(($ev['description'] ?? '') . "\n\n" . abs_url('event.php?id=' . $ev['id']))),
    'URL:' . abs_url('event.php?id=' . $ev['id']),
    'END:VEVENT', 'END:VCALENDAR',
];
$name = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ev['title'])), '-') ?: 'event';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '.ics"');
echo implode("\r\n", array_filter($lines)) . "\r\n";
