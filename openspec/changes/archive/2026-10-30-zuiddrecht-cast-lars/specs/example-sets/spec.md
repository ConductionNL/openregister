# example-sets Delta

## ADDED Requirements

### Requirement: The Zuiddrecht cast has Lars de Koning of Mobiliteit en verkeer

The Zuiddrecht cast SHALL hold the group `mobiliteit-en-verkeer`, displayed as "Mobiliteit en verkeer". It SHALL also hold the employee Lars de Koning, account `lars.dekoning`, as a member of that group.

#### Scenario: A fragment names Lars
- **GIVEN** a fragment assigns a task to `{{cast:lars.uid}}`
- **WHEN** the set is installed
- **THEN** the task SHALL belong to the account `lars.dekoning`
