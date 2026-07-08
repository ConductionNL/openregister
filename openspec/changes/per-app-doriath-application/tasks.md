## 1. Per-app Doriath registration seam (D-2, D-6)

- [ ] 1.1 Add a per-app registration seam (method/service in `lib/AppHost/` or `lib/Service/Credential/`) that resolves Doriath's `ApplicationService` cross-app via `class_exists('OCA\\Doriath\\Service\\ApplicationService')` + `OCP\Server::get` (reusing the `RegisterOpenRegisterWithDoriath::resolveApplicationService()` idiom — no compile-time Doriath dependency), returning null when unavailable.
- [ ] 1.2 In the seam, call `register(name: <appId>, description: <manifest description or generic fallback>, type: 'internal', csr: null, userId: <initiating user or null>, isAdmin: false)` so Doriath creates a `pending` row with no CSR/EncryptionSuite.
- [ ] 1.3 Add a per-app `IAppConfig` key namespaced by appId (e.g. `doriath_application_id/<appId>`), distinct from `DoriathCredentialStore::APP_CONFIG_APPLICATION_ID`; persist the Doriath-assigned application UUID after registration.

## 2. Idempotency + degrade (D-3)

- [ ] 2.1 Guard registration with an `isRegistrationLive`-style probe: skip when the per-app `IAppConfig` UUID is set AND `ApplicationService::get($uuid, '', true)` finds the row; re-register only when the row is stale/absent; never rotate or mutate an existing application.
- [ ] 2.2 Wrap the whole seam never-throw (mirror `RegisterOpenRegisterWithDoriath`): Doriath absent/disabled/unloadable → warn and skip, leaving app-key onboarding and the broker unchanged.

## 3. Wire into the onboarding hook (D-5)

- [ ] 3.1 Extend `GenericInitializeSettings::registerCredentialConsumer()` to also invoke the per-app registration seam (after the existing `CredentialAppTokenService::registerApp`), reusing `manifestDeclaresCredentials()`/the manifest read for the description; both paths stay independent and idempotent.
- [ ] 3.2 Confirm `DoriathCredentialStore`, `RegisterOpenRegisterWithDoriath` (OR's own `openregister` application), and all existing brokered secrets are untouched — custody unchanged (D-C/D-F).

## 4. Tests + verification

- [ ] 4.1 Unit tests: registration seam calls `register` with name=appId, csr=null, isAdmin=false; per-app UUID persisted; idempotency probe skips a live row and re-registers a stale one; Doriath-absent degrades (warn, no throw); custody paths unmodified.
- [ ] 4.2 Regression: with Doriath ineligible, onboarding behaviour is byte-for-byte today's (app-key `registerApp` only); verify against `opencatalogi`/`softwarecatalog` leaf init that no per-app Doriath row is attempted.
- [ ] 4.3 `composer check:strict`; `@spec` tags on all new/changed methods; no secret material persisted for identity-only apps (grep-level check).

## Acceptance criteria

- A `credentials[]`-declaring app onboarding on an eligible-Doriath instance registers its OWN Doriath `Application` (name = appId, description from manifest, type `internal`, no CSR) and appears in Doriath's Applications list separate from `openregister`.
- The per-app application is created `pending`; it becomes active only after an admin approves it in Doriath.
- Registration is idempotent: a live persisted UUID makes the hook a no-op; it never re-registers or rotates an existing application.
- No brokered secret is moved, re-keyed, or re-encrypted; custody stays under OpenRegister's single self-registered Doriath application vault.
- With Doriath ineligible, behaviour is byte-for-byte today's: app-key onboarding only, per-app registration warns-and-skips.
- No secret material is persisted for a per-app identity-only application (only the non-secret application UUID lands in `IAppConfig`).
- Examples/tests use only placeholders: nil UUID `00000000-0000-0000-0000-000000000000`, `<appId>`, `<PLACEHOLDER>`.

## Quality checklist

- ADR-031: registration is imperative cross-app plumbing; no new declarative surface; no OR schema, therefore no seed data.
- ADR-011: reuses `ApplicationService::register` + the `class_exists`/`Server::get` resolution idiom from `RegisterOpenRegisterWithDoriath` — no reimplementation; service seams only, never Doriath mappers.
- ADR-005: seam degrades fail-safe (warn, never throw); no secret/PII in logs.
- Relationship to `credential-doriath-leaf` D-C/D-F documented in design.md; this ADDS per-app identity and does NOT move custody (custody-move is the RAISED scope fork).
- Deferred/open decisions (scope, approval, trigger ownership, naming, kind) recorded in design.md Open Questions.
