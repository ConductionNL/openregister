# Tasks: read-audit-entries-name-the-rule

## 1. Data

- [x] 1.1 Migration adding nullable `rule_digest` (char 64) and `rule_version` (string) to the audit trail table, and the two fields on `AuditTrail`. Verify: migration test on an existing table. (`lib/Migration/Version1Date20261124210000.php`, `AuditTrail::$ruleDigest`/`$ruleVersion`; `Version1Date20261124210000Test`, `AuditTrailReadRuleEntryTest`, hydration golden.)
- [x] 1.2 One `RuleDigest` helper: SHA-256 over the rule's canonical JSON, using the hash chain's canonical JSON. Verify: unit tests that key order does not change the digest and a changed rule does. (`lib/Service/Audit/RuleDigest.php`: `RuleDigest::of(array)` recursively sorts associative keys, keeps list order, and encodes with the chain's `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` before `sha256`. `RuleDigestTest`.)

## 2. Writing the digest

- [x] 2.1 The read permission check returns the rule that granted the read, or none for an administrator, an owner or a schema without read rules. Verify: unit tests for each case. (`lib/Service/Audit/ReadRuleResolver.php` behind `Kernel\\Contracts\\ReadRuleResolverInterface`: the first `read` entry the reader matches, judged by the permission check's own `GroupRuleEntries`; null for administrator, owner, no read rules or `public: true`. `ReadRuleResolverTest`.)
- [x] 2.2 `ReadHistoryService::registerAuditRead()` writes `ruleDigest` and `ruleVersion` on the `read` entry. Verify: unit test, and an API test that opens an object and reads its audit entry. (`ReadHistoryService::registerAuditRead()` → `AuditTrailMapperInterface::recordRead()`; `ReadHistoryServiceTest`. The API test against an instance is a live pass.)
- [x] 2.3 Every read path that writes a read entry uses the same helper. Verify: test that REST and GraphQL reads of one object under one rule carry the same digest. (`registerAuditRead()` is the only writer of `read` entries; every object read, REST or GraphQL, reaches it through `GetObject::find()`, and the digest is `RuleDigest::ofEntry()` only. The cross-path API test is a live pass.)

## 3. Hash chain

- [x] 3.1 `ruleDigest` and `ruleVersion` enter the canonical JSON when present and are left out when absent. Verify: chain verification test over entries written before and after the migration, and a tamper test on `ruleDigest`. (`AuditTrailSealedForm` seals `ruleDigest`/`ruleVersion` when set and leaves them out when null; `AuditHashReadRuleChainTest`: a mixed old/new chain verifies, a changed, erased or added digest breaks it at that entry.)

## 4. Interface and docs

- [ ] 4.1 The audit trail detail shows the rule digest and version on a read entry. Verify: e2e that opens a read entry.
- [x] 4.2 `docs/` section on proving which rule allowed a read. (`docs/features/versioning-and-audit.md`, "Which rule allowed a read".)
