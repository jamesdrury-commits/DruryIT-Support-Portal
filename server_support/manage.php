<?php
declare(strict_types=1);

$configPath = '/volume1/homes/jdrury/.druryit-support/config.php';
$config = is_file($configPath) ? require $configPath : [];
$key = (string) ($_REQUEST['key'] ?? '');
if ($key === '' || !hash_equals((string) ($config['desk_key'] ?? ''), $key)) { http_response_code(404); exit('Not found.'); }
$root = (string) ($config['tickets_dir'] ?? '');
if (!is_dir($root)) { http_response_code(503); exit('Ticket storage is unavailable.'); }
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ticketPath(string $root, string $id): ?string { return preg_match('/^DIT-[0-9]{8}-[0-9]{6}-[A-F0-9]{6}$/', $id) ? $root . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'ticket.json' : null; }
function removeTicketRecord(string $root, string $id): bool {
    $path = ticketPath($root, $id); $rootReal = realpath($root); $dir = $path ? realpath(dirname($path)) : false;
    if ($rootReal === false || $dir === false || strpos($dir, $rootReal . DIRECTORY_SEPARATOR) !== 0) return false;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) { $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    return rmdir($dir);
}
$statuses = ['All', 'Open', 'In progress', 'Closed', 'Archived'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (string) ($_POST['ticket_id'] ?? ''); $filter = (string) ($_POST['filter'] ?? 'All');
    if ((string) ($_POST['action'] ?? '') === 'delete') { removeTicketRecord($root, $id); }
    else {
        $path = ticketPath($root, $id); $ticket = $path && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $status = (string) ($_POST['status'] ?? '');
        if (is_array($ticket) && in_array($status, array_slice($statuses, 1), true)) {
            $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 1000);
            $ticket['status'] = $status; $ticket['updated_at'] = gmdate('c');
            $ticket['history'][] = ['at' => gmdate('c'), 'status' => $status, 'note' => $note];
            file_put_contents($path, json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
        }
    }
    header('Location: manage.php?key=' . rawurlencode($key) . '&filter=' . rawurlencode($filter)); exit;
}
$filter = (string) ($_GET['filter'] ?? 'All'); if (!in_array($filter, $statuses, true)) $filter = 'All';
$tickets = [];
foreach (glob($root . DIRECTORY_SEPARATOR . 'DIT-*' . DIRECTORY_SEPARATOR . 'ticket.json') ?: [] as $path) { $t = json_decode((string) file_get_contents($path), true); if (is_array($t) && ($filter === 'All' || ($t['status'] ?? 'Open') === $filter)) $tickets[] = $t; }
usort($tickets, static fn(array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>DruryIT Ticket Management</title><style>body{max-width:1200px;margin:30px auto;padding:0 18px;background:#f6f8fb;color:#1c2735;font:15px system-ui}h1{color:#12345b}form.filters{display:flex;gap:10px;align-items:center;margin:20px 0}select,button,textarea{font:inherit;padding:8px;border:1px solid #c7d1dc;border-radius:6px}button{background:#1666ad;color:#fff;border:0;cursor:pointer}.danger{background:#b42318}.ticket{background:#fff;border:1px solid #dce3eb;border-radius:8px;padding:16px;margin:12px 0}.head{display:flex;justify-content:space-between;gap:12px}.id{font-weight:700}.meta{color:#667085;margin:5px 0}.issue{white-space:pre-wrap;margin:10px 0}.actions{display:grid;grid-template-columns:180px 1fr auto auto;gap:8px;align-items:start;margin-top:12px}.actions textarea{min-height:38px}@media(max-width:700px){.actions{grid-template-columns:1fr}.head{display:block}}</style><h1>DruryIT Ticket Management</h1><form class="filters" method="get"><input type="hidden" name="key" value="<?= e($key) ?>"><label>Status <select name="filter"><?php foreach($statuses as $s): ?><option <?= $filter===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select></label><button>Filter</button><a href="desk.php?key=<?= rawurlencode($key) ?>">Detailed desk</a></form><p><?= count($tickets) ?> matching ticket<?= count($tickets)===1?'':'s' ?></p><?php foreach($tickets as $t): ?><section class="ticket"><div class="head"><div class="id"><?= e($t['ticket_id']) ?> - <?= e($t['status']??'Open') ?></div><a href="desk.php?key=<?= rawurlencode($key) ?>&ticket=<?= rawurlencode($t['ticket_id']) ?>">View details</a></div><div class="meta"><?= e($t['client_name']??'') ?> | <?= e($t['computer']??'') ?> | <?= e($t['user']??'') ?> | <?= e($t['priority']??'') ?></div><div class="issue"><?= e($t['problem']??'') ?></div><form class="actions" method="post"><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="filter" value="<?= e($filter) ?>"><input type="hidden" name="ticket_id" value="<?= e($t['ticket_id']) ?>"><select name="status"><?php foreach(array_slice($statuses,1) as $s): ?><option <?= ($t['status']??'')===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select><textarea name="note" placeholder="Optional update note"></textarea><button name="action" value="save">Save</button><button class="danger" name="action" value="delete" onclick="return confirm('Permanently delete this ticket and its attachments?');">Delete</button></form></section><?php endforeach; ?></html>
