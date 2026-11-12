# notificatie-engine Delta

## ADDED Requirements

### Requirement: The preference list names each rule once

The effective notification preferences of a user SHALL list each rule of an owning app's schema slug once, read from
the schema row with the highest version (then the highest id), whatever other rows share that app and slug.

#### Scenario: An older schema row left behind

- **GIVEN** dossiq's `workDigest` as version 1.0.0 (id 1515) and 1.0.1 (id 1532)
- **THEN** `workDigestReady` is listed once, as 1.0.1 declares it
