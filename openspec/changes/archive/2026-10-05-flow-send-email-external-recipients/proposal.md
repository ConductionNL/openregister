---
kind: code
---

# Proposal: flow-send-email-external-recipients

## Summary

Let `openregister.send-email` reach people who have no Nextcloud account, by
email address, under an allowlist the step declares. Announce every sent
email with a typed `FlowEmailSentEvent`, so a consuming app can file the
message where it belongs (a case document, a timeline entry). Prove that
`openregister.send-notification` already reads role-shaped fields on the item.

## Why

dossiq carries its own email and notify flow nodes. They exist because the
OpenRegister send nodes only reach Nextcloud users: a recipient is a user id,
a group id, or a `{{ field }}` that resolves to user ids. dossiq mails
citizens and outside contacts by address, restricts those addresses to the
ones found on the case itself, and files each mail as a case document plus a
timeline entry.

Keeping a second mail node in a leaf app is the fork `flow-messaging-nodes`
exists to prevent: a second recipient resolver, a second template syntax, a
second place where a kill switch or a rate limit can be forgotten. Ruben
chose to extend OpenRegister so every app gets external recipients, and
dossiq drops its nodes in favour of the shared ones.

## What changes

- **Address recipients on send-email.** A recipient entry may be a literal
  email address, or a `{{ field }}` / `{{ item.field }}` template whose value
  is an address, a list of addresses, or objects carrying an `email` or
  `emailAddress` key (the convention the party model already reads). User
  and group ids resolve exactly as before, and preference checks still apply
  to user ids only.
- **An allowlist on the step: `externalRecipients`.**
  - `none` (default): addresses are refused, so an existing flow behaves as
    it did.
  - `object`: an address is sent to only when it appears in the item's own
    fields.
  - `any`: every syntactically valid address is sent to.
  Every refused address lands in the run report's `refusedRecipients`
  bucket with its reason (`external-recipients-off`, `not-on-item`,
  `invalid-address`). Nothing is dropped silently.
- **`OCA\OpenRegister\Event\FlowEmailSentEvent`**, dispatched once per
  successfully sent email, after the send, with typed getters for register,
  schema, object uuid, recipient, channel kind (`user` or `external`),
  subject, rendered body, flow id, run id, step name and acting user.
- **The run context carries `flowId`**, so the event can name the flow.
- **send-notification role fields**: a test proves the existing relation
  resolution reads a field holding a uid, a list of uids, or objects with a
  `uid` / `userId`. A single object (not wrapped in a list) is normalised so
  its display name is never read as a uid.

## What does not change

- The channel set, the guard order (kill switch, preference, bound, rate
  limit, send) and the recipient bound. External addresses count toward the
  bound like users do.
- No second mailer: addresses go through `EmailSender::sendToAddress`, the
  unit the party model already uses.

## Impact

- **Affected code**: `FlowMessagingService`, `SendEmailNode`,
  `FlowRunService::baseContextFor`, new `FlowEmailSentEvent`.
- **Affected apps**: dossiq listens to `FlowEmailSentEvent` and retires its
  own email and notify nodes.
