---
kind: code
---

# Proposal: sensitive-field-reveal-audit

## Summary

Record when a protected field is shown to someone. Field-level security
hides a property from users outside its group; the `row-field-level-security`
spec says of its own audit that decisions are logged "at debug level via
`LoggerInterface` but ... not integrated with Nextcloud's audit log". The
register puts row 5.6 on openregister with a dossiq slug, and its
`dossiq_half` reads "declare the BSN and gdprClassification fields with an
extra group and audit the reveal (A18)". The declaration exists; the reveal
audit does not. This change adds it: a property may declare
`authorization.audit: true`, and every time its value reaches a user, an
audit entry says so.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 5.6 | Sensitive personal data behind an extra permission | partial | S |

The register marks the row covered by `row-field-level-security` under the
slug `sensitive-fields-declared (dossiq)`. The coverage check for this
programme confirmed the group half and found the reveal-audit half absent,
so this change carries that half; the dossiq lane keeps the declaration.

## Why

The register's note: "`register.d/50-sociaal-domein.json#gdprClassification.accessRestriction`,
`lib/Service/CitizenLookupGuard.php`, `sociaalDomeinAuditLog`". The best
competitor, verbatim from the `best` column: "xxllnc Zaken:
`backend/zaken/src/zsnl_domains/case_management/entities/person_sensitive_data.py`
(`_round2/compare/M1-functionality.md`)".

The VNG Logging Verwerkingen shape (`processing-activity-register`) logs
reads of personal data. A BSN shown on a screen is such a read, and the one
place that knows the field was shown is the property RBAC filter.

## What changes

- A property `authorization` block accepts `audit: true`.
- When `PropertyRbacHandler::filterReadableProperties()` leaves such a
  property in a response, the system writes one audit entry
  `property.revealed` per (user, object, property, request) on the object's
  hash chain, with the request path and the caller's client as context.
  Stripped properties write nothing.
- List reads count: a list that reveals a BSN on forty rows writes forty
  entries, batched in one insert.
- Trusted internal reads (system context, exports that the retention
  pipeline runs) write one entry per run naming the process, not one per
  row.
- The audit page's kind filter gains `reveal`, and the processing-activity
  log (`processing-activity-register`) reads the same rows as read events.
- A schema declaring `audit: true` on a property without a `read`
  authorization rule is refused at schema save: auditing a public field is a
  mistake.

## Consumers

- dossiq: declare the BSN and `gdprClassification` fields with an extra
  group and `audit: true`. Specified in dossiq by the dossiq lane under
  `sensitive-fields-declared`.
- humaniq (salary, medical notes), keepiq (secret values), zaakafhandelapp.

## ADRs

- openregister ADR-003: the reveal is an entry on the chain.
- ADR-047 (openregister owns the AVG and DSAR workflow): the read log is
  the platform's.
- ADR-031: declared on the property.
- openregister ADR-009: batched inserts on list reads.

## Impact

- Extends: `row-field-level-security` requirements "Schemas MUST support
  field-level security via property authorization blocks" and "Security
  rules MUST be auditable for compliance".
- Affected code: `PropertyRbacHandler`, `RenderObject` (collect reveals per
  request), the audit writer (`property.revealed`, batched), the schema
  validator, the audit leaf filter.
- Size: S.
