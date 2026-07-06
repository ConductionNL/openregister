## 1. Store resolution (D-A)

- [ ] 1.1 Add `lib/Service/Credential/CredentialStoreResolver.php` — Doriath eligibility = `IAppManager::isEnabledForUser('doriath')` + `class_exists` on the Doriath service classes + `method_exists` on the application-scoped seam (`deleteByApplication`, read-by-name) + self-registration state in `IAppConfig`; returns `DoriathCredentialStore` or `NextcloudVaultCredentialStore`.
- [ ] 1.2 Replace the static `CredentialStore → NextcloudVaultCredentialStore` DI alias in `lib/AppInfo/Application.php` (~line 347) with a resolver-backed `registerService` factory; no call-site changes.

## 2. Doriath leaf (D-C, D-F, D-A migration)

- [ ] 2.1 Add `lib/Service/Credential/DoriathCredentialStore.php` implementing `CredentialStore`: `put` = encrypt via Doriath stateless `EncryptService::rsaEncrypt` against OR's public key PEM (`rsa-oaep-sha256-chunked-v1`), upsert application-owned secret (name = credential UUID, root folder) via `createByApplication`/`updateByApplication`; `get` = in-process read-by-name + `DecryptService::rsaDecrypt` with the system-scoped private key; `delete` = `SecretService::deleteByApplication`, idempotent, plus residual-vault cleanup; >1 name match = fail closed, secret-free log.
- [ ] 2.2 Implement lazy migration inside `DoriathCredentialStore` (composes the vault leaf): Doriath miss → vault hit → re-put into Doriath → vault delete → return; sessionless reads of un-migrated secrets fail closed.

## 3. Self-registration repair step (D-B)

- [ ] 3.1 Add `lib/Repair/RegisterOpenRegisterWithDoriath.php`: skip when registered (IAppConfig UUID + live Doriath row) or Doriath ineligible (warn, never throw); else openssl RSA-4096 keypair → private key `ICredentialsManager` system scope → self-generated PKCS#10 CSR → in-process `ApplicationService::register(name: 'openregister', type: 'internal', csr, userId: null, isAdmin: true)` → persist Doriath-assigned application UUID + public key PEM in `IAppConfig`.
- [ ] 3.2 Register the step in `appinfo/info.xml` under `<install>` and `<post-migration>`.

## 4. No-code onboarding + in-process trust (D-G)

- [ ] 4.1 Hook `lib/AppHost/Repair/GenericInitializeSettings.php` (and the virtual-app manifest registration path) to call `CredentialAppTokenService::registerApp(appId)` for manifests declaring `credentials[]`, guarded by `isRegistered(appId)` so auto-runs never rotate an existing signing secret.
- [ ] 4.2 Document (docblocks) the in-process trusted path: same-instance PHP callers pass `appId` to `CredentialBrokerService::request` directly without an HMAC token; HTTP/cross-runtime callers keep signed tokens; controller unchanged.

## 5. Background acting user (D-K)

- [ ] 5.1 Add optional `?string $actingUserId = null` to `CredentialBrokerService::request`; owner guard uses it ONLY when `IUserSession` has no user; session identity wins unconditionally otherwise.
- [ ] 5.2 Assert `CredentialController::brokerRequest` forwards no acting user (session-only on HTTP) — controller diff must be empty apart from docblocks.

## 6. Tests + verification

- [ ] 6.1 Unit tests: resolver eligibility matrix (enabled/disabled/missing classes/missing methods/unregistered), Doriath leaf put-rotate/get/delete + ambiguity fail-closed, lazy migration (migrates once; sessionless fails closed), repair idempotency + degrade, D-G no-rotation guard, D-K session-wins + sessionless-acting-user + HTTP-never-forwards.
- [ ] 6.2 IDOR pin: user B can never broker user A's credential on the Doriath path (owner guard before any store read).
- [ ] 6.3 `composer check:strict`; `@spec` tags on all new/changed methods; no secret/plaintext in any log or error path (grep-level check).

## Acceptance criteria

- With Doriath ineligible, behaviour is byte-for-byte today's: vault leaf, no new calls, repair steps warn-and-skip.
- With Doriath eligible, secrets live ONLY as application-owned ciphertext (name = credential UUID, root folder); plaintext never leaves OR process memory.
- OR's Doriath private key exists only system-scoped in `ICredentialsManager`; the application UUID and public PEM in `IAppConfig` carry no secret material.
- Lazy migration moves a vault secret exactly once and deletes the vault row; deletes clear both stores.
- Auto-onboarding never rotates an existing app signing secret; explicit admin rotation still works.
- `actingUserId` is inert on the HTTP path and whenever a session exists.
- Cross-repo: OR merges safely before or after Doriath's `application-secret-delete` (resolver probes `method_exists`).
- Examples/tests use only placeholders: `YOUR_API_KEY_HERE`, nil UUID `00000000-0000-0000-0000-000000000000`, `<angle-brackets>`.

## Quality checklist

- ADR-031: broker/store remains the documented external-integration imperative exception; no new declarative surface; no seed data (no OR schema changes).
- ADR-005: guards unchanged, fail-closed, static generic errors, no secret/PII in logs; owner IDOR guard is the user-isolation boundary (D-F trade-off documented in design.md).
- ADR-011: Doriath crypto reused cross-app (no scheme reimplementation); service seams only, never Doriath mappers.
- Cross-repo dependency on Doriath `application-secret-delete` (+ application-scoped read) recorded in proposal Impact; no OR-internal dependency on `credential-provider-doffin` (siblings).
