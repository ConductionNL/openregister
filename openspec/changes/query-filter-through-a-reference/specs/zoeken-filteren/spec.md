# zoeken-filteren

## ADDED Requirements

### Requirement: The object query filters on a field of a referenced object

The object query SHALL accept `_ref[<property>][<field>]` with a value, or with an operator and a value, where `<property>` is a reference property of the queried schema. It SHALL return the objects whose `<property>` points to at least one object of the referenced schema that matches every condition of the block. The conditions SHALL be evaluated only over referenced objects the caller may read. A property that is not a reference, a field the referenced schema does not have, an unknown operator or a chain of more than one reference SHALL be refused with HTTP 400 naming it. The filter SHALL be applied inside the query, so totals and paging are correct.

#### Scenario: requests by the applicant's place

- **GIVEN** requests whose property `aanvrager` references a person, and persons with `woonplaats`
- **WHEN** a client lists requests with `_ref[aanvrager][woonplaats]=Zuiddrecht`
- **THEN** exactly the requests whose applicant lives in Zuiddrecht are returned, and the total counts them
- @e2e exclude {query feature; task 3.1 adds a Newman case}

#### Scenario: a hidden referenced object does not answer

- **GIVEN** a request whose applicant the caller may not read, living in Zuiddrecht
- **WHEN** the caller lists requests with `_ref[aanvrager][woonplaats]=Zuiddrecht`
- **THEN** that request is not returned
- @e2e exclude {authorisation; covered by an integration test with a real RBAC fixture}

#### Scenario: a misspelt field is refused

- **GIVEN** the same schemas
- **WHEN** a client lists requests with `_ref[aanvrager][woonplaatz]=Zuiddrecht`
- **THEN** the response is HTTP 400 naming `woonplaatz`
- @e2e exclude {parser; covered by unit tests}
