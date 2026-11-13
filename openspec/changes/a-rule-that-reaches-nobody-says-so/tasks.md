# Tasks: a-rule-that-reaches-nobody-says-so

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Refuse what can never resolve

- [x] 1.1 `NotificationAnnotationValidator` refuses `notification-recipient-names-nobody` for `groups: []` and `users: []`, and does NOT refuse a declared group that is merely empty today (REQ-RRN-01)

## 2. Record what reached nobody

- [x] 2.1 `dispatchToParties()` returns the number reached instead of `void`, and the zero case calls `RuleReachRecorder::reachedNobody()` (REQ-RRN-02)
- [x] 2.2 One log line per rule per run, counting continuing underneath, so a storm is one line (REQ-RRN-02)

## 3. Reach a person

- [x] 3.1 Register `RuleReachRecorder` as a shared service rather than a private built inside the dispatcher, so the aggregate survives the dispatcher instance (REQ-RRN-03)
- [ ] 3.2 Read `report()` on the notification settings page, where an administrator configuring notifications is already standing (REQ-RRN-03)
- [ ] 3.3 Decide, with the storm question answered first, whether a scheduled job should raise a notification when `needsAPerson` is true, or whether the settings row is enough (REQ-RRN-03)

🔴 **3.2 and 3.3 are open, and until they are done `report()` has no caller.**
3.1 is done: `NotificationsWiring` registers `RuleReachRecorder` as one shared
service, so the dispatcher's `DeliveryLedger` is autowired with it and the
aggregate outlives a dispatcher instance. It still lives only as long as the
process that dispatched: a dispatch runs in the request or background job that
saved the object, and the settings page is served by another request, which
would read an empty report. So 3.2 needs the counts persisted first, which is
the question 3.3 leaves open. The warning line is logged and findable by whoever
already suspects the problem; the aggregate is not on any screen. Do not read the
tests in #3961 as evidence that an operator is told.
