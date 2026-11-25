# Tasks: a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did

- [ ] 1.1 An `automation-outcome` record (kind, key, at, subject register/schema/id, outcome, reason, recipients) with a 90-day prune job; the idempotency `NotificationDispatchLog` stays as it is.
- [ ] 1.2 The notification dispatcher writes one `notification` record per send (sent or failed, recipient count, no body or address).
- [ ] 1.3 The typed lifecycle action runner writes one `lifecycle` record per run (done or failed with the reason).
- [ ] 2.1 `GET /api/automation-outcomes?keyPrefix=&since=&limit=&offset=`, newest first, filtered to subject objects the caller may read.
- [ ] 3.1 PHPUnit per writer and for the read's object-rights filter; a Newman case per scenario.
- [ ] 4.1 Tell buildiq (`logic-automation-run-log` T11) the route and shape.
