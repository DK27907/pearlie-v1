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

The global credentials are used as the fallback for hospitals that have not configured their own M-Pesa credentials. Callback URLs must be reachable over HTTPS by Safaricom.

`MPESA_TEST_PHONE` is only used by the optional `mpesa:verify-sandbox --live` diagnostic. Configure it with a Safaricom sandbox test number; never use the live diagnostic in production.

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
