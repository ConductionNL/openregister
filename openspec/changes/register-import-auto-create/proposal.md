# register-import-auto-create

## Why

**#1487** — the register import workflow (`ImportService::importFromJson` and
the spreadsheet variants, which all accept an optional `?Register $register`)
requires the target register to already exist. When a user imports a register
bundle (a JSON envelope describing a register plus its schemas and objects) into
an instance that does not yet have that register, the import either silently
associates objects with no register or fails in a confusing, low-signal way
instead of either (a) creating the register from the bundle metadata or
(b) failing clearly with an actionable message.

This is a repeated onboarding papercut: moving a register between instances
(dev → test → prod, or sharing a bundle) should "just work" or tell the user
exactly what to do. Today it does neither reliably.

## What Changes

- **Auto-create from bundle:** when an import payload is a register bundle whose
  envelope carries the register definition (slug, title, description, schemas)
  and the target register does not exist, `importFromJson` MUST create the
  register (and its referenced schemas, reusing the existing register/schema
  import path) before importing objects into it.
- **Fail clearly otherwise:** when the payload references a register that does
  not exist AND the payload does not carry enough metadata to create it, the
  import MUST fail with a clear, actionable error (which register slug is
  missing, and that either the register must be created first or a full bundle
  supplied) — not a silent no-op or an opaque exception.
- **Idempotent:** re-importing a bundle whose register already exists MUST NOT
  duplicate the register; it upserts schemas/objects as today.

**BREAKING:** none. Existing imports into an existing register are unchanged;
this adds a create path and replaces silent/opaque failure with a clear error.

## Capabilities

### Modified Capabilities

- `data-import-export`: the JSON/bundle import contract gains requirements to
  auto-create a missing register from bundle metadata, to fail with an
  actionable error when auto-create is not possible, and to remain idempotent on
  re-import.

## Impact

**Affected code:** `lib/Service/ImportService.php` (`importFromJson`, and the
`?Register` handling shared by the Excel/CSV paths), reusing the existing
register/schema import/creation path (`ConfigurationService` /
register-import-via-repair machinery) rather than duplicating creation logic.

**Tests:** import a bundle for a non-existent register → asserts the register +
schemas are created and objects land in it; import referencing a missing
register with no creatable metadata → asserts a clear error naming the slug;
re-import an existing-register bundle → asserts no duplicate register.
Runnable in the `nextcloud:34` container.

**Dependencies:** none; no migration. Reuses existing register/schema creation.
