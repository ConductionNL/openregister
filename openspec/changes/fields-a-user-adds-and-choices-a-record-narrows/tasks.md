# Tasks: fields-a-user-adds-and-choices-a-record-narrows

## 1. A property with a scope

- [ ] 1.1 A `scope` attribute in the published property vocabulary, naming a unit or a team.
- [ ] 1.2 Adding a scoped property is a declared action gated by a group, not by the admin flag.
- [ ] 1.3 A scoped property is returned, validated and writable only within its scope.
- [ ] 1.4 A scoped property is searchable, facetable, groupable and exportable like a schema property.

## 2. Keeping the schema honest

- [ ] 2.1 An administered ceiling on scoped properties per scope, refusing the one above it.
- [ ] 2.2 A report of scoped properties unused for a declared period.
- [ ] 2.3 Promotion of a scoped property to the schema as a recorded act, keeping stored values.

## 3. A reference that narrows

- [ ] 3.1 A filter annotation on a reference property whose operands are properties of the record being edited.
- [ ] 3.2 The options read applies the filter, paged and access-scoped.
- [ ] 3.3 A write of a value outside the filter is refused on the server, naming the filter.
- [ ] 3.4 An unresolved operand returns no options and names the property it needs.
- [ ] 3.5 Schema save refuses a filter naming a property the schema or the far schema does not declare.

## 4. Tests

- [ ] 4.1 Unit tests for the scope on read and write, the ceiling, the promotion and the retained values.
- [ ] 4.2 Unit tests for the filtered options, the refused out-of-filter write and the unresolved operand.
- [ ] 4.3 An e2e over a reference offering only the contacts of the chosen organisation.
- [ ] 4.4 Deduplication check (ADR-012) recorded in the PR body.
