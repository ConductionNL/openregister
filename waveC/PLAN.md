# Wave C: unit complexity (openregister main 7199624b)

Measured with `check_sig_maintainability.py` (lizard 1.24.3 units, McCabe = lizard's cyclomatic complexity), per file, by `perfile_c.py`; scopes cut 2026-11-10 11:10 UTC.

Main now: PHP 331,811 unit LOC, lines in units over complexity 5 / 10 / 25 = 29,827 / 2,064 / 0 (9.0% / 0.6% / 0.0%, complexity 4.93 by the over-5 check). Vue 21,983 unit LOC, 2,528 / 81 / 0 (11.5% / 0.4% / 0.0%). JavaScript 5.8% / 0.3%.

Weighted gain per unit = its lines x (1 + over 10 + over 25), so a line in a unit over 10 counts 2. Units held by an open PR (its diff changes the unit's body or signature, mapped hunk by hunk onto main) carry no weight and are listed per lane as HELD AT THE CUT.
Grouping: files grouped by `lib/<A>/<B>`; a group over 60% of a lane's share is split into files; groups placed largest first on the lightest lane (8 PHP lanes, no Vue lane). Only files with free weight > 0 are listed in scopes-c.json.

| lane | largest files | files | units >5 | units >10 | lines >5 | lines >10 | weighted | held at cut (lines) |
|---|---|---|---|---|---|---|---|---|
| C01 | SchemasController, ViewsController, ReferencedCalculationRefresher | 16 | 38 | 2 | 911 | 98 | 1009 | 2 (77) |
| C02 | OrganisationService, DeletedController, FederatedConfigController | 18 | 33 | 2 | 916 | 93 | 1009 | 4 (138) |
| C03 | MagicSearchHandler, ContentReportController, RegisterSerializer | 6 | 29 | 3 | 885 | 133 | 1018 | 4 (161) |
| C04 | ImportHandler, FileMapper, RematerialiseCalculationsCommand | 10 | 36 | 2 | 922 | 93 | 1015 | 3 (107) |
| C05 | AggregationRunner, OrganisationController, ConfigurationsController | 10 | 31 | 2 | 940 | 69 | 1009 | 3 (93) |
| C06 | SaveObject, TransitionEngine, FolderManagementHandler | 16 | 40 | 1 | 957 | 52 | 1009 | 8 (364) |
| C07 | DeleteObject, PermissionHandler, ToolGrantResolver | 35 | 55 | 0 | 1018 | 0 | 1018 | 9 (201) |
| C08 | AnnotationNotificationDispatcher, PurgePhantomMigratedFlows, BackfillFlowVersions | 27 | 47 | 0 | 1009 | 0 | 1009 | 9 (272) |
| total | | 138 | 309 | 12 | 7558 | 538 | 8096 | 42 (1413) |

## Excluded
- tests/, vendor/, generated files and everything the tool's own Scope drops.
- lib/Kernel/Contracts, lib/Migration, lib/AppInfo.
- Every file of the 12 wave D lanes (`waveD/scopes-d.json`): 15,383 PHP lines over 5 in 371 files, and all but 22 Vue/JS lines over 5 (2,519 Vue + 571 JS). Vue complexity therefore has no wave C lane; wave D's briefs already hold new units at complexity 5 or below.
- Every file of the 46 open PRs from cloud/ko*, sec2*, kc*, kd*, kf*, kv*, kr* (no en1* PR open at the cut): 4,772 lines over 5 in 49 files.
- Left free but not planned: src/components/tables (9 lines) and src/services (13 lines).
- Of the 9,619 free PHP lines over 5, 2,061 sit in units an open PR's diff changes (107 of 155 free files are touched by some open PR, mostly in other methods). #7643 (development into main) and #7746 (4,804 files of branch drift) are not counted as holds.

## Leftovers from earlier lanes (STATE.md from "## 09:03Z V03 done")
Holding PRs checked with `gh pr view` at 11:10 UTC:
- Released: #6178 merged, #6219 merged, #6179 closed (landed in #6219), #8931 merged, #8696 merged, #5116 closed.
- Still open: #7721, #8704, #8495, #8456, #8831, #8877, #8617, #8562, #8734, #8658, #9058.
Units those released PRs held, and where they are now:
- ObjectsController::resolveRegisterSchemaIds (V01; #6178/#6179/#6219): now held by open #9061 (kr1) and ObjectsController.php is in 8 more K/KO PRs. Excluded.
- ObjectEntity::unlock, ObjectEntity::delete (V02; #5116): ObjectEntity.php is a wave D file and in #9092 (kv2, removes delete). Excluded.
- FileService::updateFile, updateFileMetadata, renameFile, moveFile (V06; #8696): gone from main as wide units (#8696 fixed them); copyFile remains, but FileService.php is in #9067 (kv6) and #9086 (kc3). Excluded.
- RelationHandler (#8931): no reported unit left.
So no leftover unit could be included without overlapping a K/KO lane or wave D. The rest stay held by open PRs: createAuditTrailEntry (#7721, #9058 removes it), createAuditTrail (#8704), the V02 units in #8495/#8456/#8831/#8877/#8617/#8562, the V06 Flow run units in #8734, the translation mapper units in #8658.

## Expected rating
- Now: complexity 4.93 (over 5 at 9.0%; over 10 at 0.6% rates 5.36).
- If every lane clears its scope: over 5 6.71%, over 10 0.46%, complexity 5.08.
- If lanes clear half their lines: over 5 7.85%, complexity 5.00.
- The complexity rating is held by the over-5 check (5.5 - 0.090/0.158). A unit only pays when it ends at complexity 5 or below, so briefs require that of every helper and every remaining unit, plus 15 lines or fewer so unit size does not slip.

## Measuring in the cloud
`waveC/measure_c.py` on the brief branch, run next to the scorer from `sig-lane-briefs/r12t:sig-measure`, with `--lane Cnn --scopes scopes-c.json`. `units-c.json` holds every unit over 5 in the scope with its hold at the cut; `heldunits.py` and `partition.py` rebuild it from PR diffs (paths relative to the planner's work dir).
