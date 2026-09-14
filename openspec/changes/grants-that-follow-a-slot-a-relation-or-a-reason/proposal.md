---
kind: code
depends_on: [permission-provenance-and-deny, rbac-inherits-to-children, relations-that-travel-and-what-they-expose]
---

# Proposal: grants-that-follow-a-slot-a-relation-or-a-reason

## Summary

Access is granted to people. Four of the five things a municipality actually
wants to grant are not people. The behandelaar of this case, whoever that is
this week. The gemachtigde of this person, because of the relationship. The
right to hand work to somebody, which is not read and not write. And the
right to let yourself in, time-boxed and with a reason, when somebody is at
the counter and the person who could grant it is on holiday. The fifth is
the review that has to finish: which grants inherit, and which deliberately
do not.

## The rows this closes

### Row 13.27, emergency self-granted access, time-boxed, with a reason, enabled per case type, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 13.20`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
  in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **13.27** | 13.20 | Emergency self-granted access, time-boxed, with a reason, enabled per case type | no | unread | corpus 13.4 |
```

- ledger note, verbatim:

> Row 13.4 grants access, by somebody else, with no reason and no per-case-type switch. sociaalDomeinAuditLog.authorisationGround is declared in the register and has zero readers, so there is no time box, no reason and no access level per case type.

### Row 13.30, named role slot on the case, where the right follows whoever occupies it, rated `partial`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 13.23`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **13.30** | 13.23 | Named role slot on the case, where the right follows whoever occupies it | partial | unread |  |
```

- ledger note, verbatim:

> Row 5.1 puts parties on a case with roles. Rights are granted to people and groups, not to the slot, so replacing the occupant means editing every grant.

### Row 13.34, party relationship granting dated access to the other party's record, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 13.27`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **13.34** | 13.27 | Party relationship granting dated access to the other party's record | no | unread |  |
```

- ledger note, verbatim:

> A gemachtigde, a guardian or a parent company should see the record they are entitled to see because of the relationship. Access is granted per case and per user instead.

### Row 13.40, permission to hand work to someone, distinct from read and write, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 13.33`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **13.40** | 13.33 | Permission to hand work to someone, distinct from read and write | no | unread |  |
```

- ledger note, verbatim:

> The case schema says of the team that it is assignment, not permission. Reassignment is gated by a coordinator check that resolves to isAdmin, so handing work over is an admin right rather than an axis of its own beside read and write.

### Row 13.41, row-level access by group, with the inheritance it came from declared, rated `partial`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 13.34`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **13.41** | 13.34 | Row-level access by group, with the inheritance it came from declared | partial | unread | corpus 13.2 |
```

- ledger note, verbatim:

> Rows 13.3 and 13.6 are both partial. Nothing says where a permission came from, and nothing marks one as not inheritable, so an access review cannot be finished.

## What the competitor evidence is

All five rows are among the 98 promoted under decision D1, and the corpus
states, verbatim, what its competitor columns hold:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is
> a reading of a product somebody opened, and filling these cells with it
> would fabricate thirty readings per row.

No competitor claim is made here. The register's cross-references are
`corpus 13.4` for 13.27 and `corpus 13.2` for 13.41; the other three carry
none.

## ADRs

- ADR-010 (openregister, permission verbs): a uniform core verb set with
  governed per-schema extensions. Handing work to somebody is the case the
  ADR anticipates: a concept core Nextcloud does not have, which must enter
  the governed vocabulary rather than become a parallel model.
- ADR-005 (security): every one of these grants is evaluated on the server
  for the identity making the write. A break-glass grant is still a grant
  and is still bounded.
- ADR-003 (openregister, immutable hash-chained audit trail): the reason for
  a self-granted access, and the fact that it was used, go on the chained
  trail where they cannot be edited afterwards.
- ADR-031 (schema-declarative business logic): whether break glass is
  available at all, for which access level, is declared per schema rather
  than switched on globally in code.
- ADR-022 (apps consume OpenRegister abstractions): one access model. A leaf
  app that builds a second one is how two enforcement paths disagree.

## What openregister builds

- A grant to a slot, not to a person. A per-object grant may name a party
  role on the record rather than a principal. Whoever holds that role holds
  the grant, and replacing the occupant moves the access with no grant
  edited. When the slot is empty, nobody holds it.
- A grant that follows a party relationship. A relationship type may declare
  that it grants named verbs on the other party's records, for the period
  the relationship runs. A gemachtigde reads what they are entitled to read
  because the relationship says so, and stops when it ends.
- Handing work as its own verb. `assign` enters the governed verb
  vocabulary, distinct from read and from update, published in the
  permission catalogue like every other verb, and the reassignment path is
  gated on it rather than on an administrator check.
- Break glass, declared and bounded. A schema declares whether emergency
  self-granted access is available, which verbs it may grant and for how
  long. Taking it requires a reason, grants exactly the declared verbs for
  the declared period, expires by itself, and is announced to a declared
  recipient at the moment it is taken rather than in a report next month.
- A grant that says where it came from and whether it travels. Every
  effective permission names its source, which
  `permission-provenance-and-deny` and `rbac-inherits-to-children` already
  answer, and a grant may be marked as not inheritable so it stops at the
  object it was written on. An access review can then be finished, because
  every grant is either explained or explicitly local.

## What dossiq consumes

dossiq declares the role slots on its case types and which of them carry
which verbs, declares which case types allow break glass and at what level,
and renders the reason dialogue and the gemachtigde's view. The register
names no dossiq slug for these five rows and none exists on dossiq
`development`, so the consuming halves are to be specified in dossiq.
Beside dossiq: humaniq for the relationship grants, keepiq, portaliq,
decidiq and integriq for the verb and the provenance.

## Size

L. Four grant kinds, one verb and one flag, all inside the resolution the
two changes this depends on are already rebuilding.

## The specs this extends

- `rbac-scopes`, through `permission-provenance-and-deny` (REQ-PPD-001 the
  published verb catalogue, REQ-PPD-004 the provenance of an answer,
  REQ-PPD-008 grants derived, scoped and given an end) and
  `rbac-inherits-to-children` (REQ-RIC-002 inheritance, REQ-RIC-004 where an
  inherited grant came from).
- `specs/audit-trail-immutable`, for the reason and the use of a break-glass
  grant.
- `relations-that-travel-and-what-they-expose`, whose party relationship
  (REQ-RTE-003) is the record a relationship grant hangs on, and
  `party-roles-beyond-the-requester` (REQ-PRM-001), whose typed role on an
  object is the slot.
