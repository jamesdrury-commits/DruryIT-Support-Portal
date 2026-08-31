# DruryIT Support Portal — deployed configuration

## Public application

- Files: `/volume1/web/support.druryit.com`
- Health check: `https://support.druryit.com/health.php`
- Ticket API: `https://support.druryit.com/api/ticket.php`
- Web Station virtual host: port-based HTTP on `8080`, Nginx, PHP 7.4 Default Profile, document root `web/support.druryit.com`.

Using port 8080 keeps this application separate from the existing ports 80 and 443 virtual hosts and does not change the existing `druryit.com` website.

## Cloudflare

The existing `druryit` tunnel has a published application route:

`support.druryit.com` → `http://192.168.1.170:8080`

The existing `druryit.com` tunnel routes were preserved.

## Private settings and mail

- Settings: `/volume1/homes/jdrury/.druryit-support/config.php` (mode 640)
- Logs: `/volume1/homes/jdrury/.druryit-support/logs/tickets.log` (directory mode 770, group `http`)
- Private parent: `/volume1/homes/jdrury/.druryit-support` (mode 750, group `http`)

Ticket notifications use Microsoft Graph's app-only client-credentials flow. The tenant ID, app ID, client secret, and sending mailbox are stored only in the private NAS config; none are present in the Windows client or public application files. The portal sends as `james@druryit.com` and delivers to the configured support recipient.

The Entra app registration is **DruryIT Support Portal Mailer**. It has Microsoft Graph `Mail.Send` application permission, with Exchange Online Application Access Policy restriction through the mail-enabled security group `druryit-support-portal-mail@druryit.onmicrosoft.com`. That group contains only `james@druryit.com`; access-policy verification grants James and denies other mailboxes.

The API deployment token is server-side in the private config and must not be copied into the client beyond the existing installer configuration.

Support tickets are addressed to `james@druryit.com` and sent from `support@druryit.com`, matching the existing DruryIT service-ticket form.

## Private ticket desk

`/desk.php` is an unlinked ticket-management page. It requires the long `desk_key` from the private config as a URL parameter. It lists newly received tickets, provides attachment downloads, keeps ticket history, and lets the operator set a ticket to Open, In progress, or Closed.

Ticket records and copied attachments are stored privately in `/volume1/homes/jdrury/.druryit-support/tickets`. Existing historical email-only requests are not imported; new submissions are recorded automatically.

## Private customer profiles

Customer data belongs in `/volume1/homes/jdrury/.druryit-support/customers`, not in the public `web` folder. Create one folder per customer and place a hand-maintained `customer.json` inside it. A starter file is included in `server_support/customers.example/acme-accounting/customer.json`.

Each profile can include the company name, main contact details, a small `logo.png` (or JPG/GIF), match values for deployed `client_ids`, known computer names, Windows usernames, and individual contacts. New tickets are matched in that order: client ID, computer, then username. The resolved company/contact details are copied into the ticket record and included in the notification email. The ticket desk reads the current profile to display its logo.

Add this private setting to `config.php` if it is not already present:

```php
'customers_dir' => '/volume1/homes/jdrury/.druryit-support/customers',
```

Keep secrets, passwords, and recovery codes out of customer profiles. Back up this private customer directory with the private ticket directory.

## Rollback

1. In Cloudflare Zero Trust, remove only the `support.druryit.com` published application route from tunnel `druryit`.
2. In Web Station, delete only the port-8080 virtual host whose document root is `web/support.druryit.com`.
3. Move (do not delete until confirmed) `/volume1/web/support.druryit.com` and `/volume1/homes/jdrury/.druryit-support` to dated backup paths.
4. To revert mail delivery only, restore `/volume1/web/support.druryit.com/api/ticket.php.pre-graph-20260830` over `api/ticket.php`, then restore `/volume1/homes/jdrury/.druryit-support/config.php.pre-graph-20260830` over `config.php`.
5. To remove Microsoft mail access completely, delete the Exchange Application Access Policy, the dedicated mail-enabled security group, the Entra app registration, and its client secret.

No existing `druryit.com` files, routes, or virtual hosts need to be changed for rollback.
