---
name: hydra-gate-admin-router
description: Detect admin settings Vue components registered in the vue-router. Admin settings are rendered by Nextcloud's settings framework via `AdminSettings.php`; adding their Vue components to the in-app router exposes them as publicly-accessible frontend routes, bypassing all server-side access checks. ADR-004 hard rule. Observed 2026-04-30 on doriath where `/settings → AdminRoot` was a route in `src/router/index.js` (commit c7c72e9).
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, frontend, security, router]
---

## Purpose

Admin settings Vue components (e.g. `AdminRoot.vue`) are rendered by Nextcloud's settings framework via `AdminSettings.php` — they are NOT app routes. Adding them as routes in `src/router/index.js` makes them reachable as a regular frontend URL like `/index.php/apps/{appid}/settings` to ANY authenticated user, completely bypassing the admin check that Nextcloud's settings framework enforces.

ADR-004 is explicit: *"Do NOT create a `/settings` route. Do NOT create a standalone `SettingsView.vue` page component."* User settings use `NcAppSettingsDialog` opened as a modal from the gear menu.

Observed on doriath (2026-04-30): `src/router/index.js` had `{ path: '/settings', name: 'Settings', component: AdminRoot }` and was importing `AdminRoot from '../views/settings/AdminRoot.vue'`. Fixed in commit c7c72e9 by removing both the route and the import.

## Check

```bash
for f in src/router/index.js src/router/index.ts src/router.js src/router.ts; do
    [ -f "$f" ] || continue
    # Imports of admin-prefixed components or anything from views/settings/
    grep -nE "from\s+['\"][^'\"]*(/Admin[A-Z][A-Za-z]*\.vue|views/settings/)" "$f" 2>/dev/null
    # Routes whose path is /settings or /admin
    grep -nE "path\s*:\s*['\"]/(settings|admin)\b" "$f" 2>/dev/null
done
```

## Fix action

For each FAIL line:

1. **Remove the import** of the admin settings component from the router config
2. **Remove the route entry** that references it (or that uses path `/settings` / `/admin`)
3. Confirm the admin component is registered correctly via PHP `AdminSettings.php` — its `getForm()` returns a `TemplateResponse` mounted in `templates/settings/admin.php`
4. Confirm in-app user settings open as a modal using `NcAppSettingsDialog` (driven by an `@open-settings` event on the main menu), not as a route
5. Re-run the check

The admin component should be entered ONLY via `/index.php/settings/admin/{appid}` (Nextcloud's settings page), NOT via the app's frontend router.

## Related orchestrator gate

`scripts/run-hydra-gates.sh` stage `admin-router` runs the same check. ADR-004 documents this as a hard frontend rule.

## Verification

Sample gate output is in [examples/](examples/):
- [examples/pass.log](examples/pass.log) — what stdout looks like when this gate is green on the diff
- [examples/fail.log](examples/fail.log) — what stdout looks like when this gate finds a violation; per-finding detail is in `/tmp/hydra-gate-admin-router.log` so the builder/reviewer can read line-by-line and apply Fix actions deterministically. Confirm the gate is green by running `./scripts/run-hydra-gates.sh --scope-to-diff` from the app dir before pushing.
