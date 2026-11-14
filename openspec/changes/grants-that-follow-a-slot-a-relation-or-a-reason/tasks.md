# Tasks: grants-that-follow-a-slot-a-relation-or-a-reason

> **What this change has delivered so far, and what it has not.** The change is
> size L and covers four grant kinds, one verb and one flag. Delivered: the
> `assign` verb in the governed vocabulary (3.1, 3.3) and the grant that does
> not travel (5.1, 5.2, 6.4), which is the half `rbac-inherits-to-children`
> left open and the one an access review cannot finish without, and the grant
> to a slot (1.1 to 1.4, and the slot half of 6.1).
>
> NOT delivered, and each for a reason rather than for lack of time:
> the relationship grant (2.x) and break glass (4.x, 6.3, 6.5), below. The
> notes on the other items record how they were delivered.
>
> - **The slot grant (1.x), delivered**: in the third pass, now that
>   `party-roles-beyond-the-requester` is archived and the slot exists. A
>   per-object grant names a party role in the object's own `_authorization`
>   (`{"partyRole": "behandelaar"}` in an action's rule list), and
>   `PermissionHandler` admits whoever occupies that role on the object at the
>   moment of evaluation (`PartyRoleSlotResolver`, over the existing link rows:
>   a user link, inside its period). Nothing is copied onto a person. Left
>   deliberately narrow, and each narrowing fails closed: a slot written on the
>   schema or register is not honoured (the schema validator refuses it and the
>   list emitters filter on the schema block, so honouring it on a read alone
>   would let a read and a list disagree); a slot does not reopen a `private`
>   object; a party without an account or a CardDAV contact holds the role but
>   never the access; a slot never travels to descendants. List visibility
>   through a slot, a schema-level slot and an object-level `/api/scopes`
>   read are follow-ups.
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
> - **3.2, the reassignment gate**: delivered in the second pass for
>   openregister's own path, `ObjectOwnershipService::assign`. dossiq's
>   `CaseAccessGuard`-shaped coordinator check is dossiq's to move.
> - **Since then**: `party-roles-beyond-the-requester` has been archived, and
>   the slot grant of 1.x is built on it (third pass). The relationship grant
>   (2.x) stays blocked: the relationship validation and period reading of
>   `relations-that-travel-and-what-they-expose` is not built in openregister,
>   and the party relationship schema is pipelinq's. Break glass (4.x, 6.3,
>   6.5) stays for a change of its own.

## 1. A grant to a slot

- [x] 1.1 A per-object grant may name a party role on the record instead of a principal. (`{"partyRole": "<role>"}` in an action's rule list of the object's own `_authorization`; optional `match` read as a user override's is. Honoured on the object's own block only.)
- [x] 1.2 Resolution reads the current occupant at evaluation time; an empty slot grants nothing. (`PartyRoleSlotResolver::rolesHeldBy()`, read on every question, never memoised; a missing slot, a vacant one, a malformed role value, an occupant outside the link's period, an unreadable link table or no resolver all grant nothing. Asked after every principal and after the deny pass, so it only adds where the rules said no and a deny still wins.)
- [x] 1.3 Replacing the occupant moves the access with no grant edited, and is audited. (`SlotOccupantAuditListener` on the person-link events writes `party.slot-occupant-changed` through `AuditTrailMapper::recordObjectEvent`, the existing chained path, when a user's link on an object whose own block names a slot is added, changed or removed; the trail's structure is untouched.)
- [x] 1.4 The scopes read names the slot as the source of the grant. (`PermissionHandler::provenanceFor()` with an object: `source: slot`, `slot: <role>`, `declaredAt: object`, beside the existing provenance; other records keep their shape. `/api/scopes` itself is schema-level and passes no object, so it cannot name a slot yet.)

## 2. A grant from a relationship

- [ ] 2.1 A party relationship type declares the verbs it grants on the other party's records.
- [ ] 2.2 The grant holds only for the period of the relationship record.
- [ ] 2.3 An ended or future-dated relationship grants nothing.
- [ ] 2.4 The scopes read names the relationship as the source.

## 3. The assign verb

- [x] 3.1 `assign` enters the governed verb vocabulary and the published catalogue.
- [x] 3.2 The reassignment path is gated on `assign`, not on the administrator check. (`ObjectOwnershipService::assign`: an administrator still may; anybody else only where the record's cascaded authorization block declares `assign` and admits them, and on every child of a cascade. An undeclared `assign` stays with administrators, so an upgrade grants nobody a new right. The bulk `reassign` stays an administrator action. dossiq's own coordinator check moves with dossiq.)
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

- [ ] 6.1 Unit tests for the slot resolution, the empty slot, the occupant change and the relationship period. (Slot half done: `tests/Unit/Service/Rbac/SlotGrantResolutionTest.php`, `tests/Unit/Service/Rbac/PartyRoleSlotResolverTest.php`, `tests/Unit/Listener/SlotOccupantAuditListenerTest.php`. The relationship period waits on 2.x.)
- [x] 6.2 Unit tests for `assign` granted alone and for the reassignment refusal without it. (tests/Unit/Service/Rbac/ObjectOwnershipAssignVerbTest.php)
- [ ] 6.3 Unit tests with a clock fixture for the break-glass expiry and the extension limit.
- [x] 6.4 Unit tests for the not-inheritable grant against the ancestor resolution.
- [ ] 6.5 An e2e over break glass taken with a reason, used, and expired.
- [x] 6.6 Deduplication check (ADR-012) recorded in the PR body.
