# shieldlabs/shieldlabs (PHP)

```php
use ShieldLabs\Client;

$ok = Client::verifyWebhook($rawBody, $_SERVER['HTTP_X_SHIELD_SIGNATURE'] ?? '', $secret);
```

Signature: `X-Shield-Signature: sha256=` + hash_hmac('sha256', $raw, $secret). Schema `2026-06-01`.

## License

[MIT](./LICENSE)
