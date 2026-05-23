# ADR-023: Action-level authorization via admin-configured action/group mappings

**Status:** accepted
**Date:** 2026-04-23

## Context

Conduction apps mix **data authorization** (who can read/write which OpenRegister objects) and **action authorization** (who can invoke which controller methods / workflow steps). The two are related but not the same:

- A chair of "Board A" can read all Board A minutes (data RBAC → OpenRegister) AND can invoke `generateMinutesDraft()` on them (action RBAC → app).
- A regular member of Board A can read the same minutes (data RBAC → OpenRegister) but CANNOT invoke `generateMinutesDraft()` (action RBAC denies).
- A Nextcloud admin can invoke `create()` on `SettingsController` (action RBAC → admin-only) regardless of any board membership.

OpenRegister already owns the **data** layer: object-level ownership, schema/register permissions, per-relation filtering (ADR-022 lists RBAC as one of the shared abstractions it provides). Apps consume this cleanly.

Apps DO NOT have a shared pattern for the **action** layer. Observed across decidesk / docudesk / pipelinq, the action-auth implementations range from:

- `IGroupManager::isAdmin()` hardcoded checks in controller bodies (wrong — locks governance actions to Nextcloud sysadmins, not to chairs/secretaries — see #44 / #45 on 2026-04-23)
- Missing entirely (the endpoint gates on data RBAC alone — wrong for actions that cross objects, like "generate report across all boards I chair")
- Inline `!in_array('chair', $roles)` checks that are (a) not discoverable by admins, (b) require a code change to adjust, (c) duplicated across controllers

The consistent answer needs to: live in app code (each app has its own actions), be **declarative** (admin can see and change the matrix without touching code), and be **testable** (gate-7 / gate-9 can mechanically verify each routed action either delegates to this service or is explicitly marked admin-only).

## Decision

### Rule 1 — Data RBAC is OpenRegister's job; apps never roll their own

OpenRegister decides for itself who may read / write / list which objects. App code that fetches, lists, or mutates domain objects MUST go through OpenRegister's `ObjectService` and trust the service's filtering + per-object permissions. Apps do not implement:

- Object-ownership checks (OpenRegister does it via `createdBy` / `owner` / schema settings)
- Register/schema-level access gates (OpenRegister does it via register permissions)
- Group-based read/write filtering on data (OpenRegister does it via `relations.group` / schema RBAC)
- Schema / register configuration (that's OpenRegister's own admin UI, not the consuming app's)

If the data-layer RBAC has a gap, **fix it in OpenRegister** (ADR-012 — push logic up to the shared foundation, don't re-implement per app).

### Rule 2 — Action RBAC is the app's job, declared in admin settings

Every app defines a registry of **actions** — named operations that a controller method executes. Examples (decidesk):

- `minutes.generate-draft` — produces a draft from a meeting transcript
- `minutes.distribute` — sends final minutes to the governance body
- `decision.publish` — marks a decision as published, triggers notifications
- `analytics.view-summary` — reads aggregate metrics across bodies
- `settings.write` — admin-only settings writes

Each action is mapped to a set of **user groups** via an admin-configured matrix, stored in `IAppConfig` under a well-known key. Every app maintains its own seed data for the initial mapping; the template ships a skeleton file per app that declares the action list with `["admin"]` as the default for every action. This default is **the safest first-install posture** — nothing is accidentally opened to non-admins until an admin explicitly broadens it. The admin settings panel is the only place to edit the matrix.

```json
// stored as IAppConfig["decidesk"]["actions"]
//
// First-install values (seed from the app, admin-only everywhere).
// The admin editing the matrix is the only path to broaden — code
// changes must not relax the default.
{
  "minutes.generate-draft":   ["admin"],
  "minutes.distribute":       ["admin"],
  "decision.publish":         ["admin"],
  "analytics.view-summary":   ["admin"],
  "settings.write":           ["admin"]
}
```

After admin customization (example — illustrative, not default):

```json
{
  "minutes.generate-draft":   ["chairs", "secretaries"],
  "minutes.distribute":       ["chairs", "secretaries"],
  "decision.publish":         ["chairs"],
  "analytics.view-summary":   ["chairs", "secretaries", "board-members"],
  "settings.write":           ["admin"]
}
```

**Naming convention**: `<domain>.<verb-phrase>` with dot as separator, lowercase, hyphens-in-phrases. `minutes.generate-draft`, `decision.publish`, `analytics.view-summary`. NOT `decidesk:minutes:generateDraft`. This keeps the keys grep-friendly, stable across refactors, and matches how schema keys look in OpenRegister.

The **admin settings panel** (registered via `\OCP\Settings\ISection`, route carries `#[AuthorizedAdminSetting(Application::APP_ID)]`) renders this matrix: rows = actions, columns = user groups, checkboxes = allowed. Admin edits + saves → `IAppConfig` updated. NO code change required to adjust who can do what.

Controllers enforce the mapping with a single helper call:

```php
#[NoAdminRequired]
public function generateDraft(string $minutesId): JSONResponse {
    $user = $this->userSession->getUser();
    if ($user === null) {
        return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
    }

    $this->actionAuth->requireAction($user, 'minutes.generate-draft');
    // Throws OCSForbiddenException if none of $user's groups are mapped
    // to 'minutes.generate-draft' in the admin matrix.

    // ... data-layer work via ObjectService (OpenRegister enforces its own
    //     per-object permissions on top of this action check).
}
```

### Rule 3 — When admin IS required (not delegated to action RBAC)

The following stay `#[AuthorizedAdminSetting(Application::APP_ID)]` and live **only on the admin settings page** — they are NOT expressible as action mappings because they are the plumbing the action matrix itself depends on:

- **Configuring the action ↔ group matrix** (the admin settings panel itself)
- **App configuration** — any `IAppConfig` writes (feature flags, feature toggles, workflow parameters, anything that affects app-wide behavior)
- **Backup / restore operations** — data export, re-import, cross-environment migration
- **App integration configuration** — connections to external systems (n8n, SOLR, external APIs), webhook URLs, integration feature flags
- **Credential management** — API keys, OAuth tokens, basic-auth credentials for any third-party service
- **One-off admin operations** — re-import seed data, purge caches, run migrations, trigger re-indexing

Everything a non-admin (chair / secretary / board-member / agent / regular user) might legitimately invoke during normal operation = an **action**, gated via `requireAction()`. Admin settings page handles the plumbing; user settings page / per-user UI never touches the plumbing. The user settings page is for user-personal preferences only (UI theme, notification opt-ins) — not for anything the action matrix references.

Rule of thumb: if the operation mutates something the action matrix references (keys the matrix looks up, values the matrix resolves to, integrations the actions depend on) → admin. Everything else → action.

### Rule 4 — Middleware attribute + body check layered

Per ADR-005 and ADR-016:

- `#[PublicPage]` — genuinely public (login pages, OAuth callbacks). Body does NO auth check.
- `#[NoAdminRequired]` — any authenticated user may reach the endpoint. Body **MUST** call `$this->actionAuth->requireAction($user, 'action.name')` for action-level gating. Absence of this call is a gate-9 failure — see enforcement below.
- `#[AuthorizedAdminSetting(Application::APP_ID)]` — framework-level admin gate for the exceptions in Rule 3. Body does no further admin check (the middleware already enforced it).

### Rule 5 — Gate-9 enforces the action-auth pattern mechanically

`hydra-gate-9` (semantic-auth) is extended to check:

| Pattern | Verdict |
|---|---|
| `#[NoAdminRequired]` + body calls `$this->actionAuth->requireAction(...)` | PASS |
| `#[NoAdminRequired]` + body calls `$this->authorize*(...)` (per-object auth helper per ADR-005 Rule 3) | PASS |
| `#[NoAdminRequired]` + body calls `$this->requireAdmin()` / `isAdmin()===false`→403 | FAIL — the wrong layer; use `#[AuthorizedAdminSetting]` for admin-only or `requireAction()` for role-based |
| `#[NoAdminRequired]` + no recognized auth gate in body | FAIL — inadequately gated, open endpoint |
| `#[PublicPage]` + any body auth check | FAIL — public is public, no body checks |
| `#[AuthorizedAdminSetting]` + `requireAction()` in body | PASS but redundant (middleware already gated to admin) — not a fail, but the lint could suggest removal |

Enforcement rolls out in two phases to give apps time to migrate without breaking their pipelines:

1. **Soft-fail phase** (announce in ADR): gate emits warnings, doesn't fail the gate. Apps that haven't migrated yet stay green.
2. **Hard-fail phase** (date-stamped): gate treats missing `requireAction()` as FAIL. Decided when majority of apps have adopted the pattern.

## Consequences

### Positive
- Governance actions (minutes drafting, decision publishing, quorum checks) can be delegated to chairs / secretaries / board members — NOT Nextcloud sysadmins. Current decidesk bug class (#44 + #45) goes away structurally.
- Admins can re-map actions to groups without a code change — useful when an org shifts responsibilities mid-deployment.
- One helper (`$this->actionAuth->requireAction()`) per gated method — consistent, grep-able, testable.
- Gate-7 / gate-9 enforcement has a clear target to check for (`requireAction()` call in body).
- Template repo ships this out of the box — new apps inherit the pattern instead of each rolling their own.

### Negative
- Initial setup burden: admin must populate the action matrix on first install. Mitigated with sensible defaults in `create-labels`-style seed data per app.
- Two layers of auth per request (action matrix check + OpenRegister per-object check) = two service calls per gated endpoint. Negligible cost (both are app-local memory or indexed DB).
- Admin who mis-configures the matrix can lock chairs out of essential actions. Mitigated with a "reset to defaults" button + `occ decidesk:actions:reset`.

### Neutral
- Replaces "lock everything to admin" over-restriction with "configurable by admin" flexibility. For ops that currently have only Nextcloud admins, the first-install default can be "admin-only" per action — the matrix is editable but the safe default survives if nobody touches it.

## Implementation plan

1. **This ADR** — accepted.
2. **Reference implementation in decidesk**:
   - New `OCA\Decidesk\Service\ActionAuthService` with `requireAction(IUser $user, string $action): void` — throws `OCSForbiddenException` when $user's groups don't intersect the matrix entry for $action
   - New `OCA\Decidesk\Settings\ActionMatrixAdmin` settings section (`\OCP\Settings\ISettings` + template) showing the action×group matrix, admin-only
   - `IAppConfig` key `decidesk.actions` storing the JSON mapping
   - Refactor the 13 + 2 controller methods caught by gate-9 on #44 / #45 to use `requireAction()`
   - **Seed data per app** — each app ships its own `actions.seed.json` (or equivalent) declaring the action list with `["admin"]` as default. App migration runs it on first install.
3. **Port to `nextcloud-app-template`**: copy `ActionAuthService` + skeleton settings panel + seed-data pattern. Parametrized so new apps just declare their action names. Default values all `["admin"]`.
4. **Gate-9 extension (soft-fail phase first)**:
   - Detect `#[NoAdminRequired]` + body-has-`requireAction()`-call → PASS
   - Detect `#[NoAdminRequired]` + body-has-`authorize*()`-call (per-object auth per ADR-005) → PASS
   - Detect `#[NoAdminRequired]` + no recognized gate → emit warning (soft-fail)
   - Detect `#[NoAdminRequired]` + `requireAdmin()` / `isAdmin()===false` → FAIL (hard — the wrong layer)
   - Warnings hit the verdict JSON but do not set the gate to FAIL during migration.
5. **Migrate existing apps** (hydra, decidesk first, then docudesk / pipelinq / procest / …) to the new pattern.
6. **Gate-9 hard-fail phase**: after apps are migrated, flip warnings → fails. Date-stamp to set on the PR that ships the hard-fail variant.
7. **Unblock #44 + #45**: once decidesk has `ActionAuthService`, their 13+2 methods plug into `requireAction('minutes.generate-draft')` etc. The current parked state resolves as a retry cycle.

## References

- ADR-005 (security) — per-object authorization rule + admin checks
- ADR-016 (routes) — auth attribute rules + gate layering
- ADR-021 (bounded-fix scope) — mentions `checkUserRole($uid, ['chair','secretary'])` as the correct shape (now formalized via `requireAction`)
- ADR-022 (apps consume OR abstractions) — lists RBAC as one of OpenRegister's shared abstractions; this ADR clarifies that the scope is **data** RBAC, not **action** RBAC
- decidesk#44 / #45 — both pending role-based fix that this ADR unblocks
