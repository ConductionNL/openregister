# Tasks: insert-race-runs-update-guard

- [x] 1.1 `insertObjectEntity()` writes with `failIfExists: true` and routes a taken identifier through `updateObjectEntity()` with the stored row as the old object.
- [x] 1.2 A taken identifier is not logged as a failed write.
- [x] 1.3 `MagicMapperInsertRaceGuardTest`: a lost insert refused by the guard, a lost insert the guard allows, a claim that still refuses, and an insert that wins.
