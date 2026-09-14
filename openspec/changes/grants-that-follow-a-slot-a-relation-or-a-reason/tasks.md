# Tasks: grants-that-follow-a-slot-a-relation-or-a-reason

## 1. A grant to a slot

- [ ] 1.1 A per-object grant may name a party role on the record instead of a principal.
- [ ] 1.2 Resolution reads the current occupant at evaluation time; an empty slot grants nothing.
- [ ] 1.3 Replacing the occupant moves the access with no grant edited, and is audited.
- [ ] 1.4 The scopes read names the slot as the source of the grant.

## 2. A grant from a relationship

- [ ] 2.1 A party relationship type declares the verbs it grants on the other party's records.
- [ ] 2.2 The grant holds only for the period of the relationship record.
- [ ] 2.3 An ended or future-dated relationship grants nothing.
- [ ] 2.4 The scopes read names the relationship as the source.

## 3. The assign verb

- [ ] 3.1 `assign` enters the governed verb vocabulary and the published catalogue.
- [ ] 3.2 The reassignment path is gated on `assign`, not on the administrator check.
- [ ] 3.3 `assign` is grantable without `update`, and holding `update` does not imply it.

## 4. Break glass

- [ ] 4.1 A schema declares whether emergency self-granted access is available, the verbs it may grant and its maximum duration.
- [ ] 4.2 Taking it requires a reason and grants exactly the declared verbs for the declared period.
- [ ] 4.3 The grant expires by itself and cannot be extended by taking it again beyond an administered limit.
- [ ] 4.4 Taking it notifies a declared recipient at that moment, and writes the reason to the chained audit trail.
- [ ] 4.5 Every read made under the grant is audited as made under it.

## 5. A grant that does not travel

- [ ] 5.1 A grant may be marked not inheritable, and is then not resolved for descendants.
- [ ] 5.2 The scopes read and the access review report the flag beside the provenance.

## 6. Tests

- [ ] 6.1 Unit tests for the slot resolution, the empty slot, the occupant change and the relationship period.
- [ ] 6.2 Unit tests for `assign` granted alone and for the reassignment refusal without it.
- [ ] 6.3 Unit tests with a clock fixture for the break-glass expiry and the extension limit.
- [ ] 6.4 Unit tests for the not-inheritable grant against the ancestor resolution.
- [ ] 6.5 An e2e over break glass taken with a reason, used, and expired.
- [ ] 6.6 Deduplication check (ADR-012) recorded in the PR body.
