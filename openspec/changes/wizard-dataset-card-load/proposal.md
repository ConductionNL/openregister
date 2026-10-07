---
kind: code
---

# Proposal: wizard-dataset-card-load

## Summary

The example data step in the OpenRegister setup wizard becomes one cards step whose cards load themselves. The separate "load the example data" step goes away.

## Why

The wizard asked for example data in two steps: a choice step that stored the pick, then a run-action step that loaded it. Two steps for one decision is one step too many, and the operator could not see which card was loading. `@conduction/nextcloud-vue` 2.65.0 gives a cards choice step a `loadAction`: every card except "None" gets its own Load button that posts `{ dataset }` to the action and spins while it runs. The fleet adopts that pattern from pipelinq (pipelinq#2177).

## What Changes

- The `demo-data` cards step declares `loadAction: load-demo-data`. The run-action step that loaded the pick is removed from the manifest.
- `POST /api/setup/action/load-demo-data` accepts `{ dataset }` in the body. It refuses a dataset no card offers, loads the named set, and records the pick only after the load succeeds. A call without a body still loads the stored pick, so older wizards and scripts keep working.
- `GET /api/setup/status` reports exactly the step ids of `manifest.setup.steps`. A step the server never reports stays open, and an open step reopens the wizard on every page.
- `@conduction/nextcloud-vue` is raised to `^2.65.0`, the first release with `loadAction`.

## Impact

- Affected code: `src/manifest.json`, the setup controller, its unit tests and the demo-data e2e spec.
- No data migration. The `demo_dataset` app-config key keeps its meaning.
