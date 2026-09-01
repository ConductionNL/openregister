---
kind: code
---

## Why

A Nextcloud group named in an OpenRegister `authorization` block is assumed to
exist. Nothing creates it.

`PermissionHandler::hasGroupPermission()` (`lib/Service/Object/PermissionHandler.php:1207`)
resolves access by MEMBERSHIP TEST alone. So a group that was never created and a
group nobody belongs to are **indistinguishable**: both deny every caller,
silently, with no error raised or logged anywhere. A typo in an authorization
block — or a group an administrator deleted, or a configuration exported from one
instance and imported into another that never had that group — reads exactly like
a working access control.

The fleet already depends on this. `hydra/openspec/specs/rbac-fleet-wide-consumption/spec.md:30`
makes Nextcloud group IDs *the* canonical role identifier fleet-wide and states
that `IGroupManager::groupExists(groupId)` SHALL return true for every group
listed in an `authorization` block. Nothing enforces that, and nothing creates
them. Apps ship group names as free strings inside
`{app}/lib/Settings/*_register.json`.

What exists instead is duplication: `TenantLifecycleService.php:207` hardcodes
`<org-slug>-admin`/`<org-slug>-users`, `FolderManagementHandler.php:542` and
`FileOwnershipHandler.php:108` each create one `APP_GROUP` constant, and nine
fleet apps call `createGroup()` in their own services. `decidesk` had already
noticed the gap and worked around it with an `x-openregister.x-decidesk-rbac-scopes`
block that is prose only — nothing machine-readable, nothing acted upon.

**The declaration slot already existed and was write-only.** `OasService.php:304`
emits `components.securitySchemes.oauth2.flows.authorizationCode.scopes` — a
`{groupId: description}` map derived from every schema's authorization block
(including property-level rules and the register cascade). Grepping the entire
app, that key appears **exactly once: the write**. Nothing reads it, and
`ExportHandler` never emitted it into the configuration document at all.

## What Changes

- **Declared groups are collected** as the union of a DERIVED floor (every group
  named in a register, schema or property `authorization` block) and an AUTHORED
  superset (the OAS scope map), minus reserved principals.
- **Group ids stay free-form and UNPREFIXED** (explicit product decision). Two
  apps declaring `behandelaars` converge on one Nextcloud group **by design**, so
  a declaring app must not assume it owns a group it declared.
- **`admin` and `public` are never provisioned.** `admin` is Nextcloud's built-in
  group and is short-circuited before any group test; `public` is a
  pseudo-principal for anonymous access, not a membership-bearing group.
  Creating a literal group named `public` would imply a grant RBAC never consults.
- **Provisioning is create-only and idempotent.** It never deletes a group and
  never adds a member. Deleting destroys memberships and shares irreversibly;
  seeding members would silently grant access nobody approved.
- **Import-time provisioning runs BEFORE the content-hash skip** in
  `ImportHandler::importFromJson()`. The skip means "the stored data already
  matches", which says nothing about whether the groups those blocks name exist.
- **A reconciler `TimedJob` covers what import cannot**, reading LIVE registers
  and schemas rather than shipped files.
- **`ExportHandler` emits the scope map**, so a configuration document is
  self-describing at parity with the generated API spec.
- **The declared set is persisted to `IAppConfig`** as `declared_groups_<appId>`,
  mirroring the adjacent `imported_config_<appId>_hash` key — no entity field and
  no database migration.

## Why a reconciler is required, not optional

Import-time provisioning alone inherits each leaf app's `<repair-steps>` wiring,
and that wiring is frequently wrong.

Nextcloud runs `migrateSchemaOnly()` on a FIRST install
(`lib/private/Installer.php:536-570`): `$previousVersion === ''`, so BOTH
`<pre-migration>` and `<post-migration>` are skipped, and `<install>` is the only
unconditional hook. The upgrade path (`AppManager::upgradeApp():1064-1069`) runs
pre/post-migration and NOT `install`. An app therefore needs both hooks carrying
the same step list, with idempotent steps.

Measured across the 16 core apps that ship an `info.xml` (2026-08-12): **six
declare no `<install>` block at all** — opencatalogi, openconnector,
softwarecatalog, procest (18 steps, 12 seed-shaped), pipelinq, shillinq. Their
`InitializeSettings` step — the one that imports the app's register — therefore
does nothing on a fresh instance, which is precisely when no declared group
exists. `pipelinq/lib/Repair/InitializeSettings.php:19` even cites
`openregister-integration/spec.md#requirement-auto-configuration-on-install-repair-step`;
the spec says "on install", the wiring never fires on install.

Reading live entities rather than shipped files additionally covers virtual
OpenBuild apps (no `register.json` on disk) and restores a hand-deleted group.

## Non-goals

- **Membership.** A provisioned group is EMPTY and therefore still denies
  everyone. That is the intended contract; who belongs stays an explicit
  administrator decision. The empty state must be VISIBLE rather than silent,
  which is what the inventory requirement covers.
- **Templated per-object scopes.** decidesk's `decidesk:body:{bodyId}:chair` is
  computed per object by `GovernanceRoleScopeProjector` and cannot be statically
  declared; it stays projector-owned.
- **Fixing the six apps' repair hooks.** Real and filed separately — this change
  makes OpenRegister's own behaviour independent of them rather than repairing
  each app.
- **The admin UI.** `GroupProvisioner::inventory()` supplies the data; the
  controller and view are a follow-up.

## Scope honesty

The exported scope map is DERIVED from the registers and schemas the document
already carries, so it hands the importer nothing it could not have derived
itself. Its value is an explicit, self-describing declaration at the OAS-native
location — not the recovery of otherwise-lost data. Authored-only declarations (a
group named before any authorization block references it) live in app config and
are restored by the reconciler, not by the export path.
