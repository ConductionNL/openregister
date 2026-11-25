# Tasks: cast-members-keep-the-default-organisation

- [x] 1 `ExampleSetInstance::create()` adds each declared member of a new organisation to the default organisation (`OrganisationServiceInterface::ensureDefaultOrganisation()`), writing it only when a member was missing; a failure is returned as the organisation's error with its uuid and `created` true.
- [x] 2 `ExampleSetPrerequisites::ensureOrganisations()` records a created organisation before it reports that organisation's error.
- [x] 3 Unit tests: `ExampleSetInstanceBehaviourTest` (members join, already members write nothing, no members leaves the default alone, a failed join is reported), `ExampleSetInstanceGoldenTest` (the update in the call log), `ExampleSetPrerequisitesBehaviourTest` (a created organisation is recorded even when completing it failed).
- [ ] 4 Live: install the Zuiddrecht set on a fresh instance; Pieter's active organisation is the Default Organisation, Waterland is among his organisations, and he reads the Oosthaven case (dossiq `example-set-pieter-reads-the-household`).
