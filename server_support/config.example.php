<?php
return [
    // Reuse the recipient from the current DruryIT service-ticket configuration.
    'support_to' => 'YOUR-SUPPORT-INBOX@example.com',
    'from_address' => 'support@druryit.com',
    'from_name' => 'DruryIT Support',
    // Optional Microsoft Graph app-only mail delivery. All values stay private
    // on the NAS; use graph_sender as the mailbox the app is allowed to send as.
    'graph_tenant_id' => 'YOUR-TENANT-ID',
    'graph_client_id' => 'YOUR-APP-CLIENT-ID',
    'graph_client_secret' => 'YOUR-APP-CLIENT-SECRET',
    'graph_sender' => 'support-sender@example.com',
    // Generate a long random value before deployment. This is the private desk access key.
    'desk_key' => 'replace-with-a-long-random-access-key',
    'tickets_dir' => '/volume1/homes/jdrury/.druryit-support/tickets',
    'client_tokens' => [
        'REPLACE-WITH-THE-DEPLOYMENT-TOKEN' => ['client_id' => 'druryit-test', 'client_name' => 'DruryIT Test'],
    ],
    'max_attachments' => 5,
    'max_attachment_bytes' => 5 * 1024 * 1024,
    'max_total_attachment_bytes' => 16 * 1024 * 1024,
    'allowed_types' => [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'pdf' => 'application/pdf', 'txt' => 'text/plain',
        'log' => 'text/plain',
    ],
    'log_file' => '/volume1/homes/jdrury/.druryit-support/logs/tickets.log',
];
