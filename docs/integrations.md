# Tenant API and webhooks

## API access

Hospital administrators manage credentials at **Admin → API and webhooks**. Create a separate token for each integration and revoke tokens that are no longer used. The plain token is displayed once; the application stores only its SHA-256 hash.

Send the token as a bearer credential:

```http
Authorization: Bearer <TOKEN>
Accept: application/json
```

The read-only API is rate-limited to 120 requests per minute per client IP.

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/api/v1/appointments` | List this tenant’s appointments; optional `status` and `per_page` filters (`1`–`50`) |
| `GET` | `/api/v1/appointments/{id}` | Read one appointment belonging to this tenant |

Responses contain appointment identifiers, booking reference, service and doctor IDs, date, slot time, status, payment status, and timestamps. Appointments created before booking references were introduced may have a `null` booking reference; new appointments receive one automatically. Patient name, phone, email, free-text reason, and payment-provider details are not exposed. A missing, malformed, or revoked token returns `401`; a record outside the token’s tenant is not disclosed.

## Webhook subscriptions

Subscribe to `appointment.created` and/or `appointment.updated` at the same admin page. Endpoints must use HTTPS on port 443 and resolve only to public IP addresses. The application resolves and validates every IPv4 and IPv6 answer immediately before delivery, pins those addresses for the connection, and does not follow redirects.

Each subscription receives a distinct signing secret, shown once at creation and encrypted at rest. Deliveries include:

```http
Content-Type: application/json
X-Pearlie-Event: appointment.created
X-Pearlie-Delivery: <stable UUID>
X-Pearlie-Signature: sha256=<hex HMAC>
```

The signature is `HMAC-SHA256` over the exact UTF-8 JSON request body using the subscription secret. Verify it with a constant-time comparison before processing the event. Use `X-Pearlie-Delivery` as the idempotency key: delivery is at-least-once, so consumers must safely ignore a previously handled delivery ID.

Webhook bodies contain the hospital ID, appointment ID and booking reference (which may be `null` for an older appointment), service and doctor IDs, date and slot, appointment status, payment status, and update timestamp. Patient-identifying and free-text fields are intentionally omitted.

Network failures, HTTP `429`, and HTTP `5xx` responses are retried at most five times with bounded backoff. Other non-success HTTP statuses fail without retry. Delivery records retain the attempt count, final status, HTTP status where available, and a non-sensitive error class/message; response bodies and signing secrets are not logged.

## Operational notes

- Apply database migrations before enabling the new API or webhook features.
- Queue workers must be running for webhook delivery. The job is dispatched after the appointment transaction commits.
- A webhook secret is not retrievable later; disable the subscription and create a replacement if the secret is lost.
- Keep API tokens and webhook secrets out of source control, logs, URLs, and client-side code.
