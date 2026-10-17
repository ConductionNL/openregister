## ADDED Requirements

### Requirement: Objects can be filtered by a field of the row they reference

The object search SHALL accept `_referenced[<schema>][<property>][<field>]`
blocks, with the conditions, operators and numbered blocks of `_related`. An
object SHALL match a block when its own `<property>` holds the uuid of a
non-deleted row of `<schema>` that the caller may read and that satisfies
every condition of the block. The referenced schema's own read access
predicate SHALL be part of the subquery, so a row the caller may not read
never decides which objects they see. An unusable block or an unknown schema
SHALL be refused, never ignored.

#### Scenario: beschikkingen of one case through their application
- GIVEN beschikking B1 whose `subsidieaanvraag` is A1, and A1's `case` is C1
- AND beschikking B2 whose `subsidieaanvraag` is A2, and A2's `case` is C2
- WHEN beschikkingen are searched with `_referenced[subsidieAanvraag][subsidieaanvraag][case]=C1`
- THEN only B1 is returned

#### Scenario: an application the caller may not read decides nothing
- GIVEN A1 is not readable for the caller under its schema's authorization
- WHEN the same search runs
- THEN B1 is not returned through A1

### Requirement: A camel-case property reads its snake-case column

In a related or referenced block, a property or condition field written as the
schema spells it (`propertyDefinition`) SHALL be read from its magic-table
column (`property_definition`).
