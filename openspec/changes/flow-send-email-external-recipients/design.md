# Design: flow-send-email-external-recipients

## Decision 1: the allowlist lives on the step, default closed

An address in a recipient list is a different trust decision from a user id.
A user id is verified against the user manager; an address is anything a
field on the item happens to hold, and item fields are writeable by anyone
with update rights on the object. So the step says how far it trusts
addresses, and the default is `none`: an existing flow that somehow holds an
address keeps refusing it.

`object` is the mode dossiq needs. An address is sent to only when it
appears, normalised (trimmed, lower case), somewhere in the item's own json.
A literal address in the step config passes `object` only if the item also
holds it. `any` is for flows whose author owns the address source.

## Decision 2: refused is its own bucket, with a reason

`unknownRecipients` means "this did not resolve to anyone". A refused address
did resolve: the step declined it. Mixing the two would hide the one decision
an operator needs to see, so refused addresses go into `refusedRecipients`,
sampled like every other bucket, each entry carrying `recipient` and
`reason`.

## Decision 3: how an entry is classified

- A literal entry is a user id if the user exists, a group id if the group
  exists, otherwise an address if it contains `@`, otherwise unknown. User
  first, because a Nextcloud uid may itself look like an address.
- A template entry reads the field's value. Strings are user ids when the
  user exists, addresses when they contain `@`, unknown otherwise. Objects
  are users through `uid` / `userId` / `user_id`, otherwise addresses through
  `email` / `emailAddress`; a display name comes from `name` / `displayName`.
- Syntax is checked with `FILTER_VALIDATE_EMAIL`; a malformed address is
  refused as `invalid-address`, never handed to the mailer.
- The send-notification channel never takes addresses: an address there is
  unknown, as before.

## Decision 4: the event is dispatched after the send, and never un-sends it

`FlowEmailSentEvent` is dispatched through `IEventDispatcher::dispatchTyped`
only when `EmailSender` reports `dispatched`. A listener that throws is
logged at error level and does not fail the step: failing the step would
route through `onError` and a retry would send the mail a second time.

The step name is the node id from the ambient `FlowRunContext` frame when
there is one, otherwise the node type. The flow id comes from the new
`flowId` context key, written by `FlowRunService` from the run itself.

## Decision 5: privacy of the run report

Addresses appear in the report samples exactly as user ids do: bounded by
the log's sampling rule. The report never holds a body.
