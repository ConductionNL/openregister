# Design: notification-kinds-an-administrator-forces

## D-1: forced is a layer, not a default

A group default is still a preference: the user overrides it. A forced
channel is a different kind of statement, so it sits above the merge rather
than inside it, and the effective-preference read reports it as forced with
the reason. Modelling it as a very insistent default would leave the user
override winning, which is exactly the failure.

## D-2: internal is a refusal, not a filter

Marking a kind internal could be done by leaving the external channels out
of the template. That fails the moment somebody adds a channel. So the
engine refuses the dispatch at the recipient, records it, and the schema
validator refuses the impossible pairing at save time. Two gates, because
the cost of one leak is not symmetrical with the cost of a refused send.

## D-3: the recipient's kind decides, not the channel's

A recipient is internal or external by what it is, not by which address was
picked. A party record with no account is external even when a colleague
happens to hold the same e-mail address. The party model of
`party-roles-beyond-the-requester` already carries the distinction.

## D-4: `critical` is not this

`critical: true` already exists and bypasses quiet-hours queuing only, as
the spec says in as many words. Overloading it to mean "ignore the user's
channel choice" would change the meaning of every rule that already sets
it. A separate declaration, with a separate name.

## D-5: reuse analysis (ADR-012)

- The preference merge, the scoping and the effective-preferences API:
  reused from `notification-routing-per-group-and-scope`.
- The annotation validator and the channel block format: reused.
- The notification history: reused for the refusal record.
- No second dispatcher and no second preference store.
