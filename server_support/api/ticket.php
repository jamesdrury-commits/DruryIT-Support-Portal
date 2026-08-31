<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require dirname(__DIR__) . '/lib/PHPMailer/src/Exception.php';
require dirname(__DIR__) . '/lib/PHPMailer/src/PHPMailer.php';
require dirname(__DIR__) . '/lib/PHPMailer/src/SMTP.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function logTicket(array $config, array $data): bool {
    $line = json_encode($data, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return @file_put_contents($config['log_file'], $line, FILE_APPEND | LOCK_EX) !== false;
}

/**
 * Send a ticket through Microsoft Graph using the server application's
 * client-credentials flow. The app secret remains in the private NAS config.
 */
function sendGraphMail(array $config, string $subject, string $body, array $attachments): void {
    $tenantId = (string) ($config['graph_tenant_id'] ?? '');
    $clientId = (string) ($config['graph_client_id'] ?? '');
    $clientSecret = (string) ($config['graph_client_secret'] ?? '');
    $sender = (string) ($config['graph_sender'] ?? '');
    $recipient = (string) ($config['support_to'] ?? '');
    if ($tenantId === '' || $clientId === '' || $clientSecret === '' || $sender === '' || $recipient === '') {
        throw new RuntimeException('Microsoft Graph mail configuration is incomplete');
    }

    $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
    $tokenFields = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ], '', '&');
    $curl = curl_init($tokenUrl);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $tokenFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $tokenResponse = curl_exec($curl);
    $tokenStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $tokenError = curl_error($curl);
    curl_close($curl);
    $token = json_decode((string) $tokenResponse, true);
    if ($tokenError !== '' || $tokenStatus !== 200 || !is_array($token) || empty($token['access_token'])) {
        throw new RuntimeException('Microsoft token request failed' . ($tokenError !== '' ? ': ' . $tokenError : ''));
    }

    $graphAttachments = [];
    foreach ($attachments as $attachment) {
        $content = @file_get_contents($attachment['path']);
        if ($content === false) {
            throw new RuntimeException('Could not read stored attachment for email');
        }
        $graphAttachments[] = [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $attachment['name'],
            'contentType' => $attachment['mime'],
            'contentBytes' => base64_encode($content),
        ];
    }
    $payload = json_encode([
        'message' => [
            'subject' => $subject,
            'body' => ['contentType' => 'Text', 'content' => $body],
            'toRecipients' => [['emailAddress' => ['address' => $recipient]]],
            'attachments' => $graphAttachments,
        ],
        'saveToSentItems' => true,
    ]);
    if ($payload === false) {
        throw new RuntimeException('Could not encode email request');
    }
    $sendUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender) . '/sendMail';
    $curl = curl_init($sendUrl);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token['access_token'],
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($error !== '' || $status !== 202) {
        throw new RuntimeException('Microsoft Graph send failed' . ($error !== '' ? ': ' . $error : ' (HTTP ' . $status . ')'));
    }
}

function writeTicket(string $path, array $ticket): void {
    $encoded = json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || @file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Could not save ticket record');
    }
}

function authorizedClient(array $tokens, string $provided): ?array {
    foreach ($tokens as $token => $client) {
        if (hash_equals((string) $token, $provided)) {
            return $client;
        }
    }
    return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'POST required']);
}

$configPath = '/volume1/homes/jdrury/.druryit-support/config.php';
if (!is_file($configPath)) {
    respond(503, ['ok' => false, 'error' => 'Support service is not configured']);
}
$config = require $configPath;
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\\s+(.+)$/i', $authorization, $match)) {
    respond(401, ['ok' => false, 'error' => 'Missing deployment token']);
}
$client = authorizedClient($config['client_tokens'] ?? [], trim($match[1]));
if ($client === null) {
    respond(403, ['ok' => false, 'error' => 'Invalid deployment token']);
}

$request = json_decode((string) ($_POST['ticket'] ?? ''), true);
if (!is_array($request)) {
    respond(400, ['ok' => false, 'error' => 'Invalid ticket JSON']);
}
$problem = trim((string) ($request['problem'] ?? ''));
if ($problem === '') {
    respond(400, ['ok' => false, 'error' => 'Problem description is required']);
}
if (mb_strlen($problem) > 8000) {
    respond(400, ['ok' => false, 'error' => 'Problem description is too long']);
}

$files = $_FILES['attachments'] ?? null;
$attachments = [];
$totalBytes = 0;
$maxCount = (int) ($config['max_attachments'] ?? 5);
$maxFileBytes = (int) ($config['max_attachment_bytes'] ?? 5242880);
$maxTotalBytes = (int) ($config['max_total_attachment_bytes'] ?? 16777216);
$allowed = $config['allowed_types'] ?? [];
if (is_array($files) && is_array($files['name'] ?? null)) {
    if (count($files['name']) > $maxCount) {
        respond(400, ['ok' => false, 'error' => 'Too many attachments']);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($files['name'] as $index => $submittedName) {
        $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) respond(400, ['ok' => false, 'error' => 'Attachment upload failed']);
        $size = (int) ($files['size'][$index] ?? 0);
        $tmp = (string) ($files['tmp_name'][$index] ?? '');
        $name = basename((string) $submittedName);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = $tmp !== '' ? $finfo->file($tmp) : false;
        if ($size < 1 || $size > $maxFileBytes || !isset($allowed[$extension]) || $mime !== $allowed[$extension]) {
            respond(400, ['ok' => false, 'error' => 'Attachment type or size is not allowed']);
        }
        $totalBytes += $size;
        if ($totalBytes > $maxTotalBytes) respond(400, ['ok' => false, 'error' => 'Attachments exceed the total size limit']);
        $attachments[] = ['path' => $tmp, 'name' => $name, 'mime' => $mime];
    }
}

$ticketId = 'DIT-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
$clientName = (string) ($client['client_name'] ?? 'Unknown client');
$clientId = (string) ($client['client_id'] ?? '');
$computer = trim((string) ($request['computerName'] ?? 'Unknown PC'));
$user = trim((string) ($request['userName'] ?? 'Unknown user'));
$priority = trim((string) ($request['priority'] ?? 'Normal'));
$bestTime = trim((string) ($request['bestTime'] ?? 'Any time'));
$windows = trim((string) ($request['windowsVersion'] ?? ''));
$submitted = trim((string) ($request['submittedAtLocal'] ?? ''));

$body = "New DruryIT Support Request\n\n"
    . "Ticket: {$ticketId}\nClient: {$clientName}\nClient ID: {$clientId}\n"
    . "User: {$user}\nComputer: {$computer}\nPriority: {$priority}\n"
    . "Best time to connect: {$bestTime}\nWindows: {$windows}\n"
    . "Submitted from client: {$submitted}\nReceived by server: " . gmdate('c') . "\n\n"
    . "Problem:\n{$problem}\n";

$ticket = [
    'ticket_id' => $ticketId,
    'status' => 'Open',
    'created_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
    'email_status' => 'pending',
    'client_name' => $clientName,
    'client_id' => $clientId,
    'computer' => $computer,
    'user' => $user,
    'priority' => $priority,
    'best_time' => $bestTime,
    'windows' => $windows,
    'submitted_at_local' => $submitted,
    'problem' => $problem,
    'attachments' => [],
    'history' => [[
        'at' => gmdate('c'),
        'status' => 'Open',
        'note' => 'Ticket received',
    ]],
];

try {
    $ticketsRoot = (string) ($config['tickets_dir'] ?? '');
    if ($ticketsRoot === '' || !is_dir($ticketsRoot)) {
        throw new RuntimeException('Ticket storage is unavailable');
    }
    $ticketDir = $ticketsRoot . DIRECTORY_SEPARATOR . $ticketId;
    if (!mkdir($ticketDir, 0770, true) && !is_dir($ticketDir)) {
        throw new RuntimeException('Could not create ticket storage');
    }
    foreach ($attachments as $index => &$attachment) {
        $storedName = sprintf('%02d-%s', $index + 1, preg_replace('/[^A-Za-z0-9._-]/', '_', $attachment['name']));
        $target = $ticketDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($attachment['path'], $target)) {
            throw new RuntimeException('Could not save attachment');
        }
        $attachment['path'] = $target;
        $attachment['stored_name'] = $storedName;
        $ticket['attachments'][] = [
            'name' => $attachment['name'],
            'stored_name' => $storedName,
            'mime' => $attachment['mime'],
            'size' => filesize($target),
        ];
    }
    unset($attachment);
    writeTicket($ticketDir . DIRECTORY_SEPARATOR . 'ticket.json', $ticket);
} catch (Throwable $exception) {
    error_log("DruryIT support {$ticketId}: ticket storage failed");
    respond(503, ['ok' => false, 'error' => 'Ticket storage is temporarily unavailable']);
}

try {
    $subject = "DruryIT Support - {$clientName} - {$computer} - {$ticketId}";
    if (!empty($config['graph_tenant_id'])) {
        sendGraphMail($config, $subject, $body, $attachments);
    } else {
        // Legacy fallback retained solely for an explicit config rollback.
        $mail = new PHPMailer(true);
        $mail->isSendmail();
        $mail->Sendmail = '/usr/sbin/sendmail';
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($config['from_address'], $config['from_name']);
        $mail->addAddress($config['support_to']);
        $mail->Subject = $subject;
        $mail->Body = $body;
        foreach ($attachments as $attachment) {
            $mail->addAttachment($attachment['path'], $attachment['name'], PHPMailer::ENCODING_BASE64, $attachment['mime']);
        }
        $mail->send();
    }
} catch (Exception $exception) {
    $ticket['email_status'] = 'failed';
    $ticket['updated_at'] = gmdate('c');
    $ticket['history'][] = ['at' => gmdate('c'), 'status' => 'Open', 'note' => 'Email delivery failed'];
    writeTicket($ticketDir . DIRECTORY_SEPARATOR . 'ticket.json', $ticket);
    logTicket($config, ['ticket_id' => $ticketId, 'at' => gmdate('c'), 'client_id' => $clientId, 'status' => 'mail_failed', 'mail_error' => $exception->getMessage()]);
    error_log("DruryIT support {$ticketId}: mail delivery failed");
    respond(502, ['ok' => false, 'error' => 'Ticket delivery failed']);
}

$ticket['email_status'] = 'sent';
$ticket['updated_at'] = gmdate('c');
$ticket['history'][] = ['at' => gmdate('c'), 'status' => 'Open', 'note' => 'Email sent to support'];
writeTicket($ticketDir . DIRECTORY_SEPARATOR . 'ticket.json', $ticket);

logTicket($config, ['ticket_id' => $ticketId, 'at' => gmdate('c'), 'client_id' => $clientId, 'computer' => $computer, 'user' => $user, 'priority' => $priority, 'attachment_count' => count($attachments), 'status' => 'sent']);
respond(200, ['ok' => true, 'ticketId' => $ticketId]);
