# Tasks: grants-that-follow-a-slot-a-relation-or-a-reason

> **What this change has delivered so far, and what it has not.** The change is
> size L and covers four grant kinds, one verb and one flag. Delivered: the
> `assign` verb in the governed vocabulary (3.1, 3.3) and the grant that does
> not travel (5.1, 5.2, 6.4), which is the half `rbac-inherits-to-children`
> left open and the one an access review cannot finish without.
>
> NOT delivered, and each for a reason rather than for lack of time:
>
> - **The slot grant (1.x)** hangs on a typed party role on an object, which is
>   `party-roles-beyond-the-requester` REQ-PRM-001. That change is still a
>   proposal on this branch, so there is no slot to grant to. Building a second
>   notion of a role slot here is precisely the parallel model ADR-022 refuses.
> - **The relationship grant (2.x)** hangs on the party relationship record of
>   `relations-that-travel-and-what-they-expose` REQ-RTE-003, unbuilt for the
>   same reason. A grant with no record to hang on and no period to read has
>   nothing to be dated by, and a relationship grant that does not expire is
>   the failure the row is about.
> - **Break glass (4.x)** is its own feature: a schema declaration, a bounded
>   self-granted record, an expiry, a notification at the moment it is taken,
>   and every read under it audited as made under it. It touches the chained
>   audit trail, which is the one structure in this app that cannot be
>   corrected afterwards. It deserves a change of its own rather than the tail
>   of one.
> - **3.2, the reassignment gate.** The verb now exists and is grantable; the
>   path it gates is dossiq's `CaseAccessGuard`-shaped coordinator check, not
>   openregister's, so the consuming half moves with it.

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

- [x] 3.1 `assign` enters the governed verb vocabulary and the published catalogue.
- [ ] 3.2 The reassignment path is gated on `assign`, not on the administrator check.
- [x] 3.3 `assign` is grantable without `update`, and holding `update` does not imply it.

## 4. Break glass

- [ ] 4.1 A schema declares whether emergency self-granted access is available, the verbs it may grant and its maximum duration.
- [ ] 4.2 Taking it requires a reason and grants exactly the declared verbs for the declared period.
- [ ] 4.3 The grant expires by itself and cannot be extended by taking it again beyond an administered limit.
- [ ] 4.4 Taking it notifies a declared recipient at that moment, and writes the reason to the chained audit trail.
- [ ] 4.5 Every read made under the grant is audited as made under it.

## 5. A grant that does not travel

- [x] 5.1 A grant may be marked not inheritable, and is then not resolved for descendants.
- [x] 5.2 The scopes read and the access review report the flag beside the provenance.

## 6. Tests

- [ ] 6.1 Unit tests for the slot resolution, the empty slot, the occupant change and the relationship period.
- [ ] 6.2 Unit tests for `assign` granted alone and for the reassignment refusal without it.
- [ ] 6.3 Unit tests with a clock fixture for the break-glass expiry and the extension limit.
- [x] 6.4 Unit tests for the not-inheritable grant against the ancestor resolution.
- [ ] 6.5 An e2e over break glass taken with a reason, used, and expired.
- [x] 6.6 Deduplication check (ADR-012) recorded in the PR body.
