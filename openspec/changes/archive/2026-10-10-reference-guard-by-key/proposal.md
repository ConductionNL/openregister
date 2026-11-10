# Proposal: reference guard by key

## Why

Hydra decision 182 (procedures are configuration) names capability C25, reference guard: "refuses
the delete of a row that another row cites", configured as a schema property. OpenRegister's
`onDelete: RESTRICT` already refuses a delete for a `$ref` relation, but only when the citation is
the cited row's uuid, and a citation it cannot read lets the delete through. Apps therefore keep
their own delete listeners for rows cited by a code, for example dossiq's
`WooRefusalGroundDeleteGuard`, which reads every assessment and decision to look for a ground code.

## What changes

- `referenceSchema` and `referenceKey` on a property (or its `items`): a reference by key, honoured
  with `onDelete: RESTRICT`, searched in the citing magic table by the key's value.
- Fail closed for key references: an unreadable citation blocks with `reason: unverifiable`; a
  citing table that does not exist yet counts as no citation.
- Requirement 16 in `referential-integrity`.

uuid references behave exactly as before.

## Impact

`ReferentialIntegrityService` and `PropertyValidatorHandler` (two vocabulary keys). No migration, no API change; the `DELETION_BLOCKED` response
gains an optional `reason` on a blocker.
