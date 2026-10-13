# M-Pesa operations

## Production configuration

Set these environment variables in the production secret manager. Do not commit populated credentials:

| Variable | Purpose |
|---|---|
| `MPESA_ENVIRONMENT=production` | Selects Safaricom's production Daraja endpoint. |
| `MPESA_CONSUMER_KEY` | Daraja OAuth consumer key. |
| `MPESA_CONSUMER_SECRET` | Daraja OAuth consumer secret. |
| `MPESA_PASSKEY` | Daraja STK Push passkey. |
| `MPESA_SHORTCODE` | Safaricom paybill or shortcode. |
| `MPESA_CALLBACK_URL` | Public HTTPS URL for the M-Pesa callback route. |
| `MPESA_ENFORCE_IP_ALLOWLIST=true` | Enforces the callback source IP allowlist (enabled by default). |
| `MPESA_TIMEOUT` | Outbound Daraja request timeout in seconds. |
| `MPESA_TEST_AMOUNT=1` | Sandbox verifier STK push amount in KSh (test only). |

The global credentials are used as the fallback for hospitals that have not configured their own M-Pesa credentials. Callback URLs must be reachable over HTTPS by Safaricom.

`MPESA_TEST_PHONE` is only used by the optional `mpesa:verify-sandbox --live` diagnostic. Configure it with a Safaricom sandbox test number; never use the live diagnostic in production.

## Local Windows certificate trust

On Windows, generate a local PEM bundle from the current user and local-machine trusted root certificate stores:

```powershell
C:\php\php.exe artisan app:refresh-mpesa-ca-bundle
```

The command writes `storage/app/mpesa/ca-bundle.pem`; the storage directory is ignored by Git. The M-Pesa client uses that path by default, or uses `MPESA_CA_BUNDLE` when the deployment environment explicitly selects another bundle. A configured but missing or unreadable bundle causes the request to fail before contacting Safaricom. Certificate verification is never disabled.

This option applies to M-Pesa's Laravel HTTP requests. Other PHP SDKs that create their own Guzzle clients, such as the Resend SDK, rely on PHP's `curl.cainfo`/`openssl.cafile` settings. Configure the PHP runtime's trust store separately when those clients encounter TLS verification errors. A Resend sender-domain/recipient restriction is an independent provider configuration issue; a valid TLS connection does not bypass it.

Production deployments should use the platform/PHP system CA store or a managed CA bundle. Do not deploy a developer machine's Windows root export as the production trust store.

## Required scheduler and queue processes

The payment reconciler and expired slot-hold cleanup are scheduled every minute. Run the Laravel scheduler continuously, and keep a queue worker running for payment notifications and other queued work. Under Supervisor, configure and monitor both processes:

```ini
[program:medidesk-scheduler]
process_name=%(program_name)s
command=php /var/www/medidesk/artisan schedule:work
directory=/var/www/medidesk
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/medidesk-scheduler.log
stopwaitsecs=10

[program:medidesk-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/medidesk/artisan queue:work --sleep=3 --tries=3 --timeout=90
directory=/var/www/medidesk
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/medidesk-queue.log
stopwaitsecs=100
```

Replace `/var/www/medidesk` and `www-data` with the deployment's application path and process user. After deploying code or changing configuration, restart the scheduler and queue worker processes so they load the current application.

## Health check

Run:

```sh
php artisan mpesa:health-check
```

The command reports migration presence, required M-Pesa configuration, the most recent reconciliation event, and recent failed jobs:

- Exit code `0`: every check passed.
- Exit code `1`: at least one required migration or configuration value failed.
- Exit code `2`: no hard failures, but the scheduler heartbeat is stale/missing or failed jobs were recorded during the last hour.

The reconciliation check warns if no reconciliation event was written in the last 10 minutes. A non-empty recent failed-job count warns so the queue can be investigated.

## Safaricom callback IP allowlist

The callback routes are protected by `safaricom.ip`; the client-facing payment status routes are not. The configured ranges are in `config/mpesa.php` under `safaricom_ip_allowlist`. To update the list, verify new ranges against Safaricom's current Daraja documentation, edit that configuration array, deploy it, and restart long-running application processes. Do not add unverified ranges. The allowlist uses the request client IP; only configure trusted proxies when the deployment requires them and the proxy trust configuration is intentionally set.

This integration does not validate a callback HMAC or shared-secret signature. In production, the application enforces the source-IP allowlist even if the opt-out flag is misconfigured; it also verifies successful callback results with Daraja's payment-status endpoint before applying payment state. These checks are defense in depth, not a cryptographic signature. Keep production ingress behind HTTPS, configure trusted proxies narrowly, monitor rejected callback IPs, and verify any Safaricom IP-range changes from current provider documentation before deploying them. The allowlist bypass and opt-out are restricted to non-production environments.

## Troubleshooting

### Sandbox callback never arrives

If `mpesa:verify-sandbox --live` returns `ResponseCode 0`, the phone buzzes, and the PIN is entered but no `payment_completed` event appears, inspect the ngrok request log:

```powershell
curl.exe http://127.0.0.1:4040/api/requests/http
```

Check whether a Safaricom source IP (`196.201.212.*`) appears at the expected timestamp. If it does not, Safaricom could not reach the callback endpoint. Common causes include a stale ngrok tunnel, an `MPESA_CALLBACK_URL` that does not match the current ngrok public URL, not running `php artisan config:clear` after changing `.env`, or `php artisan serve` not running on port 8000.

### Callback returns 403

If ngrok shows an incoming POST to `/api/mpesa/callback` with a `403` response, the `safaricom.ip` middleware likely rejected the source IP.

- Check `storage/logs/laravel.log` for an `Unauthorized source` warning and the incoming IP.
- If Safaricom has rotated its IP ranges, verify the new ranges against Safaricom's current Daraja documentation and update `safaricom_ip_allowlist` in `config/mpesa.php`.
- Run `php artisan route:list --path=mpesa -v` to verify `safaricom.ip` is attached to the expected callback routes.

### ResultCode reference

| ResultCode | Meaning | Action |
|---|---|---|
| `0` | Success | None. |
| `1` | Insufficient M-Pesa balance | Top up the test wallet. |
| `1032` | User cancelled | Retry and complete the prompt. |
| `1037` | Timeout — user did not respond | Retry with the phone unlocked and respond promptly. |
| `2001` | Wrong PIN | Retry and enter the correct PIN. |
| `1025` | System error | Wait one minute, then retry. |

### Payment remains pending for more than five minutes

The callback may not have arrived and reconciliation may not have run.

- Run `php artisan schedule:list` and confirm `app:reconcile-mpesa-payments` is scheduled.
- Run `php artisan mpesa:health-check`; the `Scheduler heartbeat` check warns when reconciliation has not run recently.
- Trigger reconciliation manually with `php artisan app:reconcile-mpesa-payments`.

### Sandbox verifier shows KSh 500 instead of KSh 1

`MPESA_TEST_AMOUNT` may be missing from `.env`. Set `MPESA_TEST_AMOUNT=1`, then run `php artisan config:clear`. This variable only controls the sandbox verifier STK push amount; it does not change production appointment deposits.

### Amount mismatch (`markFailed('amount_mismatch')`)

The amount sent with the STK push does not match the amount stored on the payment row. Verify the payment row was created with the same amount sent to Safaricom. This should not happen in production when both values come from the same source; it can occur in sandbox tests if a payment row predates an amount configuration change.
