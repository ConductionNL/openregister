# avg-verwerkingsregister

## ADDED Requirements

### Requirement: Reads of personal data are registered through the one read registration

A read of an object whose schema opts in with `x-openregister-processing.logReads` SHALL be registered through `ReadHistoryService::registerProcessingRead()`, which SHALL delegate to `ProcessingLogService` unchanged. The processing log SHALL keep its own storage (`openregister_processing_log`), its own opt-in and attribution, its own retention (`ProcessingLogMapper::deleteCreatedBefore()`) and its own readers (admin or FG group through `ProcessingLogController`). It SHALL be written regardless of the instance audit setting and regardless of a per-call `_audit: false`. The processing log SHALL NOT be read to drive a convenience feature such as the `_recent` lens (doelbinding, AVG art. 5(1)(b)).

#### Scenario: the audit trail is off and personal data is read

- **GIVEN** `retention.auditTrailsEnabled` is `false`
- **AND** schema `inwoners` declares `x-openregister-processing` with `logReads: true`
- **WHEN** user `medewerker-1` reads object `inwoner-123`
- **THEN** a processing-log entry with action `read` and actor `medewerker-1` MUST be written
- **AND** no audit `read` row is written
- @e2e exclude {switching the instance audit setting would disturb the shared e2e instance; covered by ReadHistoryServiceTest::testAProcessingReadIsLoggedEvenWithAuditOff}

#### Scenario: the recent lens never reads the processing log

- **GIVEN** a user whose reads are recorded only in the processing log because the audit trail is off
- **WHEN** the user queries `_recent=true`
- **THEN** the page is empty and `@self.lenses.recent.reason` is `audit-trail-disabled`
- @e2e exclude {covered by ReadHistoryServiceTest::testAuditOffIsAnEmptyLensWithAReason, which asserts no history is read}
