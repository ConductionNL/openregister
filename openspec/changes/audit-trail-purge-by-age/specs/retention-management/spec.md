# retention-management

## ADDED Requirements

### Requirement: The hourly log cleanup job deletes audit trail entries past the configured retention

The log cleanup background job SHALL delete audit trail entries whose expiry has
passed, where the expiry is the entry's creation time plus the retention an
administrator set for that kind of action. It SHALL never delete an entry younger than
the register's configured minimum audit retention, and SHALL record how many entries a
run removed.

#### Scenario: old change history is removed

- **WHEN** an administrator sets the update log retention to one year and the cleanup job runs
- **THEN** update entries older than one year are deleted, newer ones remain, and the run records how many it removed
- @e2e exclude {background job, covered by unit tests in task 1.1}

#### Scenario: the register minimum wins over a shorter setting

- **WHEN** a register requires ten years of audit retention and the update log retention is set to one year
- **THEN** the cleanup job leaves that register's update entries younger than ten years in place
- @e2e exclude {background job, covered by unit tests in task 1.2}
