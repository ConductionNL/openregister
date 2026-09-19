# Tasks

## 1. The interface

- [x] 1.1 `getKind()` and `getCategory()` on `IFlowNode`, both defaulted — `serviceTask` and `other`.
- [x] 1.2 Enumerate both vocabularies as constants so a typo is a fatal, not a silent `other`.
- [x] 1.3 A test that a node implementing neither is still registered, still in the catalog, and reports the defaults.

## 2. Declare this repository's 21 nodes

- [x] 2.1 Triggers: `trigger-object`, `trigger-manual`, `trigger-schedule` → `event` / `triggers`.
- [x] 2.2 Human: `user-task` → `userTask` / `human`; `portal-task` → `userTask` / `human`; `await-signal` → `receiveTask` / `human` (D-3).
- [x] 2.3 Objects: `object-read`, `object-write`, `lock-object`, `unlock-object`, `batch` → `serviceTask` / `objects`.
- [x] 2.4 Logic: `switch`, `route`, `filter`, `iterate`, `explode`, `merge`, `map`, `set-fields`, `flow-state`, `wait`, `end`, `sub-flow` → `gateway` or `scriptTask` or `subProcess` as each actually is; `decision-table` → `businessRuleTask` / `logic`.
- [x] 2.5 Messaging: `send-email`, `send-notification`, `send-talk-message` → `sendTask` / `messaging`.
- [x] 2.6 Review every assignment against the trap: the kind describes the step, not its subject.

## 3. Catalog and palette

- [x] 3.1 The registry applies the defaults; the catalog serves both fields.
- [ ] 3.2 The palette groups by category in a fixed order, never registration order.
- [ ] 3.3 nc-vue: grouped palette, jest spec seen RED first, en/nl for the category labels with the `writing` skill loaded first.

## 4. The gate

- [ ] 4.1 A hydra gate refusing a node in THIS repository's node directory that declares neither. Scoped by path (D-3 trap), never by interface.
- [ ] 4.2 An acceptance fixture with a planted undeclared node, so the gate is proven to refuse rather than assumed to.

## 5. Proof

- [x] 5.1 Playwright: the catalog reports a kind and a category for every openregister node, and the palette renders grouped.
- [x] 5.2 Assert a contributed node with no declaration still appears, under `other`.
- [ ] 5.3 `composer check:strict`, both l10n gates, full unit suite. Exit code, not summary line.
