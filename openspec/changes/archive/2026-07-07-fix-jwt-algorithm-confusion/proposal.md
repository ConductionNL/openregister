---
kind: fix
depends_on: []
adr: openspec/architecture/adr-004-credential-broker-custody.md
---

## Why

`AuthorizationService::authorizeJwt()` is vulnerable to JWT **algorithm
confusion** (`lib/Service/AuthorizationService.php:260-279`):

```php
$publicKey = $authConf['publicKey'] ?? '';
$algorithm = $authConf['algorithm'] ?? $header['alg'];   // attacker-controlled fallback
if (isset(self::HMAC_MAP[$algorithm]) === false) { throw ...; }
$hmacValid = $this->verifyHmac(..., secret: $publicKey, algorithm: $algorithm);
```

When a consumer's stored `authorizationConfiguration` does not pin `algorithm`,
`$algorithm` falls back to the **caller-supplied JWT header** (`$header['alg']`).
The class declares `PKCS1_ALGORITHMS` / `PSS_ALGORITHMS` constants (`:55-66`)
but implements **no** RSA/PSS verification path — `verifyHmac()` is the only
verifier ever invoked. So a consumer configured for RS256 (storing an RSA
**public** key in `publicKey`, intending asymmetric verification) is verified
via `hash_hmac('sha256', ..., $publicKey)` whenever the attacker sets
`alg: HS256` in the forged token header.

Because the "public" key is by definition not secret, an attacker who obtains it
(it is public) can self-sign a valid HS256 token, pass verification, and then
`userSession->setUser(...)` (`:289`) logs them in as the issuer's user —
full impersonation.

## What Changes

- Never take the verification algorithm from attacker input. Require `algorithm`
  from server-side consumer config; if absent, reject (do not fall back to
  `$header['alg']`).
- Implement real RSA (PKCS1/PSS) verification for consumers configured with an
  asymmetric algorithm, using the stored public key with a proper signature
  verify (`openssl_verify` / a vetted JWT library) — not HMAC.
- Reject any token whose header `alg` does not match the algorithm class pinned
  in the consumer config (an RS-configured consumer MUST refuse an HS token).
- Add a hardening fix to `authorizeBasic()` (`:308-310`): guard
  `base64_decode()` against `false` and use `explode(':', $decode, 2)` so a
  password containing `:` is not truncated and malformed input does not raise a
  TypeError.

## Impact

- Affected: `lib/Service/AuthorizationService.php`.
- Behavioural change: consumers relying on the (insecure) implicit-algorithm
  path must set `algorithm` explicitly in their `authorizationConfiguration`;
  document the migration.
- Risk: none to correctly-configured HMAC consumers that already pin `algorithm`;
  RS-intended consumers move from silently-HMAC to actually-asymmetric.
