# Tasks: relation-types-with-inverses

## 1. Schema

- [x] 1.1 Validate `x-openregister-relation` on `$ref` properties and `x-openregister-relation-types` on the schema; refuse the two error cases.
- [x] 1.2 Resolve `type` to the vocabulary entry on schema read.

## 2. Read path

- [x] 2.1 Label pair on `RelationHandler::getUses()` rows.
- [x] 2.2 Referencing property and inverse label on `getUsedBy()` rows, memoised per referencing schema per request.
- [x] 2.3 The relations leaf renders the labels in both directions.

## 3. Tests

- [x] 3.1 `tests/e2e/ci/relation-types.spec.ts`: link two objects through a typed property, open the far side, read "blocked by".
- [x] 3.2 Unit tests for validation, resolution and both enrichments; Newman for the two endpoints.

## Discovery cluster 61

- [x] C61.1 A split that records a typed relation to the source object and the source entry (D-C61-1).
- [x] C61.2 Declared inheritance on a relation type: classification, confidentiality, responsible principal, once at creation (D-C61-2).
- [x] C61.3 A later change to the parent does not change the child, with a test that asserts it.
- [x] C61.4 An external address as a relation target with a title and a type (D-C61-3).
- [x] C61.5 A reference recorded from prose creating a typed relation on both sides, removed with the text.
- [x] C61.6 A depth-bounded graph read with typed, directed edges, marked when truncated, and its export (D-C61-4).
- [x] C61.7 Tests: the split provenance, the inheritance at creation, the unchanged child, the external relation, the graph bound.
- [ ] C61.8 Hand over to the dossiq lane for `case-merge` and `DeelzaakService`, with candidate ids C-case-core-15, -17, -19, -22, -23, -33 and -38.

## What was built, and what was deliberately left to another lane

**C61.5 is a seam, not a feature here.** `timeline-entries-are-records` owns
resolving an administered pattern out of text and rendering it as a link. This
change owns the row that results: `ObjectRelationService::recordProseReference()`
writes one row per direction under one anchor, and
`withdrawProseReferences()` takes both away when the text goes. Both sides
writing rows would double every mention, so the timeline lane calls these two
methods rather than reaching for the mapper. The same seam is on the API
(`POST .../relation-rows` with a `target` and an `anchor`, and
`DELETE .../relation-references/{anchor}`), because a service with no reachable
caller is a capability nobody can exercise and nobody can test, which looks
exactly like one that was never built.

**The reverse-view SURFACE is not here either.**
`objects-as-the-hinge-between-cases` owns the grouped, summarised view of
everything that references an object. This change owns the relation row and
the two endpoints that carry it, which that surface reads.

**The affected-set walk and the per-link-type exposure are not here.**
`relations-that-travel-and-what-they-expose` declares a dependency on this
change and builds on REQ-RTI-005. The bounded graph read and its export are
here; the recorded prune, the party-to-party relationship type and the
exposed field set are that lane's.

**C61.8 stays open on purpose.** It is a handover to the dossiq lane, not work
in this repo. `case-merge` and `DeelzaakService` live in dossiq, and the
contract they consume is in the PR body of openregister#3746.

**Inheritance is applied at an explicit act, not inside the save pipeline.**
`party-roles-beyond-the-requester` is inside the reference-resolution half of
`SaveObjects.php`, and a second lane editing the same path would collide.
Deriving a child is a decision somebody makes, which is also the honest shape:
an inheritance that happened invisibly during an ordinary write is exactly the
access change nobody remembers authorising (D-C61-2). It is therefore reached
through `POST .../{id}/derive` rather than as a side effect of a `$ref` write.
