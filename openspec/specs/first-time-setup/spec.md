# first-time-setup Specification

## Purpose
The setup wizard helps an administrator start with OpenRegister. This spec covers how it stays out of the way once closed, how each example data card loads its own dataset, and how the setup status reports every step the wizard shows.

## Requirements

### Requirement: Closing the setup wizard is remembered on the server

When an administrator closes or finishes "Set up Open Register", the app
SHALL record it on the server through the `dismiss-setup` setup action, and
the setup status SHALL then report the example data step done, so the wizard
does not open again in another browser or on another device. The action
SHALL NOT overwrite a dataset that was picked or loaded.

#### Scenario: Closed in one browser, closed everywhere

- GIVEN an instance where no example data was picked
- WHEN the administrator closes the setup wizard
- THEN `GET /api/setup/status` reports `demo-data` as done
- AND the wizard does not open on the next page load in another browser

#### Scenario: Closed after loading example data

- GIVEN the administrator loaded the example data
- WHEN they close the wizard
- THEN the recorded load stays as it was

### Requirement: Each example data card loads itself

The `demo-data` setup step MUST be a cards choice step with `loadAction: load-demo-data`. The setup wizard MUST NOT carry a separate run-action step that loads the picked dataset.

#### Scenario: The operator loads a dataset from its card

- GIVEN the setup wizard shows the example data cards
- WHEN the operator presses Load on a card
- THEN the wizard posts `{ "dataset": <card value> }` to `/api/setup/action/load-demo-data`
- AND the server loads that dataset
- AND the server records the dataset as the pick only after the load succeeds
- @e2e exclude the card and its spinner are CnSetupWizard UI, tested in nextcloud-vue; the posted body is covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: An unknown dataset is refused

- GIVEN a dataset id that no card offers
- WHEN it is posted to `/api/setup/action/load-demo-data`
- THEN the server answers 400 with `success: false`
- AND nothing is loaded or stored
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts

#### Scenario: A call without a body keeps working

- GIVEN a dataset was stored through `/api/setup/config`
- WHEN `/api/setup/action/load-demo-data` is called without a body
- THEN the stored dataset is loaded
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts

#### Scenario: A failed load leaves the step open

- GIVEN the load of the posted dataset fails
- WHEN the server answers
- THEN the answer carries `success: false`
- AND no pick or decision is stored
- @e2e exclude needs a load that fails on a live instance; covered by tests/Unit/Controller/SetupControllerTest.php

### Requirement: Setup status reports every manifest step

`GET /api/setup/status` MUST report a `done` state for every step id in `manifest.setup.steps`.

#### Scenario: The status ids match the manifest

- GIVEN the OpenRegister manifest
- WHEN an administrator reads `/api/setup/status`
- THEN `steps` holds an entry for every manifest step id
- AND the retired load step is not reported
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts
