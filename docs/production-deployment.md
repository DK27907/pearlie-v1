# Production deployment

Deploy only after staging has passed the payment, tenant-isolation, authorization, notification, queue, and scheduler checks. Do not copy local demo accounts, a KSh 1 service price, or sandbox credentials into production.

## Prepare an isolated production environment

Use separate production and staging environments, databases, provider credentials, and sending identities. In Laravel Cloud, connect the Git repository, create separate staging and production environments, select the required PHP runtime and region, and keep the application and attached resources in the same region where possible.

Provision and attach the production database and shared cache before deploying. Configure the queue and persistent file storage for the workload. Cloud filesystems are ephemeral between deployments and replicas; do not rely on a local path for uploads, sessions, generated files, or cache. Use the environment's configured persistent storage and the Cloud storage visibility model appropriate to each bucket.

Before the first production release, verify the environment's build and deploy commands, attach its database and cache, configure its worker and scheduler processes, and confirm that production secrets are linked only to production. Do not use a local `.env` file as the production configuration source.

## Configure deployment secrets

Set secrets through the hosting platform's secret manager, not in source control or a committed environment file. At minimum, set:

- `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY`, and the public HTTPS `APP_URL`.
- Database, cache, queue, session, and filesystem settings for the attached production resources.
- A production M-Pesa environment, verified public callback URL, approved shortcode, consumer key/secret, and passkey. Prefer tenant-specific credentials where each hospital must use a separate provider account.
- A verified mail sender and provider credentials. Resend's testing-domain recipient restriction is separate from TLS and requires a verified sender domain for normal onboarding recipients.
- Approved WhatsApp and SMS provider credentials and public webhook URLs, if those channels are enabled.

Do not place production keys in build logs, screenshots, command-line arguments, or source files. Never reuse development passwords, test phone numbers, or sandbox payment credentials.

## Per-tenant integration credentials

Set `INTEGRATIONS_ALLOW_GLOBAL_FALLBACK=false` in production. The application defaults this flag to `false` outside the test environment. A global provider configuration must not silently supply a tenant's missing credentials. Only enable global fallback deliberately for a single-tenant installation or a controlled test environment; never enable it as a workaround for an unconfigured production hospital.

Every active hospital that uses M-Pesa must have its own `consumer_key`, `consumer_secret`, `shortcode`, and `passkey` saved in its integration settings or legacy encrypted hospital fields. The callback URL may be a shared application configuration or an explicit per-hospital integration setting. WhatsApp-enabled hospitals must have tenant `phone_number_id` and `access_token`; webhook handling also requires a tenant `verify_token` and `app_secret`. SMS-enabled hospitals must configure the selected provider's credentials: Africa's Talking requires `username` and `api_key`; Twilio requires `from_number`, `account_sid`, and either `auth_token` or the `twilio_api_key`/`api_secret` pair.

Tenant M-Pesa credentials are resolved by `MpesaService`; outbound WhatsApp credentials are resolved by `WhatsAppService` and `NotificationService`; SMS credentials are resolved by `NotificationService`; WhatsApp webhook verification credentials are resolved by `WhatsAppWebhookController`. Each tenant-bound path throws a `MissingProviderCredentialsException` containing the hospital slug, provider, and missing key names when its tenant credentials are incomplete and global fallback is disabled. Web chat returns a visible setup message, and webhook verification responds with HTTP 503. Unbound platform-wide commands may continue using global configuration. `HospitalSettings` still uses global defaults for ordinary non-provider hospital settings; these are not provider credential fallbacks.

`php artisan mpesa:health-check` prints M-Pesa credential readiness for every active hospital without displaying credential values. In production it exits unsuccessfully if any active hospital is missing required tenant credentials, even if global fallback is enabled. It also checks shared callback configuration, reconciliation, scheduler, and queue health. Provision tenant credentials before deploying or enabling M-Pesa for a hospital.

## Build and release

Use the platform's build and deploy commands. A typical Laravel Cloud build command is:

```sh
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan optimize
```

Run schema changes as a release command:

```sh
php artisan migrate --force
```

Do not run local seeders or create demo accounts during production deployment. Provision the platform administrator deliberately, then invite hospital administrators through the superadmin workflow. Review each migration before deployment and take a database backup according to the platform's retention policy.

## Scheduler, queues, and persistent storage

Enable Laravel's scheduler on a production app/worker cluster so `php artisan schedule:run` runs every minute. The M-Pesa reconciliation, callback inbox processing, and slot-hold cleanup tasks run every minute; no-show, daily-summary, and pruning tasks run on their configured schedules. The tasks use overlap and single-server locks, so production instances must share an atomic-lock-capable cache.

Configure a continuously running queue worker or the platform's managed queue for payment notifications, email, and other queued work. Verify that it is processing jobs and that failures are visible in application logs and the failed-jobs table. Attach persistent storage for uploads and configure public and private buckets according to the hosting platform's visibility rules.

## Domain and callback readiness

Configure the production domain and HTTPS before registering provider callbacks. Verify that:

- The M-Pesa callback URL is reachable publicly over HTTPS and points to the correct environment.
- The M-Pesa source-IP allowlist and trusted-proxy configuration match the ingress path.
- WhatsApp webhooks use the public HTTPS tenant callback URL and the expected verify token.
- Mail uses an approved sender identity and the intended production recipient restrictions.
- Tenant path/subdomain routing resolves only the matching hospital and does not expose another tenant's contact data or records.

Do not weaken certificate verification to work around a TLS error. Production should use a valid platform/PHP certificate store; a local Windows-exported bundle is not a production trust-store substitute.

## Post-deployment verification

After release, verify the public health endpoint, representative tenant and role dashboards, and application logs. Confirm that a scheduled task reports `NOT-DUE` when its next cron window is in the future; this is a passing health state, not a missed run. Then verify the following operational checks without making a real charge:

```sh
php artisan route:list --path=mpesa -v
php artisan schedule:list
php artisan mpesa:health-check
php artisan queue:failed
```

Run the full test suite in CI before release. Use a staging Daraja account and an explicitly approved test handset for any real sandbox prompt. Confirm the exact displayed amount before any account owner chooses to authorize it; never ask for or record a payment PIN. Check verified callback/reconciliation evidence before declaring a payment successful.
