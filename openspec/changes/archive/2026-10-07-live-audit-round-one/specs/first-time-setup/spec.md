## ADDED Requirements

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
