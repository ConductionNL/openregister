# agent-tool-governance Specification (delta)

## ADDED Requirements

### Requirement: An agent reads only the views it is granted

An agent with one or more views SHALL only find objects inside those views when its chat searches for context, and the agent's owner SHALL set those views on the agents screen.

#### Scenario: an object outside the agent's views is not found

- **GIVEN** an agent granted only the view `open-cases`
- **WHEN** the agent's chat searches for context
- **THEN** an object outside `open-cases` is not returned
- @e2e exclude {specified only; task 1 adds the test}

#### Scenario: the owner removes a view

- **GIVEN** the agents screen and an agent the signed-in user owns
- **WHEN** the owner removes a view and saves
- **THEN** the agent no longer finds objects of that view
- @e2e exclude {specified only; task 2 adds the test}
