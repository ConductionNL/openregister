## ADDED Requirements

### Requirement: A property read rule holds on every route that returns its value

When a property read rule withholds a property from a caller, the system SHALL
NOT return that property's value to the caller through any copy of it: not in the
object body, not in `@self.relations`, not in the `@self` name, description,
summary or image the schema copies from it, and not as facet buckets, whether the
facet is discovered from `facetable` or requested explicitly with `_facets`.
Callers the rule admits SHALL see every copy as before.

#### Scenario: The relations mirror

- **GIVEN** a property `contactPerson` ruled `authorization.read: ["authenticated"]` whose uuid value is mirrored in `@self.relations`
- **WHEN** an anonymous caller reads the object, alone or in a list
- **THEN** neither the body nor `@self.relations` contains the value

#### Scenario: A metadata copy

- **GIVEN** a schema whose `objectDescriptionField` is a property ruled `authenticated`
- **WHEN** an anonymous caller reads the object
- **THEN** `@self.description` does not carry the property's value

#### Scenario: An explicit facet

- **WHEN** an anonymous caller asks `_facets[contactPerson][type]=terms` on that schema
- **THEN** the response has no `contactPerson` facet and no query reads the column

#### Scenario: A caller the rule admits

- **WHEN** a signed-in caller reads the same object
- **THEN** `@self.relations` and `@self.description` carry the values
