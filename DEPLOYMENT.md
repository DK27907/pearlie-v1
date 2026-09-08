Pearlie AI Assistant V1 — Deployment & Queue Worker Instructions

This document provides example Supervisor configuration and deployment instructions for running queue workers and other background processes in production.

1. Environment

- Ensure the following are configured in your .env file:
  - APP_ENV=production
  - QUEUE_CONNECTION=database (or redis)
  - DB_CONNECTION (database settings)
  - MAIL_* settings for SMTP (MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS)
  - ESCALATION_NOTIFICATION_EMAIL
  - AFRICASTALKING_USERNAME / AFRICASTALKING_API_KEY (if using)
  - TWILIO_SID / TWILIO_TOKEN / TWILIO_FROM (if using Twilio)
  - WHATSAPP_PHONE_NUMBER_ID / WHATSAPP_ACCESS_TOKEN (if using WhatsApp)

2. Queue: database driver (recommended for simple deployments)

- Prepare the jobs table:

  php artisan queue:table
  php artisan migrate --force

3. Supervisor config example

Create a Supervisor program config (e.g., /etc/supervisor/conf.d/pearlie-worker.conf):

[program:pearlie-worker]
command=php /path/to/your/project/artisan queue:work --sleep=3 --tries=3 --timeout=90
process_name=%(program_name)s_%(process_num)02d
numprocs=1
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/pearlie/worker.log
stopwaitsecs=3600

Notes:
- Adjust user to your web server user (e.g., www-data, wwwrun)
- Increase numprocs to run multiple workers if needed
- For long-running workers, monitor memory usage and restart if it grows

4. Supervisor reload & start

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start pearlie-worker:*

5. Failed jobs & monitoring

- Configure failed jobs table if not present:

  php artisan queue:failed-table
  php artisan migrate --force

- Inspect failed jobs:

  php artisan queue:failed
  php artisan queue:retry all

6. Logs and rotation

- The app writes to storage/logs/laravel.log; ensure log rotation is configured (logrotate) for production.
- Supervisor worker stdout logs are configured in the example at /var/log/pearlie/worker.log. Rotate that too.

7. Deploying new code

- Typical steps:
  1. git pull origin main
  2. composer install --no-dev --prefer-dist --optimize-autoloader
  3. php artisan migrate --force
  4. php artisan config:cache
  5. php artisan route:cache
  6. npm ci && npm run build (if you manage frontend assets)
  7. supervisorctl restart pearlie-worker:*

8. Security & scaling notes

- Use a queue backend like Redis if high throughput is required.
- Secure webhook endpoints and provider credentials.
- Use environment-specific configurations and secrets management (Vault, AWS Parameter Store, or equivalent).

9. Security hardening checklist (recommended before production)

- APP_ENV=production and APP_DEBUG=false.
- Use HTTPS and force it in Laravel (e.g., TrustProxies + URL::forceScheme('https') in AppServiceProvider for production).
- Enforce HSTS (middleware sets Strict-Transport-Security in production) and a Content-Security-Policy header.
- Ensure SESSION_SECURE_COOKIE=true and SESSION_HTTP_ONLY=true in production env.
- Rotate provider credentials regularly and store them in a secret manager.
- Do not expose invite tokens in the UI — invites are emailed only.

10. Invite & admin provisioning

- Admin users should be provisioned via invite tokens sent by email or via manual seeding in CI/CD with a one-time password.
- Default seeded admin passwords are insecure — set ADMIN_EMAIL/ADMIN_PASSWORD in production prior to seeding or disable seeding entirely in production.

11. Supervisor example (included above) — additional notes

- Monitor worker memory and use supervisor 'numprocs' to scale horizontally for more throughput.
- Monitor failed_jobs table and set alerts for recurring failures.

12. Systemd & Docker Compose examples

- If you prefer systemd to Supervisor, create a unit file for the queue worker and enable it similarly to the Supervisor example.
- For containerized deployments, run a separate queue worker service using the same image and command.

Use the improved examples below — this file includes both Supervisor and systemd options plus a production-minded Docker Compose example.

13. systemd unit example (production-ready)

Create a systemd unit on your server (e.g., /etc/systemd/system/pearlie-worker.service). This example reads environment variables from an EnvironmentFile so provider secrets are kept out of the unit file itself.

[Unit]
Description=Pearlie Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php /path/to/your/project/artisan queue:work --sleep=3 --tries=3 --timeout=90 --queue=default,emails
RestartSec=5s
EnvironmentFile=/etc/pearlie/pearlie.env

[Install]
WantedBy=multi-user.target

# Example /etc/pearlie/pearlie.env
# APP_ENV=production
# QUEUE_CONNECTION=redis
# REDIS_HOST=127.0.0.1
# TWILIO_SID=...
# TWILIO_TOKEN=...
# AFRICASTALKING_API_KEY=...

Commands to manage service:

sudo systemctl daemon-reload
sudo systemctl enable pearlie-worker
sudo systemctl start pearlie-worker
sudo systemctl status pearlie-worker

Notes:
- EnvironmentFile should be owned by root and readable only by root to protect secrets.
- Use a monitoring/alerting solution (PagerDuty/Sentry) to watch repeated failed_jobs or worker crashes.

14. Docker Compose example (production-friendly)

The following docker-compose.yml demonstrates a production-oriented layout that keeps secrets out of the compose file (use environment_file or a Docker secrets manager) and runs the worker as a separate service. This example assumes images are built by your CI pipeline.

version: '3.8'
services:
  app:
    image: pearlie-app:stable
    ports:
      - "8080:80"
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
    env_file:
      - ./deploy/.env.app
    volumes:
      - ./storage:/var/www/html/storage
    depends_on:
      - db
      - redis

  web:
    image: nginx:alpine
    ports:
      - "443:443"
    volumes:
      - ./docker/nginx/conf.d:/etc/nginx/conf.d:ro
      - ./ssl:/etc/ssl:ro
    depends_on:
      - app

  db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: pearlie
      MYSQL_USER: pearlie
      MYSQL_PASSWORD: ${MYSQL_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql
    restart: unless-stopped

  redis:
    image: redis:6-alpine
    volumes:
      - redis_data:/data
    restart: unless-stopped

  worker:
    image: pearlie-app:stable
    command: php artisan queue:work --sleep=3 --tries=3 --timeout=90 --queue=default,emails
    env_file:
      - ./deploy/.env.worker
    depends_on:
      - redis
      - db
    restart: unless-stopped

volumes:
  db_data:
  redis_data:

Notes:
- Use separate env files for app and worker (deploy/.env.app, deploy/.env.worker) and keep them out of git.
- For production, consider running workers under orchestration (Kubernetes, ECS) or systemd on dedicated hosts rather than docker-compose.
- Prefer managed RDS/Cloud SQL and managed Redis for reliability.
- Do not commit provider credentials to the repository. Use your cloud provider's secrets manager or the Docker secrets feature.
