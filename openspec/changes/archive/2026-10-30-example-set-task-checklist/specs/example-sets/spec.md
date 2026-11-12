# example-sets Delta

## ADDED Requirements

### Requirement: A fragment may give a task its checklist

A fragment's task MAY declare `checklist`, a list of items with a non-empty `id` and `label`, an optional `description` and an optional `checked`. Install SHALL write the checklist to the engine as declared, so a ticked item is ticked on the task page.

The check SHALL refuse:
- a checklist that is not a list;
- an item without an id or a label;
- an item id used twice in one checklist;
- a `checked` that is not true or false.

#### Scenario: A task with two of four items done
- **GIVEN** dossiq's fragment declares a task with a checklist of four items, the first two `checked: true`
- **WHEN** the set is installed
- **THEN** the task SHALL be in the engine with those four items, and its page SHALL show two of four done

#### Scenario: A malformed checklist is refused
- **GIVEN** a task whose checklist item has no label
- **WHEN** the set is checked
- **THEN** the check SHALL refuse it and nothing SHALL be written
