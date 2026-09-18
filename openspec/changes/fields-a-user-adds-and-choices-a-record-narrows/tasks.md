# Tasks: fields-a-user-adds-and-choices-a-record-narrows

## 1. A property with a scope

- [ ] 1.1 A `scope` attribute in the published property vocabulary, naming a unit or a team.
  - 🔴 **DELIBERATELY NOT SHIPPED ON ITS OWN, 2026-09-18.** Publishing `scope`
    without 1.3 would be an inert declaration, and this one is inert in the
    dangerous direction: a schema author writes `scope: team-a`, the key
    validates, the vocabulary publishes it, and the field is readable by
    everybody. They would believe the field is team-scoped precisely because
    the platform accepted the word.
  - That is the same defect as a widget declaring roles nothing reads
    (dossiq#2947) and as a reporter with no caller (openregister#3896). The
    difference is that those were visible as "nothing happened"; this one looks
    like it worked.
  - So 1.1 lands WITH 1.3, not before it. The read filter, the write refusal and
    the published key are one change, and section 2's ceiling and promotion sit
    on top of them.
- [ ] 1.2 Adding a scoped property is a declared action gated by a group, not by the admin flag.
- [ ] 1.3 A scoped property is returned, validated and writable only within its scope.
  - The enforcement 1.1 must not ship without. It touches the object READ path,
    which is where a scoped property has to disappear for a principal outside
    the scope, and that is the part no unit test on a fixture can settle.
- [ ] 1.4 A scoped property is searchable, facetable, groupable and exportable like a schema property.

## 2. Keeping the schema honest

- [ ] 2.1 An administered ceiling on scoped properties per scope, refusing the one above it.
- [ ] 2.2 A report of scoped properties unused for a declared period.
- [ ] 2.3 Promotion of a scoped property to the schema as a recorded act, keeping stored values.

## 3. A reference that narrows

- [x] 3.1 A filter annotation on a reference property whose operands are properties of the record being edited.
  - `x-openregister-reference-filter` and `ReferenceFilterDeclaration`, checked
    at schema save from `PropertyValidatorHandler::validateProperty()` and
    throwing in the `PropertyVocabularyException` family, so every schema-save
    path answers it as a 422 naming the property.
  - The operator set is deliberately three: `eq`, `neq`, `in`. Every one is a
    comparison the objects API already answers, so a filter cannot declare
    something the options read would have to emulate in PHP over an unbounded
    set.
- [ ] 3.2 The options read applies the filter, paged and access-scoped.
  - STILL OPEN, and it needs a surface that does not exist: there is no
    reference-options endpoint. `/api/vocabulary/options` is the CONCEPT one.
    The resolver 3.4 built is the half that endpoint will call, so the rule is
    written once rather than twice.
- [x] 3.3 A write of a value outside the filter is refused on the server, naming the filter.
  - `SaveObject::assertReferenceMatchesFilter()`, called from
    `validateReferences()` right after the existence check, throwing the
    existing `ReferenceValidationException` so every 422 handler already routes
    it. No new exception family and no controller change.
  - IT CALLS THE SAME `resolve()` the options read will. One reader, one
    resolver; this method only compares. A picker that offers one set and a
    save path that accepts another is two evaluators of one rule.
  - AN UNRESOLVED OPERAND REFUSES rather than waving through. The picker would
    have offered nothing, so no value can be inside the filter. Waving it
    through would make the server accept precisely the writes the form exists
    to prevent.
  - ANYTHING THE COMPARISON DOES NOT RECOGNISE REFUSES: an unknown operator, a
    missing value on the referenced object, an empty `in` list. Accepting is the
    direction that discloses.
  - Costs nothing for a property declaring no filter: the reader returns null
    before any read is made.
- [x] 3.4 An unresolved operand returns no options and names the property it needs.
  - `ReferenceFilterDeclaration::resolve()` answers a filter OR a `needs`, never
    both and never a partial filter. ONE unresolved condition drops the WHOLE
    filter, because half a filter is wider than the filter and wider is the
    direction that discloses: a picker meant to show one organisation's contacts
    would show every organisation's.
  - Every empty shape is treated as unresolved: null, '' and []. "Not chosen
    yet" arrives as null from one client, as an empty string from a form post
    and as an empty array from a multi-select, and a resolver that only knew
    null would open the picker on the other two.
- [x] 3.5 Schema save refuses a filter naming a property the schema or the far schema does not declare.
  - `assertOperandsExist()`, and the message names WHICH of the two schemas is
    missing the property; without that an author checks the wrong one first
    every time. Called where both schemas are in hand, not from
    `validateProperty()`, which sees one property.

## 4. Tests

- [ ] 4.1 Unit tests for the scope on read and write, the ceiling, the promotion and the retained values.
- [ ] 4.2 Unit tests for the filtered options, the refused out-of-filter write and the unresolved operand.
- [ ] 4.3 An e2e over a reference offering only the contacts of the chosen organisation.
- [ ] 4.4 Deduplication check (ADR-012) recorded in the PR body.
