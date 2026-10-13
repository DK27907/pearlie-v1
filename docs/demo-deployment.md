# Local demo deployment

Use this guide for a local or isolated demo environment. It is not a production deployment procedure.

## Start the application on Windows

Install the PHP version required by `composer.json`, Composer, Node.js, and npm. In this workspace PHP is installed at `C:\php\php.exe`.

```powershell
composer install
Copy-Item .env.example .env
C:\php\php.exe artisan key:generate
C:\php\php.exe artisan migrate
C:\php\php.exe artisan db:seed
npm install
npm.cmd run build
C:\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

Open `/` for the marketing site, `/h/pearl` for Pearl Hospital, or `/h/pearl/chat` for the booking assistant. Vite's development server is optional when the built assets are current.

The local seed creates demo tenants and accounts. Their shared development password is `password`; these accounts and credentials must never be deployed or reused in production.

## M-Pesa sandbox TLS

On Windows, export the machine's trusted public root certificates for M-Pesa requests:

```powershell
C:\php\php.exe artisan app:refresh-mpesa-ca-bundle
```

The command writes `storage/app/mpesa/ca-bundle.pem`, which is ignored by Git. `config/mpesa.php` uses that file by default; `MPESA_CA_BUNDLE` can select another readable PEM bundle when provided by the environment. TLS verification remains enabled, and a configured but missing bundle fails before an M-Pesa request is sent. Never set certificate verification to `false`.

The exported roots come from the current Windows user and local-machine trusted root stores. Refresh the file after those stores change. Other PHP clients that construct their own Guzzle clients, including the Resend SDK, use PHP's `curl.cainfo`/`openssl.cafile` settings rather than the M-Pesa-specific option. Keep those PHP trust settings valid for any local integration that needs to make HTTPS requests.

## KSh 1 facility demo

For a local facility demo only, an administrator can set Pearl's `Dental Checkup & Cleaning` service price to KSh 1 in **Admin → Service catalog**. Service-specific bookings charge the saved service price; the hospital deposit setting is used only when a booking has no service price. Do not make a real facility's production service KSh 1 as a substitute for defining its deposit policy.

Before confirming a booking in chat, verify that the summary explicitly says **KSh 1.00**. If it shows any other amount, do not confirm or authorize the STK prompt. If a KSh 1 STK prompt arrives, verify its amount on the handset before the account owner decides whether to authorize it. Never enter or share a PIN as part of a software test.

Use synthetic patient details and an isolated local database. Review the service price and the appointment/payment rows after the demo; do not treat a request accepted by Safaricom as proof of a completed payment. A successful booking requires verified callback/reconciliation evidence and the corresponding paid appointment state.

## Local checks

```powershell
C:\php\php.exe artisan test --compact
vendor\bin\pint --test --format agent
git diff --check
```

Do not run demo seeders, test accounts, sandbox credentials, or a KSh 1 service in production.
