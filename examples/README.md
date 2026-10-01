# Example: signup guard and webhook receiver

`server.php` is a small app for PHP's built-in web server that does both halves of a
ShieldLabs integration:

- `POST /signup` reads the `requestId` your frontend sent (JSON `{"requestId": "..."}` or a
  form field), waits for the verdict with `identifications->get()` and applies
  `Risk::evaluate()`: a missing, reused, stale or rate-limited identification is refused, and
  so are browser automation, disabled JavaScript and the dangerous band.
- `POST /webhooks/shieldlabs` verifies the signature, parses the event and logs it once per
  `request_id`.

## Run it

From the repository root:

```bash
composer install
export SHIELDLABS_API_KEY=sec_your_private_key
export SHIELDLABS_WEBHOOK_SECRET=whsec_your_signing_secret
php -S localhost:8000 examples/server.php
```

Use your own Private API Key and signing secret. With the placeholder key the SDK logs a
warning in the server output that the key does not look like a Private API Key; the
responses are not affected.

`SHIELDLABS_WEBHOOK_SECRET` can hold several comma-separated secrets while you rotate one.
`SHIELDLABS_API_BASE_URL` points the History API calls somewhere else, for example a local
mock (https, or plain http on `localhost` or `127.0.0.1`). Used request IDs are kept in a
JSON file in the system temp directory; use your database or cache in production.

To use the code in your own project, copy it and replace the `require` line with your
Composer autoloader.

## Try it

In a second terminal, send a request ID from a real identification in your browser
integration:

```bash
curl -i -X POST http://localhost:8000/signup \
  -H 'Content-Type: application/json' \
  -d '{"requestId": "3b241101-e2bb-4255-8caf-4136c566a962"}'
```

Send a signed `webhook.ping` the way ShieldLabs does. The signature is computed from
`SHIELDLABS_WEBHOOK_SECRET`, so export the same value in this terminal first:

```bash
export SHIELDLABS_WEBHOOK_SECRET=whsec_your_signing_secret
BODY='{"created_at":"2026-09-30T12:34:56Z","event_type":"webhook.ping","schema_version":"2026-06-01"}'
SIGNATURE=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SHIELDLABS_WEBHOOK_SECRET" | sed 's/^.* //')
curl -i -X POST http://localhost:8000/webhooks/shieldlabs \
  -H 'Content-Type: application/json' \
  -H "X-Shield-Signature: sha256=$SIGNATURE" \
  --data-binary "$BODY"
```

The server log shows each event. To receive real deliveries, expose the server over HTTPS
(for example with a tunnel), add the endpoint in the ShieldLabs analytics dashboard and
press "Verify" or "Test".
