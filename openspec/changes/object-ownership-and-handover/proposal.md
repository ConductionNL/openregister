---
kind: code
depends_on: [object-level-sharing-and-private-scope]
---

# Proposal: object ownership and handover

## Summary

A record has one named owner and may have one owning group. The owner is derived
from the authenticated actor and cannot be set by a caller. A colleague takes a
record over themselves, the previous owner is told, an administrator reassigns
many records at once, and every change of hands is one typed entry in the audit
trail.

## Ledger rows

| row | capability | rating before |
|---|---|---|
| ownership.1 | a named owner on a record, and only the owner may edit it | no |
| ownership.2 | ownership derived from the authenticated actor, never claimable | no |
| ownership.3 | a group can own a record, so colleagues can edit it | no |
| ownership.4 | a second person takes ownership, and the handover is recorded | no |
| ownership.5 | an owner is reassigned across many records in one action | no |
| ownership.6 | an owner change carries to child records | no |

From the Woo capability round against GPP-Woo, the Dutch municipal publication
platform. The round had to downgrade our own authorization score, because our
evidence argued from ownership while the question was about roles and we had no
model for the difference.

## Why

GPP-Woo's shape, for reference rather than imitation: a publication has one named
owner plus an owning group, a colleague takes it over without an administrator, an
administrator reassigns in bulk, and the owner comes from the authenticated actor.
Its own manual warns that the previous owner gets no signal. We do better on that
one point: every handover notifies the person who held the record.

## What this is not

Not roles, and not permissions in general. Ownership is one principal among
several, and this change touches only that principal and the audit action that
records it changing.
