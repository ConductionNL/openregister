# Tasks: a-rule-that-reaches-nobody-says-so

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Refuse what can never resolve

- [x] 1.1 `NotificationAnnotationValidator` refuses `notification-recipient-names-nobody` for `groups: []` and `users: []`, and does NOT refuse a declared group that is merely empty today (REQ-RRN-01)

## 2. Record what reached nobody

- [x] 2.1 `dispatchToParties()` returns the number reached instead of `void`, and the zero case calls `RuleReachRecorder::reachedNobody()` (REQ-RRN-02)
- [x] 2.2 One log line per rule per run, counting continuing underneath, so a storm is one line (REQ-RRN-02)

## 3. Reach a person

- [x] 3.1 Register `RuleReachRecorder` as a shared service rather than a private built inside the dispatcher, so the aggregate survives the dispatcher instance (REQ-RRN-03)
- [x] 3.2 Read `report()` on the notification settings page, where an administrator configuring notifications is already standing (REQ-RRN-03)
      > 2026-11-25: the counts are persisted first. `RuleReachLedger` keeps, per rule, the
      > latest run's count and when it was last seen, in one lazy app config value
      > (`notification_rule_reach`, newest first, capped at 200 rules). The shared recorder
      > writes it on a rule's first occurrence, on each doubling (a storm of 400 is nine
      > writes) and the remainder on `flush()` or release; a rule that reaches somebody again
      > drops off. `GET /api/notification-rule-reach` (`NotificationRuleReachController`,
      > administrators only by the middleware) serves `RuleReachLedger::report()`, and the
      > admin settings show it in the `NotificationRuleReach` section beside Push Notifications
      > (`RuleReachLedgerTest`, `RuleReachRecorderLedgerTest`,
      > `AnnotationNotificationDispatcherRuleReachTest`, `NotificationRuleReachControllerTest`,
      > `NotificationRuleReach.spec.js`). Not seen in a browser here (no live instance).
- [ ] 3.3 Decide, with the storm question answered first, whether a scheduled job should raise a notification when `needsAPerson` is true, or whether the settings row is enough (REQ-RRN-03)

3.2 is done: the aggregate is persisted in `RuleReachLedger` and is on the admin
settings page. 🔴 **3.3 is open**: whether a scheduled job should also raise a
Nextcloud notification when `needsAPerson` is true, so somebody who is not
looking is told. That is a decision about storms and nagging, not code, and the
persisted ledger is what such a job would read.
