## Why

OpenRegister's object CRUD endpoints were just made `@PublicPage` (PR #185 to `beta`) so that OpenRegister's own RBAC is the sole authorization gate. That removed the Nextcloud app-enable / group gate (the HTTP 412) that previously sat in front of every request. With that outer gate gone, the RBAC engine's default-*allow* behaviour on schemas with a non-empty but partially-configured `authorization` block is now the only thing standing between a non-member and full CRUD access.

Today, when a schema's `authorization` block is non-empty but simply omits an action (e.g. it configures `read` but not `create`), all three enforcement paths treat the omitted action as **open to everyone — including `public`/unauthenticated**. This is a fail-*open* default that silently grants write access on read-only schemas. This change makes the engine fail-*closed* per action: an action that is not explicitly granted on a non-empty authorization block is denied.

## What Changes

- When a schema's (or cascaded register's) `authorization` block is **non-empty**, any action not explicitly granted is **denied** for everyone except the retained admin and object-owner bypasses — including the `public`/unauthenticated pseudo-group. **BREAKING**: schemas that configure some actions but omit others change from open to denied for the omitted actions.
- When `authorization` is **empty** (null or `[]`), classic **default-allow** is preserved (no behaviour change).
- The admin-group bypass and the object-owner bypass are **retained** and remain independent of "groups mentioned in the authorization block".
- The per-call `$_rbac=false` short-circuit (admin context / RBAC-not-enforced) and the global `rbac.enabled` switch in `ConfigurationSettingsHandler` (default `true`) are **unchanged** — this proposal does not touch the on/off switch, only the default verdict when RBAC *is* enforced on a non-empty block.
- The change is applied consistently across all three enforcement paths (single-object PHP, row-level PHP, SQL list) so that single-GET, row-level checks, and list/search endpoints agree.
- The list/search path enforces `action: 'read'` (not a separate `'list'`), so schemas that configure `read` keep public read **and** browse; only the write actions (`create`/`update`/`delete`) lock down. **BREAKING** is therefore scoped to write actions for read-configured schemas, and to read+write for any schema that omits `read`.

## Capabilities

### New Capabilities
- `rbac-default-deny`: The fail-closed default verdict for actions not explicitly granted on a non-empty `authorization` block, enforced uniformly across the single-object, row-level, and SQL-list RBAC paths, while preserving open-default on empty blocks and the admin/owner bypasses.

### Modified Capabilities
<!-- No existing spec requirement is being rewritten. The new `rbac-default-deny`
     capability tightens the default documented narratively in `rbac-scopes`
     ("if neither register nor schema has authorization, all users have full CRUD")
     and `rbac-zaaktype` ("deny access to unauthorized users"), but those
     requirements describe the *empty-authorization* and *explicit-deny* cases,
     which remain correct. The newly-specified case — non-empty-but-partial
     authorization — is not covered by any existing requirement, so it is added
     as a new capability rather than a delta. -->

## Impact

**Affected enforcement code (three paths, changed together):**
- `lib/Service/Object/PermissionHandler.php` — `hasGroupPermission()` (the single-object / schema-level PHP verdict).
- `lib/Db/MagicMapper/MagicRbacHandler.php` — `hasPermission()` (the row-level PHP verdict) and `applyRbacFilters()` (the SQL list/search filter path; its deny-all is already encoded as the existing `1 = 0` impossible predicate).

**Not changed:**
- `lib/Service/PropertyRbacHandler.php` — its "no property-level authorization ⇒ follow object-level rules" default is correct; the *absence* of a property-level block is not a deny.
- `lib/Service/ConfigurationSettingsHandler.php` — the global `rbac.enabled` switch is orthogonal and untouched.
- ADR-011 ConditionMatcher remains the single shared match evaluator; this change adds no new evaluator.

**Installed-config audit (drives the migration/seed review):**
- **20 read-only schemas** configure `read` only, so their `create`/`update`/`delete` become admin/owner-only while public read+browse is unaffected: `bag` (Nummeraanduiding, Verblijfsobject, Pand), `brp` (Ingeschreven Persoon), `kvk` (Maatschappelijke Activiteit, Vestiging), `ori` (Vergadering, Agendapunt, Raadsdocument, Stemming, Raadslid, Fractie), and the `opencatalogi` publication register (Publication, Catalog, Listing, Organization, Page, Theme, Menu, Glossary). For the external reference registers (bag/brp/kvk/ori) the write lockdown is the desired secure behaviour; for the opencatalogi publication schemas it is a behaviour change worth flagging (writes become curated/admin-only).
- **1 schema flips on the READ side** ⚠ — `docudesk` **Publication Prohibition** configures `create`/`update`/`delete` but omits `read`, so after this change read **and** list deny for non-admin/non-owner. It almost certainly needs an explicit `read` grant added to its config. That config lives in the `docudesk` repo (`docudesk/lib/Settings/docudesk_register.json`), outside OpenRegister's tree — tracked as a **cross-app follow-up**.
- **Unaffected**: `dso` and `n8n_workflows` have no authorization blocks, so classic open-default stays.

**Test impact:** PHPUnit RBAC tests and the Newman RBAC/IDOR suite currently assert the old open-default for partially-configured schemas and must be updated to assert the new deny.

**Target branch for the eventual PR:** `beta`.
