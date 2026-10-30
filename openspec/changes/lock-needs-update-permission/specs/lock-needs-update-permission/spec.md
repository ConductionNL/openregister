# Spec: taking a lock needs update permission

## ADDED Requirements

### Requirement: A user lock needs update permission on the object

Before a user takes a lock on a stored object, the system SHALL check that the user may update that object under the schema's RBAC, including conditional rules matched against the object, and SHALL refuse the lock with HTTP 403 when they may not. Nothing SHALL be written when the lock is refused. A lock taken by a flow run SHALL keep its own authorization.

#### Scenario: A reader cannot freeze a record
@e2e exclude unit; tests/Unit/Service/Object/LockHandlerUpdatePermissionTest.php

- **GIVEN** a user who may read a ticket but not update it
- **WHEN** they POST its lock route
- **THEN** the answer is 403 and the ticket carries no lock

#### Scenario: A handler locks a finished ticket
@e2e exclude unit; tests/Unit/Service/Object/LockHandlerUpdatePermissionTest.php

- **GIVEN** a user who may update the ticket
- **WHEN** they POST its lock route without a duration
- **THEN** the ticket is locked until it is unlocked
