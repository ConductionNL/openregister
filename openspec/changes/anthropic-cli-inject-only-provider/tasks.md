# Tasks: anthropic-cli-inject-only-provider

One additive entry in a runtime-immutable, review-gated security file. No PHP, no tests authored — the
`inject_only` branches are pre-existing, keyed purely on the flag, and already covered. Task 2 verifies
the existing implementation against the new entry rather than duplicating that coverage.

This is the head of a three-link chain (ADR-032). It MUST merge before hermiq's
`cli-runner-credential-declaration` declares the provider in its manifest:
`CredentialController::create()` rejects an unregistered provider with a 400
(`lib/Controller/CredentialController.php:267`), so the reverse order gives users a failure on save.

## Implementation Tasks

### Task 1: Register the anthropic-cli inject-only provider
- **spec_ref**: `openspec/changes/anthropic-cli-inject-only-provider/specs/credential-broker/spec.md#requirement-the-catalogue-registers-anthropic-cli-as-an-inject-only-provider`
- **files**: `lib/Settings/credential-providers.json`
- **acceptance_criteria**:
  - The `anthropic-cli` entry carries `inject_only: true` and declares NO `baseUrl` and NO `allowRules`
  - Key order matches the `generic-*` house style: `identifier`, `title`, `$comment`, `inject_only`, `authScheme`
  - The `$comment` records that the secret leaves OpenRegister into the calling app, names both bounding guards (owner/IDOR + `allowedApps`), states the personal-scope-only ToS constraint, and says the `authScheme` is descriptive only
  - `version` is bumped `1.5.0` → `1.6.0` — verified against HEAD, NOT `1.4.0` as the `$injectOnlyComment` narrative implies; `6473d7e37` already took `1.5.0`
  - `$injectOnlyComment` is extended so `inject_only` no longer reads as "the five `generic-*` entries": it must state the general rule (the broker cannot bound the call) covering BOTH an unbounded host and a non-HTTP consumer
  - `anthropic` and `anthropic-oauth` are byte-for-byte unchanged
  - The file remains valid JSON and contains no secret — `{secret}` is a placeholder only
- [ ] Implement
- [ ] Test

### Task 2: Verify the broker's existing guards against the new entry
- **spec_ref**: `openspec/changes/anthropic-cli-inject-only-provider/specs/credential-broker/spec.md#requirement-an-inject-only-credential-is-never-proxied`
- **files**: `lib/Service/Credential/CredentialBrokerService.php` (read-only — verification, no edit expected)
- **acceptance_criteria**:
  - A proxied request against an `anthropic-cli` credential is denied for every method and path, at `request()`'s inject-only guard, with the reason directing the caller to `resolveInjectable()`
  - `resolveInjectable()` returns the raw secret for an `anthropic-cli` credential only after Guard 1 (owner/IDOR) and Guard 2 (`allowedApps`) both pass
  - A credential owned by another user, or one not granting the calling app, is denied and yields no secret
  - `resolveInjectable()` still returns null for `anthropic-oauth` — the host-locked proxy providers stay zero-knowledge
  - No provider-specific branch is added: both paths must keep keying on the `inject_only` flag alone (`isInjectOnly()`)
  - If this task finds a gap, a code change is OUT OF SCOPE here — raise it rather than widening this `kind: config` change into a `mixed` one (ADR-032)
- [ ] Implement
- [ ] Test

## Quality checklist

- No PHPUnit tests authored: this change adds no business logic. A provider-specific test would pin a provider into a code path that is correctly provider-agnostic.
- No Newman tests: no endpoint added or changed. `GET /api/credentials/providers` and `POST /api/credentials` gain a new data value, not a new shape.
- No Playwright tests: no UI change. The Credentials tab renders the new row through the existing shared component.
- No i18n: `title` and `$comment` are catalogue metadata in the source language, not user-facing translated strings.
- No seed data, no migration: the catalogue is a read-only lib file, explicitly NOT an OpenRegister schema and not register-seeded objects. No tables, columns or data transformations.
- Never store a secret in this file. `{secret}` is substituted by the broker from the vault at call time.
- The file must remain valid JSON: `ProviderCatalogue::load()` validates only the top-level `providers` map, so a malformed entry surfaces at the consuming call site rather than at load.
- Landing order is mandatory: this change first, then hermiq's manifest declaration. Reverse order gives a 400 on save.
- `openspec validate --strict` passes.
