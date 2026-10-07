---
kind: code
---

# Proposal: flow-and-run-detail-pages

## Summary

Give a flow a page that says what it does, and give a run a page of its
own. Today a click on a flow in `/flows` opens the editor straight away,
and a run only exists as a view inside the editor's sidebar. This change
adds a flow detail page at `/flows/{id}/overview` and turns the run
address `/flow-runs/{uuid}` from a redirect into a real page. Both are
built from endpoints that already exist; no backend changes. Ruben
approved the design on 7 October 2026 ("build as designed").

## Why

**A flow has no overview.** The index row opens the canvas, with its
read-only banner and "Create draft version". Someone who wants to know
"did this flow run, and did it work" has to read the canvas and open the
sidebar. Trigger, versions, run health and the run history are all served
by the API (`GET /api/flows/{id}`, `/versions`, `GET /api/flow-runs`),
but no screen shows them together.

**A run has an address but no page.** `/flow-runs/{uuid}` reads the run
and replaces itself with the editor (`src/views/flows/FlowRunDetail.vue`).
The editor does not mark the failed step, and the failure itself sits in a
narrow sidebar. The run record already carries everything a full page
needs: status, error, the per-step log with input, output, error and
duration, the subject, `runAs`, `correlationKey`. Its objects
(`/flow-runs/{uuid}/objects`) and tasks (`/api/flow-tasks?runUuid=`) have
their own reads.

## What changes

- New route `/flows/{id}/overview` (`flow-overview`), registered in
  `src/main.js` beside the run and task deep links. The `/flows` index
  rows open it (`rowRoute: flow-overview`). The editor stays at
  `/flows/{id}`, so `/flows/new`, the save redirect and every
  `?run=` link keep working unchanged.
- The flow detail page shows the header (name, version, lifecycle status,
  enabled, app, description), actions (run now, enable or disable, open
  in the editor), health cards from the loaded run history, the steps in
  order, how it runs, versions with "Create draft", and the runs table
  with a status filter.
- `/flow-runs/{uuid}` becomes the run detail page: header with retry,
  resume (only when the run is suspended) and "Show on the canvas", a
  failure alert naming the failed step, a summary, and tabs for steps,
  objects, tasks, context and the raw log.
- The derivations (step order, failed step, durations, run counts) live
  in one plain module, `src/views/flows/flowDetail.js`, so they are unit
  tested without mounting a page.

## Fields in the design the API does not serve

These are left out rather than faked:

- "Open tasks" on the flow page: `/api/flow-tasks` has no flow filter.
- "Retry of" on the run page: a run records no retry origin.
  `parentRunUuid` is the sub-flow parent and is shown as "Part of run".
- "Published by" on a version when `publishedBy` is null.
- "Keeps run history for" and "Audit trail" when the flow leaves
  `retentionDays` or `auditEnabled` unset.

## Impact

- Frontend only: two views, one helper module, one manifest value, one
  route, l10n keys in every locale (the parity gate requires it).
- No API, schema or migration change.
- References ADR-022 (consume OpenRegister reads), ADR-004 (standard
  Nextcloud components, CSS variables for NL Design System theming).
