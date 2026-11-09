# Wave S plan: SIG unit interfacing to 5.0 (openregister, round 12)

Generated 2026-11-09 from main `ecc034faa032` (fresh clone `~/sig-lanes/local/plan-s`). Briefs: branch `sig-lane-briefs/r12s` (`https://raw.githubusercontent.com/ConductionNL/openregister/sig-lane-briefs/r12s/<LANE>.txt`). Measure: `python3 sig-measure/lane_measure.py --app <clone> --lane <LANE> --scopes sig-measure/scopes.json`.

## Main now

`check_sig_maintainability.py report --profile five-star`: estimated rating 4.10 to 4.16. Unit interfacing 2.64; php params 3+/5+/7+ = 19.3% (2★) / 2.0% (5★) / 0.8% (4★). Component independence 2.65 is the other property below 5 (not in this wave).

Units (same UnitReader): 325,792 PHP unit LOC; > 2 params 63,021 (19.34%), > 4 6,520 (2.00%), > 6 2,643 (0.81%). 5.0 needs ≤ 6.25% / 1.25% / 0.3% (tool: PHP ≤ ~6.75% / 1.35% / 0.32% because other languages are at 5).

## Work list (> 2 band, 63,021 LOC)

| bucket | LOC |
|---|---|
| units changed by an open PR (347 open PRs on main, name + removed-line match) | 28,999 |
| whole file in an open PR (no patch) | 983 |
| files of running lanes R15–R39 | 9,770 |
| off-limits dirs (Event/Contract/Kernel/Migration/AppInfo) | 222 |
| **wave S** | **23,047** (of it 7,510 residue: value constructors, DTOs, ≤4-member aggregates; 2,592 fixed signatures to thin) |

Doable wave S work ≈ 15,537 LOC, cut into 36 lanes of ~390 LOC (two single-file lanes: MagicMapper, MagicSearchHandler). Clusters: an interface, its implementers and all callers in lib stay in one lane (union of callers grep `->m(`/`::m(` ∩ class name, `implements`, `new Cls(`), capped at 1.25× lane size; edges over the cap are listed in the brief as "callers in other wave S lanes" (old signature stays as ≤5-line delegate). Registrar/Wiring files go to the lane with most built classes; other lanes may edit only the `new <TheirClass>(...)` expression there (registrar exception in the brief).

## Decisions taken

- **ListQuery without a limit (S00, lands first)**: `ListQuery::UNBOUNDED = PHP_INT_MAX`, `ListQuery::unbounded(array $filters = [], array $sort = [])`, `isUnbounded()`, `limitOrNull()`. `int $limit` keeps its type so no reader and no open PR breaks; PHP_INT_MAX fails safe (a reader that forgets limitOrNull() still gets all rows). fromParams() unchanged. Every S brief says: check it is on main, do other units first until it is.
- Held units, not held files: in wave Q/R files with open PRs only the units an open PR changes are held; the rest of the file is wave S's (Ruben released the file holds).
- C06 mapper holds dropped (component work cancelled).
- 36 lanes rather than 40: at ~390 doable LOC per lane; more lanes would be under-filled.

## Projection after Q + R + S (LOC; share of 325,792)

Assumes open-PR units end at the Q+R lanes' own target ratio (26.8% / 14.4% / 20.0% of before per band), R15–R39 reach their scope targets, wave S reaches its targets (residue + thinned fixed delegates + delegates kept for running-lane callers).

| band | off-limits | open-PR units → after | R15–R39 after | wave S after | total | share | 5.0 limit |
|---|---|---|---|---|---|---|---|
| > 2 | 222 | 29,982 → 8,029 | 2,672 | 8,693 | 19,616 | 6.02% | 6.25% |
| > 4 | 119 | 3,296 → 476 | 197 | 412 | 1,204 | 0.37% | 1.25% |
| > 6 | 58 | 1,394 → 278 | 112 | 179 | 627 | 0.19% | 0.3% |

Projected unit interfacing ≈ 5.0 only if every lane hits its target: > 2 lands at ~6.0%, a 0.2-point margin. At 85% execution it is ~7%, about 4.8. The big remaining block is the residue (7,510 LOC of value constructors / aggregates in S files alone, accepted by Rule 4 (g)); if that is not enough, the next lever is value constructors with 3+ fields (DTO splitting), which ADR-011 currently accepts.

## Lanes

S00: lib/Db/ListQuery.php + its test + one ADR sentence, 1 hour, lands first.

| lane | files | doable LOC | > 2 before→target | > 4 | > 6 | ctors 3+p | files with held units | dirs |
|---|---|---|---|---|---|---|---|---|
| S01 | 38 | 387 | 568→174 | 39→0 | 22→0 | 32 | 4 | lib/AppHost, lib/AppHost/Listener, lib/AppHost/Observability, lib/AppHost/Observability/Source … |
| S02 | 24 | 327 | 608→281 | 82→64 | 61→43 | 15 | 6 | lib/BackgroundJob, lib/Capabilities, lib/Command, lib/Controller … |
| S03 | 25 | 383 | 670→287 | 89→80 | 60→60 | 17 | 10 | lib/Controller, lib/Controller/Support, lib/Service/Form |
| S04 | 36 | 365 | 762→407 | 132→118 | 19→19 | 19 | 7 | lib/Controller, lib/Controller/Support, lib/Controller/Trait, lib/Db … |
| S05 | 20 | 263 | 330→112 | 47→14 | 12→0 | 4 | 2 | lib/BackgroundJob, lib/Controller, lib/Db, lib/Files/Db … |
| S06 | 1 | 1,649 | 1,692→43 | 528→0 | 69→0 | 1 | 1 | lib/Db |
| S07 | 23 | 421 | 579→191 | 11→0 | 0→0 | 16 | 7 | lib/Controller, lib/Controller/Support, lib/Db, lib/Listener … |
| S08 | 16 | 403 | 668→281 | 185→54 | 99→0 | 6 | 4 | lib/Controller, lib/Db, lib/Db/MagicMapper, lib/Service/Schemas |
| S09 | 1 | 1,118 | 1,144→26 | 154→0 | 17→0 | 1 | 1 | lib/Db/MagicMapper |
| S10 | 23 | 229 | 353→163 | 10→0 | 0→0 | 11 | 3 | lib/Db, lib/Db/MagicMapper, lib/Db/Schema, lib/Db/SearchTrail … |
| S11 | 12 | 388 | 514→126 | 10→0 | 0→0 | 9 | 4 | lib/Files/Controller, lib/Files/Service, lib/Files/Service/File, lib/Files/Service/File/Pdf/Fallback … |
| S12 | 40 | 388 | 482→109 | 17→5 | 0→0 | 30 | 4 | lib/Files/Service/TextExtraction, lib/Formats, lib/Listener, lib/Service/Handoff … |
| S13 | 43 | 382 | 679→297 | 34→22 | 0→0 | 25 | 8 | lib/Files/Service, lib/Listener, lib/Listener/FlowNodes, lib/Listener/Support … |
| S14 | 36 | 385 | 852→465 | 225→81 | 197→81 | 21 | 4 | lib/Notifications/Db, lib/Notifications/Search, lib/Notifications/Service, lib/Notifications/Service/Notification … |
| S15 | 38 | 383 | 617→227 | 9→0 | 0→0 | 31 | 3 | lib/Repair, lib/Search/BackgroundJob, lib/Search/Controller, lib/Search/Listener … |
| S16 | 6 | 374 | 389→15 | 73→15 | 15→15 | 3 | 1 | lib/Service, lib/Service/Export |
| S17 | 12 | 382 | 484→107 | 102→18 | 75→18 | 8 | 4 | lib/Service, lib/Service/File |
| S18 | 11 | 386 | 535→149 | 104→36 | 16→0 | 7 | 1 | lib/Service, lib/Service/TalkLink |
| S19 | 37 | 373 | 704→331 | 0→0 | 0→0 | 22 | 9 | lib/Controller, lib/Db, lib/Service, lib/Service/ApiCaller … |
| S20 | 21 | 266 | 489→233 | 66→40 | 25→25 | 12 | 4 | lib/Service/Calculation, lib/Service/Calculation/TemporalSweep, lib/Service/Calendar, lib/Service/CalendarEvent … |
| S21 | 1 | 452 | 539→85 | 280→75 | 62→10 | 0 | 1 | lib/Service/Configuration |
| S22 | 32 | 378 | 655→276 | 116→61 | 116→61 | 16 | 7 | lib/Controller, lib/Service/Configuration, lib/Service/Credential, lib/Service/Decision … |
| S23 | 23 | 268 | 502→239 | 0→0 | 0→0 | 12 | 3 | lib/Controller, lib/Db, lib/Listener, lib/Service/Edepot … |
| S24 | 37 | 462 | 751→292 | 37→0 | 0→0 | 21 | 9 | lib/Controller, lib/Service/ExampleSet, lib/Service/Export, lib/Service/Federation … |
| S25 | 38 | 383 | 806→456 | 51→39 | 0→0 | 21 | 11 | lib/Listener, lib/Service/Case/Nodes, lib/Service/Flow, lib/Service/Flow/Nodes … |
| S26 | 39 | 369 | 598→230 | 25→13 | 12→0 | 24 | 4 | lib/Service/Case, lib/Service/Flow/Timer, lib/Service/Form, lib/Service/Gdpr … |
| S27 | 42 | 353 | 489→144 | 41→9 | 0→0 | 28 | 4 | lib/Listener, lib/Service/Integration, lib/Service/Integration/BuiltinProviders, lib/Service/Integration/Providers … |
| S28 | 9 | 532 | 846→329 | 120→120 | 0→0 | 2 | 1 | lib/Service/Lifecycle, lib/Service/Merge, lib/Service/MigrationPack, lib/Service/Notification … |
| S29 | 28 | 438 | 1,123→718 | 241→187 | 160→139 | 18 | 11 | lib/BackgroundJob, lib/Command, lib/Controller, lib/Db … |
| S30 | 26 | 377 | 1,453→1,093 | 616→578 | 443→422 | 10 | 9 | lib/Command, lib/Db/MagicMapper, lib/Db/Schema, lib/Listener … |
| S31 | 5 | 395 | 395→0 | 67→0 | 67→0 | 1 | 0 | lib/Service/Object, lib/Service/Relation |
| S32 | 1 | 426 | 631→205 | 77→77 | 44→44 | 1 | 1 | lib/Service/Object |
| S33 | 49 | 382 | 785→411 | 4→0 | 0→0 | 34 | 13 | lib/Controller, lib/Service/Object, lib/Service/Object/SaveObject, lib/Service/Object/SaveObjects … |
| S34 | 29 | 382 | 719→338 | 38→0 | 0→0 | 10 | 8 | lib/Service/Quality, lib/Service/Rbac, lib/Service/Relation, lib/Service/Resolver/Exception |
| S35 | 23 | 202 | 418→210 | 47→29 | 0→0 | 9 | 5 | lib/Controller/Support, lib/Listener, lib/Service/Rules, lib/Service/Schema … |
| S36 | 28 | 486 | 660→172 | 130→0 | 87→0 | 12 | 6 | lib/Service/ConfigurationDeployment, lib/Service/ShippedBaseline, lib/Service/Sync, lib/Service/Task … |
