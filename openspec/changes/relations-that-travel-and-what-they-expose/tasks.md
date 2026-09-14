# Tasks: relations-that-travel-and-what-they-expose

## 1. The affected set

- [ ] 1.1 An affected-set read over the existing bounded walk, filtered to the named relation types.
- [ ] 1.2 Parties reached through the walk are projected into the answer beside the objects.
- [ ] 1.3 A prune list is accepted, and what it cut is reported with its type and its node.
- [ ] 1.4 The answer is evaluated for the caller and names truncation by depth or by cap.

## 2. Party relationships

- [ ] 2.1 A `partyRelationship` schema: two party references, a type, a period, a provenance.
- [ ] 2.2 A relationship type declares the party kind at each end, its label and its reciprocal label.
- [ ] 2.3 Validation refuses a relationship whose ends do not match the declared kinds, and a self-relationship.
- [ ] 2.4 A party read returns its relationships with the label for the reading direction.

## 3. What a link exposes

- [ ] 3.1 `exposes` on a relation type, validated at schema save against the far schema's properties.
- [ ] 3.2 The read path evaluates the exposed set beside field-level security, narrowing only.
- [ ] 3.3 A property outside the set reads as withheld, not as absent.

## 4. Tests

- [ ] 4.1 Unit tests for the type filter, the prune report, the kind validation and the intersection rule.
- [ ] 4.2 An e2e over a cross-domain link showing two fields and withholding the rest.
- [ ] 4.3 Deduplication check (ADR-012) recorded in the PR body.
