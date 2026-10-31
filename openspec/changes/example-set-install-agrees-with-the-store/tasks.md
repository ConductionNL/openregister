# Tasks

- [x] 1. Tests first: the example-set party fake's `find` refuses as an anonymous occ read (fails: "no object ... to put the party on"); PartyRoleServiceTest: the trusted import finds a party the caller could not read; a fake store keeping a moment in UTC and '' as null (fails: two lost paths).
- [x] 2. PartyService::findUnscoped; ExampleSetParties::write and PartyRoleService::importParty use it; addParty unchanged.
- [x] 3. ExampleSetProof: '' stored as null is the same; ExampleSetChanges::lost and revert use the proof.
- [ ] 4. EXSET-LAND re-installs the Zuiddrecht set on :8080 after merge.
