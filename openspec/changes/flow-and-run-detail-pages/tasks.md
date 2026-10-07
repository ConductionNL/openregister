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
- [ ] 5.2 Playwright journey: index row to overview to run detail, in
      the verification pass after the design round.
