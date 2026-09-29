---
kind: code
---

## Why

`retention.legalHold` held one hold per object. Two matters can cover the same
record (a lawsuit and an audit, or two Woo appeals), and with one slot the
second placement overwrote the first reason, whose placement never reached
`history`, and releasing either matter lifted the hold the other still needed
(openregister#4172). Filinq keeps an overlap ledger of its own to work around
it (filinq `e-discovery-legal-hold`, filinq#234), and every app placing holds
would need the same.

## What Changes

- `retention.legalHold.holds` is a list of active holds, each with an `id`, an
  `ownerKey` (the app and the placing object, for example
  `filinq:legalHoldCase:<uuid>`), a `reason`, `placedBy` and `placedDate`.
- `placeHold($object, $reason, $ownerKey)` adds the hold with that owner key,
  or updates its reason when that owner already holds the object.
- `releaseHold($object, $releaseReason, $ownerKey)` lifts only that owner's hold
  and moves it to `history`. A release that names no owner lifts every hold, as
  it always did.
- `legalHold.active` stays and is derived: true while any hold is in the list.
  The top-level `reason`, `placedBy` and `placedDate` mirror the most recent
  active hold. Every reader of `legalHold.active` (destruction check, retention
  clocks, e-depot, audit retention) is unchanged.
- A stored single-slot hold reads as a list of one owned by
  `openregister:manual`, so existing data stays valid.
- `LegalHoldService` and `RetentionService`, which each wrote the slot their
  own way, now share `LegalHoldLedger`. Both hold endpoints accept `ownerKey`.

## Impact

- Filinq can drop its overlap ledger and pass its case UUID as the owner key.
- No migration: the stored shape is read as it is and rewritten on the next
  placement or release.
