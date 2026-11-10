---
kind: code
depends_on: [notification-routing-per-group-and-scope]
---

# Proposal: notification-kinds-an-administrator-forces

## Summary

Notification preferences answer what a person wants. Two things a
municipality needs are not preferences at all: a kind that always goes out
on a named channel because the law or the process says so, and a kind that
must never reach the party outside the organisation. Both are administered
decisions, and today neither can be stated: every kind is a preference a
user can switch off, and no kind is marked internal.

## The row this closes

### Row 6.28, notification kinds chosen per recipient, with a channel an administrator can force, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 6.24`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
  in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **6.28** | 6.24 | Notification kinds chosen per recipient, with a channel an administrator can force | no | unread | discovery D-freescout-31 |
```

- ledger note, verbatim:

> Row 13.18 watchers is itself no, so nobody subscribes to anything yet. Nothing marks a kind as never reaching the external party, and nothing lets an administrator say this one always goes by post.

## What the competitor evidence is

The row is one of the 98 promoted under decision D1, and the corpus is
explicit about its competitor columns, verbatim:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is
> a reading of a product somebody opened, and filling these cells with it
> would fabricate thirty readings per row.

No competitor claim is made here. The register's cross-reference for the row
is the discovery finding `D-freescout-31`.

## ADRs

- ADR-031 (schema-declarative business logic): the forced channel and the
  internal marking are declared on the notification, beside the trigger and
  the recipients, not decided in the dispatcher.
- ADR-005 (security, no PII where it does not belong): a kind marked
  internal is refused at dispatch to an external address rather than
  filtered in a template.
- ADR-022 (apps consume OpenRegister abstractions): the engine enforces it,
  so an app cannot accidentally route around it by writing its own sender.

## What openregister builds

- A kind that chooses its recipients per kind, already half there. The
  effective preference of `notification-routing-per-group-and-scope`
  (REQ-NRG-002) merges schema, group and user per notification kind. This
  change adds the layer above it: an administrator's decision that a
  preference does not apply.
- A forced channel. A notification declares a channel as mandatory, with a
  reason an administrator can read. The dispatcher sends on that channel
  whatever the recipient's preference says, and the preference surface shows
  the kind as forced rather than as an option that quietly does nothing.
- An internal kind. A notification declares that it never leaves the
  organisation. Dispatch to a party that is not internal is refused, the
  refusal is recorded with the kind and the recipient, and a schema that
  pairs an internal kind with an external-only channel is refused at save.
- A per-recipient answer an administrator can read. For one recipient and
  one kind, the system reports which channel will be used, which layer
  decided it, and whether the decision was forced.

## What dossiq consumes

dossiq declares which of its kinds are internal (an internal note, a
handler's assignment) and which are forced (a decision that must go by post),
and renders the forced state in its own preference screen. No dossiq slug
exists for this on dossiq `development`, so it is to be specified in dossiq.
Beside dossiq: portaliq, humaniq, zaakafhandelapp.

## Size

S. Two declarations, one refusal and one read, over a change already in
flight.

## The specs this extends

- `specs/notificatie-engine`, requirements "Users MUST be able to manage
  their notification preferences", "The dispatcher MUST consult the merged
  preference before delivering the in-app/push channel" and "Schemas MAY
  declare notifications via `x-openregister-notifications` with a normative
  channel block format".
- The change `notification-routing-per-group-and-scope`, REQ-NRG-002 and
  REQ-NRG-003, which this one sits above.
