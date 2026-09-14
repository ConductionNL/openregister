# Tasks: saved-view-count-alert

## 1. Data and validation

- [ ] 1.1 `alert` and `alertState` on `View` with a migration; validator reusing the recipient and channel grammar; owner-or-write guard.

## 2. Sweep

- [ ] 2.1 `ViewAlertSweepJob` (TimedJob): due selection, cap, watermark, count under the owner's RBAC, crossing state machine, dispatch through the engine with a `view-alert` source.
- [ ] 2.2 Register the job in `appinfo/info.xml`.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/view-alert.spec.ts`: set a threshold on a view, push the count over it, see the notification once.
- [ ] 3.2 Unit tests for the validator, the state machine and the bounded pass.
