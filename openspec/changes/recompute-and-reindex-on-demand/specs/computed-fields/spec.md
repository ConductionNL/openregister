---
status: proposed
---

# computed-fields

## ADDED Requirements

### Requirement: Computed fields are re-derived on demand over a selection (REQ-RRD-001)

`POST /api/objects/{register}/{schema}/recompute` SHALL accept exactly one selection (`ids`, or the object list filter parameters) and `dryRun`, SHALL require update rights on every selected object (objects the caller may not update are counted as `failed` with reason `forbidden`, never written), and SHALL re-derive every `x-openregister-calculations` field. For up to 500 objects it SHALL answer `{touched, unchanged, failed: [{id, reason}]}`; above 500 it SHALL queue a recorded run and answer `{run}`, the run carrying the same counts when it ends. With `dryRun` nothing SHALL be written. `occ openregister:rematerialise-calculations` SHALL produce the same counts through the same service.

#### Scenario: an officer recomputes the deadlines of a filtered set
- **GIVEN** 40 cases of one case type whose deadline calculation changed, among 300 cases
- **WHEN** an officer filters the case list on that type and chooses "Recompute calculated fields"
- **THEN** the result says 40 touched, 0 unchanged, 0 failed, and the other 260 are untouched

#### Scenario: a dry run writes nothing
<!-- @e2e exclude Covered by PHPUnit RecomputeEndpointTest::testADryRunWritesNothing. -->

- **GIVEN** a selection whose values would change
- **WHEN** it is posted with `dryRun`
- **THEN** the counts are returned and no object's `updated` moves

#### Scenario: the command and the endpoint agree
<!-- @e2e exclude Covered by PHPUnit CalculationRematerialiserTest::testCommandAndEndpointProduceTheSameCounts. -->

- **GIVEN** a schema with stale calculated values
- **WHEN** the command runs with `--dry-run` and the endpoint runs with `dryRun` over the whole schema
- **THEN** both report the same counts

### Requirement: A parent's change re-derives the children that read it (REQ-RRD-002)

On every object update, OpenRegister SHALL determine, from the declared calculations, which schemas read the updated object's schema through a relation, and which of the read properties changed between the old and the new object. When at least one changed, it SHALL queue one recorded recompute run over the children whose relation points at the updated object, deduplicated per parent while a run for it is pending, and the run SHALL be listed on the operations console with its counts. When none of the read properties changed, nothing SHALL be queued.

#### Scenario: renaming a case type updates its cases' derived label
- **GIVEN** cases with a calculation `typeLabel` reading `@ref.caseType.title`
- **WHEN** an administrator renames the case type
- **THEN** a recompute run for that case type's cases appears on the operations console, and after it ends every case's `typeLabel` holds the new name and is found by searching it

#### Scenario: an unrelated change queues nothing
<!-- @e2e exclude Covered by PHPUnit CalculationDependencyListenerTest::testAnUnreadPropertyChangeQueuesNothing with the real ObjectUpdatedEvent. -->

- **GIVEN** the same case type
- **WHEN** a property no calculation reads changes
- **THEN** no run is queued

#### Scenario: ten quick edits make one run
<!-- @e2e exclude Covered by PHPUnit CalculationDependencyListenerTest::testRunsAreDedupedPerParent. -->

- **GIVEN** ten updates to the same parent before the queued run starts
- **WHEN** the listener handles them
- **THEN** one run is pending for that parent
