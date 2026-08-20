## 1. Pin the algorithm server-side

- [ ] 1.1 In `AuthorizationService::authorizeJwt()` (`lib/Service/AuthorizationService.php:260-279`), remove the `?? $header['alg']` fallback. Resolve `$algorithm` from `$authConf['algorithm']` only; if unset, reject with an auth error.
- [ ] 1.2 After resolving the header, assert `$header['alg']` matches the pinned algorithm class (HMAC vs PKCS1 vs PSS); reject on mismatch before any verification.

## 2. Implement real asymmetric verification

- [ ] 2.1 For `PKCS1_ALGORITHMS`/`PSS_ALGORITHMS`, verify the signature against the stored public key with `openssl_verify()` (or a vetted JWT lib), NOT `verifyHmac()`.
- [ ] 2.2 Route to `verifyHmac()` only when the pinned algorithm is in `HMAC_MAP`.

## 3. Harden basic auth parsing

- [ ] 3.1 In `authorizeBasic()` (`:308-310`), guard `base64_decode()` for `false`; use `explode(':', $decode, 2)` so passwords containing `:` are preserved and malformed input returns an auth failure, not a TypeError.

## 4. Verification

- [ ] 4.1 Security test: an RS256-configured consumer with no explicit `algorithm` set → an `alg:HS256` token signed with the public key is REJECTED.
- [ ] 4.2 Security test: an `alg` header mismatching the pinned class is rejected.
- [ ] 4.3 Positive test: correctly-signed RS256 token verifies; correctly-signed HS256 token (HMAC-configured consumer) verifies.
- [ ] 4.4 `composer check:strict` passes.

## Acceptance criteria

- The verification algorithm is never sourced from the token header.
- An asymmetric-configured consumer cannot be authenticated via HMAC using its
  public key.
- A token whose `alg` mismatches the pinned algorithm is rejected.
- Basic-auth parsing handles malformed input and `:`-containing passwords safely.
