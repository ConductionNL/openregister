## ADDED Requirements

### Requirement: A property declares what the portal may do with it

A schema property SHALL accept `portal` with one of `none`, `read` or `edit`,
and the property validator SHALL refuse any other value. For a read made on
behalf of a portal subject, OpenRegister SHALL leave out every property whose
`portal` is `none` or absent. For a write made on behalf of a portal subject,
OpenRegister SHALL refuse a change to a property whose `portal` is not `edit`,
naming the property, and SHALL write nothing. Callers without a portal subject
SHALL see no change.

#### Scenario: an internal note stays off the portal

- GIVEN a `case` schema whose `internalNote` has no `portal` key and whose `subject` has `portal: "read"`
- WHEN the portal reads the resident's case on behalf of that resident
- THEN the answer carries `subject` and no `internalNote`

#### Scenario: the resident changes only what they may change

- GIVEN a `case` schema whose `phone` has `portal: "edit"` and `subject` has `portal: "read"`
- WHEN the portal saves a change to `subject` on behalf of the resident
- THEN the write is refused naming `subject`, and the case is unchanged

#### Scenario: a wrong value is refused at save

- GIVEN a schema author setting `portal: "write"` on a property
- WHEN the schema is saved
- THEN the save is refused naming the property and the accepted values
