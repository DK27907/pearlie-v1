# Pearlie deployment guide

## Before deployment

1. Provision a supported PHP runtime, Composer, Node.js, a production database, and durable cache/queue backends appropriate to expected traffic.
2. Supply secrets through the host's secret manager or protected environment configuration; do not commit `.env`.
3. Set `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY`, the canonical HTTPS `APP_URL`, and `SESSION_SECURE_COOKIE=true`.
4. Configure production database, cache, queue, mail, and notification providers. Set `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_BUSINESS_ACCOUNT_ID`, `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_APP_SECRET`, and (if needed) `WHATSAPP_VERSION` in protected environment configuration.
5. Configure Daraja production credentials and the externally reachable HTTPS callback URL in `MPESA_CALLBACK_URL`. The callback handler checks each result with Daraja's STK query API before applying payment state.
6. Set `PEARLIE_NO_SHOW_GRACE_MINUTES` to the hospital's desired grace interval (default: 30 minutes).
7. Do not run sample seeders in production. `DoctorUserSeeder` refuses to create its development-password accounts, and `AdminUserSeeder` requires `ADMIN_PASSWORD`.

## WhatsApp webhook setup

1. Configure a Meta Business app with the WhatsApp product and subscribe to the `messages` webhook field.
2. Set `WHATSAPP_VERIFY_TOKEN` to a private verification string and enter the exact same value in Meta. Set `WHATSAPP_APP_SECRET` to the app secret; the webhook validates `X-Hub-Signature-256`.
3. Register `https://<your-domain>/api/whatsapp/webhook` as the callback URL in Meta and verify the subscription. The callback must be reachable over HTTPS.
4. Confirm the phone number ID and access token are valid in staging. Do not expose tokens or signature data in logs or support tickets.

## Build and migrate

Run these commands from the application release directory:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Review migrations and take a verified database backup before applying schema changes in production. Do not use `migrate:fresh` or `migrate:rollback` as a routine deployment strategy.

## Background processes

Run a queue worker under a process manager and restart it after each release:

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=90
```

Run Laravel's scheduler every minute (for example, using cron):

```cron
* * * * * cd /path/to/pearlie && php artisan schedule:run >> /dev/null 2>&1
```

The scheduled `doctor:daily-summary` command runs at 07:00 in the configured application timezone. The `appointments:mark-no-shows` command runs every 30 minutes and uses `PEARLIE_NO_SHOW_GRACE_MINUTES` (default 30). Configure SMTP or another production mailer so daily summaries and patient no-show notices are delivered; email notices are only sent for appointments with a collected email address. WhatsApp notices require valid Meta credentials. Queue-backed notifications also require an active worker.

## Verification and operations

- Run `php artisan test` and `composer audit` for each release.
- Keep log files rotated and monitor application errors, failed jobs, queue depth, scheduler execution, and database backups.
- Use HTTPS for all public pages and provider callbacks. Confirm secure cookies and `APP_DEBUG=false` after deployment.
- Use `php artisan route:list` and `php artisan schedule:list` to confirm deployed routes and schedule configuration.
- Configure webhook URLs with Meta and Safaricom only after the deployed host is reachable. Keep provider tokens and signatures out of logs and support tickets.
- In the Meta developer dashboard, verify the callback URL `https://<your-domain>/api/whatsapp/webhook`, the matching verify token, app secret, and `messages` subscription.
- Confirm `php artisan schedule:list` shows both the daily doctor summary and 30-minute no-show command, and verify the configured grace period.
- Use a staging environment with provider sandbox credentials before enabling live payments.
