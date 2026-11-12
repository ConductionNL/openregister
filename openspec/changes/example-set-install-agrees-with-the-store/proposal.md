## Why

On :8080 (24 Oct) the Zuiddrecht install exited 2:

- Four portaliq parties were "not written: no object 710d44a4 to put the party on", while the case answers 200. The party step looked the case up with `PartyService::find`, a read with RBAC on and no user under `occ`; the Case schema refuses "Anonymous", and `find` answers a refusal as null. The trusted `importParty` then looks the party up the same way for the kind check (the Case schema declares party kinds), so it would have refused next with "Party not found". Every other example-set read already passes `_rbac: false`.
- Nine of the 29 "written but not kept" lines were values the store keeps in another form: six decidiq `agendaPublishedAt` changes (written `09:00+02:00`, stored `07:00+00:00`, the same moment; the change check compared strings) and three opencatalogi status messages (written `''`, stored `null`).

## What Changes

- `PartyService::findUnscoped` reads an object without RBAC or multitenancy. `ExampleSetParties::write` finds the object with it, and `PartyRoleService::importParty` (the trusted path, a named linker and no user) finds the party with it for the kind check. `addParty`, the request path, keeps reading with the caller's rights. Nothing elevates to the system principal (ADR-099 boundary test unchanged).
- `ExampleSetProof` counts an empty text stored as null as kept; `ExampleSetChanges` uses the proof's comparison (one moment in two offsets, numbers, empty text) both to report a change lost and to decide on remove whether a path still holds what the change wrote.

## Impact

- `lib/Service/ExampleSet/ExampleSetParties.php`, `ExampleSetProof.php`, `ExampleSetChanges.php`, `lib/Service/Party/PartyService.php`, `PartyRoleService.php`; tests in `tests/Unit/Service/ExampleSet/` and `PartyRoleServiceTest`.
- The other 20 lines on :8080 are data: 18 dossiq suppliers wrote an undeclared `description` (dossiq fix), and 2 portaliq sub-cases write the readOnly, term-derived `deadline`.
