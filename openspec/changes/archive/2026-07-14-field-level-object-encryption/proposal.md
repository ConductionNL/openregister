---
kind: code
---

# Field-level object encryption

## Why

OpenRegister already encrypts secrets at rest — source credentials
(`SourcesController`), workflow-engine credentials (`WorkflowEngineRegistry`),
and the per-tenant audit-trail HMAC key (`TenantKeyService`) — all via
`OCP\Security\ICrypto`. But it does **not** encrypt arbitrary *object field
values*. Municipal case data stored in OpenRegister objects (BSN, medical
information, financial data) has no field-level encryption-at-rest primitive
today: the only relevant control is `writeOnly` redaction (or#380/or#392),
which hides a field from API responses but does nothing for the value sitting
in plaintext in the database (magic table blob and, until now, indexed
columns).

This is a data-platform gap, not a per-app one: every Conduction app that
stores citizen data on top of OpenRegister (procest, zaakafhandelapp,
larpingapp, decidesk, …) needs this. Reimplementing crypto per app would
violate the fleet's "apps consume OR abstractions" rule (ADR-022) and risks
each app rolling its own (worse) crypto. It belongs in OpenRegister as a
schema-declared primitive, exactly like `writeOnly` redaction and property
RBAC.

## What Changes

- **New schema property flag `x-openregister-encrypted: true`** (OpenRegister
  vendor-extension convention, sibling to the existing `x-openregister-*`
  extensions; `writeOnly` itself is a *standard* JSON-Schema keyword, not an
  `x-openregister-*` extension — this flag intentionally follows the
  vendor-extension family instead since it is an OpenRegister-specific
  capability, not a portable JSON-Schema concept).
- **`FieldEncryptionHandler`** (`lib/Service/FieldEncryptionHandler.php`) —
  encrypts/decrypts flagged property values via `OCP\Security\ICrypto`
  (reused, not reimplemented — the same primitive `TenantKeyService` and the
  credential controllers already use). Values are stored as an envelope
  (`openregister:enc:v1:<ciphertext>`) so encrypted and legacy-plaintext
  values are unambiguous during rollout.
- **Encrypt-on-save**: wired into `SaveObject::prepareObjectData()` — the
  last step before persistence, after validation/cascading/defaults/computed
  fields have all run on plaintext.
- **Decrypt-on-read, composed with existing redaction**: wired into
  `RenderObject::renderEntity()` and `RenderObject::redactWriteOnlyFromRows()`
  — strictly *after* the existing writeOnly/property-authorization strip
  block. Decryption only touches properties still present in the data at that
  point, so a property redaction already removed is never reached — an
  unauthorized caller gets the same absence every other redacted property
  gets, never ciphertext, never plaintext.
- **Search/facet exclusion + fail-loud filtering**: `MagicMapper` no longer
  creates a dedicated magic-table column for an encrypted property (it only
  ever holds ciphertext there); `MagicSearchHandler::applyObjectFilters()`
  throws a typed `EncryptedFieldFilterException`, translated by a new
  `EncryptedFieldFilterMiddleware` to HTTP 400, when a caller filters on an
  encrypted property — never a silent zero-row result. `FacetHandler` excludes
  encrypted properties from the facetable-fields listing.
- **Migration path**: new `openregister:encrypt-field --property=<name>` CLI
  command (mirrors `BackfillSystemOwnerCommand`'s shape) encrypts existing
  plaintext values of a newly-flagged property, idempotently (already-enveloped
  values are skipped), with `--dry-run`, `--register`, `--schema` scoping.
- **Fail loud on decryption failure**: never a swallowed catch. The read path
  substitutes a structured `@openregister_decryption_error` marker (logged at
  ERROR) rather than crashing a whole list render over one bad row; the
  migration command and any `throwOnFailure: true` caller get a thrown
  `FieldDecryptionException` instead.

## Capabilities

### New Capabilities

- `field-level-encryption`: the encryption-at-rest primitive itself — the
  schema flag, the save/render composition with RBAC/writeOnly, the
  search/facet exclusion, and the migration path.

### Modified Capabilities

None — this is additive. No existing OpenRegister capability's documented
behaviour changes for a schema that does not use the new flag.

## Impact

**Affected code**
- `lib/Service/FieldEncryptionHandler.php` — new.
- `lib/Exception/FieldDecryptionException.php`,
  `lib/Exception/EncryptedFieldFilterException.php` — new.
- `lib/Middleware/EncryptedFieldFilterMiddleware.php` — new, registered in
  `lib/AppInfo/Application.php`.
- `lib/Command/EncryptFieldCommand.php` — new, registered in `appinfo/info.xml`.
- `lib/Db/Schema.php` — `hasEncryptedProperties()`/`getEncryptedProperties()`.
- `lib/Service/Object/SaveObject.php` — encrypt-on-save hook in `prepareObjectData()`.
- `lib/Service/Object/RenderObject.php` — decrypt-on-read hook in `renderEntity()`
  and `redactWriteOnlyFromRows()`.
- `lib/Db/MagicMapper.php` — `buildTableColumnsFromSchema()` skips encrypted properties.
- `lib/Db/MagicMapper/MagicSearchHandler.php` — filter rejection + full-text-search skip.
- `lib/Service/Object/FacetHandler.php` — facetable-fields exclusion.

**APIs**
- No existing endpoint's request/response shape changes for unflagged
  schemas. A schema that flags a property `x-openregister-encrypted: true`
  gains: transparent encrypt-on-save/decrypt-on-read for authorized callers,
  and a new HTTP 400 (`encrypted-field-not-filterable`) if a filter targets
  that property.

**Not in scope**
- Encrypting non-string (array/object-typed) property values — v1 supports
  scalar string fields only (documented limitation in design.md).
- Client-side / end-to-end encryption — this is server-side encryption at
  rest; the Nextcloud instance (and anyone with `occ` access) can still
  decrypt via the running application.
- Key rotation tooling beyond what `ICrypto` itself provides (the instance
  secret in `config.php`) — see design.md's threat model for the key-management
  posture this inherits.
