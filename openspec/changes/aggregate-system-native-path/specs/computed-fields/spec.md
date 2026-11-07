## ADDED Requirements

### Requirement: An aggregate resolved as the system counts every row it filters on

When a materialised aggregate reference is resolved as the system (`bypassRbac`), OpenRegister SHALL NOT apply the caller's row-level read predicate to the aggregated rows on any path, SHALL resolve the same figure with or without a user session, and SHALL NOT serve or store that figure through a cache entry scoped to the caller.

#### Scenario: rematerialise under occ counts published lessons

- **GIVEN** a learniq course with three published lessons and enrolments whose `totalPublishedLessonCount` is an aggregate over `lesson` filtered on the course and `lifecycle: published`
- **WHEN** `occ openregister:rematerialise-calculations learniq enrolment` runs, with no user
- **THEN** each enrolment of that course holds `totalPublishedLessonCount` 3, the report says failed 0 and the command exits 0
- @e2e exclude {occ command; covered by AggregationRunnerReadPermissionTest}

#### Scenario: a conditional reader's own question is not answered from the system's figure

- **GIVEN** a reader who may read only its own rows of a schema
- **WHEN** the system resolves an aggregate over that schema while the reader is signed in, and the reader then asks the same ad-hoc question
- **THEN** the reader's answer counts only its own rows
- @e2e exclude {cache scoping; covered by AggregationRunnerReadPermissionTest}
