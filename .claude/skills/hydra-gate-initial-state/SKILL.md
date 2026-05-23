---
name: hydra-gate-initial-state
description: Detect DOM data-attribute reads in `.vue`/`.js`/`.ts` files (e.g. `document.getElementById('x').dataset.version`) used to pull server-side data into the frontend. The Nextcloud-idiomatic pattern is `IInitialState::provideInitialState()` in PHP + `loadState()` from `@nextcloud/initial-state` in Vue. DOM data-attributes break on CSP-hardened instances and bypass the canonical pattern documented in ADR-004. Observed 2026-04-30 on doriath where `AdminRoot.vue` read `document.getElementById('doriath-settings').dataset.version`.
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, frontend, initial-state]
---

## Hard Rule

Server-side PHP data (app version, config flags, feature toggles) MUST reach the frontend via the Nextcloud `IInitialState` service. NEVER use DOM `data-*` attributes for server-to-client data: do not read `getElementById(...).dataset.*` in `.vue`/`.js`/`.ts` files.

## Purpose

The DOM-attribute pattern works under default CSP but breaks on strict CSP instances and is not the canonical pattern documented in ADR-004.

Observed on doriath (2026-04-30): `AdminRoot.vue` had `appVersion: document.getElementById('doriath-settings')?.dataset?.version || 'Unknown'` and the template emitted `<div id="doriath-settings" data-version="...">`. Fixed in commit d90a620 by switching to `loadState('doriath', 'version', 'Unknown')` + `IInitialState::provideInitialState('version', $version)` in PHP.

## Check

```bash
# Any read of `.dataset.<key>` from a getElementById'd element inside src/
grep -rnE "getElementById\s*\([^)]+\)[^.]*\.dataset\b" src/ \
    --include='*.vue' --include='*.js' --include='*.ts' 2>/dev/null
```

## Fix action

For each FAIL line:

1. **PHP side** — inject `IInitialState` and call `provideInitialState`:
   ```php
   use OCP\AppFramework\Services\IInitialState;
   public function __construct(private IInitialState $initialState) {}
   $this->initialState->provideInitialState('version', $version);
   ```
2. **Vue side** — replace the DOM read with `loadState()`:
   ```javascript
   import { loadState } from '@nextcloud/initial-state'
   // ...
   appVersion: loadState('appid', 'version', 'Unknown'),
   ```
3. **Template side** — drop the `data-*` attribute from `templates/settings/admin.php`:
   ```php
   <div id="appid-settings"></div>
   ```
4. Re-run the check.

## Related orchestrator gate

`scripts/run-hydra-gates.sh` stage `initial-state` runs the same check. ADR-004 documents this as a hard frontend rule.

## Verification

Sample gate output is in [examples/](examples/):
- [examples/pass.log](examples/pass.log) — what stdout looks like when this gate is green on the diff
- [examples/fail.log](examples/fail.log) — what stdout looks like when this gate finds a violation; per-finding detail is in `/tmp/hydra-gate-initial-state.log` so the builder/reviewer can read line-by-line and apply Fix actions deterministically. Confirm the gate is green by running `./scripts/run-hydra-gates.sh --scope-to-diff` from the app dir before pushing.
