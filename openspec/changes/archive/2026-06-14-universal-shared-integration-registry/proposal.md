# Universal Shared Integration Registry (global bootstrap)

## Problem

The pluggable integration registry (`pluggable-integration-registry`) installs
`window.OCA.OpenRegister.integrations` and renders integration tabs/widgets via
`CnObjectSidebar`, `CnDashboardPage`, and `CnDetailPage`. But the registry is
only installed + populated by **OpenRegister's own webpack bundles**
(`main`, `adminSettings`, `filesSidebar`, `mailSidebar`). On a page served by a
**consuming app** (e.g. an OpenCatalogi publication detail page), OpenRegister's
bundle never runs, so:

1. A leaf app (e.g. OpenConnector) that loads its Path-2 component bundle and
   calls `registerIntegration(...)` only ever populates a **stub** registry
   (`{_queue, register}`) — nothing drains it, because the drain happens inside
   `installIntegrationRegistry`, which only OpenRegister calls.
2. `useIntegrationRegistry()` in a foreign app's bundle reads its **own
   per-bundle module singleton**, never the window-global the leaf queued onto.

Net effect: the leaf's "Synced from" tab/widget never renders outside
OpenRegister's own SPA, even though the descriptor was queued. The whole point
of the leaf system — extend OpenRegister **without changing its tables or code**
and have leaves surface **inside any consuming app** — is unmet.

## Solution

Ship a tiny global bootstrap bundle and load it on **every** full-page render:

- **`src/integration-global.js`** — new webpack entry
  (`openregister-integration-global.js`) that imports and calls the existing
  `ensureIntegrationRegistry()`. Idempotent + tiny.
- **`src/integrations/bootstrap.js`** — `ensureIntegrationRegistry()` now
  resolves the **shared** registry via `getSharedRegistry(window)`
  (nc-vue `universal-shared-integration-registry`: converge-not-clobber +
  install-if-needed) and registers builtins + leaves into *that* instance, so
  every consuming app's `useIntegrationRegistry()` — which now defaults to the
  same shared window-global via `sharedRegistryIfInstalled()` — sees them.
- **`lib/Listener/IntegrationGlobalScriptListener.php`** — listens on
  `BeforeTemplateRenderedEvent` and calls
  `Util::addInitScript('openregister', 'openregister-integration-global')`
  unconditionally, so the registry is installed + populated on every page,
  not just OpenRegister's.

This requires **zero changes** to any consuming app: an OpenCatalogi
publication page now hosts a fully-populated shared registry, and any leaf
(OpenConnector's `sync-contract`) that queued a descriptor renders its tab/widget.

## Out of scope

- The nc-vue reconciliation primitives (`getSharedRegistry`,
  `sharedRegistryIfInstalled`, converge-not-clobber `installIntegrationRegistry`,
  composable shared-default) — landed in nc-vue beta (ncv#443).
- Externalizing Vue/nc-vue from leaf integration bundles (follow-up).
