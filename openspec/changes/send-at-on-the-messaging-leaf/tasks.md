# Tasks: send-at-on-the-messaging-leaf

## 1. E-mail channel

- [ ] 1.1 Seeded `smtp-mail` OpenConnector source; `email` channel in `MessageDispatchProvider` with the source allow-list; `Message-ID` minted and returned.
      Built in OpenRegister (2026-11-14): `smtp-mail` on `MessageDispatchProvider::ALLOWED_SOURCES`; `POST /api/integrations/email/send` (`MessageDispatchController::emailSend()`) taking `{to, subject, text, html?, replyTo?}` (and `path`, `sendAt`, `object`), 400 for a missing or malformed field, 503 with `details.cause` for a degraded source; every message, sent now or scheduled, gets a Message-ID from `MessageIdMinter` (`<128 random bits@host>`), sent as its header and returned; the provider answers a sent message with the Message-ID it left with, and the sweep stores that one. Left: seeding the `smtp-mail` source itself, which is a register fragment in integriq (where `cmcom-sms` and the other sources are seeded), not OpenRegister.

## 2. Scheduling

- [x] 2.1 Migration: `openregister_scheduled_messages` (channel, source, path, body, headers, object, author, send at, state, attempts, response, message id). — `Version1Date20261113190000`, plus `claimed_by`, `claimed_at`, `last_error`; read and written by `ScheduledMessageStore` (compare-and-set claim on id, state and attempts).
- [x] 2.2 `sendAt` and `object` on the send endpoints; list and cancel routes; audit entries on the object.
      Built so far: `sendAt` and `object: {register, schema, id}` on `POST /api/integrations/{sms,whatsapp}/send` (202 with the stored message, 400 for a past or unreadable `sendAt`, 404 for an object the caller may not read, 503 without the scheduler, never sent now instead); `GET /api/objects/{register}/{schema}/{id}/messages` and `POST .../messages/{messageId}/cancel` (author or `update`, compare-and-set so a cancel cannot cross a claim, 409 once sent, parked or cancelled); scheduling and cancelling audited on the object (`ScheduledMessageScheduler`, `ScheduledMessagesController`). The send and fail entries (2026-11-14): `ScheduledMessageAudit`, called by `ScheduledMessageSweep` after each send (`message.sent`, with the Message-ID) and each failure (`message.failed`, with the cause, the attempt and whether it is parked); it reads the bound object by uuid without a user, writes nothing for an unbound message, and logs instead of failing the sweep when the object is gone or the entry cannot be written.
- [x] 2.3 `ScheduledMessageSweepJob`, registered in `appinfo/info.xml`. — done: every five minutes, `ScheduledMessageSweep` applies `ScheduledMessagePolicy` and dispatches through `MessageDispatcherInterface`; states and attempt limit are the policy's (pending, claimed, sent, cancelled, parked; 5). **The
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
- [ ] 3.2 Unit tests for the e-mail channel; Newman for the routes. Unit tests for the e-mail channel are in (2026-11-14): `MessageDispatchEmailTest` (14), `MessageIdMinterTest` (8), the Message-ID cases in `MessageDispatchProviderTest`, and the audit in `ScheduledMessageAuditTest` and `ScheduledMessageSweepTest`. Newman is left (a running instance). **The
      claim, the retries and the guards are tested**:
      `tests/Unit/Service/Notification/ScheduledMessagePolicyTest.php` (16).
