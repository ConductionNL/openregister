# Enforce five engine primitives that leaf apps already assume

## Why

Five engine-primitive gaps where leaf-app code relies on a guarantee OpenRegister
does not actually make. Each was verified against HEAD (`58e7d4b54`) before this
change was written; two of the five turned out to be materially different from
the original audit and are scoped down accordingly (see design.md).

These fixes were authored in May 2026 and merged to the GitHub fork, but never
reached this repository — the fork point is `f92b128a0` and `development` has
moved 1,507 commits since. This is not a cherry-pick: three of the five conflict
with architecture that landed in the meantime, and one sub-fix is **actively
broken** against current code. Every fix below was re-derived against HEAD.

1. **`readOnly` is pure metadata (integrity).** A JSON-Schema property declared
   `readOnly: true` is enforced by nothing on the write path. Opis has no
   `readOnly` keyword parser (`vendor/opis/json-schema` ships no such parser), so
   it is silently discarded during validation, and `SchemaMapper.php:2769` lists
   `readOnly` among the fields that "can be freely overridden". A schema author
   declaring an immutable field gets no immutability.

2. **Schemas with no authorization block are default-OPEN for writes
   (CWE-862).** `PermissionHandler.php:1069` — `if (empty($authorization)) return
   true;` — grants create/update/delete on any such schema to **any**
   authenticated user. #1955 closed this for *anonymous* callers only and
   explicitly recorded the authenticated case as "a separate, broader policy
   decision" (`PermissionHandler.php:450-452`). This change is that decision,
   made opt-in.

3. **The bulk write path skips invariants the single-object path enforces.**
   Bulk save is a separate pipeline that does not delegate to `SaveObject`.
   Two gaps remain after the independent hardening that landed in `e21e855ac`:
   - **append-only is unenforced**: `isAppendOnly()` appears only on the
     single-object path. A bulk POST carrying an existing uuid silently rewrites
     a row that `ObjectService::saveObject` would reject — a tamper-evidence
     defect on exactly the schemas chosen for their immutability.
   - **every row is authorized as `create`** (`SaveObjects.php:1137`, hardcoded).
     Bulk save is an upsert, so a caller with create-but-not-update rights can
     rewrite existing rows through the bulk path.

4. **Dotted condition tokens silently never match.** `$user.uid` is not a token:
   it falls through `resolveDynamicValue()` as the literal string `'$user.uid'`
   and is compared against the object's stored value. The rule silently never
   matches, with no diagnostic — and an object that *stores* that literal
   satisfies the condition, making a client-controllable value decide an
   authorization outcome.

5. **Per-object `_authorization` is dead storage.** The column is hydrated from
   the database on every read (`MagicSearchHandler.php:2054-2064`) and serialized
   out to API clients (`ObjectEntity.php:955`), and is consulted by **nothing**.
   Every reader was enumerated: there are two, both serialization. This is the
   fleet's orphaned-capability defect class — a column that looks like a control
   and is not one.

## What Changes

- **`readOnly` is enforced on UPDATE.** `ValidateObject::validateReadOnlyConstraints()`
  compares incoming values against stored values; `ObjectService::enforceReadOnlyOnUpdate()`
  invokes it and rejects violations. CREATE is deliberately not covered.
- **`rbac.enforce_default_closed`** (default **false**) lets an instance deny
  writes on schemas with no authorization block. With the flag off, the engine
  emits a one-time deprecation warning per schema per action so operators can
  find affected schemas before any future default flip. Reads are unaffected.
- **Bulk save derives each row's real action** against the database and enforces
  **append-only** on rows that target an existing object.
- **Dotted tokens resolve on BOTH evaluators** — `$user.uid`, `$user.email`,
  `$user.displayName`, `$organisation.uuid`. An unknown token resolves to null,
  which both evaluators treat as a deny, and is logged.
- **Per-object `_authorization` is consumed** on the live permission path, for
  write actions only.

## Impact

- Affected specs: `authorization-rbac`, `objects-crud`
- Affected code: `lib/Service/Object/PermissionHandler.php`,
  `lib/Service/Object/ValidateObject.php`, `lib/Service/ObjectService.php`,
  `lib/Service/Object/SaveObjects.php`, `lib/Service/ConditionMatcher.php`,
  `lib/Db/MagicMapper/MagicRbacHandler.php`

**Behaviour changes, and their blast radius:**

- Default-closed is **opt-in and off**. No instance changes behaviour on upgrade.
- `readOnly` enforcement is **on**, and is the one fix with real fleet reach. No
  register in this repo declares property-level `readOnly` today, so the live
  fleet is unaffected — but 95 declarations ship in the importable
  `docs/static/oas/Examples/ZaakRegister/*.json` exports. Several sit on relation
  arrays the engine itself writes back. Nothing imports those examples today; see
  design.md before that changes.
- Bulk append-only rejection is a **new denial** on append-only schemas. That is
  the point: those writes were never supposed to succeed.
- Bulk `update`-action derivation is a **new denial** for callers holding
  `create` but not `update`. Also the point.
- Dotted tokens add resolution where there was none; bare-token semantics are
  untouched.
- Per-object `_authorization` defaults to empty, so every existing row is
  unaffected.

**Deliberately out of scope** (see design.md for why): `$user.groups`, per-object
`read` overrides, and the `"public": true` opt-in shape from the original patch.
