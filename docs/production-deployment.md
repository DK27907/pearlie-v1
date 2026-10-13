# Production deployment

Deploy only after staging has passed the payment, tenant-isolation, authorization, notification, queue, and scheduler checks. Do not copy local demo accounts, a KSh 1 service price, or sandbox credentials into production.

## Prepare an isolated production environment

Use separate production and staging environments, databases, provider credentials, and sending identities. With Laravel Cloud, connect the repository and select the required PHP runtime and region; keep the application and attached resources in the same region where possible.

Provision and attach the production database and shared cache before deploying. Configure the queue and persistent file storage for the workload. Cloud filesystems are ephemeral between deployments and replicas; do not rely on a local path for uploads, sessions, generated files, or cache. Use the environment's configured persistent storage and the Cloud storage visibility model appropriate to each bucket.

## Configure deployment secrets

Set secrets through the hosting platform's secret manager, not in source control or a committed environment file. At minimum, set:

- `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY`, and the public HTTPS `APP_URL`.
- Database, cache, queue, session, and filesystem settings for the attached production resources.
- A production M-Pesa environment, verified public callback URL, approved shortcode, consumer key/secret, and passkey. Prefer tenant-specific credentials where each hospital must use a separate provider account.
- A verified mail sender and provider credentials. Resend's testing-domain recipient restriction is separate from TLS and requires a verified sender domain for normal onboarding recipients.
- Approved WhatsApp and SMS provider credentials and public webhook URLs, if those channels are enabled.

Do not place production keys in build logs, screenshots, command-line arguments, or source files. Never reuse development passwords, test phone numbers, or sandbox payment credentials.

## Build and release

Use the platform's supported build/release hooks. A typical Laravel Cloud build command is:

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

Enable Laravel's scheduler on a production app/worker cluster. The M-Pesa reconciliation, callback inbox processing, and slot-hold cleanup tasks run every minute; no-show and daily-summary tasks run on their configured schedules. The tasks use overlap and single-server locks, so production instances must share an atomic-lock-capable cache.

Use a running queue worker or the platform's managed queue for payment notifications, email, and other queued work. Verify the worker is processing jobs and that failures are visible in application logs. Attach persistent storage for uploads and configure public and private buckets according to the hosting platform's visibility rules.

## Domain and callback readiness

Configure the production domain and HTTPS before registering provider callbacks. Verify that:

- The M-Pesa callback URL is reachable publicly over HTTPS and points to the correct environment.
- The M-Pesa source-IP allowlist and trusted-proxy configuration match the ingress path.
- WhatsApp webhooks use the public HTTPS tenant callback URL and the expected verify token.
- Mail uses an approved sender identity and the intended production recipient restrictions.
- Tenant path/subdomain routing resolves only the matching hospital and does not expose another tenant's contact data or records.

Do not weaken certificate verification to work around a TLS error. Production should use a valid platform/PHP certificate store; a local Windows-exported bundle is not a production trust-store substitute.

## Post-deployment verification

After release, verify the public health endpoint, representative tenant and role dashboards, and application logs. Then verify the following operational checks without making a real charge:

```sh
php artisan route:list --path=mpesa -v
php artisan schedule:list
php artisan mpesa:health-check
php artisan queue:failed
```

Run the full test suite in CI before release. Use a staging Daraja account and an explicitly approved test handset for any real sandbox prompt. Confirm the exact displayed amount before any account owner chooses to authorize it; never ask for or record a payment PIN. Check verified callback/reconciliation evidence before declaring a payment successful.
