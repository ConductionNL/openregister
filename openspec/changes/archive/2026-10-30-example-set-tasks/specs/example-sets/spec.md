# example-sets Delta

## ADDED Requirements

### Requirement: A fragment may put tasks on its objects

A fragment MAY declare `tasks`, each entry a `key` unique in the fragment and `data` with the task's fields: `title`,
`description`, `objectUuid` (usually `{{object:<key>.id}}`), `state` (any state the task engine maps), `assignee`,
`candidateGroups`, `priority`, `dueAt`, `kind`, and for a finished task `completedAt` and `completedBy`, placeholders
allowed. The check SHALL refuse, before anything is written, a field outside that list, a task without a title or an
object, an `assignee` or `completedBy` that is not an account the set creates or adopts, a state the engine does not
map, and a date that is not one. Install SHALL write each task once through the task service's trusted path, keyed
`example-set:<app>/<key>` with the fragment's app as its app id, SHALL record its uuid, and SHALL report a recorded task
that is no longer there as missing.

#### Scenario: A case gets its open and its finished tasks
- **GIVEN** dossiq's fragment declares an open task assigned to `{{cast:thomas.uid}}` on `{{object:woo-lindelaan.id}}`
  and a completed one with `completedAt`
- **WHEN** the set is installed twice
- **THEN** both tasks SHALL be in the engine on that case once, the completed one with its completion moment, and the
  second install SHALL write nothing

#### Scenario: A task for a stranger is refused
- **GIVEN** a task assigned to an account the set does not declare
- **WHEN** the set is checked
- **THEN** the check SHALL refuse it, naming the account, and nothing SHALL be written

### Requirement: Remove deletes the tasks a set wrote

Remove SHALL delete the tasks the record holds, newest first and before the objects they are on, and SHALL count them.
A task already gone SHALL count as removed.

#### Scenario: Remove leaves no task behind
- **GIVEN** an installed set with two tasks
- **WHEN** the set is removed
- **THEN** both tasks SHALL be deleted from the engine and the record SHALL hold none
