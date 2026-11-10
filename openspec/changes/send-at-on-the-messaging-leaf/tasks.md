# Tasks: send-at-on-the-messaging-leaf

## 1. E-mail channel

- [ ] 1.1 Seeded `smtp-mail` OpenConnector source; `email` channel in `MessageDispatchProvider` with the source allow-list; `Message-ID` minted and returned.

## 2. Scheduling

- [ ] 2.1 Migration: `openregister_scheduled_messages` (channel, source, path, body, headers, object, author, send at, state, attempts, response, message id).
- [ ] 2.2 `sendAt` and `object` on the send endpoints; list and cancel routes; audit entries on the object.
- [ ] 2.3 `ScheduledMessageSweepJob`, registered in `appinfo/info.xml`. **The
      RULES it obeys are built and tested** in
      `lib/Service/Notification/ScheduledMessagePolicy.php`; the job, the
      table and the routes are not. The sweep is the dangerous part of
      scheduling rather than the scheduling itself: a row saying "send this at
      nine" is harmless, and a job reading it is where a message gets sent
      twice, sent after it was cancelled, or retried for ever.
      The claim is compare-and-set on the state AND the attempt count, so two
      sweeps that read one pending row cannot both write — the failure that
      prevents reaches a citizen as two letters carrying one reference number.
      Cancellation wins over being due and over an existing claim. A stale
      claim is taken over rather than leaving the row stuck in a state that
      looks like progress. A spent message is parked with its last error
      rather than dropped (which reads as sent) or retried for ever (which
      hammers a mail server about an address that will never accept it). A
      missed window still sends; an unparseable `sendAt` does NOT mean now.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/scheduled-message.spec.ts`: schedule from an object, list it, cancel it.
- [ ] 3.2 Unit tests for the e-mail channel; Newman for the routes. **The
      claim, the retries and the guards are tested**:
      `tests/Unit/Service/Notification/ScheduledMessagePolicyTest.php` (16).
