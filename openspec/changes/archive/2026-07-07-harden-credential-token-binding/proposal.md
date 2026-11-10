---
kind: fix
depends_on: []
adr: openspec/architecture/adr-004-credential-broker-custody.md
---

## Why

Two credential/auth hardening gaps from the security review of the broker
custody model (ADR-004):

1. **RSA private key written to a predictable, world-readable temp path (MED).**
   `AuthenticationService::getRSJWK()` (`lib/Service/AuthenticationService.php:334-348`)
   decodes a private key and writes it to `"/var/tmp/privatekey-".microtime().getmypid()`
   with default umask (typically group/world-readable) in a shared temp dir,
   then reads it back into the JWK factory. On a multi-tenant or co-located host
   this is a real private-key disclosure window; the filename has no randomness.

2. **Broker token has no method/path binding — replayable for its full 5-minute
   TTL (MED).** `CredentialAppTokenService::issueToken()`
   (`lib/Service/Credential/CredentialAppTokenService.php:142-169`) binds only
   `{appId, credentialId, iat, exp}`; `verify()` (`:187-216`) checks signature +
   expiry only. Anyone who captures one token can replay it against
   `POST /api/credentials/{id}/request` with **any** method/path allowed by the
   provider's `allowRules[]` for the whole `TOKEN_TTL` (300s) window — not just
   the original call.

## What Changes

- Rewrite `getRSJWK()` to avoid the disk exposure: prefer an in-memory key load
  (`JWKFactory::createFromKey()` on the string if supported); if a file is
  unavoidable, use `tempnam(sys_get_temp_dir(), ...)`, `chmod 0600` immediately
  after creation and before writing, and `unlink` in a `finally`.
- Bind the broker token to the specific request: include a digest of
  method + path (+ body hash) in the signed payload and verify it in
  `CredentialBrokerService`, so a captured token cannot be replayed against a
  different call. Optionally add single-use nonce tracking for higher-value
  providers and/or shorten the TTL.

## Impact

- Affected: `lib/Service/AuthenticationService.php`,
  `lib/Service/Credential/CredentialAppTokenService.php`,
  `lib/Service/Credential/CredentialBrokerService.php`.
- Behavioural change: consuming apps must request a token scoped to the exact
  call they will make; update the broker-request flow + docs.
- Risk: the request digest must be computed identically on issue and verify
  (canonicalise method/path) or legitimate calls will 403.
