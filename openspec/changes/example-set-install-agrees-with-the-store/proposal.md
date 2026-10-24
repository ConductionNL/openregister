## Why

On :8080 (24 Oct) the Zuiddrecht install exited 2:

- Four portaliq parties were "not written: no object 710d44a4 to put the party on", while the case answers 200. The party step looked the case and the party up with `PartyService::find`, a read with RBAC on and no user under `occ`; the Case schema refuses "Anonymous", and `find` answers a refusal as null. Every other example-set read and write already runs as the system (`_rbac: false`).
- Nine of the 29 "written but not kept" lines were values the store keeps in another form: six decidiq `agendaPublishedAt` changes (written `09:00+02:00`, stored `07:00+00:00`, the same moment; the change check compared strings) and three opencatalogi status messages (written `''`, stored `null`).

## What Changes

- `ExampleSetParties::write` runs the lookup and the link through `ObjectService::runAsSystem`.
- `ExampleSetProof` counts an empty text stored as null as kept; `ExampleSetChanges` uses the proof's comparison (one moment in two offsets, numbers, empty text) both to report a change lost and to decide on remove whether a path still holds what the change wrote.

## Impact

- `lib/Service/ExampleSet/ExampleSetParties.php`, `ExampleSetProof.php`, `ExampleSetChanges.php`; tests in `tests/Unit/Service/ExampleSet/`.
- The other 20 lines on :8080 are data: 18 dossiq suppliers wrote an undeclared `description` (dossiq fix), and 2 portaliq sub-cases write the readOnly, term-derived `deadline`.
