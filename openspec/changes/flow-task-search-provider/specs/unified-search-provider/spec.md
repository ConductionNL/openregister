# unified-search-provider

## ADDED Requirements

### Requirement: Nextcloud search finds the flow tasks a user may see

OpenRegister SHALL register a unified-search provider for flow tasks. For a term it SHALL return the tasks the current user would see in their task inbox (assigned to them, offered to one of their groups, or watched by them) whose title or description matches the term, case and accent insensitively, open tasks before finished ones. Each result SHALL carry the task title, a subline with state, due date and the subject's title when the user may read the subject, and a link that opens the task: the subject's owning app's registered task route when there is one, otherwise `/apps/openregister/flow-tasks/{uuid}`. The provider SHALL page with a cursor and SHALL NOT return a task the inbox would not show the user, an administrator included.

#### Scenario: a caseworker finds the callback task

- **GIVEN** a task "Terugbellen mevrouw Jansen" assigned to the caseworker
- **WHEN** the caseworker searches "terugbellen jansen" in Nextcloud's search bar
- **THEN** the Tasks section lists the task, and the result opens its task page
- @e2e exclude {specified only; task 2.2 adds tests/e2e/ci/flow-task-search.spec.ts}

#### Scenario: someone else's task is not found

- **GIVEN** a task assigned to another user, not offered to any of the searcher's groups and not watched by the searcher
- **WHEN** the searcher searches its exact title
- **THEN** the Tasks section does not list it
- @e2e exclude {authorisation; covered by a unit test through the real inbox query}

#### Scenario: an app's own task view wins

- **GIVEN** a task whose subject belongs to an app that registered a task route in the deep link registry
- **WHEN** the task is found
- **THEN** the result links to that app's route
- @e2e exclude {routing; covered by a unit test with a registered route}
