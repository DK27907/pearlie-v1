# MediDesk AI — Facility Demo Guide

Use this guide for a local or isolated facility demo. It is not a production deployment procedure.

## Prerequisites

- PHP and Composer
- Node.js 20+ and npm
- Both servers running on the same laptop
- A test phone with a Safaricom SIM, if demonstrating the STK prompt

In this workspace PHP is installed at `C:\php\php.exe`.

## Before the demo

1. Connect the demo device and laptop to the same Wi-Fi. Find the laptop's LAN IPv4 address:

   ```powershell
   ipconfig | findstr /i "IPv4"
   ```

2. Configure the local, untracked `.env` for the laptop's LAN URL (`APP_URL=http://<LAN-IP>:8000`). Do not commit `.env` or put credentials in screenshots or commands.

3. If this project has a stale Vite hot-file from a different host, remove `public\hot` so Vite can regenerate it. Start Laravel and Vite in separate terminals, replacing the directory if the project is elsewhere:

   ```powershell
   cd /d C:\Projects\pearlie-v1
   C:\php\php.exe artisan serve --host=0.0.0.0 --port=8000
   ```

   ```powershell
   cd /d C:\Projects\pearlie-v1
   npm.cmd run dev -- --host 0.0.0.0 --port 4173 --strictPort
   ```

4. Allow ports 8000 and 4173 through Windows Firewall if needed. Open `http://<LAN-IP>:8000/h/pearl/chat` from the demo device.

5. On Windows, refresh the local M-Pesa trust bundle if required, then confirm the integration health:

   ```powershell
   C:\php\php.exe artisan app:refresh-mpesa-ca-bundle
   C:\php\php.exe artisan mpesa:health-check
   ```

   The health check must exit successfully before the demo. TLS verification must remain enabled.

6. Confirm `Dental Checkup & Cleaning` is KSh 1.00 in **Admin → Service catalog**. Service-specific bookings use their saved service price; the hospital deposit is used only when a booking has no service price. Never set a production service to KSh 1 as a substitute for defining its deposit policy.

## During the demo

Use synthetic patient details and an isolated local database:

1. Start at `http://<LAN-IP>:8000/h/pearl/chat`.
2. Request an appointment, choose Dental Checkup, and provide the demo patient's name, phone, date, and time.
3. Before confirming, verify that the summary explicitly says **KSh 1.00**. If it shows another amount, do not confirm or authorize the STK prompt.
4. If an STK prompt arrives, verify the amount on the handset. Do not enter or share a PIN as part of this software verification.
5. Treat the booking as paid only after verified provider callback or reconciliation evidence and a matching paid appointment state. A prompt, request acceptance, or simulated callback alone is not proof of payment.

## Troubleshooting

| Issue | Check |
|---|---|
| The demo device cannot reach the app | Confirm the LAN IP, firewall rules, and that both devices use the same Wi-Fi. |
| No STK prompt arrives | Run `mpesa:health-check`; inspect `storage/logs/laravel.log` and the payment status. |
| The browser shows stale assets | Hard-refresh with `Ctrl+Shift+R`; check whether `public\hot` points to the running Vite server. |
| Laravel or Vite stops responding | Check the relevant terminal output and `storage/logs/laravel.log`. |

After the demo, inspect appointment and payment records and remove only records that are positively identified as demo-owned. Never delete unrelated baseline records.

## Local checks

```powershell
C:\php\php.exe artisan test --compact
vendor\bin\pint --test --format agent
git diff --check
```

Do not run demo seeders, test accounts, sandbox credentials, or a KSh 1 service in production.
