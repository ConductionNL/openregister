# Tasks: organisation-capability-halt

- [x] 1.1 `OrganisationHaltService`: engage, release, list, haltFor, haltsMatching; app config list; audit rows for engage and release.
- [x] 1.2 `OrganisationHaltCheck` registered by `FlowOversightRegistrationListener` beside `KillSwitchCheck`; run organisation from FlowRun; unattributed run refused while a halt matches.
- [x] 1.3 Admin routes and `OrganisationHaltController`.
- [x] 2.1 `tests/Unit/Service/Flow/Oversight/OrganisationHaltTest.php` through the real `FlowOversightRegistry` and the real listener: org A refused, org B and other node types run, prefix narrows, unattributed refused, read + release, refusals; red on development (no classes).
- [x] 2.2 `tests/Unit/Controller/OrganisationHaltControllerTest.php`.
- [ ] 3.1 hermiq migrates its tenant kill switch onto this service (hermiq lane; tell hermiq).
