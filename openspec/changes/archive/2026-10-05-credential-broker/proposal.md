---
kind: config
depends_on: []
chain:
  - credential-broker          # this spec (config head — credential schema, lib provider catalogue, manifest field)
  - credential-broker-service  # code — CredentialStore + ICredentialsManager leaf + broker service + endpoints
  - credential-broker-ui       # code — CnCredentials settings section + per-app manifest wiring
---

## Why

Conduction apps increasingly need to act on external providers (GitHub, GitLab, …)
*on a user's behalf* — Hermiq wants to publish a skill to GitHub, OpenBuild wants
to discover external apps — but today the only way is for the user to paste a
personal access token *into the consuming app*, which then holds the secret. That
spreads long-lived secrets across every app, with no central revocation, no
per-app scoping, and no audit. We need a **credential broker**: the user gives a
secret to OpenRegister *once*, and apps make outbound calls through OR without
ever seeing the secret.

## What Changes

This change is the **config head of a three-spec chain** (ADR-032). It lands the
declarative data model that the code phases build on; it ships no PHP/Vue.

- Introduce a `credential` OR schema — owner-scoped metadata for a stored secret
  (`name`, `provider`, `owner`, `allowedApps[]`, `createdAt`). The metadata holds
  **no secret** — only a handle. The token lives in the Nextcloud vault, keyed by
  the credential's UUID (delivered in the service phase).
- Ship the curated provider catalogue as a **runtime-immutable declarative JSON file
  in OR's `lib/`** (`lib/Settings/credential-providers.json`), NOT an OR schema. Each
  entry declares the host-locked `baseUrl`, the auth-header scheme
  (`Authorization: token {secret}` / `Bearer {secret}`), and the constrained-proxy
  `allowRules[]` (permitted HTTP method + path-prefix patterns), with `github` and
  `gitlab` shipped. The catalogue is fixed at release — the allow-rules are the
  proxy's security guardrails and MUST NOT be widenable at runtime via any API.
- Seed a couple of secret-less example `credential` metadata objects (ADR-001
  seed-data rule).
- Add a `credentials` field to the shared app-manifest schema
  (`@conduction/nextcloud-vue`) so an app can declare which providers it uses:
  `credentials: [{ provider, reason, scopes }]`.

**Downstream chain (narrated here, not implemented in this change):**

- `credential-broker-service` (`kind: code`, `depends_on: credential-broker`) —
  the `CredentialStore` abstraction + first `ICredentialsManager` vault leaf, the
  `CredentialBrokerService` and `POST /api/credentials/{id}/request` endpoint that
  performs the outbound call server-side under the four ordered guards
  (owner → allowedApps → allow-rules → host-lock), plus credential CRUD endpoints.
- `credential-broker-ui` (`kind: code`, `depends_on: credential-broker-service`) —
  the shared `CnCredentials` user-settings section (create / name / duplicate /
  delete / allow-per-app) and per-app manifest `credentials` declarations. Consuming
  apps (Hermiq `publishToHub`, OpenBuild external-app discovery) adopt the broker in
  their own later changes.

## Capabilities

### New Capabilities

- `credential-broker`: A secret-broker capability where OR holds provider secrets
  in the Nextcloud vault and performs constrained outbound calls on a user's behalf,
  so consuming apps never hold the secret. This config head defines the `credential`
  schema, the runtime-immutable `lib/` provider catalogue (`credential-providers.json`,
  github/gitlab), and the manifest `credentials` declaration. Downstream chain specs
  ADD the broker service, the guarded request endpoint, and the settings UI to this
  same capability.

### Modified Capabilities

- `openregister-app-manifest`: the app-manifest schema gains an optional
  `credentials[]` field (`{ provider, reason, scopes }`) so apps can declare their
  provider usage. Additive and back-compatible — existing manifests keep validating.

## Impact

- **New declarative surfaces**: `lib/Settings/credential_broker_register.json`
  (`credential` schema descriptor + secret-less credential example objects) and
  `lib/Settings/credential-providers.json` — the runtime-immutable provider catalogue
  (github/gitlab, host-lock + auth scheme + allow-rules), read-only at runtime, never
  API-editable. These are **inert descriptors**: OpenRegister does not self-import its own
  register JSON at boot (ADR-037), so the `credential` schema is materialised by a `lib/Repair/`
  step that ships in the `credential-broker-service` code phase (`importFromFilePath()`,
  idempotent, slug-matched — mirroring `SeedAppVirtualSchemas`), not by this config head.
- **Shared schema**: `@conduction/nextcloud-vue/src/schemas/app-manifest*.schema.json`
  gains the additive `credentials` field (validated by `npm run check:manifest`).
- **No code, no endpoints, no secrets** in this change — those land in
  `credential-broker-service`. No secret value is ever stored in an OR object.
- **Downstream dependents**: Hermiq (publish-to-GitHub) and OpenBuild
  (external-app discovery) unblock once the service + UI phases merge.
