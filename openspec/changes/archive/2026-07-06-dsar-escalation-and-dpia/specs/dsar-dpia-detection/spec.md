## ADDED Requirements

### Requirement: DPIA pattern detection over DSAR cases
OpenRegister SHALL provide a scheduled DPIA pattern-detection capability (GDPR art-35) that
groups DSAR cases received inside a rolling window by configurable characteristics (default:
request `type` plus normalised scope) and, when a group's size reaches the configured threshold,
sets `dpiaRequired = true` on every case in the group through the normal object write path with
an audit-trail entry recording the detection (rule, group key, window, count). Detection MUST be
idempotent: cases already flagged are counted for the group but never re-written or re-audited,
and a group that already triggered does not re-trigger for the same membership.

#### Scenario: Threshold crossing flags the group
- **WHEN** the number of cases with the same type and scope received inside the rolling window reaches the configured threshold
- **THEN** every unflagged case in the group gets `dpiaRequired = true` through an audited write
- **AND** the audit entry names the detection rule, group key, window, and count

#### Scenario: Re-runs are idempotent

@e2e exclude job idempotency — covered by PHPUnit DsarDpiaDetectionJobTest::testReRunOverFlaggedGroupIsNoOp + DpiaPatternDetectionServiceTest::testIdempotentReRunExposesNoUnflaggedMembers

- **WHEN** the detection job runs again over an already-flagged group with no new members
- **THEN** no case is re-written, no audit entry is added, and no notification is re-sent

#### Scenario: Below-threshold groups stay untouched

@e2e exclude below-threshold + manual-flag ratchet — covered by PHPUnit DpiaPatternDetectionServiceTest (window/threshold matrices) + DsarDpiaDetectionJobTest (ratchet never clears a manual flag)

- **WHEN** a group's size inside the window is below the threshold
- **THEN** no case in it is flagged and `dpiaRequired` set manually by a handler is never cleared by the job

### Requirement: Detection configuration lives in the DSAR policy pack
OpenRegister SHALL read the detection threshold, rolling-window length, grouping characteristics,
and the privacy-officer recipient from the active `dsarPolicyPack` (new `dpiaDetection` block) —
configuration as data, never hard-coded in the job. When no policy pack resolves, or the pack
declares no `dpiaDetection` block, detection MUST be a no-op (fail-safe: no false DPIA flags).
The seeded default pack SHALL carry the generalised defaults (threshold 10, window 30 days,
group by type + scope — pipelinq `DpiaDetectionService` parity).

#### Scenario: Pack-driven thresholds

@e2e exclude config-as-data threshold — covered by PHPUnit DpiaPatternDetectionServiceTest::testPackDrivenThreshold

- **WHEN** an administrator lowers the active pack's `dpiaDetection.threshold`
- **THEN** the next detection run applies the new threshold without any code change

#### Scenario: No pack, no detection

@e2e exclude fail-safe no-op — covered by PHPUnit DsarDpiaDetectionJobTest::testNoPackOrNoBlockIsFailSafe + DpiaPatternDetectionServiceTest::testFailSafeConfigurationMatrix

- **WHEN** no `dsarPolicyPack` resolves
- **THEN** the detection run exits without flagging, auditing, or notifying

### Requirement: DPIA flagging notifies the privacy officer
The DSAR register SHALL declare an `x-openregister-notifications` rule dispatching to the policy
pack's privacy-officer recipient when a case's `dpiaRequired` transitions from false to true, so
detection results reach the officer through the existing notification engine (ADR-031) rather
than a bespoke dispatch path in the job.

#### Scenario: Officer notified on detection flag
- **WHEN** the detection job sets `dpiaRequired = true` on a case
- **THEN** the declared rule dispatches one notification to the privacy-officer recipient naming the case

#### Scenario: Manual flagging uses the same rule

@e2e exclude one declared calculatedChange rule covers both paths — the shared dpiaFlagged rule is declarative register config (no job-specific dispatch); the resolver is covered by PrivacyOfficerRecipientResolverTest

- **WHEN** a handler manually sets `dpiaRequired = true` on a case
- **THEN** the same declared rule dispatches — detection and manual flagging share one notification path

@e2e A privacy officer seeds eleven access requests with the same scope inside the window, runs the DPIA detection job, and sees the cases flagged dpiaRequired with an audit entry and a notification in the officer's inbox; re-running the job produces no duplicates, and removing the policy pack's dpiaDetection block makes the next run a no-op.
