# Tasks: flow-and-run-detail-pages

## 1. Shared derivations

- [x] 1.1 `src/views/flows/flowDetail.js`: step order from the flow's
      nodes and edges, the failed step of a run, run duration as the sum
      of step durations, run counts by status, trigger and status labels.

## 2. Flow detail page

- [x] 2.1 `src/views/flows/FlowOverview.vue` at `/flows/:id/overview`,
      route name `flow-overview`, registered in `src/main.js`.
- [x] 2.2 `src/manifest.json`: the `flows` index `rowRoute` names
      `flow-overview`, so a row click opens the overview, not the canvas.
- [x] 2.3 Header, actions (run now, enable or disable, open in editor),
      health cards, steps, how it runs, versions with "Create draft",
      runs table with status filter and paging.

## 3. Run detail page

- [x] 3.1 `src/views/flows/FlowRunDetail.vue` renders the run instead of
      redirecting: header, retry, resume when suspended, show on the
      canvas (`/flows/{flowId}?run={uuid}`).
- [x] 3.2 Failure alert naming the failed step and its error.
- [x] 3.3 Summary, and tabs for steps (input, output, error per step),
      objects, tasks, context and the raw log.

## 4. Text

- [x] 4.1 Every new string in `l10n/*.js` for all locales, Dutch
      reviewed; Conduction voice, sentence case, no em-dashes.

## 5. Tests

- [x] 5.1 Jest spec `src/views/flows/flowDetail.spec.js` for the
      derivations and both pages' load and action methods, failing on the
      old code.
- [x] 5.2 Playwright journey: index row to overview to run detail, in
      the verification pass after the design round. Two tests in
      `tests/e2e/flow-engine.spec.ts` ('the Flows page', gated on
      `OR_UI_E2E=1` like the rest of that describe): a row opens the
      overview, the overview links the run, the run page names its steps;
      a failed run names the step it stopped at.

## 6. Found in the live check (7 October 2026)

- [x] 6.1 `FlowController::run()` catches `FlowRunRefused` and answers 403
      (401 without a session) with `error` and `verdict`, as
      `FlowRunnableGuard` does. Uncaught it became an HTML 500, so Run now
      on an imported flow, which arrives without an owner, showed only
      "That did not work". Unit test in `FlowControllerTest`, failing on
      the old controller.
- [x] 6.2 The overview offers Adopt when the flow has no owner, through the
      existing `POST /api/flows/{id}/adopt`. Without it the refusal named a
      step nobody could take from any screen.
