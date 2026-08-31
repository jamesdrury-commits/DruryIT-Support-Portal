DRURYIT SUPPORT V1 — TEST / DEPLOY HANDOFF

WINDOWS TEST
1. Run DruryITSupportSetup.exe.
2. The installer places the app in %LOCALAPPDATA%\DruryIT Support and creates Desktop + Start menu shortcuts.
3. It opens DruryIT Support automatically after install.
4. The app is preconfigured to POST to:
   https://support.druryit.com/api/ticket.php
5. Until the server endpoint and SMTP configuration are live, pressing Send will correctly show a send failure; the text stays on screen.

WINDOWS CLIENT FEATURES
- DruryIT branded UI
- "Tell James what's happening."
- problem description
- urgency
- preferred connection time
- Capture Screen
- Paste Screenshot (Windows+Shift+S, then Paste Screenshot)
- file attachments
- automatic client/computer/user information
- secure Bearer token
- HTTPS multipart upload
- server ticket ID confirmation

SERVER
The server/ folder contains:
- index.php        simple support.druryit.com landing/status page
- health.php       JSON health check
- ticket.php       authenticated API + direct server-side SMTP + attachments
- config.example.php
- DEPLOY_TO_DRUSAN.sh

SMTP
Edit /volume1/web_private/druryit-support/config.php after deployment.
The Windows/Mac app does NOT contain SMTP credentials.
The server performs SMTP using TLS/SSL.

CLOUDFLARE
Recommended hostname:
  support.druryit.com

Add a Published Application route on the existing DruSAN Cloudflare Tunnel and map support.druryit.com to the Synology Web Station service that will serve the support site.
Cloudflare will create the DNS record when the published route is added from the dashboard.

SYNOLOGY
The provided deploy script defaults to:
  /volume1/web/support.druryit.com
and private configuration/logs to:
  /volume1/web_private/druryit-support

If your Web Station document root differs, run:
  WEBROOT=/your/real/path ./DEPLOY_TO_DRUSAN.sh

Then configure a Web Station virtual host for support.druryit.com to that document root, using the PHP version already used by druryit.com.

MAC
Two unsigned app bundles are included separately:
- arm64 = Apple Silicon (M1/M2/M3/M4/M5 etc.)
- amd64 = Intel Mac

They use the same support endpoint and token. Because they are not Apple Developer-signed/notarized, macOS Gatekeeper may require right-click -> Open for testing.
MAKE_DRURYIT_DMG_ON_MAC.sh can turn the appropriate .app into a DMG when run on a Mac with hdiutil.

SECURITY
This V1 uses a unique random test deployment token. Before client rollout, issue a separate token per client/device and add rate limiting / token revocation.
