# example-sets Delta

## ADDED Requirements

### Requirement: A fragment may give a task its engine key and organisation

A fragment's task MAY declare `taskKey` and `organisation`. Install SHALL write the task with `taskKey` as its engine key when declared, and with the set's own key otherwise. It SHALL write `organisation` as the task's organisation.

#### Scenario: A tenant's onboarding steps
- **GIVEN** a fragment declares seven tasks of kind `dossiq.onboarding` on tenant Waterland, keyed `contract` … `go_live`, five of them completed
- **WHEN** the set is installed
- **THEN** dossiq's onboarding progress for Waterland SHALL read 5 of 7
