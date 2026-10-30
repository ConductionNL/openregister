# example-sets Delta

## ADDED Requirements

### Requirement: A fragment may give a resident a portal task

A fragment's task MAY declare `performerType` with the value `external`, with an `assignee` of the form `party:<reference>`, placeholders allowed. Such a task MAY declare `uploadRules` with the keys `required`, `maxFiles`, `maxSizeBytes` and `acceptedTypes`.

The check SHALL refuse:
- an external task whose assignee is not a `party:` reference;
- a `completedBy` on an external task;
- `uploadRules` on a task that is not external;
- an `uploadRules` key outside that list.

An external assignee SHALL NOT be checked against the set's accounts. Install SHALL write `uploadRules` as the task's `metadata.upload` and SHALL write the task as the fragment's app.

#### Scenario: Sanne is asked for a copy of her ID
- **GIVEN** portaliq's fragment declares an external task on Sanne's parking permit case, with assignee `party:{{cast:sanne.uid}}` and `uploadRules: {required: true, maxFiles: 2}`
- **WHEN** the set is installed
- **THEN** the task SHALL be in the engine with performer type `external`, assignee `party:sanne.devries` and `metadata.upload.maxFiles` 2, so the portal lists it as Sanne's open task

#### Scenario: A misaddressed external task is refused
- **GIVEN** an external task assigned to `sanne.devries` without the `party:` prefix
- **WHEN** the set is checked
- **THEN** the check SHALL refuse it and nothing SHALL be written
