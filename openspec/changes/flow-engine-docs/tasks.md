# Tasks: flow-engine-docs

## docs/features/flows.md (new)

Write the page with this structure, each section grounded in the shipped
code named beside it:

- [x] **What flows are, and what they are not** — the fleet's single engine
      (ADR-065); the two boundary statements up front: notifications notify
      / flows orchestrate (ADR-031), API calls via OpenConnector sources
      (ADR-094, `openconnector.source-call`) — with the explicit sentence
      that no native HTTP node exists and why.
      > note: ADR-031 is cited by its title; the ADR-065 and ADR-094 files are not in this repository, so the doc cites them by number and subject, not by title.
- [x] **The canvas** — nodes carry behaviour, edges carry sequence; multiple
      outgoing edges run branches in parallel; converging edges merge by
      default and `join: true` synchronises; annotations; the form pane and
      the JSON pane (coordinate wording with `flow-node-config-forms`).
      > note: the editor itself lives in @conduction/nextcloud-vue (not in this repo). The form/JSON wording is grounded in IFlowNodeConfigForm: a node that describes its fields gets a form, others fall back to raw JSON.
- [x] **Triggers** — the three trigger node types; the full event catalogue
      enumerated from `EventCatalogService::CATALOG` (16 events: object
      created/updated/deleted/locked/unlocked/reverted/transitioned, file
      created/updated/deleted, user created/deleted, share created/deleted,
      tag assigned/unassigned); one subject per object trigger and why two
      schemas need two triggers; multiple triggers per flow.
      > note: the catalogue has 19 events, not 16 (case.item.completed/terminated/disabled were added); the doc and test follow the code. openregister.trigger-object accepts only object.created/updated/deleted; its `scope` key is validated but does not filter which events fire the flow (documented as such).
- [x] **Steps** — the built-in catalogue from the registry (object-read,
      object-write, set-fields, map, filter, switch, route, merge, explode,
      iterate, batch, flow-state, wait, sub-flow, await-signal, end), one
      paragraph each: what it does, its config keys, a worked example;
      contributed nodes (openconnector, hermiq) noted as coming from their
      apps' palettes with their own docs. Fed from `flow-messaging-nodes`
      (shipped): `openregister.send-notification` sends an in-app
      notification (web-push riding along) to users, groups or an item
      field, through the ADR-031 channel machinery;
      `openregister.send-email` sends an email with the same recipients,
      guardrails and placeholder syntax; `openregister.send-talk-message`
      posts a Talk chat message as the run's acting user, who must already
      be a participant. State the boundary beside them: notifications
      notify, flows orchestrate, and API calls go through OpenConnector
      sources — there is deliberately no send-webhook node.
      > note: the registry ships 32 openregister.* node types (not the 16 listed here); all 32 are documented with their configKeys().
- [x] **Approvals** — await-signal end to end: the question, who answers,
      how the answer routes downstream, the heartbeat, what happens when
      nobody answers (reaped as failed, flow schedulable again); telling
      the approver via the messaging nodes once `flow-messaging-nodes`
      lands (until then, document what ships — do not pre-document unshipped
      nodes).
      > note: code does NOT match the expectation "reaped as failed, flow schedulable again". await-signal always suspends with a heartbeat resume time, and the 14-day abandoned-signal reaper only fails runs suspended with NO resume time, so an unanswered await-signal stays suspended indefinitely and keeps its flow's schedule closed. There is no per-run cancel. The doc states this; gap to be filed as an issue.
- [x] **Runs** — run history, per-node input/output/log with sampling,
      statuses incl. suspended and dead_letter, last-run fields on the flow,
      retry and resume, why a half-wired flow saves with warnings but
      refuses to run.
- [x] **Stopping things** — the kill switch as implemented (verify the
      actual mechanism in code while writing — stop/disable per flow, stale
      run handling; document only what exists and file gaps as issues).
      > note: shipped: per-flow `enabled`, the instance kill switch (`flow_kill_switch`, checked before every hop, ends runs as `stopped`), per-organisation halts, stale/queued/abandoned-signal reapers. Not shipped: cancelling a single run (no endpoint); disabling a flow does not end runs already queued or suspended. Doc says so; gaps to be filed as issues.
- [ ] **Sharing** — a shared flow can be read and run, never edited; how
      that interacts with the `flow.*` rights matrix.
      > note: left open. Per-flow read/run sharing is not implemented (shared-credentials-and-flows group 6 is blocked; `sharedWith` exists only on the unused flow register schema). The doc states that plainly and documents the organisation scoping, the flow.* rights and FlowRunAuthorization that govern access today. Tick when flow sharing ships and the section can describe it.
- [x] **Shipping flows** — `x-openregister-flows`: flows shipped inside a
      configuration and imported on install (`SchemaFlowImportListener`),
      when to ship vs author in place.
- [x] **The trigger cutover, honestly** — per the spec delta: nodes-first
      with column fallback, what re-authoring changes, deleting a trigger
      node unsubscribes, the unscoped object trigger limitation and its
      workaround. No prose that implies the columns are gone.
      > note: additional gap found: the scheduler still selects flows by the `trigger: schedule` + `cron` columns; an openregister.trigger-schedule node supplies only `runAs`. Documented as field-only today.

## Index and neighbours

- [x] `docs/features/README.md` — add the "Flows (Workflow Automation)" row
      linking `flows.md`; re-scope the existing Workflow Automation row to
      external-tool integration; both rows cross-link.
- [x] `docs/features/workflow-automation.md` — opening pointer: "for
      built-in automation see flows.md"; remove/replace any sentence
      presenting n8n/Windmill as the way to automate OpenRegister.

## Keeping it true

- [x] Doc-lint test (PHPUnit): documented event ids == catalogue ids;
      documented `openregister.*` node ids == registry ids; `README.md`
      links `flows.md`. Positive control: the test fails when an id is
      removed from the doc.
      > note: tests/Unit/Docs/FlowsDocumentationTest.php. Positive controls run: deleting the `tag.unassigned` row fails the event test; renaming `openregister.batch` in the doc fails both node tests; both restored, suite green.
- [ ] Every behaviour claim spot-checked against the live dev instance (live pass, decision 139)
      while writing; each mismatch filed as an issue and the doc worded to
      the shipped behaviour.
      > note: left open: no live dev instance was available; every claim was checked against the code instead.

## Acceptance criteria

- A flow author can build, trigger, approve, inspect, retry and share a
  flow using only these docs.
- No page in `docs/features/` presents an external tool as the native
  automation path.
- The doc-lint test guards the two enumerations against drift.

## Quality checklist

- All content in English (fleet rule); node/config identifiers verbatim
  from code.
- References ADR-031, ADR-065, ADR-094 by their actual titles.
- No PHP changes in this change; issues filed for gaps found while writing.
