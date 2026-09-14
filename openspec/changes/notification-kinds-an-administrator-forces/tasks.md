# Tasks: notification-kinds-an-administrator-forces

## 1. A forced channel

- [ ] 1.1 `forcedChannels` with a reason on a notification, validated at schema save.
- [ ] 1.2 The dispatcher sends on a forced channel whatever the merged preference says.
- [ ] 1.3 The effective-preferences read reports the kind as forced, naming the reason.

## 2. An internal kind

- [ ] 2.1 `internalOnly` on a notification, validated at schema save.
- [ ] 2.2 Dispatch of an internal kind to a recipient outside the organisation is refused and recorded.
- [ ] 2.3 A schema pairing `internalOnly` with an external-only channel is refused at save.

## 3. The administrator's answer

- [ ] 3.1 A read that reports, per recipient and kind, the channel that will be used, the deciding layer and whether it was forced.

## 4. Tests

- [ ] 4.1 Unit tests for the override of a user preference, the refused external dispatch, the save-time refusal and the per-recipient read.
- [ ] 4.2 A Newman request for the per-recipient read.
- [ ] 4.3 Deduplication check (ADR-012) recorded in the PR body.
