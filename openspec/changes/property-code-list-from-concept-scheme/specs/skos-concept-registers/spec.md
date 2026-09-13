# skos-concept-registers

## ADDED Requirements

### Requirement: A property can take its values from a concept scheme

A string or string-array property MAY declare `x-openregister-concepts`
with `scheme`, `store` (`uri` or `notation`) and `allowDeprecated`. Schema
save SHALL refuse it beside `enum`. On object save the value SHALL be a
concept of the scheme in the declared form, a deprecated concept SHALL be
refused unless allowed, and the error SHALL name the scheme.

#### Scenario: a value outside the scheme is refused

- **GIVEN** a property with `x-openregister-concepts: {scheme: "https://example.org/themes"}` and a save with a URI the scheme does not hold
- **WHEN** the object is saved
- **THEN** the response is 422 naming the property and the scheme
- @e2e exclude {validation, covered by ValidationHandler unit tests}

#### Scenario: a new concept is accepted without a schema save

- **GIVEN** the same property and a concept added to the scheme a minute ago
- **WHEN** an object is saved with it
- **THEN** the save succeeds
- @e2e exclude {live read, covered by unit tests with a scheme fixture}

### Requirement: Options and labels are served for the form and the reader

The schema read SHALL expose, for such a property, a bounded list of
options `{value, label, notation}` with labels in the negotiated language
and a count, and object reads SHALL carry the concept label under
`@self.labels.<property>` when `_extend` asks for it. Facets on the property
SHALL group by concept and show labels.

#### Scenario: a form renders a select without knowing SKOS

- **GIVEN** a scheme of twelve concepts with Dutch and English labels
- **WHEN** the schema is read with `Accept-Language: nl`
- **THEN** the property carries twelve options with Dutch labels
- @e2e exclude {proposal only; task 3.1 adds tests/e2e/ci/concept-code-list.spec.ts when the form consumes options}
