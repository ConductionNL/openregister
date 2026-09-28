# Tasks: flow-send-email-external-recipients

## Recipients

- [x] Classify recipient entries into user ids, groups, addresses and
      unknowns; addresses only on the email channel.
- [x] `externalRecipients` allowlist (`none`, `object`, `any`) on
      `SendEmailNode`: config key, config form field, validation.
- [x] Refused addresses in `refusedRecipients` with a reason; syntax checked.
- [x] Addresses count toward the recipient bound, the rate limiter and the
      kill-switch skip; preference checks stay uid-only.
- [x] Deliver addresses through `EmailSender::sendToAddress`.

## Event

- [x] `FlowEmailSentEvent` with typed getters.
- [x] Dispatch after a `dispatched` outcome only; a throwing listener is
      logged and does not fail the step.
- [x] `flowId` on the run context.

## send-notification role fields

- [x] Test a uid field, a list of uids, a list of objects with `uid` /
      `userId`, and a single object.
- [x] Normalise a single role object so its other values are not read as
      uids.

## Tests

- [x] Address resolution, allowlist modes, refused addresses in the report,
      event dispatch with the real event class, role field shapes.
