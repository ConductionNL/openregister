# Tasks: flow-engine-docs

## docs/features/flows.md (new)

Write the page with this structure, each section grounded in the shipped
code named beside it:

- [ ] **What flows are, and what they are not** — the fleet's single engine
      (ADR-065); the two boundary statements up front: notifications notify
      / flows orchestrate (ADR-031), API calls via OpenConnector sources
      (ADR-094, `openconnector.source-call`) — with the explicit sentence
      that no native HTTP node exists and why.
- [ ] **The canvas** — nodes carry behaviour, edges carry sequence; multiple
      outgoing edges run branches in parallel; converging edges merge by
      default and `join: true` synchronises; annotations; the form pane and
      the JSON pane (coordinate wording with `flow-node-config-forms`).
- [ ] **Triggers** — the three trigger node types; the full event catalogue
      enumerated from `EventCatalogService::CATALOG` (16 events: object
      created/updated/deleted/locked/unlocked/reverted/transitioned, file
      created/updated/deleted, user created/deleted, share created/deleted,
      tag assigned/unassigned); one subject per object trigger and why two
      schemas need two triggers; multiple triggers per flow.
- [ ] **Steps** — the built-in catalogue from the registry (object-read,
      object-write, set-fields, map, filter, switch, route, merge, explode,
      iterate, batch, flow-state, wait, sub-flow, await-signal, end), one
      paragraph each: what it does, its config keys, a worked example;
      contributed nodes (openconnector, hermiq) noted as coming from their
      apps' palettes with their own docs.
- [ ] **Approvals** — await-signal end to end: the question, who answers,
      how the answer routes downstream, the heartbeat, what happens when
      nobody answers (reaped as failed, flow schedulable again); telling
      the approver via the messaging nodes once `flow-messaging-nodes`
      lands (until then, document what ships — do not pre-document unshipped
      nodes).
- [ ] **Runs** — run history, per-node input/output/log with sampling,
      statuses incl. suspended and dead_letter, last-run fields on the flow,
      retry and resume, why a half-wired flow saves with warnings but
      refuses to run.
- [ ] **Stopping things** — the kill switch as implemented (verify the
      actual mechanism in code while writing — stop/disable per flow, stale
      run handling; document only what exists and file gaps as issues).
- [ ] **Sharing** — a shared flow can be read and run, never edited; how
      that interacts with the `flow.*` rights matrix.
- [ ] **Shipping flows** — `x-openregister-flows`: flows shipped inside a
      configuration and imported on install (`SchemaFlowImportListener`),
      when to ship vs author in place.
- [ ] **The trigger cutover, honestly** — per the spec delta: nodes-first
      with column fallback, what re-authoring changes, deleting a trigger
      node unsubscribes, the unscoped object trigger limitation and its
      workaround. No prose that implies the columns are gone.

## Index and neighbours

- [ ] `docs/features/README.md` — add the "Flows (Workflow Automation)" row
      linking `flows.md`; re-scope the existing Workflow Automation row to
      external-tool integration; both rows cross-link.
- [ ] `docs/features/workflow-automation.md` — opening pointer: "for
      built-in automation see flows.md"; remove/replace any sentence
      presenting n8n/Windmill as the way to automate OpenRegister.

## Keeping it true

- [ ] Doc-lint test (PHPUnit): documented event ids == catalogue ids;
      documented `openregister.*` node ids == registry ids; `README.md`
      links `flows.md`. Positive control: the test fails when an id is
      removed from the doc.
- [ ] Every behaviour claim spot-checked against the live dev instance
      while writing; each mismatch filed as an issue and the doc worded to
      the shipped behaviour.

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
