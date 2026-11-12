---
kind: code
---

# Proposal: read-audit-entries-name-the-rule

## Summary

A `read` audit entry says who opened a record and when. It does not say which access rule let them see it. This change adds a `ruleDigest` to every read entry: the SHA-256 of the canonical JSON of the schema authorization rule that granted the read. An auditor can then recompute the digest from the schema as it stood at `ruleVersion` and prove which rule was in force. The digest is part of the hash chain, so it cannot be changed afterwards without breaking the chain.

Ruben decided on 12 Nov 2026 (decision 402) to give this idea a capability row and a spec, not built yet. It comes from the archived `add-openregister-discovered-capabilities` change (its `row-level-security-audit` delta), which listed it as not covered elsewhere.

## Why

Read logging is built (audit-trail-immutable, "Every user-facing read of an object is logged as a read"), and row-level rules are built (row-field-level-security). The two do not meet. An auditor sees "alice read melding 123" but cannot answer which of the schema's read rules let her, or whether that rule has changed since. Tender questions about row-level security ask exactly that.

## What changes

- Two nullable columns on the audit trail: `ruleDigest` (64-character hex) and `ruleVersion` (the schema version the rule belonged to).
- `ReadHistoryService::registerAuditRead()` takes the rule that granted the read from the permission check and writes its digest and the schema version on the `read` entry.
- A read that no rule mediated (an administrator, the object's owner, a schema without read rules) writes `ruleDigest: null`.
- The digest is computed in one place, so the same rule gives the same digest on every read path that writes a read entry.
- Both columns enter the canonical JSON of the hash chain. Entries written before the columns existed verify as before.

## Out of scope

- One aggregated audit entry per list or search query. List reads are not logged per row today, and how a list is audited is its own decision.
- A `read-denied` entry for a refused read.
- Field-level rules. The digest names the row-level rule only.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | hist-read-rule-digest | See which access rule let someone view a record, in the record's read log | no |

## Risks

- Adding fields to the canonical JSON can break verification of older entries. The spec requires an absent field to be left out of the canonical JSON, not written as `null`, so a pre-change entry hashes exactly as it did.
- Resolving which rule matched costs a lookup on every read. The permission check already evaluates the rules in order, so it returns the matching rule instead of evaluating twice.
