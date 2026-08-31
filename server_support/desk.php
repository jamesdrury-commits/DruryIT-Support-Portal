<?php
declare(strict_types=1);

require __DIR__ . '/lib/customers.php';

$configPath = '/volume1/homes/jdrury/.druryit-support/config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit('Ticket desk is not configured.');
}
$config = require $configPath;
$key = (string) ($_REQUEST['key'] ?? '');
if ($key === '' || !hash_equals((string) ($config['desk_key'] ?? ''), $key)) {
    http_response_code(404);
    exit('Not found.');
}
$ticketsRoot = (string) ($config['tickets_dir'] ?? '');
if ($ticketsRoot === '' || !is_dir($ticketsRoot)) {
    http_response_code(503);
    exit('Ticket storage is unavailable.');
}
$customerProfiles = loadCustomerProfiles($config);

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function loadTicket(string $root, string $id): ?array {
    if (!preg_match('/^DIT-[0-9]{8}-[0-9]{6}-[A-F0-9]{6}$/', $id)) return null;
    $path = $root . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'ticket.json';
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    return is_array($data) ? $data : null;
}
function saveTicket(string $root, array $ticket): void {
    $path = $root . DIRECTORY_SEPARATOR . $ticket['ticket_id'] . DIRECTORY_SEPARATOR . 'ticket.json';
    file_put_contents($path, json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
}
function removeTicket(string $root, string $id): bool {
    if (!preg_match('/^DIT-[0-9]{8}-[0-9]{6}-[A-F0-9]{6}$/', $id)) return false;
    $rootPath = realpath($root);
    $path = realpath($root . DIRECTORY_SEPARATOR . $id);
    if ($rootPath === false || $path === false || strpos($path, $rootPath . DIRECTORY_SEPARATOR) !== 0 || !is_dir($path)) return false;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    return rmdir($path);
}

if (isset($_GET['download'])) {
    $ticket = loadTicket($ticketsRoot, (string) $_GET['download']);
    $index = filter_var($_GET['attachment'] ?? null, FILTER_VALIDATE_INT);
    if ($ticket === null || $index === false || !isset($ticket['attachments'][$index])) {
        http_response_code(404); exit('Not found.');
    }
    $attachment = $ticket['attachments'][$index];
    $path = $ticketsRoot . DIRECTORY_SEPARATOR . $ticket['ticket_id'] . DIRECTORY_SEPARATOR . $attachment['stored_name'];
    if (!is_file($path)) { http_response_code(404); exit('Attachment unavailable.'); }
    header('Content-Type: ' . $attachment['mime']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $attachment['name']) . '"');
    readfile($path); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticket = loadTicket($ticketsRoot, (string) ($_POST['ticket_id'] ?? ''));
    $status = (string) ($_POST['status'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    $allowed = ['Open', 'In progress', 'Closed', 'Archived'];
    if ((string) ($_POST['action'] ?? '') === 'delete') {
        removeTicket($ticketsRoot, (string) ($_POST['ticket_id'] ?? ''));
        header('Location: desk.php?key=' . rawurlencode($key) . '&filter=' . rawurlencode((string) ($_POST['filter'] ?? 'All')));
        exit;
    }
    if ($ticket !== null && in_array($status, $allowed, true)) {
        $ticket['status'] = $status;
        $ticket['updated_at'] = gmdate('c');
        $ticket['history'][] = ['at' => gmdate('c'), 'status' => $status, 'note' => mb_substr($note, 0, 1000)];
        saveTicket($ticketsRoot, $ticket);
    }
    header('Location: desk.php?key=' . rawurlencode($key) . '&filter=' . rawurlencode((string) ($_POST['filter'] ?? 'All')) . '&ticket=' . rawurlencode((string) ($_POST['ticket_id'] ?? '')));
    exit;
}

$tickets = [];
foreach (glob($ticketsRoot . DIRECTORY_SEPARATOR . 'DIT-*' . DIRECTORY_SEPARATOR . 'ticket.json') ?: [] as $path) {
    $ticket = json_decode((string) file_get_contents($path), true);
    if (is_array($ticket)) $tickets[] = $ticket;
}
usort($tickets, static fn(array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
$filters = ['All', 'Open', 'In progress', 'Closed', 'Archived'];
$filter = (string) ($_GET['filter'] ?? 'All');
if (!in_array($filter, $filters, true)) $filter = 'All';
$filteredTickets = $filter === 'All' ? $tickets : array_values(array_filter($tickets, static fn(array $t): bool => ($t['status'] ?? 'Open') === $filter));
$selected = isset($_GET['ticket']) ? loadTicket($ticketsRoot, (string) $_GET['ticket']) : ($filteredTickets[0] ?? null);
$open = count(array_filter($tickets, static fn(array $t): bool => ($t['status'] ?? '') !== 'Closed'));
$selectedCustomer = $selected === null ? null : ($customerProfiles[(string) ($selected['customer_id'] ?? '')] ?? null);
$selectedLogo = customerLogoDataUri($selectedCustomer);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DruryIT Ticket Desk</title><style>
body{margin:0;background:#f5f7fa;color:#1d2939;font:15px system-ui,-apple-system,"Segoe UI",sans-serif}header{background:#12345b;color:#fff;padding:22px 32px}h1{margin:0;font-size:24px}.sub{opacity:.8;margin-top:4px}.layout{display:grid;grid-template-columns:380px 1fr;gap:20px;padding:20px;max-width:1440px;margin:auto}.panel{background:#fff;border:1px solid #d9e1ea;border-radius:10px;overflow:hidden}.summary{padding:16px;border-bottom:1px solid #d9e1ea;font-weight:600}.ticket{display:block;color:inherit;text-decoration:none;padding:14px 16px;border-bottom:1px solid #eef1f4}.ticket:hover,.ticket.active{background:#eaf3ff}.id{font-weight:700}.meta{font-size:13px;color:#667085;margin-top:4px}.status{display:inline-block;border-radius:99px;padding:3px 8px;font-size:12px;font-weight:700;background:#e8f1fc;color:#14599c}.closed{background:#e7f6ec;color:#18713a}.detail{padding:28px}.detail h2{margin-top:0}.customer-heading{display:flex;align-items:center;gap:12px}.customer-logo{max-width:72px;max-height:48px;object-fit:contain}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:18px 0}.box{background:#f8fafc;padding:12px;border-radius:7px}.label{font-size:12px;color:#667085;text-transform:uppercase;font-weight:700}.problem{white-space:pre-wrap;line-height:1.5;border-left:3px solid #1e6db2;padding-left:14px}.history{padding-left:18px}.history li{margin:8px 0}.actions{margin-top:24px;border-top:1px solid #e6eaf0;padding-top:18px}.actions select,.actions textarea,.actions button{font:inherit;padding:9px;border:1px solid #b8c4d1;border-radius:6px}.actions textarea{width:100%;box-sizing:border-box;margin:10px 0;min-height:70px}.actions button{background:#1666ad;color:white;border:0;cursor:pointer}.attachments a{margin-right:10px}@media(max-width:800px){.layout{grid-template-columns:1fr}.grid{grid-template-columns:1fr}}
</style></head><body><header><h1>DruryIT Ticket Desk</h1><div class="sub"><?= count($tickets) ?> ticket<?= count($tickets) === 1 ? '' : 's' ?> · <?= $open ?> open</div></header><main class="layout"><aside class="panel"><div class="summary">Tickets</div><?php foreach ($tickets as $t): $active=$selected && $t['ticket_id']===$selected['ticket_id']; ?><a class="ticket <?= $active?'active':'' ?>" href="?key=<?= rawurlencode($key) ?>&ticket=<?= rawurlencode($t['ticket_id']) ?>"><div class="id"><?= h($t['ticket_id']) ?> <span class="status <?= ($t['status']??'')==='Closed'?'closed':'' ?>"><?= h($t['status']??'Open') ?></span></div><div><?= h($t['customer_name']??$t['client_name']??'Unknown customer') ?> · <?= h($t['computer']??'Unknown computer') ?></div><div class="meta"><?= h($t['updated_at']??'') ?></div></a><?php endforeach; ?></aside><section class="panel detail"><?php if ($selected === null): ?><p>No portal tickets yet. New client submissions will appear here.</p><?php else: ?><div class="customer-heading"><?php if ($selectedLogo !== ''): ?><img class="customer-logo" src="<?= h($selectedLogo) ?>" alt="<?= h($selectedCustomer['name'] ?? 'Customer') ?> logo"><?php endif; ?><h2><?= h($selected['ticket_id']) ?> <span class="status <?= ($selected['status']??'')==='Closed'?'closed':'' ?>"><?= h($selected['status']??'Open') ?></span></h2></div><div class="grid"><div class="box"><div class="label">Customer / client</div><?= h($selected['customer_name']??$selected['client_name']??'') ?></div><div class="box"><div class="label">Contact</div><?= h($selected['contact_name']??$selected['user']??'') ?><?= empty($selected['contact_email'])?'':' · '.h($selected['contact_email']) ?><?= empty($selected['contact_phone'])?'':' · '.h($selected['contact_phone']) ?></div><div class="box"><div class="label">Computer</div><?= h($selected['computer']??'') ?></div><div class="box"><div class="label">Priority / best time</div><?= h($selected['priority']??'') ?> · <?= h($selected['best_time']??'') ?></div><div class="box"><div class="label">Email delivery</div><?= h($selected['email_status']??'') ?></div></div><h3>Issue</h3><div class="problem"><?= h($selected['problem']??'') ?></div><h3>Attachments</h3><div class="attachments"><?php if (empty($selected['attachments'])): ?>None<?php else: foreach ($selected['attachments'] as $i=>$a): ?><a href="?key=<?= rawurlencode($key) ?>&download=<?= rawurlencode($selected['ticket_id']) ?>&attachment=<?= $i ?>"><?= h($a['name']) ?></a><?php endforeach; endif; ?></div><h3>History</h3><ul class="history"><?php foreach (($selected['history']??[]) as $event): ?><li><strong><?= h($event['status']??'') ?></strong> · <?= h($event['at']??'') ?><?= empty($event['note'])?'':' — '.h($event['note']) ?></li><?php endforeach; ?></ul><form class="actions" method="post"><input type="hidden" name="key" value="<?= h($key) ?>"><input type="hidden" name="ticket_id" value="<?= h($selected['ticket_id']) ?>"><label>Status <select name="status"><?php foreach (['Open','In progress','Closed'] as $s): ?><option <?= ($selected['status']??'')===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></label><textarea name="note" placeholder="Optional update note"></textarea><button type="submit">Save ticket update</button></form><?php endif; ?></section></main></body></html>
