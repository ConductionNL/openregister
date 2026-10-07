# Tasks: notification-kinds-an-administrator-forces

## 1. A forced channel

- [x] 1.1 `forcedChannels` with a reason, refused at schema save when the
      reason is missing. `lib/Service/Notification/ForcedChannelPolicy.php`
      holds the rules and `NotificationAnnotationValidator` calls it, so the
      save and the send read ONE interpretation rather than two. Both
      spellings are read (`forcedChannels: [...]` and the envelope with a
      reason), so a hand-written schema is refused for the missing reason
      rather than ignored as an unknown shape.
- [x] 1.2 A forced channel is A LAYER ABOVE the user's in the existing
      resolution, not a dispatcher: `resolveEffective()` already walks schema
      default, group default and the user's own value and reports the layers
      it walked, so the existing sender stays the only thing that sends and
      there is no second reading of the dialect gate 18 enforces.
      **Forcing ADDS to the preference rather than replacing it**: somebody
      who also asked for e-mail keeps e-mail, and what they cannot do is
      remove the channel the process requires.
- [x] 1.3 The decision carries `forced`, the `reason` and the deciding
      `layer`, so the read reports why a kind cannot be switched off. Wiring
      that decision into the HTTP effective-preferences response is 3.1 and
      is not built.

## 2. An internal kind

- [x] 2.1 `internalOnly`, validated at schema save.
- [x] 2.2 An internal kind aimed at a recipient outside the organisation
      returns the named refusal `internal-only-recipient-outside-organisation`
      **rather than an empty channel list**, because an empty list is exactly
      the shape that looks like success: it is the same bytes as a kind nobody
      configured, and the difference matters the day somebody asks why the
      applicant was never told. A test pins it, with a control asserting that
      a kind which simply has no channels carries no refusal.
      An internal kind is also stripped of channels that CAN leave the
      organisation even for an inside recipient: the channel is the leak, not
      the recipient. Recording the refusal on the dispatch path is not built.
- [x] 2.3 Refused at save, in both directions: a kind whose only channels can
      leave the organisation would never send at all, and one that FORCES such
      a channel contradicts its own internal-only declaration.

## 3. The administrator's answer

- [ ] 3.1 A read that reports, per recipient and kind, the channel that will be used, the deciding layer and whether it was forced.

## 4. Tests

- [x] 4.1 Unit tests for the override, the refused external dispatch, the
      save-time refusals and the additive forcing:
      `tests/Unit/Service/Notification/ForcedChannelPolicyTest.php` (14),
      including one that asserts the rules reach the validator the SAVE calls
      rather than holding only in the policy's own test. The per-recipient
      read is 3.1 and is not built.
- [ ] 4.2 A Newman request for the per-recipient read.
- [ ] 4.3 Deduplication check (ADR-012) recorded in the PR body.
