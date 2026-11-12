# Tasks: notification-routing-per-group-and-scope

## 1. Group recipients

- [x] 1.1 A rule addresses a Nextcloud group or a declared role on the object (D-1).
- [x] 1.2 Members are resolved at dispatch, once per dispatch, and each member's preference applies.
- [x] 1.3 An unresolvable group is a recorded failed dispatch, never a silent no-op.

## 2. The three-layer preference

- [x] 2.1 Schema default, group default, user override, in that order (D-2).
- [x] 2.2 A group administrator sets the group default.
- [x] 2.3 The effective-preferences API names the layer that decided each value (D-2).

## 3. Scoped preferences

- [x] 3.1 A preference per register, per schema or per declared domain (D-3).
- [x] 3.2 An unset scope falls through to the global value, with no migration.

## 4. Transports and broadcast

- [x] 4.1 A rule declares its transports; one firing runs all of them (D-4).
- [x] 4.2 One event identifier, one outcome per transport, readable together.
- [x] 4.3 A broadcast with subject, body and period, delivered once per user and recorded (D-6).

## 5. Templates

- [x] 5.1 A named, editable template shipped for every platform event, variables documented (D-5).
- [x] 5.2 A listing of events with no template; no silent generic fallback.

## 6. Tests

- [x] 6.1 `tests/e2e/ci/notification-routing.spec.ts`: a group rule reaching a new member, a group default overridden by a user, a scoped preference, a broadcast seen once.
- [x] 6.2 Unit tests: the three-layer merge and its layer naming, the unresolvable group, two transports with one failing, the missing-template listing.
- [x] 6.3 `openspec validate notification-routing-per-group-and-scope --strict`.

## 7. Hand over

- [x] 7.1 Hand the template set to the dossiq lane, which ships the Dutch text per event, with the nine candidate ids.
- [x] 7.2 Hand the outbound transport to the integriq lane, replacing the separate ZGW dispatch.
