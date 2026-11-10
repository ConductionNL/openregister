## ADDED Requirements

### Requirement: A fragment flow may carry finished runs

A `flows` entry MAY declare `runs`, each a finished run (`status` one of completed, stopped, dead_letter, failed) on a `subject` object uuid, with optional `subjectRegister`, `subjectSchema`, `trigger`, `triggeredBy`, `error`, `created` and `updated`. The set SHALL store each run for the flow in the flow's organisation, and SHALL delete the runs with the flow on remove. A run that is not finished, has no subject or carries another field SHALL be refused at check time.

#### Scenario: a case's run history
- **GIVEN** a flow entry with a completed and a failed run on a case
- **WHEN** the set is installed
- **THEN** the case's finished runs read both, and removing the set removes them

#### Scenario: a waiting run
- **WHEN** a run declares status `suspended`
- **THEN** the check refuses it and nothing is written
