# example-sets Delta: example-set-refresh-skips-unchanged

## ADDED Requirements

### Requirement: A refresh writes only an object whose values differ

On `--refresh` a kept object SHALL be written only when the update the refresh would send, after the schema's write rules, differs from the stored row in at least one field. An object whose every sent field already holds the value it would be sent SHALL NOT be updated, so no audit-trail row and no background job is made for it. The comparison SHALL be made against the stored row, so a value changed on the instance since an earlier refresh SHALL be written back. The report SHALL count such an object as `unchanged` for its type; an object that is written SHALL be counted as `refreshed` as before.

#### Scenario: A second refresh on the same day
- **GIVEN** a set refreshed on 14 October
- **WHEN** the set is refreshed again on 14 October and nothing on the instance changed
- **THEN** no kept object SHALL be updated, and every kept object SHALL be counted as `unchanged`

#### Scenario: A declared date moves on a later day
- **GIVEN** a kept object with `reported` declared as `{{date:-1}}`, installed on 13 October
- **WHEN** the set is refreshed on 16 October
- **THEN** the object SHALL be written with `reported` 15 October and counted as `refreshed`

#### Scenario: A value changed on the instance
- **GIVEN** a kept object whose `title` an editor changed after the last refresh
- **WHEN** the set is refreshed on the same day
- **THEN** the object SHALL be written with its declared `title`
