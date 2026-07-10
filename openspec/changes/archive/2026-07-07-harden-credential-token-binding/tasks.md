## 1. Private-key handling

- [ ] 1.1 Rewrite `AuthenticationService::getRSJWK()` (`lib/Service/AuthenticationService.php:334-348`) to load the key in memory where the JWK library supports it (`createFromKey()` on the string).
- [ ] 1.2 If a temp file is unavoidable: use `tempnam(sys_get_temp_dir(), ...)`, `chmod 0600` before writing the key, `unlink` in `finally`. No predictable `microtime()+pid` name in `/var/tmp`.

## 2. Token request-binding

- [ ] 2.1 In `CredentialAppTokenService::issueToken()` (`:142-169`), add a request digest (canonical method + path, optionally body hash) to the signed payload.
- [ ] 2.2 In `verify()` (`:187-216`) / `CredentialBrokerService`, recompute the digest for the actual brokered call and reject on mismatch.
- [ ] 2.3 (Optional) Add single-use nonce tracking and/or a shorter TTL for higher-value providers.

## 3. Verification

- [ ] 3.1 Test: a token issued for `GET /repos/x` cannot be replayed for `PUT /repos/x/contents/y` (403 on digest mismatch) even within TTL.
- [ ] 3.2 Test: the private key temp file (if any) is `0600` and removed after use; no world-readable window.
- [ ] 3.3 `composer check:strict` passes; existing broker e2e still green.

## Acceptance criteria

- Private key is never written world/group-readable to a predictable path.
- A captured broker token cannot be replayed against a different method/path
  within its TTL.
