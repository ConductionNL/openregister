# objects-crud

## ADDED Requirements

### Requirement: A record can be validated without saving it

OpenRegister SHALL offer `validateObject()` on `ObjectServiceInterface` and
`POST /api/objects/{register}/{schema}/validate`, which check a sample record,
as a create or as an update of a named object, against the schema and every
save rule that decides acceptance, and answer whether it is valid with one
error per failing property and the rule that failed. The call SHALL write
nothing and dispatch no object event. Validate SHALL call a sample invalid
exactly when a save of it would be refused for a rule.

#### Scenario: a maker's release test checks a sample record

- **GIVEN** a maker with `create` rights on schema `aanvraag`, whose property `gemeente` only allows values from a coded list
- **WHEN** buildiq calls `POST /api/objects/{register}/aanvraag/validate` with a sample whose `gemeente` is "Atlantis"
- **THEN** the answer is 200 with `valid` false and one error on `gemeente` with rule `coded-value`
- **AND** no `aanvraag` object, audit entry or event was created
- @e2e exclude {specified only; task 3.1 adds the Newman case}

#### Scenario: validate and save agree

- **GIVEN** the same sample
- **WHEN** it is saved through `POST /api/objects/{register}/aanvraag`
- **THEN** the save is refused with 422 naming `gemeente`, the property validate named
- @e2e exclude {specified only; task 3.1 adds the Newman case}
