---
status: draft
---
# Capability: `field-level-encryption`

## Purpose

Provide encryption-at-rest for individual OpenRegister object property
values, declared per-schema via `x-openregister-encrypted: true`, so that
every app built on OpenRegister inherits field-level protection for sensitive
data (BSN, medical, financial) as a data-platform primitive rather than a
per-app reimplementation. Composes with existing property-level RBAC and
`writeOnly` redaction rather than replacing or bypassing them.

## ADDED Requirements

### Requirement: Flagged properties are encrypted on save

A schema property carrying `x-openregister-encrypted: true` SHALL have its
value replaced with an encryption envelope
(`openregister:enc:v1:<ciphertext>`, produced via `OCP\Security\ICrypto`)
before the object is persisted. Encryption SHALL run after schema validation
and after every other save-time transform (cascading, default values,
computed fields) — those transforms operate on plaintext; only the final
persisted value is enveloped. A value already in envelope form SHALL be left
unchanged (idempotent — re-saving an already-encrypted object never
double-encrypts). A `null` or empty-string value SHALL be left unchanged. A
non-string value for a flagged property SHALL be left unchanged and logged
(scalar string properties only in v1).

#### Scenario: A flagged field is encrypted on create
- **WHEN** an object is created against a schema where property `bsn` carries
  `x-openregister-encrypted: true`, with `bsn: "123456789"`
- **THEN** the value persisted for `bsn` is an `openregister:enc:v1:`-prefixed
  envelope, never the plaintext `"123456789"`

#### Scenario: An unflagged field is never encrypted
- **WHEN** an object is saved against a schema where property `name` carries
  no `x-openregister-encrypted` flag
- **THEN** the persisted value for `name` is unchanged plaintext

#### Scenario: Re-saving an already-encrypted value is idempotent
- **WHEN** an object whose flagged property already holds an
  `openregister:enc:v1:` envelope is saved again unchanged
- **THEN** the persisted envelope is unchanged — it is not re-encrypted or
  double-wrapped

### Requirement: Authorized reads are decrypted; unauthorized reads never see ciphertext

On render, a flagged property's envelope value SHALL be decrypted back to
plaintext for a caller whose read survives the existing `writeOnly` and
property-authorization redaction. Decryption SHALL run strictly *after* that
redaction step and SHALL only act on a property that is still present in the
rendered data at that point — a property the redaction step already removed
SHALL NOT be decrypted, and SHALL be absent from the response exactly like
any other redacted property (never ciphertext, never plaintext). A value not
yet in envelope form (a plaintext value from before the property was flagged,
during rollout) SHALL be returned unchanged.

#### Scenario: Authorized caller receives decrypted plaintext
- **WHEN** an authorized caller reads an object whose `bsn` property holds an
  `openregister:enc:v1:` envelope
- **THEN** the response contains the decrypted plaintext value for `bsn`,
  never the envelope string

#### Scenario: Unauthorized caller never receives ciphertext
- **WHEN** a caller without read authorization for property `bsn` (denied by
  `writeOnly` or property-level `authorization.read`) reads an object whose
  `bsn` property holds an `openregister:enc:v1:` envelope
- **THEN** `bsn` is absent from the response — never the envelope ciphertext,
  never the decrypted plaintext

#### Scenario: Mixed rollout — unmigrated plaintext is returned unchanged
- **WHEN** an authorized caller reads an object whose flagged property still
  holds a plaintext value (written before the property was flagged, not yet
  migrated)
- **THEN** the response contains that plaintext value unchanged — it is not
  treated as a decryption failure

### Requirement: Decryption failure surfaces a clear error, never silent data loss

The system SHALL NOT silently substitute `null`, an empty value, or the raw
ciphertext when an envelope value fails to decrypt (missing or rotated key
material, corrupted ciphertext). The default read-path behaviour SHALL
substitute a structured, unambiguous error marker for that property and log
the failure at ERROR level, without failing the entire response. A caller
that opts into strict mode (the migration command; any future integrity-check
caller) SHALL instead receive a thrown, typed exception.

#### Scenario: A single corrupted field does not break the whole read
- **WHEN** an object with two flagged properties is read, and one property's
  envelope fails to decrypt while the other decrypts successfully
- **THEN** the response is still returned (HTTP 200), the successfully
  decrypted property has its plaintext value, and the failed property carries
  a structured error marker instead of its value

#### Scenario: The migration command fails loud on a decryption failure
- **WHEN** the `openregister:encrypt-field` command's per-row encryption
  fails for a row (e.g. an environment/key issue)
- **THEN** the command reports the failure per row and exits with a non-zero
  (failure) status — it does not report success while data remains
  unprotected

### Requirement: Encrypted fields are excluded from search and facets

A property flagged `x-openregister-encrypted: true` SHALL NOT be given a
dedicated, independently-queryable magic-table column, SHALL be excluded from
full-text search scanning, and SHALL be excluded from the facetable-fields
listing regardless of any `facetable` configuration on the same property. A
request that filters on an encrypted property SHALL be rejected with a clear,
typed error (HTTP 400) rather than silently executing against ciphertext and
returning zero results.

#### Scenario: Filtering on an encrypted property is rejected, not silently empty
- **WHEN** a caller issues a list/search request with a filter on property
  `bsn`, which is flagged `x-openregister-encrypted: true`
- **THEN** the response is HTTP 400 with a structured body identifying `bsn`
  as not filterable because it is encrypted — never HTTP 200 with an empty
  result set

#### Scenario: An encrypted property never appears as a facet option
- **WHEN** a caller requests facetable fields or facet counts for a schema
  where property `medicalRecord` is flagged `x-openregister-encrypted: true`
  (with or without `facetable: true` also set)
- **THEN** `medicalRecord` is absent from the facetable-fields listing

#### Scenario: Full-text search does not scan an encrypted property
- **WHEN** a caller issues a full-text search request against a schema with a
  flagged encrypted string property
- **THEN** the encrypted property is not included in the full-text `LIKE`
  scan conditions

### Requirement: Existing plaintext values can be migrated to encrypted

An idempotent CLI command SHALL encrypt existing plaintext values of a
property that has been newly flagged `x-openregister-encrypted: true`,
scoped optionally to a register and/or schema, with a dry-run mode that
reports counts without writing.

#### Scenario: Migrating existing plaintext data
- **WHEN** an operator runs `occ openregister:encrypt-field --property=bsn`
  after flagging `bsn` encrypted on a schema with existing plaintext `bsn`
  values
- **THEN** every existing plaintext `bsn` value across that schema's objects
  is replaced with an `openregister:enc:v1:` envelope, and objects that
  already held an envelope (or no `bsn` value) are left unchanged

#### Scenario: Dry run reports without writing
- **WHEN** an operator runs the migration command with `--dry-run`
- **THEN** the command reports the number of values that would be encrypted
  without modifying any stored data

#### Scenario: Re-running the migration is a no-op on already-migrated data
- **WHEN** the migration command is run a second time after a successful
  first run with no new plaintext data added
- **THEN** it reports zero newly-encrypted values — it does not re-encrypt
  already-enveloped values
