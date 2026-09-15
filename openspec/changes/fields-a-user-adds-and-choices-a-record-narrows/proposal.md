---
kind: code
depends_on: [property-vocabulary-published, code-list-lifecycle-and-hierarchy]
---

# Proposal: fields-a-user-adds-and-choices-a-record-narrows

## Summary

Two things a team cannot do to their own records. They cannot add one field
without a change request, because adding a property is an administrator's
act on the schema. And they cannot have a reference field that offers only
the contacts of the organisation already chosen on this case, because the
choices of a reference are the whole schema or nothing. One is about who may
extend a record, the other about what a choice is allowed to depend on.

## The rows this closes

### Row 11.45, custom field added by an ordinary user, still searchable and groupable, rated `partial`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 11.37`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
  in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **11.45** | 11.37 | Custom field added by an ordinary user, still searchable and groupable | partial | unread | corpus 11.3 |
```

- ledger note, verbatim:

> Row 11.3 custom fields per case type is yes, and only an administrator can add one. A team that needs one field waits for a change request.

### Row 11.47, reference field whose choices are a query over the record being edited, rated `no`

- source (ledger `source` field, verbatim): `dossiq#2314, published as 11.39`
- corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
  table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **11.47** | 11.39 | Reference field whose choices are a query over the record being edited | no | unread |  |
```

- ledger note, verbatim:

> Row 11.10 code lists are flat and fixed. A field cannot offer only the contacts of the organisation already chosen on this case.

## What the competitor evidence is

Both rows are among the 98 promoted under decision D1, and the corpus states
what its competitor columns hold, verbatim:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is
> a reading of a product somebody opened, and filling these cells with it
> would fabricate thirty readings per row.

No competitor claim is made here. 11.45 carries the cross-reference `corpus
11.3`, the custom-fields row, and 11.47 carries none.

## ADRs

- ADR-031 (schema-declarative business logic): both are declarations on the
  property, not code. A filtered reference is an annotation, and a
  team-scoped property is a property with a scope.
- ADR-022 (apps consume OpenRegister abstractions): the platform owns the
  property model, so a leaf app does not grow a second one for the fields
  users add.
- ADR-023 (action-level authorization): who may add a scoped property is a
  declared action with a group, not an `isAdmin()` check.
- ADR-005 (security): a filtered reference is evaluated on the server. A
  client that ignores the filter and writes a value outside it is refused,
  so the filter is a rule and not a convenience.
- ADR-009 (openregister, performance invariants): an option query is
  bounded, paged and indexed like any other object query.

## What openregister builds

- A property with a scope. A property may be declared at a scope narrower
  than the schema: an organisational unit or a team. It is a real property,
  so it is validated, searchable, facetable, groupable and exportable like
  any other, and it is visible only within its scope. Who may add one is a
  declared action with a group, so a functional administrator can be given
  it without being given the schema.
- A guard against the schema filling up. A scoped property counts against an
  administered ceiling per scope, and a property nothing has used for a
  declared period is reported as unused, so the register does not accumulate
  four hundred abandoned fields.
- A promotion path. A scoped property that turns out to be needed everywhere
  is promoted to the schema as a recorded act, keeping the values already
  stored. That is the alternative to a change request, not a second class of
  field that can never grow up.
- A reference whose choices are a query. A reference property may declare a
  filter over the referenced schema whose operands are properties of the
  record being edited. The options endpoint returns only the matching
  objects, and a write of a value outside the filter is refused on the
  server with the filter named.
- An honest empty list. When the property the filter depends on has no value
  yet, the options read says so rather than returning everything, because
  offering every contact in the instance is how the wrong one gets chosen.
  A filter naming a property that does not exist is refused at schema save.

## What dossiq consumes

dossiq lets a team add a field to its own case type within the scope, and
declares the dependent references its case types need, such as the contacts
of the chosen organisation. The register names no dossiq slug for either
row and none exists on dossiq `development`, so the consuming half is to be
specified in dossiq. The build plan names `property-definition-management`
as dossiq's half of the case-type property work, and these two rows land
beside it. Beside dossiq: humaniq, pipelinq, keepiq and opencatalogi.

## Size

M. One scope on the property model with its authorization and its ceiling,
one filter annotation with its options query and its server-side refusal.

## The specs this extends

- `specs/runtime-schema-api`, and the change `property-vocabulary-published`
  (REQ-PVP-001, REQ-PVP-002), which publishes the property vocabulary and
  validates a declared property against it. A scope is a new attribute in
  that vocabulary.
- `code-list-lifecycle-and-hierarchy` (REQ-CLH-002), which lets a coded
  property bind its option subset to the value of another property. That is
  the concept-scheme half of the same idea; this change gives the object
  reference the same ability.
- `rules-engine-operability` (REQ-REO-005), whose dependent value table
  administers allowed values as pairs. A query over the record is the case
  that a table of pairs cannot express.
