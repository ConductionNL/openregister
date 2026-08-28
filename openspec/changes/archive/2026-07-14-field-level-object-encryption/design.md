# Design — Field-level object encryption

## Context

OpenRegister stores object data as a JSON blob (`object` column) per row in a
per-register-per-schema "magic table", plus a set of dedicated typed columns
mirrored from scalar schema properties for fast filtering/faceting/sorting.
Read/write for every object flows through two narrow choke points regardless
of caller (controller, MCP tool, sync engine): `SaveObject::saveObject()` on
write and `RenderObject::renderEntity()` / `redactWriteOnlyFromRows()` on
read. Property-level access control (RBAC `authorization.read`/`update` and
`writeOnly` secret-stripping) already composes at those exact choke points via
`PropertyRbacHandler`. This feature adds a third property-level behaviour —
encryption at rest — at the same choke points, so it composes with the other
two instead of creating a fourth, competing code path.

## Goals / Non-Goals

**Goals**
- Encrypt flagged scalar string property values at rest, transparently, for
  any app built on OpenRegister.
- Compose correctly with existing RBAC/writeOnly redaction: an unauthorized
  reader must never receive ciphertext (a slightly-less-bad leak than
  plaintext, but still a leak of *some* stored value where none was
  authorized).
- Make the search/filter behaviour for an encrypted field unambiguous: never
  silently return zero rows, never leak.
- Provide an explicit, auditable migration path for existing plaintext data.
- Fail loud on decryption failure — never a swallowed catch that looks like a
  healthy, working feature.

**Non-goals**
- New cryptographic primitives. This reuses `OCP\Security\ICrypto` exactly as
  `TenantKeyService` and the credential controllers already do.
- Client-side / end-to-end encryption. This is server-side encryption at
  rest, protecting the data at the storage layer (database dumps, DBA access,
  disk theft) — not from the running Nextcloud application itself or from an
  admin with `occ` access.
- Encrypting non-scalar (array/object-typed) properties. Out of scope for v1;
  see "Known limitations" below.
- New key-management infrastructure. This inherits `ICrypto`'s posture
  wholesale (see "Key management" below) rather than introducing a second key
  store alongside it.

## Threat model

**In scope (mitigated by this feature)**
- **Database-at-rest exposure**: a stolen/leaked database dump, a
  misconfigured backup, or read access to the raw `openregister_table_*`
  tables no longer yields plaintext BSN/medical/financial values for flagged
  properties — only opaque ciphertext envelopes.
- **Accidental over-broad read access**: a bug or misconfiguration elsewhere
  in the read path that bypasses RBAC/writeOnly redaction (the exact ocon#147
  class of bug this fleet has hit before) still cannot yield plaintext for an
  encrypted field, because decryption is a *second*, independent gate that
  the ciphertext must additionally pass through. This is defense in depth,
  not a replacement for RBAC.
- **Search/filter side-channel**: a caller cannot use `?bsn=123456789` to
  confirm the existence of a specific value via a 200-with-results vs 200-
  empty timing/response-shape oracle, because the request is rejected outright
  (HTTP 400) rather than silently executed against ciphertext.

**Explicitly out of scope (not mitigated, and not claimed to be)**
- **A compromised Nextcloud application process.** `ICrypto`'s encryption key
  is derived from the instance secret in `config.php`, which the running PHP
  process can always read (it has to, to decrypt on every authorized
  request). An attacker with code execution inside the app, or an admin with
  `occ` shell access, can decrypt any flagged field via the same code path a
  legitimate request uses. This is identical to the existing posture for
  source credentials and the tenant HMAC key — this feature does not weaken
  or strengthen that boundary, it extends the same boundary to object fields.
- **A malicious or compromised database administrator with query access
  through the application itself** (e.g., via an MCP tool or API token that
  is authorized to read the object) — decryption happens for any caller that
  clears RBAC, by design; this is encryption *at rest*, not per-user
  end-to-end encryption with per-user keys.
- **Ciphertext presence as itself a signal.** The property still appears as a
  key in the object with a non-empty (ciphertext) value; this feature does
  not hide *whether* a flagged field has a value, only *what* the value is,
  from an unauthorized or database-level reader.

## Key management

This feature introduces **no new key material and no new key store**. It
reuses Nextcloud's `OCP\Security\ICrypto`, which derives its encryption key
from the instance-wide secret in `config.php` (`secret` value, itself
typically generated at install time and never exposed via any API). This is
the exact same key source `TenantKeyService` uses for the audit-trail HMAC key
and the credential controllers (`SourcesController`,
`WorkflowEngineRegistry`) use for stored source/workflow credentials.

**Rotation posture**: `ICrypto` does not expose an application-level rotation
API distinct from rotating the underlying instance secret. Rotating the
instance secret invalidates *every* value encrypted via `ICrypto` fleet-wide
(credentials, tenant keys, and now flagged object fields) — this is a
pre-existing, instance-wide operational concern this feature inherits rather
than introduces. `FieldEncryptionHandler::decryptValue()` surfaces a rotation
(or any other decryption) failure as a typed `FieldDecryptionException`,
never a silent `null`/empty value, so a rotation event is immediately visible
in logs and (for the migration command) in the command's exit code — it does
not silently look like "the feature quietly stopped working."

**Not addressed by this change**: per-field or per-tenant distinct encryption
keys. Every flagged field on an instance is encrypted under the same
`ICrypto` key. A future change could layer `TenantKeyService`-style per-tenant
keys underneath `FieldEncryptionHandler` if per-tenant key isolation becomes a
requirement; the envelope format's version segment (`v1`) exists precisely so
that future change can introduce a `v2` envelope without an ambiguous
migration.

## Envelope format

`openregister:enc:v1:<ICrypto::encrypt(plaintext)>`

- The prefix disambiguates ciphertext from plaintext during a mixed rollout —
  a property flagged encrypted *today* may still hold plaintext written
  *before* the flag was set, until a save or the migration command
  (`openregister:encrypt-field`) re-encrypts it. Decryption checks the prefix
  before ever calling `ICrypto::decrypt()`, so a plaintext value in this
  mixed state is correctly recognised as "not yet encrypted" rather than
  producing a decryption error.
- The `v1` version segment allows a future envelope-format change (e.g. a
  different AEAD construction, or per-tenant keys) without breaking values
  already encrypted under this format — decryption can dispatch on the
  version segment.
- Idempotent by construction: `encryptProperties()` checks `isEnvelope()`
  before encrypting, so re-saving an already-encrypted object (or re-running
  the migration command) never double-wraps a value.

## Composition with RBAC / writeOnly redaction

The critical invariant: **decryption must run strictly after redaction, and
must only act on properties still present in the data.**

```
renderEntity():
  ... file hydration, fields/filter/unset projection ...
  [redaction block] strip writeOnly + property-authorization-denied fields
  [NEW] decrypt properties still present, flagged x-openregister-encrypted
  ... translation resolution, virtual calculations ...
```

`FieldEncryptionHandler::decryptProperties()` iterates the schema's flagged
property *names* and, for each, checks `array_key_exists($property, $data)`
before touching it. A property the redaction block just removed is no longer
a key in `$data` — decryption is a no-op for it. This means an unauthorized
caller's outcome for an encrypted+redacted field is **identical** to any
other redacted field: the key is simply absent. There is no code path where
an unauthorized caller receives ciphertext (a lesser leak than plaintext, but
still a leak of "the field has *a* value") or, worse, an accidentally
decrypted plaintext value.

The same ordering is mirrored in the cheap list-render path
(`RenderObject::redactWriteOnlyFromRows()`), which — like the writeOnly strip
it composes with — calls `PropertyRbacHandler::filterReadableProperties()`
first and decrypts second.

**Known, documented asymmetry**: `redactWriteOnlyFromRows()` bypasses *all*
processing (including the new decryption step) for `_rbac: false` /
`SystemOperationContext::isActive()` trusted-internal-reader contexts,
because that early-return already exists for writeOnly semantics ("internal
code that needs the raw secret reads `ObjectEntity::getObject()` directly and
never enters this method"). Applied to *encryption* rather than *writeOnly*
secrecy, this means an internal batch-list reader that legitimately needs
decrypted values (e.g., a future sync engine reading BSNs in bulk) currently
gets ciphertext from this specific cheap-list path, not decrypted plaintext.
The single-object path (`renderEntity()`) does **not** have this asymmetry —
its decryption step is unconditional (not gated on `$_rbac`), matching the
functional reality that ciphertext is useless to trusted internal code, not
just to unauthorized external callers. This split is called out explicitly
as a known scope boundary rather than silently left inconsistent; a follow-up
can extend unconditional decryption to the cheap-list path if a real
internal-bulk-read consumer needs it — filed as a documented limitation, not
implemented speculatively here.

## Search/facet exclusion rationale

An encrypted field's stored value is ciphertext — AES output is, by design,
statistically indistinguishable from random bytes and never equal to the
plaintext a caller would filter by. Three consequences follow, and this
change implements all three rather than picking one:

1. **No dedicated magic-table column.** `MagicMapper::buildTableColumnsFromSchema()`
   skips a flagged property entirely. A typed column populated with
   ciphertext would be actively misleading (it looks like a normal indexed
   column, inviting a filter/sort/facet attempt) for zero benefit — the value
   still lives in the `object` JSON blob, which is all the read path needs.
2. **Fail loud on a filter attempt**, rather than let the (nonexistent, or —
   pre-migration — still legacy-typed) column silently participate in a query
   and return zero matches indistinguishable from "no such record."
   `MagicSearchHandler::applyObjectFilters()` throws
   `EncryptedFieldFilterException` before building any SQL condition;
   `EncryptedFieldFilterMiddleware::afterException()` translates it to HTTP
   400 with a structured body (`{"error": "encrypted-field-not-filterable",
   "property": "..."}`) so API clients can distinguish this from "your filter
   syntax is wrong" or "no results." Every internal call site that could
   otherwise swallow this exception into a warning-and-empty-result (several
   exist in `MagicMapper`'s search/count wrappers, pre-dating this feature)
   was audited and given an explicit rethrow for `EncryptedFieldFilterException`
   specifically, so "fail loud" survives the existing defensive
   catch-and-degrade layers rather than being silently absorbed by them.
3. **Excluded from full-text search and facets.** `applyFullTextSearch()`
   skips a flagged property in its `LIKE`-based scan (it can never match
   plaintext search terms against ciphertext); `FacetHandler` excludes a
   flagged property from the facetable-fields listing even if a schema author
   also marks it `facetable: true` — grouping by ciphertext is meaningless at
   best and a values-are-distinct-per-record leak into facet option labels at
   worst.

**Scope boundary, documented not chased exhaustively**: `MagicMapper` has
several independent try/catch layers around per-schema search/count calls
(the single-schema path, and the multi-schema fan-out used by unified
search). The primary single-schema filter/count path and the multi-schema
*count* path were given explicit `EncryptedFieldFilterException` rethrows so
the exception is never silently absorbed there. The deepest multi-schema
*result-fetch* fan-out (`searchAcrossMultipleTables()`) was not individually
audited given the size of this change; a filter on an encrypted property in
that specific narrow path could still degrade to a partial/empty result for
that one schema rather than a hard 400. This is called out as a known
follow-up rather than silently left unverified.

## Migration / rollout

`openregister:encrypt-field --property=<name> [--register=] [--schema=]
[--dry-run]` (`lib/Command/EncryptFieldCommand.php`, modelled directly on the
existing `openregister:backfill-system-owner` command shape):

- Resolves every register+schema pair where the *schema* flags the named
  property `x-openregister-encrypted: true` — running the command against a
  property nobody flagged is a no-op, not an accidental encrypt-everything.
- Per magic table: `SELECT id, object`, decode the JSON blob, encrypt the
  named property's value if it is a non-empty string that is not already an
  envelope, `UPDATE ... SET object = ...`. Row-by-row because `ICrypto::encrypt()`
  is not batchable in SQL.
- **Idempotent**: an already-enveloped value is skipped (detected via
  `isEnvelope()`), so re-running the command (e.g. after new data arrives, or
  to verify nothing was missed) only touches remaining plaintext rows.
- Best-effort nulls out a same-named legacy dedicated column, if one still
  exists from before the property was flagged (a schema can have had a
  plaintext typed column for months before an admin flags it encrypted) — a
  plaintext mirror must not survive outside the now-encrypted blob. This is
  the one place a database error (missing column) is deliberately swallowed,
  because "the column doesn't exist" is the *expected*, successful outcome
  going forward (new encrypted properties never get one) — this is distinct
  from decryption failure, which is never swallowed.
- `--dry-run` reports counts without writing.
- Per-row encryption failure increments a `failed` counter and is logged;
  a non-zero `failed` count fails the whole command (`Command::FAILURE`) even
  though individual rows were still processed — an operator scripting this
  command must see a non-zero exit code if any row still holds plaintext.

## Known limitations (v1)

- **Scalar string properties only.** `encryptProperties()`/`decryptProperties()`
  skip (log a warning, leave untouched) a flagged property whose value is not
  a string — array/object-typed properties are not supported. Flagging such a
  property is inert, not an error; a future change could add structured
  (per-leaf) encryption if a real need arises.
- **Single fleet-wide key** (see "Key management" above) — no per-tenant
  isolation in v1.
- **Cheap-list-path system-context asymmetry** (see "Composition" above) —
  `_rbac: false` batch reads via `redactWriteOnlyFromRows()` receive
  ciphertext, not decrypted plaintext, unlike the single-object render path.
- **Deep multi-schema search fan-out** (see "Search/facet exclusion
  rationale" above) is not individually verified to reject an encrypted-field
  filter with the same rigor as the primary single-schema path.

## Alternatives considered

- **Encrypting the entire `object` JSON blob per row.** Rejected: this would
  make every property un-filterable (defeating the magic-table's entire
  purpose), require decrypting on every read regardless of which fields a
  caller actually needs, and could not distinguish "this schema wants one
  field protected" from "this schema wants everything protected" — a much
  coarser, less useful primitive than field-level flagging.
- **A separate encrypted-values side table**, keyed by object id + property
  name (similar in shape to `openregister_tenant_keys`). Rejected: this
  reintroduces exactly the "written to more than one place" class of bug the
  writeOnly/ocon#147 fix had to close for the `relations` search-index mirror
  — a second storage location for the same logical value is a second place
  for redaction/decryption to be forgotten. Keeping the ciphertext inline in
  the same JSON blob (just enveloped) means every existing save/render call
  site's plumbing continues to work unchanged; only the value's *shape*
  differs.
- **A per-property `ICrypto::encrypt($value, $password)` with a schema- or
  property-derived password.** Considered for future per-tenant/per-schema
  key isolation; not implemented in v1 to keep this change's crypto surface
  identical to the already-audited `TenantKeyService` pattern (no password
  argument). Explicitly left as the natural extension point for a `v2`
  envelope.
