# Annotation-only maps (no new requirements)

Two sub-clusters add NO new requirements — their methods are already fully covered by the canonical specs. They have no `specs/<cap>/spec.md` delta (which would require a delta section under `--strict`); instead the existing-requirement mapping lives here.

## security-and-rate-limiting → auth-system (existing requirements)

`lib/Service/SecurityService.php` is already covered by `auth-system`:

- **Requirement: Rate limiting MUST protect against brute force attacks and API abuse** ← `checkLoginRateLimit`, `recordFailedLoginAttempt`, `recordSuccessfulLogin`, `clearIpRateLimits`, `clearUserRateLimits` (task-3). 5-attempt / 900s window, 3600s lockout, progressive 2s→60s delay, per-user + per-IP counters, success clears all caches, admin manual clear emits `ip_rate_limits_cleared` / `user_rate_limits_cleared`.
- **Requirement: Authentication and security events MUST be audited** ← `logSecurityEvent` (private; exercised by every rate-limit method, task-3). `user_locked_out` / `login_attempt_during_lockout` at WARNING, rest at INFO, each timestamped.
- **Requirement: Input sanitization MUST prevent XSS and injection attacks** ← `sanitizeInput`, `validateLoginCredentials` (task-4). Trim → length-cap → null-byte strip → `htmlspecialchars(ENT_QUOTES|ENT_HTML5)` → dangerous-pattern removal; credential validation (presence, username ≥2 chars, invalid-char reject `<>"'/\`, password ≤1000 chars).
- **Requirement: CORS policy MUST be enforced per Consumer and prevent CSRF** (security-headers scenario) ← `addSecurityHeaders`, `getClientIpAddress` (task-5).

### Security notes (auth-system)

- **Rate limiting is dormant for API auth** — already a known `auth-system` gap: nothing calls `checkLoginRateLimit()` before `AuthorizationService::authorizeBasic/Jwt/ApiKey`; protection only applies where a caller (e.g. `UserController::login`) explicitly wires it in.
- **`recordSuccessfulLogin()` clears IP lockout on any success** — one valid credential from a shared/NAT'd IP resets that IP's counters for everyone behind it. Low severity (success implies valid credential).
- **`getClientIpAddress()` trusts client-supplied forwarding headers** when the first hop is a public IP — spoofable to an attacker-chosen public address unless a trusted reverse proxy normalises forwarding headers.
- `sanitizeForCacheKey` maps to `[a-zA-Z0-9._@-]`, truncated to 64 chars — collision-prone for very long usernames but safe for cache-key use.

## edepot-transfer-extras → edepot-transfer (existing requirements)

`lib/Service/Edepot/EdepotTransferService.php` + `lib/Service/Edepot/SipPackageBuilder.php` are already covered by `edepot-transfer`:

- **Requirement: The system MUST support multiple transport protocols for SIP delivery** (transport-failure-with-retry) ← `EdepotTransferService::executeTransfer` (task-6).
- **Requirement: The system MUST track transfer status per object** ← `gatherObjectsWithFiles`, `getObjectFiles`, `processResults`, `markObjectTransferred`, `markObjectTransferFailed` (task-6).
- **Requirement: The system MUST assemble SIP packages for e-Depot transfer** ← `SipPackageBuilder::splitIntoBatches`, `buildSinglePackage`, `createManifestEntry` (task-7).
- **Requirement: The system MUST log all transfer actions in the audit trail** ← `notifyTransferCompletion` (task-6); `logTransferInitiated/logObjectTransferred/logTransferFailed` already annotated to earlier passes.

### Notes (edepot-transfer)

- `executeTransfer` hard-codes the completion-notification recipient to the `admin` user, not the archivist role named in the spec's notification scenarios.
- `getObjectFiles` stats files off the local filesystem; objects whose files are absent at transfer time are silently skipped, producing metadata-only SIP entries — the same path serves both intentionally-fileless objects and missing-content failures.
