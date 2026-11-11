---
kind: code
depends_on: [live-audit-round-one]
---

# Proposal: adopt-setup-dismiss-action

## Summary

OpenRegister lets nextcloud-vue 2.71.0 tell the server that the setup wizard
was closed, through the manifest key `setup.dismissAction`, and drops its own
workaround that watched an internal flag of CnAppRoot.

## Why

#4445 made a closed setup wizard stay closed in every browser. The library
had no hook for a close then, so `src/services/wizardDismissal.js` watched
CnAppRoot's internal `setupWizardDismissed` flag and posted `dismiss-setup`
itself. That couples the app to a private field of the library. nextcloud-vue
2.71.0 adds `setup.dismissAction` (manifest schema 2.53.0): CnAppRoot posts
`POST /apps/{appId}/api/setup/action/{dismissAction}` with `{ finished }` once
when the wizard is closed or finished. Ruben decided every app uses the
server-side close.

## What changes

- `@conduction/nextcloud-vue` goes to `^2.71.0`; the vendored manifest schema
  in `tests/schemas/` is copied from the package again.
- `src/manifest.json` names `"dismissAction": "dismiss-setup"` under `setup`.
- `src/services/wizardDismissal.js`, its spec and its wiring in `App.vue`
  (the `appRoot` ref and the `mounted()` call) are removed.
- The server action stays as #4445 shipped it: it answers the open example
  data step and never overwrites a pick or a load (option (a) of the library
  contract). `GET /api/setup/status` does not change.

## Out of scope

- A `dismissed` field in the setup status. The step answer already closes the
  wizard everywhere.
