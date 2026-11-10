---
kind: code
issue: 1617
---

## Why

Services that write OpenRegister objects without an active HTTP/user session — cron jobs, background workers, internal service calls such as openconnector's `CallService::call()` — currently create rows with empty `_owner`. The REST list endpoint then hides those rows from every user because the RBAC filter requires `_owner` to match the requesting user (or be reachable via an authorization rule).

Confirmed in chain-E: `CallService` writes a `call_log` row, direct DB shows `_owner=''`, `GET /api/objects/openregister/api/objects/openconnector/call_log` returns `total: 0`. Direct UUID lookup still returns the row — the data exists, it's just invisible. This is the last gap before openconnector's dashboard can show real call-log counts.

## What Changes

- **System-context attribution on save.** When `SaveObject::prepareObjectForCreation()` cannot resolve a user (no `IUserSession` user), `_owner` is set to a configured system identifier (default `__system__`) instead of being left empty. The identifier is exposed via OR's `appConfig` under `openregister.systemUserId` so a deployment can override it.
- **System rows are visible to admins.** `MagicRbacHandler::applyRbacFilters()` and `buildRbacConditionsSql()` add one condition: rows with `_owner = systemUserId` are visible to users in the `admin` group OR in any group listed in `openregister.systemReaderGroups` (multi-string config, default empty). For non-admin, non-reader users behaviour is unchanged — they still need an authorization rule match.
- **Audit trail is honest.** `AuditTrailMapper` continues to record the actor as before (`null` user → empty actor on the audit row). The change only addresses the data-row owner; audit-row provenance is untouched so a forensic read can still distinguish "owned by `__system__`" from "modified by a logged-in user named `__system__`" (impossible, the identifier is `__` prefixed which Nextcloud rejects for real users).
- **Backwards compatible.** Pre-existing rows with `_owner=''` are NOT migrated. Operators who want them re-attributed run a one-off SQL or OCC command (documented in the change). New writes from this PR onward get the system owner automatically.

## Capabilities

### New Capabilities

None — this is a behavioural change to existing capabilities.

### Modified Capabilities

- `auth-system`: codifies the "no active user session → `_owner = systemUserId`" rule on object create, and the system-owner visibility carve-out in the RBAC filter. Adds two app-config keys (`systemUserId`, `systemReaderGroups`).

## Impact

- **Code.** `lib/Service/Object/SaveObject.php` (one branch in `prepareObjectForCreation`), `lib/Db/MagicMapper/MagicRbacHandler.php` (one extra OR-condition in two filter paths), one helper on `OrganisationService` or a new `SystemIdentityService` to centralize the config lookup.
- **API.** No new endpoints. Existing list endpoint now returns system-owned rows for admins where it previously returned zero.
- **Schema/DB.** No migration. New rows get `_owner='__system__'` by default instead of `''`.
- **Config.** Two new app-config keys:
  - `openregister.systemUserId` (string, default `__system__`)
  - `openregister.systemReaderGroups` (comma-separated list, default empty — admins always read system rows regardless)
- **Dependencies.** Consumers (openconnector `CallService`, decidesk schedulers, future cron writers) get correct behaviour automatically — no caller-side changes required.
- **Tests.** Adds unit coverage for both code paths (SaveObject system-attribution, MagicRbacHandler visibility carve-out) and a regression test that pins the reported chain-E scenario: write with no user session → list as admin returns the row.

## Security trade-offs

This is RBAC-adjacent, hence the `ready-for-security-review` label.

- **Risk: a malicious org member could create a user literally named `__system__` and impersonate system writes.** Nextcloud rejects user IDs containing `__` at registration; verified in OC's `Manager::validateUserId`. Even so, the visibility carve-out is gated on group membership (`admin` or a configured reader group), not on the requesting user's UID — a normal user can't read system rows just because their UID happens to match.
- **Risk: cross-organisation leakage via `_organisation=null`.** Existing `MagicOrganizationHandler::applyOrganizationFilter` already only shows null-`_organisation` rows to admin in non-SaaS mode (and never in SaaS mode). System-attributed rows go through `getOrganisationForNewEntity()` which falls back to the default org, so system rows DO have an `_organisation` and the existing tenant boundary holds. We add an explicit unit test for this path.
- **Risk: log-row visibility lets attackers in admin-of-org-A read logs from system writes that happened in org-B.** Same protection as above: each row has an `_organisation`, and the org filter is applied independently of the RBAC owner check.
- **Risk: the configurable system identifier is a footgun.** A deployment that sets `systemUserId` to a real existing user ID would grant that user read access to everything system-written. Mitigation: documentation warns; we keep the default to an identifier Nextcloud's own validator cannot collide with (`__system__`).
- **Deferred:** action-level authorization (ADR-023) and per-organisation system identities are out of scope. Tracked in follow-up issues if needed.
