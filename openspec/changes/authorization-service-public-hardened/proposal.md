# Proposal: OpenRegister's AuthorizationService serves an app's inbound endpoints

## Why

integriq authenticates inbound calls with its own `AuthorizationService`, which gate 23 (rule 6, consume-or-rbac-fleet-wide) flags. It cannot move onto OpenRegister's service today (for-ruben/integriq-gate23-gap.md): OpenRegister's entry points are protected, it reads consumers only from its own table while integriq keeps them as objects of its `consumer` schema, it fails closed on RS/PS tokens, it accepts a reused `jti` and an `iat` in the future, and it has no NC-session check and no way to read back the consumer that authenticated. Ruben chose (DECISIONS rows 62 and 64, Q5): build the gap in OpenRegister, with a PLUGGABLE CONSUMER SOURCE so integriq keeps its schema and data.

## What changes

- `authorizeJwt`, `authorizeBasic`, `authorizeOAuth`, `authorizeApiKey` become public; `authorizeNcSession` is added (signed-in user, Nextcloud CSRF check, users/groups allow-list).
- `ConsumerSource` interface (`findByIssuer`, `findByApiKey`) with `ConsumerMapperSource` (OpenRegister's table) as the default; JWT and API-key entry points take an optional source. `ResolvedConsumer` carries the source's own record.
- `getResolvedConsumer()` returns the consumer the last JWT or consumer API-key call authenticated, null for user-based mechanisms.
- `JwtValidator` (HMAC + claims) and `RsaJwsVerifier` (RS256/384/512, PS256/384/512 via web-token, only the pinned algorithm loaded): RS/PS are verified instead of refused; a PEM or a base64 PEM is accepted.
- Claims: an `iat` beyond 60 s in the future is refused, `nbf` is honoured, a `jti` is accepted once (distributed cache until expiry; refused when no cache can check it). The lifetime cap (integriq's 3600 s) is NOT added: Q4 is open.
- Basic and OAuth enforce the endpoint's users/groups allow-list; OAuth requires the request to carry a Bearer header (a session cookie is not a token).
- Every credential mechanism acts as its user through `setVolatileActiveUser()`, never `setUser()`, so a credential never rewrites a browser session.

## Impact

- `lib/Service/AuthorizationService.php`, new `lib/Service/Consumer/` (6 classes), three new optional constructor arguments (autowired).
- integriq migrates afterwards (its lane): it passes a `ConsumerSource` over its `consumer` schema objects and calls the public entry points.
