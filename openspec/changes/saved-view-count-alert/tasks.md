# Tasks: saved-view-count-alert

## 1. Data and validation

- [~] 1.1 `alert` and `alertState` on `View` with a migration; validator reusing the recipient and channel grammar; owner-or-write guard.

## 2. Sweep

- [~] 2.1 `ViewAlertSweepJob` (TimedJob): due selection, cap, watermark, count under the owner's RBAC, crossing state machine, dispatch through the engine with a `view-alert` source.
- [x] 2.2 Register the job in `appinfo/info.xml`.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/view-alert.spec.ts`: set a threshold on a view, push the count over it, see the notification once.
- [x] 3.2 Unit tests for the validator, the state machine and the bounded pass.

## Status, 2026-09-18

**Built: the declaration, the crossing rule, and the bounded sweep.**

- `ViewAlert` reads a declared `{operator, threshold, recipients, channels,
  every}` and refuses anything else **naming its field**. A 422 that does not
  say what to fix sends somebody back to a form with five inputs and no idea
  which one. An alert with no recipients is refused too: it is a query run on a
  timer forever with nobody reading it.
- The crossing rule is one method with one test. `armed → fired → armed`: a
  count above the line fires once and stays `fired` until a sweep sees it back,
  then re-arms SILENTLY. Nobody asked to hear that a backlog cleared, and a
  "resolved" message they did not ask for is the second half of the noise this
  design avoids. The scenario is a test: eight sweeps over a standing backlog
  send one notification.
- `ViewAlertSweepJob` takes at most 200 views per pass, oldest evaluation
  first, so a thousand due views take five passes and none starves behind a
  busier neighbour. `every` has a floor of 300 seconds: one view counting every
  ten seconds is a load nobody notices, a thousand is an outage, and the person
  who set the first had no way to know about the other nine hundred.
- **The count is taken as the view's OWNER**, through `runAs`. A shared view
  alerts on what its owner may see; counting as the system would turn a
  threshold on a shared view into a way to learn how many records sit behind a
  filter the reader is not entitled to. A view whose owner no longer exists is
  skipped rather than counted as the system.
- A failed count leaves the state alone. Treating it as "below the threshold"
  would silently re-arm a fired alert and page somebody again the moment
  counting worked.

**The notification leg is NOT wired, and 1.1's validator is not on the write
path.** Both are named rather than half-built:

- **2.1's dispatch is an EVENT, not a notification.** Every notification sender
  in this app is object-shaped: each takes an `ObjectEntity` and builds a
  deeplink from its register, schema and uuid. A view alert is about a NUMBER —
  there is no object it is about — and inventing one to satisfy the signature
  would put a fabricated record in the link the notification tells somebody to
  click. `ViewAlertCrossedEvent` carries the view, the alert and the count, is
  dispatched once per crossing and is tested; what it needs is a sender that
  can address a person about something other than an object.
- **1.1's owner-or-write guard and the controller wiring are not built.**
  `ViewAlert::parse()` is the validator and it refuses correctly, but nothing
  calls it on the view save path yet, so the column accepts what the API puts
  in it. That is a write-path change to `ViewService`/`ViewsController` with its
  own authorisation question, and it is the next piece.
- **3.1, the e2e**, which the spec already defers until the field ships in
  nextcloud-vue.
