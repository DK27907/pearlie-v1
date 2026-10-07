# Configuration Guide

This guide distinguishes hospital-specific settings from application-wide deployment configuration. For production, store secrets in the deployment platform's secret manager or environment settings; do not commit populated secrets to `.env.example`.

## Quick reference

| Setting | Where it lives | Who can edit it |
|---|---|---|
| Hospital page header, footer, and logo | `/admin/integration-settings` → Branding | Hospital administrator |
| Deposit, appointment slot duration, auto-confirm after payment, and business hours | `/admin/integration-settings` → General | Hospital administrator |
| M-Pesa consumer key/secret, shortcode, passkey, environment, and callback URL | `/admin/integration-settings` → M-Pesa | Hospital administrator |
| WhatsApp phone number ID, access token, verify token, app secret, and API version | `/admin/integration-settings` → WhatsApp | Hospital administrator |
| Africa's Talking or Twilio provider and credentials | `/admin/integration-settings` → SMS | Hospital administrator |
| Resend or SMTP mailer, from address/name, and credentials | `/admin/integration-settings` → Email | Hospital administrator |
| Hospital name, slug, contact details, plan, status, and active state | `/superadmin/hospitals` | Superadmin |
| Initial hospital administrator | Create hospital form, or the hospital detail page's administrator invitation | Superadmin |
| `APP_*`, `DB_*`, `QUEUE_*`, `CACHE_*`, `SESSION_*`, and infrastructure defaults | Deployment environment (`.env` locally) | Deployer / server administrator |
| Provider fallback credentials and default M-Pesa environment/callback URL | Deployment environment (`.env` locally) | Deployer / server administrator |

The hospital settings page also contains the provider defaults as placeholders when no hospital-specific credentials have been saved. Secret inputs are masked; leaving a secret blank keeps its saved value. Use the page's clear-credentials control only when the hospital should stop using that integration.

## For hospital administrators

Open **Settings** in the hospital admin area, or visit `/admin/integration-settings`. Choose a tab, edit the fields, and use that tab's save button. Changes apply to the current hospital.

1. **Branding:** edit the public-site header and footer text; optionally upload a PNG, JPG, JPEG, or SVG logo (up to 2 MB); save the changes.
2. **General:** set the KES deposit, appointment slot duration, whether paid bookings are automatically confirmed, and open/close hours for each weekday; save.
3. **M-Pesa:** enter the Daraja consumer key, consumer secret, shortcode, passkey, sandbox/production environment, and a publicly reachable callback URL; save. Secret values are encrypted when stored.
4. **WhatsApp:** enter the Meta phone number ID, access token, verify token, app secret, and Graph API version; save. Keep the webhook URL configured with the platform/webhook setup and make it publicly reachable.
5. **SMS:** choose Africa's Talking or Twilio, then complete that provider's username/API key/sender ID or account SID/auth token/from number; save.
6. **Email:** choose Resend, SMTP, or log delivery; set the from address/name and the selected provider's API key or SMTP details; save.

The settings tabs contain editable forms, not merely status indicators. Provider credentials are hospital-specific and take precedence over configured global fallbacks.

## For superadmins

Open `/superadmin/hospitals`.

1. Select **Create hospital** and enter the hospital name, unique slug, contact information, plan, and initial administrator email. The system sends the initial administrator a secure, single-use setup invitation.
2. Open a hospital record and choose **Edit** to update its name, slug, contact details, plan, active status, and supported hospital settings.
3. On the hospital detail page, use **Invite hospital administrator** to send an administrator setup invitation to another email address. Hospital data is retained when access is suspended.

## For deployers

Set application and infrastructure values in the deployment environment before deploying. A local `.env` is for local development only; keep it out of version control. Generate a unique application key with `php artisan key:generate` for each environment and configure the database, queue, cache, and session services to match the infrastructure.

Example values below are placeholders, not working credentials:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<application-domain>
APP_KEY=<generated-unique-application-key>

DB_CONNECTION=mysql
DB_HOST=<database-host>
DB_PORT=3306
DB_DATABASE=<database-name>
DB_USERNAME=<database-user>
DB_PASSWORD=<database-password>

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

# Global fallback for a hospital without its own M-Pesa credential.
MPESA_ENVIRONMENT=production
MPESA_CONSUMER_KEY=<daraja-consumer-key>
MPESA_CONSUMER_SECRET=<daraja-consumer-secret>
MPESA_SHORTCODE=<business-shortcode>
MPESA_PASSKEY=<daraja-passkey>
MPESA_CALLBACK_URL=https://<application-domain>/api/mpesa/callback

# Optional global provider fallbacks. Prefer per-hospital credentials when applicable.
AFRICASTALKING_USERNAME=<provider-username>
AFRICASTALKING_API_KEY=<provider-api-key>
AFRICASTALKING_SENDER_ID=<approved-sender-id>
TWILIO_ACCOUNT_SID=<twilio-account-sid>
TWILIO_API_KEY=<twilio-api-key>
TWILIO_API_SECRET=<twilio-api-secret>
TWILIO_FROM_NUMBER=<twilio-sender-number>
RESEND_API_KEY=<resend-api-key>
MAIL_MAILER=smtp
MAIL_HOST=<smtp-host>
MAIL_PORT=587
MAIL_USERNAME=<smtp-username>
MAIL_PASSWORD=<smtp-password>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=<verified-sender-address>
MAIL_FROM_NAME=<sender-display-name>

WHATSAPP_PHONE_NUMBER_ID=<meta-phone-number-id>
WHATSAPP_ACCESS_TOKEN=<meta-access-token>
WHATSAPP_VERIFY_TOKEN=<webhook-verify-token>
WHATSAPP_APP_SECRET=<meta-app-secret>
WHATSAPP_API_VERSION=v20.0
```

Set `APP_DEBUG=false` and use HTTPS in production. Configure provider callback/webhook URLs to public HTTPS endpoints, verify email sender domains, and use production provider credentials only after provider-side approval. Never reuse sandbox payment credentials in production.

## Fallback behavior

Credentials and settings saved by a hospital administrator are scoped to that hospital and override the corresponding global provider fallback where the application supports one. This allows each tenant to use its own M-Pesa, WhatsApp, SMS, or email account. The `.env` provider values are defaults for a tenant without an override; they are not a substitute for tenant-specific setup when accounts must be isolated.

`MPESA_ENVIRONMENT` and `MPESA_CALLBACK_URL` provide M-Pesa defaults when a hospital has not saved an override. Deployment-wide settings such as the application URL, database connection, queue/cache/session drivers, and global provider credentials belong in the deployment environment, not in an individual hospital's settings.
