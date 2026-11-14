# Tasks: read-audit-entries-name-the-rule

## 1. Data

- [ ] 1.1 Migration adding nullable `rule_digest` (char 64) and `rule_version` (string) to the audit trail table, and the two fields on `AuditTrail`. Verify: migration test on an existing table.
- [x] 1.2 One `RuleDigest` helper: SHA-256 over the rule's canonical JSON, using the hash chain's canonical JSON. Verify: unit tests that key order does not change the digest and a changed rule does. (`lib/Service/Audit/RuleDigest.php`: `RuleDigest::of(array)` recursively sorts associative keys, keeps list order, and encodes with the chain's `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` before `sha256`. `RuleDigestTest`.)

## 2. Writing the digest

- [ ] 2.1 The read permission check returns the rule that granted the read, or none for an administrator, an owner or a schema without read rules. Verify: unit tests for each case.
- [ ] 2.2 `ReadHistoryService::registerAuditRead()` writes `ruleDigest` and `ruleVersion` on the `read` entry. Verify: unit test, and an API test that opens an object and reads its audit entry.
- [ ] 2.3 Every read path that writes a read entry uses the same helper. Verify: test that REST and GraphQL reads of one object under one rule carry the same digest.

## 3. Hash chain

- [ ] 3.1 `ruleDigest` and `ruleVersion` enter the canonical JSON when present and are left out when absent. Verify: chain verification test over entries written before and after the migration, and a tamper test on `ruleDigest`.

## 4. Interface and docs

- [ ] 4.1 The audit trail detail shows the rule digest and version on a read entry. Verify: e2e that opens a read entry.
- [ ] 4.2 `docs/` section on proving which rule allowed a read.
