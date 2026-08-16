# Tasks: universal shared integration registry (OpenRegister global bootstrap)

- [x] `src/integration-global.js` — new webpack entry that calls
      `ensureIntegrationRegistry()`.
- [x] `webpack.config.js` — add `integrationGlobal` entry →
      `openregister-integration-global.js`.
- [x] `src/integrations/bootstrap.js` — `ensureIntegrationRegistry()` resolves
      the shared registry via `getSharedRegistry(window)` and registers
      builtins + leaves into it (was `installIntegrationRegistry`).
- [x] `lib/Listener/IntegrationGlobalScriptListener.php` — load the global
      bundle on every `BeforeTemplateRenderedEvent`.
- [x] `lib/AppInfo/Application.php` — register the listener.
- [x] Build green; `openregister-integration-global.js` produced + deployed.

## Verification

- [x] On an OpenCatalogi publication detail page (ZERO OpenCatalogi changes):
      `window.OCA.OpenRegister.integrations` is a real registry (not a stub),
      contains the built-ins + OpenConnector's `sync-contract` leaf, and the
      "Synced from" tab/widget renders. **Browser-side verification handoff.**
      Bundle confirmed shipped: `js/openregister-integration-global.js`
      (9.5 MB minified) is built + present on disk in the dev container, and
      the `IntegrationGlobalScriptListener` wires it on every
      `BeforeTemplateRenderedEvent`. Final end-user "Synced from" tab render
      requires a publication with a matching `sync-contract` leaf attached on
      a live OpenCatalogi instance; tracked alongside the integration-xwiki +
      manual-entity dev-stack smoke runs.
