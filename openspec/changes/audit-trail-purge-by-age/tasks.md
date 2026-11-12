# Tasks: audit-trail-purge-by-age

## 1. Pin the behaviour

- [ ] 1.1 Unit test for the log cleanup job: entries whose expiry has passed are deleted, newer ones stay, and the run reports the count. Verify: `tests/Unit/Cron/LogCleanUpTaskTest.php`.
- [ ] 1.2 Unit test for the floor: a retention shorter than the register's minimum audit retention does not delete entries younger than that minimum. Verify: same test file.
