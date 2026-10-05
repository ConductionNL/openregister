## 1. Declarative descriptors (this config head)

- [x] 1.1 Create `lib/Settings/credential_broker_register.json` — the `credential-broker` register descriptor (`x-openregister.type: application`) with the `credential` schema (`name`, `provider`, `owner`, `allowedApps[]`, `createdAt`; NO secret-bearing property) and 2 secret-less example `credential` objects (general org data, nil-UUID `owner`) under `components.objects[]`. This is the declarative source the **service-phase** Repair step imports — OpenRegister does NOT self-import register JSON at boot (ADR-037; OR seeds its own schemas via `lib/Repair/` steps, cf. `SeedAppVirtualSchemas`).
- [x] 1.2 Add `lib/Settings/credential-providers.json` — the runtime-immutable provider catalogue with `github` + `gitlab` entries (`identifier`, `title`, `baseUrl` host-lock, `authScheme{header,template}`, `allowRules[]{method,pathPattern}`). Read-only at runtime; NOT a register schema and NOT seeded objects (D2).

## 2. Manifest schema field

- [x] 2.1 Add the additive optional `credentials[]` field (`{provider, reason, scopes}`) to `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` and `app-manifest-v2.schema.json`.
- [x] 2.2 Bump the manifest schema version additively and confirm `npm run check:manifest` passes for a manifest with and without `credentials`.

## 3. Verification

- [x] 3.1 Deduplication check — document that no existing OR service/schema covers a credential broker; confirm the service phase reuses `ObjectService`, `IClientService`, `ICredentialsManager`, `ConfigurationService` (findings recorded even if "no overlap").
- [x] 3.2 JSON-validate the new register + catalogue files; run `composer check:strict`; confirm no regression to opencatalogi/softwarecatalog manifest validation.

## Deferred to `credential-broker-service` (code phase)

- The PHP Repair step (`lib/Repair/`) that imports `credential_broker_register.json` into OR (via `importFromFilePath()`) so the `credential` schema + example objects land on `occ upgrade`, idempotently (slug-matched). OR does not self-import its own registers at boot, so this seeding is code, not declarative config — it ships with the broker service.

## Acceptance criteria

- `lib/Settings/credential_broker_register.json` declares the `credential` schema (no secret-bearing property) + ≥2 secret-less example objects with the nil-UUID `owner` placeholder; the file is valid JSON.
- `lib/Settings/credential-providers.json` ships `github` (`Authorization: token {secret}`, permits `PUT /repos/*/contents/*` + `GET /repos/*`) and `gitlab` (`Bearer {secret}`) entries, host-locked; it is a read-only file (no create/update/delete API).
- The app-manifest schema accepts an optional `credentials[]` field; manifests with and without it both validate.
- No secret/token value appears in any descriptor or seed object.
- The Repair-step import that makes the schema live is scoped to `credential-broker-service` (this head ships descriptors only).

## Quality checklist

- ADR-001: domain metadata in OR objects; the secret is NOT an OR object property (vault, delivered in the service phase).
- ADR-031: the provider catalogue is a runtime-immutable `lib/` JSON file — a deliberate, security-justified exception to declarative-first (the allow-rules must not be runtime-widenable).
- ADR-011: no new HTTP/credential utility invented in this change — reuse documented for the code phases.
- ADR-037: OR does not self-import its own register monolith; the import is a service-phase `lib/Repair/` step, not a declarative-import wiring.
- Seed data uses only safe placeholders (`YOUR_TOKEN_HERE`, nil UUID); no value resembling a real token/secret/UUID.
- Manifest change is additive and back-compatible (existing fleet manifests keep validating).
- This is the `kind: config` head of the chain; no PHP/Vue/endpoints land here.
