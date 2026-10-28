# MediDesk Assistant

MediDesk Assistant is a multi-tenant hospital platform. Each hospital has isolated users, knowledge, conversations, appointments, availability, payments, and escalation records. Pearl Hospital's existing Pearlie assistant remains available as the default tenant experience.

The platform logo uses a locally hosted healthcare portrait from [Unsplash](https://unsplash.com/license), used under the Unsplash License.

## Requirements

- PHP 8.3 or newer and Composer
- Node.js and npm for frontend assets
- SQLite for local development; production may use SQLite, MySQL, or PostgreSQL

## Local setup

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
npm install
npm run build
php artisan serve
```

The public marketing site is available at `/`; Pearl Hospital's assistant is at `/pearlie`. Tenant-specific assistant URLs use `/h/{hospital-slug}`, `/chat/{hospital-slug}`, or a hospital subdomain. Hospital administrators manage their tenant under `/admin` and `/hospital/onboarding`; doctor routes are under `/doctor`. Platform administrators manage hospitals under `/superadmin`.

See [local demo deployment](docs/demo-deployment.md), [production deployment](docs/production-deployment.md), [M-Pesa operations](docs/mpesa-operations.md), and the [configuration guide](docs/configuration-guide.md) for environment-specific setup.

## Multi-tenancy and subscriptions

The `hospitals` table stores each tenant's contact details, branding, assistant language preferences, scheduling policies, subscription plan, and M-Pesa settings. Daraja consumer keys, secrets, and passkeys are encrypted using Laravel's application key. Existing Pearl Hospital rows are assigned to the `pearl` tenant by migration. Tenant-aware Eloquent scopes filter the hospital-owned tables using the resolved request tenant.

`ResolveHospital` selects a tenant from a verified super-admin impersonation session, the tenant path or subdomain, the authenticated user's hospital, or `PEARLIE_DEFAULT_HOSPITAL`. A suspended, inactive, or expired-trial tenant is denied access. Feature middleware gates doctor management, bookings, M-Pesa, and other plan capabilities. Platform administrators must explicitly start and end an impersonation session before accessing hospital-admin screens.

For tenant subdomains in production, point a wildcard DNS record (`*.yourdomain.com`) at the application, provision a TLS certificate that covers the wildcard, and set `TENANT_BASE_DOMAIN=yourdomain.com`. Path-based tenant URLs remain available when wildcard DNS is not used.

Local `php artisan db:seed` creates Pearl Hospital, Demo Hospital, and AxiomForge tenants, along with local-only demo accounts (all use `password`):

| Role | Email |
| --- | --- |
| Platform super-admin | `super@axiomforge.co.ke` |
| Pearl Hospital admin | `admin+pearl@example.test` (or the configured `ADMIN_EMAIL`) |
| Demo Hospital admin | `admin+demo@example.test` |
| Pearl doctors | `doctor1@pearl.test`, `doctor2@pearl.test` |
| Demo Hospital doctors | `doctor1@demohospital.co.ke`, `doctor2@demohospital.co.ke` |
| Pearl patient | `patient@pearl.test` |

These credentials are for local development only. Configure `SUPER_ADMIN_EMAIL` and `SUPER_ADMIN_PASSWORD` before provisioning a production platform administrator. Production seeding does not create demo tenants or sample users. Never reuse development passwords.

Hospitals are invited to set up administrator accounts from `/superadmin`; invitation links are signed, expire after seven days, and can be used once. Hospital administrators can complete their profile, branding, language, and payment configuration through `/hospital/onboarding`.

## Shared hospital header and footer

The master layout gives public, sign-in, admin, and doctor pages the same responsive Pearl Hospital header and footer. Signed-in staff retain their admin or doctor navigation below the shared header. The chat page keeps its own chat panel inside this site chrome.

## No-show policy

Only appointments that are both confirmed and paid can be manually marked as a no-show. The scheduled `appointments:mark-no-shows` command marks paid, confirmed appointments from today after their slot end time and the configured grace period. The default is 30 minutes; set `PEARLIE_NO_SHOW_GRACE_MINUTES` to change it. No-show records retain the timestamp and optional reason. Patients receive a notice by WhatsApp when configured and by email when an email address was collected; the policy informs patients that the booking deposit is retained and gives the hospital contact number to rebook.

## Human escalation queue

Pearlie escalates low-confidence answers, requests for a health worker, urgent medical keywords, and repeated patient messages. Admins can review the live queue at `/admin/escalations`; doctors can use `/doctor/escalations`. The queue refreshes every 15 seconds. Claiming an escalation assigns it to the current health worker and notifies the patient by WhatsApp, with SMS as a fallback when a patient phone is available. Replies and patient messages are retained in the escalation conversation context.

Set `PEARLIE_ESCALATION_PHONE`, `PEARLIE_ESCALATION_WHATSAPP`, and `PEARLIE_ESCALATION_MAX_WAIT` to configure staff alert recipients and the pending wait target. Staff SMS uses the existing Africa's Talking integration configured with `AFRICASTALKING_USERNAME`, `AFRICASTALKING_API_KEY`, and `AFRICASTALKING_FROM`; WhatsApp uses the Meta settings below. Configure `ESCALATION_NOTIFICATION_EMAIL` or `PEARLIE_NOTIFY_EMAILS` for email alerts. Keep the queue's email queue worker running in production; SMS, WhatsApp, and database notifications are sent during escalation creation.

## Integrations and setup

- **Groq AI:** Obtain `GROQ_API_KEY` from [console.groq.com](https://console.groq.com/) and set it with `GROQ_MODEL` as needed. The assistant can also answer from the local knowledge base.
- **M-Pesa Daraja:** Obtain `MPESA_CONSUMER_KEY`, `MPESA_CONSUMER_SECRET`, and `MPESA_PASSKEY` from the Safaricom Daraja portal. Configure `MPESA_SHORTCODE`, `MPESA_ENVIRONMENT`, and `MPESA_CALLBACK_URL` as well. These global values are the fallback for hospitals; each hospital can instead configure its encrypted credentials in onboarding. The tenant callback is routed at `/api/mpesa/callback/<hospital-slug>`; callbacks are verified with Daraja's STK query API except when local sandbox verification is explicitly enabled.
- **WhatsApp Business:** Create/configure a Meta Business app with the WhatsApp product and add a WhatsApp Business phone number. Set `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET`, and `WHATSAPP_VERIFY_TOKEN`; `WHATSAPP_BUSINESS_ACCOUNT_ID` and `WHATSAPP_VERSION` are also supported. Obtain these values from the Meta Business app settings. Register `https://<your-host>/api/whatsapp/webhook/<hospital-slug>` as the callback URL, enter that hospital's verify token, and subscribe the app to the `messages` webhook field. Keep all tokens and app secrets in the deployment secret store.
- **SMS:** Configure either Africa's Talking (`AFRICASTALKING_USERNAME`, `AFRICASTALKING_API_KEY`, and optional `AFRICASTALKING_FROM`) or Twilio. Twilio supports `TWILIO_ACCOUNT_SID` plus `TWILIO_TOKEN` (legacy account SID/token names are also supported), or `TWILIO_API_KEY` plus `TWILIO_API_SECRET`; set `TWILIO_FROM_NUMBER` (or the supported legacy `TWILIO_FROM`) for the sender.
- **Mail:** Select a delivery provider with `MAIL_MAILER`. Resend uses `RESEND_API_KEY`; Mailgun uses `MAILGUN_DOMAIN` and `MAILGUN_SECRET`; SMTP uses `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, and `MAIL_ENCRYPTION`. Also configure `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` for the sending identity. Obtain credentials from the selected provider. Local development defaults to `MAIL_MAILER=log`, which does not deliver external email.
- `.env.example` documents the supported variables with empty values; supply real credentials through the deployment secret store, never in source control. Application startup logs a warning for missing integration credentials without logging their values.
- WhatsApp and web appointment bookings collect a patient email address so no-show notices can also be sent by email. Email is stored on the appointment request.
- `php artisan db:seed --class=DoctorUserSeeder` creates the local Pearl and Demo Hospital doctor accounts. The seeder refuses to run in production.
- `php artisan db:seed --class=AdminUserSeeder` provisions local hospital administrator accounts. Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` for production provisioning; production requires an explicit password.

## M-Pesa Sandbox Testing

Safaricom's sandbox does not send real callbacks. To test M-Pesa end-to-end locally:

1. Set `MPESA_SKIP_VERIFY_LOCAL=true` in `.env`.
2. Ensure `APP_ENV=local`.
3. Run `php artisan mpesa:simulate-callback ws_CO_XXXXX --receipt=TEST123`.

The command simulates a callback for an existing sandbox payment and bypasses STK Query verification only when both the M-Pesa environment is sandbox and the application environment is local. In production, set `MPESA_SKIP_VERIFY_LOCAL=false`; real callbacks will be verified.

## Mail setup

Doctor invitations and password resets use Laravel's configured mailer. For production, choose one transport in `.env`:

- **Resend (recommended):** `MAIL_MAILER=resend` and `RESEND_API_KEY=...`. The Resend SDK is included in Composer dependencies.
- **Gmail SMTP:** `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_USERNAME=...`, `MAIL_PASSWORD=...`, and `MAIL_ENCRYPTION=tls`. Use a Google App Password.
- **Mailgun:** `MAIL_MAILER=mailgun`, `MAILGUN_DOMAIN=...`, `MAILGUN_SECRET=...`, and optionally `MAILGUN_ENDPOINT=api.mailgun.net`. The Mailgun Symfony transport is included in Composer dependencies.

Set `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` to an address/domain authorized by your provider. Local development defaults to `MAIL_MAILER=log`, which writes emails to the Laravel log instead of delivering them.

## Production checklist

Set a unique `APP_KEY`, `APP_ENV=production`, and `APP_DEBUG=false`; serve the app over HTTPS with `SESSION_SECURE_COOKIE=true`; configure database, mail, queue, Meta WhatsApp, and provider credentials; and never deploy sample account passwords. Apply migrations with `php artisan migrate --force`, keep a queue worker running, and run Laravel's scheduler every minute so the 07:00 doctor summaries and 30-minute no-show processing run. Provision a production platform administrator explicitly with `SUPER_ADMIN_EMAIL` and a strong `SUPER_ADMIN_PASSWORD`, then run `php artisan db:seed --class=SuperAdminSeeder --force`; hospital administrators should be invited from `/superadmin`. Do not run the local demo `AdminUserSeeder` or `DoctorUserSeeder` in production. Confirm the Meta callback URL and webhook subscription before launch. See [production deployment](docs/production-deployment.md) for the release checklist.

## Verification

```powershell
php artisan test
php artisan test --filter=Layout
php artisan test --filter=NoShow
php artisan test --filter=WhatsApp
php artisan route:list -v --path=admin
npm.cmd run build
```

The CI workflow runs the test suite against SQLite with the synchronous queue driver. Before production, also check `php artisan schedule:list`, verify WhatsApp and M-Pesa callbacks in staging, test email delivery with the configured provider, and review the scheduler/queue logs.

## Security

Report application vulnerabilities privately to the repository maintainers. Do not publish credentials, payment data, or patient information in an issue.
