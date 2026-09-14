# Tasks: send-at-on-the-messaging-leaf

## 1. E-mail channel

- [ ] 1.1 Seeded `smtp-mail` OpenConnector source; `email` channel in `MessageDispatchProvider` with the source allow-list; `Message-ID` minted and returned.

## 2. Scheduling

- [ ] 2.1 Migration: `openregister_scheduled_messages` (channel, source, path, body, headers, object, author, send at, state, attempts, response, message id).
- [ ] 2.2 `sendAt` and `object` on the send endpoints; list and cancel routes; audit entries on the object.
- [ ] 2.3 `ScheduledMessageSweepJob` with compare-and-set claim, cap, retries; registered in `appinfo/info.xml`.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/scheduled-message.spec.ts`: schedule from an object, list it, cancel it.
- [ ] 3.2 Unit tests for the claim, retries, guards and the e-mail channel; Newman for the routes.
