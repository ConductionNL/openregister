# Component checker scores, local scan

Current state = round r5 (04:01-04:19 UTC 13 Nov, all 20 apps, checker `0.0.2+d6a3b66d9fc4` = cache `~/.cache/hydra-gates/checker` at d6a3b66d9fc4dd11817f3a48c739656be20b8014, unchanged since r4; scoring digest `9fecce06b1cacea2`, lane SCANN). The change column compares with r4. openregister at `main`, every other app at `development`. Statuses: P pass, W warn, F fail, i info; the digit after W/F is the star rating (1-5). Each scan is a fresh treeless clone of the branch head, deleted afterwards. Raw JSON: r5 and r4 in `~/lanes/scann/scans/<round>/<app>.json`, old-checker control on the r4 commits in `~/lanes/scann/scans/ctl/`, earlier rounds in `~/lanes/scan/scans/<round>/` (all with `--full`).

## What moved

**Round r5, 04:01-04:19 UTC 13 Nov** (all 20 apps; openregister `main`, fleet `development`). Same checker as r4 (d6a3b66) and same scoring file, so every change below is merged work; no control run was needed (no score moved without merges behind it). Merges are first-parent commits between the r4 and r5 heads.

| app | r4 | r5 | merges | what moved | merged work behind it |
|---|---|---|---|---|---|
| launchpad | 81 | **93** | 17 | docker, dockerimage, helmchart FAIL -> pass; endorsed no -> **yes**; coverage 81.01 -> 88.98% | #1108 (feat/app-deploy); 15 test-coverage PRs + coverage-summary #1156 |
| filinq | 90 | **97** | 40 | adrvalidator FAIL -> **pass** (the servers-URL error and the kebab-case warning are both gone); testcoderatio warn -> pass (99.4 -> 109.1%); endorsed no -> **yes** | #1972 (api-v1); ~36 test-coverage PRs, coverage-summary #2011 |
| larpinq | 90 | **97** | 1 | adrvalidator FAIL -> **pass**; endorsed no -> **yes** | #1005 (api-v1) |
| thematiq | 89 | **97** | 4 | owaspsecurecoding WARN -> pass (both innerHTML hits fixed); adrvalidator WARN (no spec) -> **pass** (spec added, no warnings) | #1665 (owasp), #1661 (secret look-alikes), #1662 (api-v1), coverage-summary #1667 |
| portaliq | 85 | **88** | 4 | adrvalidator WARN (no spec) -> **pass**; owasp still FAIL but 24 -> 23 hits and **all 23 are now the committed `woo/static/js` bundle** (MarkdownBlock.vue and the other src hits are gone) | #2367 (api-v1), #2370 (owasp), #2366 (npm audit), coverage-summary #2371 |
| stackiq | 92 | 92 | 40 | coverage 44.64 -> **65.17%** (still FAIL), maintainability 2.40-2.65 -> **3.08-3.30** (stays WARN 3), testcoderatio 78.6 -> 85.5%; rating 4.4 -> 4.5. SPDX 23 -> 24 files | the 12 maintainability PRs (#1784-#1799 maint-*), ~25 test-coverage PRs, coverage-summary #1814 |
| buildiq | 88 | 88 | 18 | coverage 77.27 -> **79.92%**: still FAIL, **0.08 points short** of 80 | 17 test-coverage PRs, coverage-summary #1490 |
| dossiq | 88 | 88 | 68 | coverage 70.06 -> **75.80%** (still FAIL, 4.2 points short), testcoderatio 106.6 -> 110.3% | ~67 test-coverage PRs, coverage-summary #6224 |
| hermiq | 93 | 93 | 42 | adrvalidator stays WARN but changed cause: "no spec" -> a real spec with **6 warnings, all `use-problem-schema`** (`/openapi.json` 404, `/health` 500 etc.); coverage 81.88 -> 85.33% | #1584 (gap-19-openapi), ~25 test-coverage PRs, coverage-summary #1643 |
| keepiq | 89 | 89 | 43 | adrvalidator warnings 510 -> 536; owasp still the **one** `favicon.js` hit (see measurement note); coverage 80.23 -> 82.89% | #1680 (api-v1), #1677 + #1690 (owasp literal fixes; did not clear the hit), ~38 test-coverage PRs, coverage-summary #1720 |
| decidiq, pipelinq, integriq, shillinq | 93 | 93 | 4, 25, 14, 2 | no criterion moved (pipelinq/integriq/decidiq coverage and test ratio up slightly; shillinq npm audit) | test-coverage and coverage-summary PRs, shillinq #2797 (npm audit) |
| openregister | 97 | 97 | 39 | no criterion moved | integrator merges (#10061-#10092 and others) |
| humaniq, learniq, versioniq | 93 | 93 | 1 each | no criterion moved | coverage-summary PRs only (#1101, #2432, #663) |
| opencatalogi, planninq | 90, 93 | 90, 93 | 0 | nothing merged since r4 | - |

- **Fleet**: 5 apps moved (+12 launchpad, +7 filinq, +7 larpinq, +8 thematiq, +3 portaliq); fleet mean 90.8 -> 92.7. Endorsed **15 of 20** (was 12). Still blocked: coverage (buildiq 79.92, dossiq 75.80, stackiq 65.17), adrvalidator (opencatalogi), OWASP (portaliq).
- **Score 97 club**: openregister, filinq, larpinq, thematiq (thematiq rating 4.9 is the fleet high).
- **API specs that land clean pass outright**: thematiq and portaliq went from "no spec" warn to pass in one PR each, so the api-v1 branches still open in buildiq, decidiq, launchpad, learniq, hermiq, integriq, versioniq and opencatalogi are now the cheapest +3/+4 left.
- **Not changed**: opencatalogi adrvalidator FAIL (branch `quality/api-v1-opencatalogi` unmerged, 3 ahead); complexity informative FAILs (opencatalogi 31, humaniq 33, keepiq 49, portaliq 278); dompurify 2.5.9 high in decidiq, launchpad, learniq; SPDX drift stackiq 24, shillinq 15, filinq 6, integriq 6, humaniq 1.
- **Measurement notes**: (1) keepiq `src/utils/favicon.js` is a false positive: the rule `(api_key|secret|password|token)\s*[:=]\s*'...{8,}'` matches the icon map entry `api_key: 'CodeTags'`, and because `\s*` spans newlines the line split in #1677/#1690 changed nothing; quoting the key (`'api_key': 'CodeTags'`) breaks the match, and checker side `isSecretLookAlike` should accept PascalCase identifiers. (2) portaliq's OWASP FAIL measures third-party minified code (the vendored woo bundle), not portaliq's own source. (3) coverage is read from the committed `coverage/coverage-summary.json`, so the numbers are self-reported by each app's last summary refresh (stackiq +20.5 points in one round came with its summary refresh #1814); not independently re-measured. (4) portaliq adrvalidator warn -> pass scored +3, not the usual +4 (rounding in the weighted mean).

**Round r4, 01:02-01:14 UTC 13 Nov** (all 20 apps; openregister `main`, fleet `development`). Checker moved `ae7df2b` -> **`d6a3b66`** (fork scan/local-build with PR #4 licence excludes from the repo's scope file, PR #5 hardcoded-secret rule needs a secret-looking value, PR #6 Go/pytest/e2e/Android paths count as test code). Same scoring file (digest `9fecce06b1cacea2`). To split checker effect from merged work, every r4 commit was also scanned with the old checker `ae7df2b` (control, `~/lanes/scann/scans/ctl/`). Score r3 -> control (old checker, new commit) -> r4:

| app | r3 | ctl | r4 | moved by merged work | moved by the checker change |
|---|---|---|---|---|---|
| openregister | 86 | 93 | 97 | adrvalidator FAIL -> pass (servers URL versioned, IL3 integration) | owasp WARN -> pass (+4: the 3 "hardcoded secret" hits were names, not values; the 3 dynamic-code hits are gone in both), OSH warn -> pass (licence excludes) |
| filinq | 74 | 86 | 90 | docker, dockerimage, helmchart FAIL -> pass (#1954) | owasp WARN -> pass (+4; quality/owasp-filinq #1975 also merged) |
| versioniq | 77 | 89 | 93 | docker, dockerimage, helmchart (#649) | owasp WARN -> pass (+4: `lib/Db/Pat.php` was a false positive), OSH |
| portaliq | 72 | 85 | 85 | publiccode (gap-4 #2365), docker, dockerimage, helmchart (#2340) | OSH only; owasp still FAIL (24 hits, 22 in `woo/static/js`) |
| opencatalogi | 78 | 90 | 90 | docker, dockerimage, helmchart (#2550) | none |
| thematiq | 77 | 89 | 89 | docker, dockerimage, helmchart (#1649) | none (owasp still WARN, 2 real innerHTML) |
| larpinq | 78 | 90 | 90 | docker, dockerimage, helmchart (#988); OSH fail -> warn (npm audit) | OSH warn -> pass |
| keepiq | 77 | 85 | 89 | docker, dockerimage (#1668); adrvalidator stays WARN but now with a real spec (gap-19-openapi #1676: 0 errors, 510 warnings) | owasp FAIL -> WARN (+4: test paths `_test.go`, e2e, androidTest no longer count); 1 hit left |
| integriq | 86 | 93 | 93 | adrvalidator FAIL -> WARN (gap-3-versioned-base), owasp WARN -> pass (gap-9-secretnames) | OSH |
| stackiq | 85 | 92 | 92 | adrvalidator FAIL -> pass (gap-3-api-v1), maintainability FAIL -> WARN, testcoderatio FAIL -> WARN (44.6% coverage unchanged) | none |
| learniq | 89 | 93 | 93 | owasp WARN -> pass (gap-9-securecoding) | none |
| pipelinq | 89 | 93 | 93 | owasp WARN -> pass (gap-9-literals), secretscanning FAIL -> pass (gap-5-gitleaks #3539) | none |
| dossiq | 84 | 88 | 88 | owasp WARN -> pass (gap-9-secretkey #6082) | OSH |
| launchpad | 77 | 77 | 81 | secretscanning FAIL -> pass (gap-secretscanning-gitleaks #1117) | owasp WARN -> pass (+4; owasp-launchpad #1138 also merged) |
| hermiq | 88 | 89 | 93 | publiccode WARN -> pass (gap-4 #1583) | owasp WARN -> pass (+4; gap-9-secretnames #1585 merged too), OSH |
| shillinq | 89 | 89 | 93 | none at criterion level | owasp WARN -> pass (+4: all 6 hits were constant names), OSH |
| buildiq | 84 | 84 | 88 | none at criterion level (coverage still 77.27%) | owasp WARN -> pass (+4) |
| decidiq, humaniq, planninq | 93 | 93 | 93 | none at criterion level | humaniq OSH only |

- **Fleet total**: 15 of 20 apps moved; 8 apps gained +4 from the checker fix alone (shillinq, buildiq, launchpad, hermiq, versioniq, filinq, keepiq, openregister). Those +4s are a measurement correction (the r3 OWASP hits were constant names, not secrets), not code improvement.
- **OSH (recommended, no score effect)**: 10 apps warn -> pass. This needs both the merged `quality/license-excludes` scope file (excludes 16-21 AGPL/@nextcloud dependencies) and checker PR #4 that reads it. Licence aspect 1 star -> 3-5 stars. The remaining OSH warns sit at 3 stars on Activity 2 stars + Freshness 3 stars + licence 3 stars (1 medium-risk dependency left).
- **Endorsement**: 12 of 20 endorsed (was 7). Blocked: coverage (stackiq, dossiq, buildiq), adrvalidator (opencatalogi, filinq, larpinq), launchpad deploy (3), portaliq OWASP.
- **SPDX drift continues** (pass, 4 stars): stackiq 12 -> 23 files, shillinq 0 -> 15 (new `lib/` value classes from h04/g08), filinq 6, integriq 6, humaniq 1.
- **Not changed**: coverage (buildiq 77.27, dossiq 70.06, stackiq 44.64); complexity informative FAILs (opencatalogi 31, humaniq 33, keepiq 49, portaliq 278); launchpad still has no Dockerfile/Helm chart (branch `feat/app-deploy` pushed, not merged).

**Round r3, 20:25-20:39 UTC** (openregister plus the 11 apps with 5+ merges since their previous scan: dossiq 61, learniq 60, humaniq 48, decidiq 40, stackiq 28, portaliq 24, hermiq 22, buildiq 22, keepiq 19, launchpad 17, integriq 11; openregister 76). Scans were fast this round because heavy.sh slots were free, not because anything was skipped: `unmeasured` is empty for every app.
- **openregister 83 -> 86**: coverage WARN -> **pass (83.71%)**, secretscanning FAIL (184) -> **pass (0)**. adrvalidator now 1 error / 0 warnings (only `include-major-version-in-uri @ servers.0.url` is left; gap 3). OWASP findings 13 -> 6.
- launchpad: maintainability WARN -> pass (3.42 -> 3.65), but **secretscanning pass -> FAIL**: 2 `generic-api-key` hits in `openspec/changes/embedded-analytics/specs/embedded-analytics/spec.md:659` and `:686` (example keys in a spec). Score unchanged (77, rating 3.8 -> 3.7).
- humaniq: maintainability WARN -> pass (3.11 -> 3.45). hermiq: testcoderatio WARN -> pass (93.9% -> 100.6%). Neither moves the score (recommended criteria).
- SPDX: buildiq back to 100%; stackiq 20 -> 12 missing (new ones keep arriving: 12 different files), integriq 7 -> 6.
- Unchanged: the 7 deploy PRs (gap 1) are still open, so docker/dockerimage/helmchart still fail in 8 apps; portaliq and keepiq OWASP still FAIL.

**Round r2, 17:05-18:06 UTC** (rescanned openregister and the 7 apps with 5+ merges since their r1 scan: stackiq 29, pipelinq 28, learniq 25, filinq 22, buildiq 18, integriq 9, opencatalogi 5; openregister 30+). No score and no criterion status changed. Below the status level:
- openregister adrvalidator: 26 errors / 74 warnings -> **1 error / 1 warning** (still FAIL). What is left is the fleet-wide `include-major-version-in-uri @ servers.0.url` plus `paths-kebab-case` on `/objects/{register}/{schema}/{id}/filesMultipart`. One fix away from WARN (gap 3).
- **SPDX headers regressing through today's merges** (still pass, 4 stars, but 5 -> 4 for filinq and buildiq): stackiq 10 -> 20 files without SPDX-License-Identifier, filinq 0 -> 6, buildiq 0 -> 1, integriq 6 -> 7. Mostly new tests/ files and small new lib/ value classes. File lists under each app below. The hydra spdx gate only checks `@license` docblocks in lib/, so tests/ and src/ slip through.
- Small drifts with no status effect: testcoderatio up 0.1-1.7 points in all 7 apps, maintainability up slightly everywhere, buildiq max CCN 18 -> 10.
- Not merged yet: none of the 7 deploy PRs (gap 1) had landed by 18:00, so docker/dockerimage/helmchart are unchanged.

## Score per app

| app | commit | scanned (UTC) | score | rating | endorsed | unmeasured | change since r4 |
|---|---|---|---|---|---|---|---|
| openregister | 5af71ed90 | 2026-11-13 04:05 | 97 | 4.8 | yes | none | +0 |
| pipelinq | 7933b59c4 | 2026-11-13 04:09 | 93 | 4.3 | yes | none | +0 |
| opencatalogi | 92b8e73e6 | 2026-11-13 04:15 | 90 | 4.3 | no | none | +0 same commit |
| integriq | 0a984caa0 | 2026-11-13 04:12 | 93 | 4.4 | yes | none | +0 |
| filinq | e1f08e30b | 2026-11-13 04:18 | 97 | 4.6 | yes | none | +7 (testcoderatio warn>pass, adrvalidator fail>pass) |
| thematiq | bafd06c33 | 2026-11-13 04:16 | 97 | 4.9 | yes | none | +8 (owaspsecurecoding warn>pass, adrvalidator warn>pass) |
| launchpad | 39f212172 | 2026-11-13 04:16 | 93 | 4.3 | yes | none | +12 (docker fail>pass, dockerimage fail>pass, helmchart fail>pass) |
| stackiq | 7cd10138f | 2026-11-13 04:12 | 92 | 4.5 | no | none | +0 |
| larpinq | 1137ab67c | 2026-11-13 04:15 | 97 | 4.7 | yes | none | +7 (adrvalidator fail>pass) |
| dossiq | d63da17a9 | 2026-11-13 04:14 | 88 | 4.4 | no | none | +0 |
| shillinq | e7b76198c | 2026-11-13 04:08 | 93 | 4.3 | yes | none | +0 |
| learniq | c16800b62 | 2026-11-13 04:10 | 93 | 4.3 | yes | none | +0 |
| portaliq | a893a302a | 2026-11-13 04:19 | 88 | 4.4 | no | none | +3 (adrvalidator warn>pass) |
| decidiq | 7cd7d1756 | 2026-11-13 04:07 | 93 | 4.3 | yes | none | +0 |
| buildiq | 5f5f87c67 | 2026-11-13 04:14 | 88 | 4.3 | no | none | +0 |
| keepiq | 2f62be103 | 2026-11-13 04:17 | 89 | 4.3 | yes | none | +0 |
| hermiq | beeb8ec1c | 2026-11-13 04:11 | 93 | 4.4 | yes | none | +0 |
| humaniq | 8d29580c1 | 2026-11-13 04:06 | 93 | 4.4 | yes | none | +0 |
| versioniq | 2d33f50fe | 2026-11-13 04:15 | 93 | 4.5 | yes | none | +0 |
| planninq | 8fc73df3d | 2026-11-13 04:06 | 93 | 4.4 | yes | none | +0 same commit |

## App x criterion

| criterion | level | openregister | pipelinq | opencatalogi | integriq | filinq | thematiq | launchpad | stackiq | larpinq | dossiq | shillinq | learniq | portaliq | decidiq | buildiq | keepiq | hermiq | humaniq | versioniq | planninq |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| sourcecode | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| openapi | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| license | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| eupllicense | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| licenceconsistency | rec | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| copyrightowner | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| spdxheaders | rec | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| publiccode | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| docker | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| dockerimage | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| cicd | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| sbom | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| documentation | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| changelog | rec | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| tests | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| coverage | man | P | P | P | P | P | P | P | F1 | P | F1 | P | P | P | P | F1 | P | P | P | P | P |
| complexity | inf | P | P | F1 | P | P | P | P | P | P | P | P | P | F1 | P | P | F1 | P | F1 | P | P |
| maintainability | rec | P | W3 | P | P | P | P | P | W3 | P | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 | P | P | P |
| opensourcehealth | rec | P | W3 | W3 | P | W3 | P | W3 | W3 | P | P | P | W3 | P | W3 | W3 | W3 | P | P | P | W3 |
| testcoderatio | rec | P | W3 | W3 | W3 | P | W3 | F2 | W3 | F2 | P | W3 | W3 | W3 | F2 | W3 | W3 | P | P | P | F2 |
| owaspsecurecoding | man | P | P | P | P | P | P | P | P | P | P | P | P | F1 | P | P | W3 | P | P | P | P |
| adrvalidator | man | P | W3 | F1 | W3 | P | P | W3 | P | P | W3 | W3 | W3 | P | W3 | W3 | W3 | W3 | W3 | W3 | W3 |
| contributing | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| codeofconduct | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| security | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| secretscanning | rec | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| openssfscorecard | rec | i | i | i | i | i | i | i | i | i | i | i | i | i | i | i | i | i | i | i | i |
| semver | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |
| fivelayer | man | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 | W3 |
| helmchart | man | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P | P |

Shared across the fleet (count of apps not passing):

- **coverage**: 3 (stackiq, dossiq, buildiq)
- **complexity**: 4 (opencatalogi, portaliq, keepiq, humaniq)
- **maintainability**: 10 (pipelinq, stackiq, dossiq, shillinq, learniq, portaliq, decidiq, buildiq, keepiq, hermiq)
- **opensourcehealth**: 10 (pipelinq, opencatalogi, filinq, launchpad, stackiq, learniq, decidiq, buildiq, keepiq, planninq)
- **testcoderatio**: 14 (pipelinq, opencatalogi, integriq, thematiq, launchpad, stackiq, larpinq, shillinq, learniq, portaliq, decidiq, buildiq, keepiq, planninq)
- **owaspsecurecoding**: 2 (portaliq, keepiq)
- **adrvalidator**: 14 (pipelinq, opencatalogi, integriq, launchpad, dossiq, shillinq, learniq, decidiq, buildiq, keepiq, hermiq, humaniq, versioniq, planninq)
- **fivelayer**: 20 (openregister, pipelinq, opencatalogi, integriq, filinq, thematiq, launchpad, stackiq, larpinq, dossiq, shillinq, learniq, portaliq, decidiq, buildiq, keepiq, hermiq, humaniq, versioniq, planninq)

## Findings per app

### openregister (97, rating 4.8)

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Service layer (back-end microservice); Data layer (data store / register); Integration layer (gateway / NLX); Process layer (orchestration / BFF). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer. The repository also references Common Ground.

### pipelinq (93, rating 4.3)

- **maintainability** WARN (recommended, 3 stars): Estimated maintainability rating 3.26 to 3.37 (3 of 5 stars) over 1421 file(s). 4 stars or more passes, 3 stars warns.
  - `Duplication: ★★★★☆ 3.6 (javascript 5.1, php 3.2, vue 4.0)`
  - `Unit size: ★★★★☆ 3.8 (javascript 5.0, php 3.4, vue 4.7)`
  - `Unit complexity: ★★★★☆ 3.8 (javascript 4.8, php 3.4, vue 5.0)`
  - `Unit interfacing: ★★★☆☆ 3.2 (javascript 5.1, php 2.5, vue 5.5)`
  - `Module coupling: ★★★★★ 5.0 (php 5.0)`
  - `Component independence: ★☆☆☆☆ 1.1 (php 1.1)`
  - `Component entanglement: ★★★☆☆ 3.2 (php 3.2)`
  - `Lever: module coupling in php, about 1752 lines to fix for half a star`
  - `Lever: unit complexity in php, about 2972 lines to fix for half a star`
  - `Lever: duplication in vue`
  - `Skipped 1 file(s) the analyser could not read`

- **opensourcehealth** WARN (recommended, 3 stars): Open Source Health 3 of 5 stars over 488 production dependencies (npm). 4 stars or more passes, 3 stars warns.
  - `Vulnerabilities: ★★★★☆ 0 critical, 0 high, 3 moderate`
  - `Freshness: ★★★☆☆ >1.0y 16.4%, >2.0y 10.7%, >3.5y 6%, >5.0y count 17`
  - `Activity: ★★☆☆☆ >1.0y 53.9%, >2.0y 38.9%, >4.0y 21.5%, >5.5y count 78`
  - `Licence use: ★★★☆☆ 0 critical, 0 high, 1 medium, 0 low risk (20 excluded by scope.yaml)`
  - `Management: ★★★★★ 0 unmanaged of 488 dependencies`

- **testcoderatio** WARN (recommended, 3 stars): Test code ratio 85.4% (3 of 5 stars).
  - `Test code ratio: 85.4%`
  - `Test code: 178,051 lines in 1,532 files`
  - `Production code: 208,493 lines in 1,457 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **adrvalidator** WARN (mandatory, 3 stars): No API specification could be resolved for ADR linting. Provide an API specification URL/path.

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Service layer (back-end microservice); Interaction layer (frontend / portal); Integration layer (gateway / NLX); Data layer (data store / register); Process layer (orchestration / BFF). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### opencatalogi (90, rating 4.3)

Endorsement blocked by: Mandatory criterion fails: API Design Rules

- **complexity** FAIL (informative, 1 stars): Lizard analysis ran. Avg CCN 1.90 (threshold: 15); Max CCN 31.00 (threshold: 30). Threshold exceeded: MaxCCN 31.00 > 30.
  - `Analyzer: lizard`
  - `Lizard returned non-zero exit code 1, but summary metrics were parsed.`
  - `Average CCN: 1.90 (threshold: 15)`
  - `Max CCN: 31.00 (threshold: 30)`
  - `5      3     28      1       5 stoppedOnError@15-19@src/services/harvestRunLabel.js`
  - `3      3     20      2       3 attemptError@59-61@src/services/listingSyncStatus.js`
  - `6      2     24      1       6 normalizePath@12-17@src/services/formatZodErrors.js`
  - `2      1     17      0       2 (anonymous)@34-35@src/services/formatZodErrors.js`
  - `6      1     29      1       7 getError@31-37@src/services/formatZodErrors.js`
  - `2      1     17      0       2 (anonymous)@54-55@src/services/formatZodErrors.js`

- **opensourcehealth** WARN (recommended, 3 stars): Open Source Health 3 of 5 stars over 664 production dependencies (composer, npm). 4 stars or more passes, 3 stars warns.
  - `Vulnerabilities: ★★★★☆ 0 critical, 0 high, 1 moderate`
  - `Freshness: ★★★☆☆ >1.0y 22.9%, >2.0y 18.1%, >3.5y 12.2%, >5.0y count 54`
  - `Activity: ★★☆☆☆ >1.0y 43.4%, >2.0y 33.7%, >4.0y 15.2%, >5.5y count 64`
  - `Licence use: ★★★☆☆ 0 critical, 0 high, 13 medium, 0 low risk (21 excluded by scope.yaml)`
  - `Management: ★★★★★ 0 unmanaged of 664 dependencies`

- **testcoderatio** WARN (recommended, 3 stars): Test code ratio 87.4% (3 of 5 stars).
  - `Test code ratio: 87.4%`
  - `Test code: 86,152 lines in 616 files`
  - `Production code: 98,545 lines in 597 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **adrvalidator** FAIL (mandatory, 1 stars): API Design Rules violations found across 1 specification(s): 1 error(s), 1 warning(s), 0 info, 0 hint(s).
  - `API specification was auto-discovered by the checker: openapi.json`
  - `Discovery source: auto-discovered`
  - `Spectral ruleset source: https://static.developer.overheid.nl/adr/ruleset.yaml`
  - `ERROR include-major-version-in-uri x1 (shown in evidence; e.g. servers.0.url)`
  - `WARNING paths-kebab-case x1 (shown in evidence; e.g. paths./robots.txt)`

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Service layer (back-end microservice); Integration layer (gateway / NLX); Data layer (data store / register); Process layer (orchestration / BFF). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer. The repository also references Common Ground.

### integriq (93, rating 4.4)

- **testcoderatio** WARN (recommended, 3 stars): Test code ratio 97.0% (3 of 5 stars).
  - `Test code ratio: 97.0%`
  - `Test code: 171,793 lines in 1,384 files`
  - `Production code: 177,143 lines in 1,769 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **adrvalidator** WARN (mandatory, 3 stars): API Design Rules warnings found across 1 specification(s): 0 error(s), 25 warning(s), 0 info, 0 hint(s).
  - `API specification was auto-discovered by the checker: openapi.json`
  - `Discovery source: auto-discovered`
  - `Spectral ruleset source: https://static.developer.overheid.nl/adr/ruleset.yaml`
  - `WARNING use-problem-schema x21 (shown in evidence; e.g. paths./lti/token.post.responses.400.content)`
  - `WARNING paths-kebab-case x4 (shown in evidence; e.g. paths./scim/v2/Groups)`

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Data layer (data store / register); Integration layer (gateway / NLX); Service layer (back-end microservice); Process layer (orchestration / BFF). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer. The repository also references Common Ground.

### filinq (97, rating 4.6)

- **opensourcehealth** WARN (recommended, 3 stars): Open Source Health 3 of 5 stars over 455 production dependencies (composer, npm). 4 stars or more passes, 3 stars warns.
  - `Vulnerabilities: ★★★★☆ 0 critical, 0 high, 1 moderate`
  - `Freshness: ★★★☆☆ >1.0y 17.2%, >2.0y 11%, >3.5y 5.7%, >5.0y count 15`
  - `Activity: ★★☆☆☆ >1.0y 42.4%, >2.0y 31.6%, >4.0y 14.1%, >5.5y count 41`
  - `Licence use: ★★★☆☆ 0 critical, 0 high, 1 medium, 15 low risk (20 excluded by scope.yaml)`
  - `Management: ★★★★★ 0 unmanaged of 455 dependencies`

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Service layer (back-end microservice); Integration layer (gateway / NLX); Process layer (orchestration / BFF); Data layer (data store / register). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### thematiq (97, rating 4.9)

- **testcoderatio** WARN (recommended, 3 stars): Test code ratio 84.0% (3 of 5 stars).
  - `Test code ratio: 84.0%`
  - `Test code: 82,236 lines in 491 files`
  - `Production code: 97,901 lines in 489 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Service layer (back-end microservice); Integration layer (gateway / NLX); Data layer (data store / register). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### launchpad (93, rating 4.3)

- **opensourcehealth** WARN (recommended, 3 stars): Open Source Health 3 of 5 stars over 474 production dependencies (npm). 4 stars or more passes, 3 stars warns.
  - `Vulnerabilities: ★★★☆☆ 0 critical, 1 high, 1 moderate`
  - `Freshness: ★★★☆☆ >1.0y 15%, >2.0y 9.7%, >3.5y 5.9%, >5.0y count 19`
  - `Activity: ★★☆☆☆ >1.0y 41.8%, >2.0y 31.2%, >4.0y 11.6%, >5.5y count 32`
  - `Licence use: ★★★☆☆ 0 critical, 0 high, 1 medium, 0 low risk (19 excluded by scope.yaml)`
  - `Management: ★★★★★ 0 unmanaged of 474 dependencies`
  - `Vulnerable: npm:dompurify@2.5.9 (high)`

- **testcoderatio** FAIL (recommended, 2 stars): Test code ratio 73.3% (2 of 5 stars).
  - `Test code ratio: 73.3%`
  - `Test code: 92,990 lines in 659 files`
  - `Production code: 126,830 lines in 709 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **adrvalidator** WARN (mandatory, 3 stars): No API specification could be resolved for ADR linting. Provide an API specification URL/path.

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Data layer (data store / register); Service layer (back-end microservice); Integration layer (gateway / NLX); Process layer (orchestration / BFF). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### stackiq (92, rating 4.5)

Endorsement blocked by: Mandatory criterion fails: Code coverage

- **coverage** FAIL (mandatory, 1 stars): Code coverage is 65.17%, below the required 80% threshold.
  - `coverage-summary.json: 65.17%`

- **maintainability** WARN (recommended, 3 stars): Estimated maintainability rating 3.08 to 3.30 (3 of 5 stars) over 404 file(s). 4 stars or more passes, 3 stars warns.
  - `Duplication: ★★★★☆ 3.8 (javascript 5.1, php 3.9, vue 3.2)`
  - `Unit size: ★★★☆☆ 3.0 (javascript 4.7, php 2.5, vue 4.2)`
  - `Unit complexity: ★★★☆☆ 3.0 (javascript 4.8, php 3.1, vue 1.5)`
  - `Unit interfacing: ★★★★★ 4.8 (javascript 5.1, php 4.6, vue 5.5)`
  - `Module coupling: ★★★★★ 5.5 (php 5.5)`
  - `Component independence: ★☆☆☆☆ 0.9 (php 0.9)`
  - `Component entanglement: ★★★☆☆ 3.1 (php 3.1)`
  - `Lever: unit complexity in vue, about 485 lines to fix for half a star`
  - `Lever: duplication in php, about 1237 lines to fix for half a star`
  - `Lever: unit complexity in php, about 1706 lines to fix for half a star`

- **opensourcehealth** WARN (recommended, 3 stars): Open Source Health 3 of 5 stars over 648 production dependencies (composer, npm). 4 stars or more passes, 3 stars warns.
  - `Vulnerabilities: ★★★★☆ 0 critical, 0 high, 4 moderate`
  - `Freshness: ★★★☆☆ >1.0y 18%, >2.0y 12.9%, >3.5y 8.1%, >5.0y count 37`
  - `Activity: ★★☆☆☆ >1.0y 52%, >2.0y 38.6%, >4.0y 20.7%, >5.5y count 96`
  - `Licence use: ★★★☆☆ 0 critical, 0 high, 1 medium, 0 low risk (20 excluded by scope.yaml)`
  - `Management: ★★★★★ 0 unmanaged of 648 dependencies`

- **testcoderatio** WARN (recommended, 3 stars): Test code ratio 85.5% (3 of 5 stars).
  - `Test code ratio: 85.5%`
  - `Test code: 88,368 lines in 725 files`
  - `Production code: 103,339 lines in 461 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Service layer (back-end microservice); Integration layer (gateway / NLX); Data layer (data store / register); Process layer (orchestration / BFF). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer. The repository also references Common Ground.

### larpinq (97, rating 4.7)

- **testcoderatio** FAIL (recommended, 2 stars): Test code ratio 66.1% (2 of 5 stars).
  - `Test code ratio: 66.1%`
  - `Test code: 29,584 lines in 234 files`
  - `Production code: 44,746 lines in 220 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Service layer (back-end microservice); Integration layer (gateway / NLX); Data layer (data store / register); Process layer (orchestration / BFF). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### dossiq (88, rating 4.4)

Endorsement blocked by: Mandatory criterion fails: Code coverage

- **coverage** FAIL (mandatory, 1 stars): Code coverage is 75.80%, below the required 80% threshold.
  - `coverage-summary.json: 75.80%`

- **maintainability** WARN (recommended, 3 stars): Estimated maintainability rating 3.16 to 3.29 (3 of 5 stars) over 2241 file(s). 4 stars or more passes, 3 stars warns.
  - `Duplication: ★★★★☆ 4.1 (javascript 5.2, php 4.1, vue 3.4)`
  - `Unit size: ★★★★☆ 3.5 (javascript 5.0, php 3.3, vue 4.7)`
  - `Unit complexity: ★★★★☆ 3.7 (javascript 4.8, php 3.5, vue 5.0)`
  - `Unit interfacing: ★★☆☆☆ 2.4 (javascript 5.0, php 1.9, vue 5.5)`
  - `Module coupling: ★★★★★ 5.0 (php 5.0)`
  - `Component independence: ★☆☆☆☆ 1.1 (php 1.1)`
  - `Component entanglement: ★★★☆☆ 2.8 (php 2.8)`
  - `Lever: duplication in php, about 3240 lines to fix for half a star`
  - `Lever: unit complexity in php, about 4243 lines to fix for half a star`
  - `Lever: duplication in vue`

- **adrvalidator** WARN (mandatory, 3 stars): No API specification could be resolved for ADR linting. Provide an API specification URL/path.

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Integration layer (gateway / NLX); Service layer (back-end microservice); Process layer (orchestration / BFF); Data layer (data store / register). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### shillinq (93, rating 4.3)

- **maintainability** WARN (recommended, 3 stars): Estimated maintainability rating 3.27 to 3.37 (3 of 5 stars) over 1447 file(s). 4 stars or more passes, 3 stars warns.
  - `Duplication: ★★★☆☆ 3.2 (javascript 5.2, php 3.1, typescript 5.5, vue 3.4)`
  - `Unit size: ★★★★☆ 4.1 (javascript 4.9, php 4.0, vue 4.4)`
  - `Unit complexity: ★★★★☆ 4.0 (javascript 4.7, php 3.9, vue 4.6)`
  - `Unit interfacing: ★★★☆☆ 3.0 (javascript 5.1, php 2.6, vue 5.4)`
  - `Module coupling: ★★★★★ 5.2 (php 5.2)`
  - `Component independence: ★☆☆☆☆ 1.2 (php 1.2)`
  - `Component entanglement: ★★☆☆☆ 2.3 (php 2.3)`
  - `Lever: module coupling in php, about 718 lines to fix for half a star`
  - `Lever: duplication in vue`
  - `Lever: unit complexity in php, about 3281 lines to fix for half a star`

- **testcoderatio** WARN (recommended, 3 stars): Test code ratio 88.2% (3 of 5 stars).
  - `Test code ratio: 88.2%`
  - `Test code: 202,951 lines in 1,535 files`
  - `Production code: 230,110 lines in 1,497 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **adrvalidator** WARN (mandatory, 3 stars): No API specification could be resolved for ADR linting. Provide an API specification URL/path.

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Service layer (back-end microservice); Integration layer (gateway / NLX); Data layer (data store / register); Process layer (orchestration / BFF). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### learniq (93, rating 4.3)

- **maintainability** WARN (recommended, 3 stars): Estimated maintainability rating 3.40 to 3.50 (3 of 5 stars) over 1051 file(s). 4 stars or more passes, 3 stars warns.
  - `Duplication: ★★★☆☆ 3.5 (javascript 5.4, php 3.3, vue 3.4)`
  - `Unit size: ★★★★☆ 4.1 (javascript 5.3, php 3.9, vue 4.5)`
  - `Unit complexity: ★★★★☆ 4.1 (javascript 5.1, php 3.7, vue 5.1)`
  - `Unit interfacing: ★★★☆☆ 3.1 (javascript 5.0, php 2.4, vue 5.4)`
  - `Module coupling: ★★★★★ 5.3 (php 5.3)`
  - `Component independence: ★☆☆☆☆ 1.1 (php 1.1)`
  - `Component entanglement: ★★★☆☆ 3.4 (php 3.4)`
  - `Lever: unit complexity in php, about 1846 lines to fix for half a star`
  - `Lever: duplication in vue, about 1670 lines to fix for half a star`
  - `Lever: unit interfacing in php, about 1331 lines to fix for half a star`

- **opensourcehealth** WARN (recommended, 3 stars): Open Source Health 3 of 5 stars over 527 production dependencies (npm). 4 stars or more passes, 3 stars warns.
  - `Vulnerabilities: ★★★☆☆ 0 critical, 1 high, 1 moderate`
  - `Freshness: ★★★☆☆ >1.0y 14.5%, >2.0y 9.6%, >3.5y 5.2%, >5.0y count 18`
  - `Activity: ★★☆☆☆ >1.0y 37%, >2.0y 27.1%, >4.0y 11.8%, >5.5y count 36`
  - `Licence use: ★★★☆☆ 0 critical, 0 high, 1 medium, 0 low risk (18 excluded by scope.yaml)`
  - `Management: ★★★★★ 0 unmanaged of 527 dependencies`
  - `Vulnerable: npm:dompurify@2.5.9 (high)`

- **testcoderatio** WARN (recommended, 3 stars): Test code ratio 82.7% (3 of 5 stars).
  - `Test code ratio: 82.7%`
  - `Test code: 122,209 lines in 1,070 files`
  - `Production code: 147,695 lines in 1,097 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **adrvalidator** WARN (mandatory, 3 stars): No API specification could be resolved for ADR linting. Provide an API specification URL/path.

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Service layer (back-end microservice); Integration layer (gateway / NLX); Data layer (data store / register); Process layer (orchestration / BFF). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### portaliq (88, rating 4.4)

Endorsement blocked by: Mandatory criterion fails: OWASP Secure Coding

- **complexity** FAIL (informative, 1 stars): Lizard analysis ran. Avg CCN 2.58 (threshold: 15); Max CCN 278.00 (threshold: 30). Threshold exceeded: MaxCCN 278.00 > 30.
  - `Analyzer: lizard`
  - `Lizard returned non-zero exit code 1, but summary metrics were parsed.`
  - `Average CCN: 2.58 (threshold: 15)`
  - `Max CCN: 278.00 (threshold: 30)`
  - `3      1      9      1       3 isConfigurationError@58-60@src/lib/portalHomePage.js`
  - `3      1     22      2      13 foldErrors@789-801@src/lib/trafficSummary.js`
  - `8      2     32      2       8 newErrorRow@810-817@src/lib/trafficSummary.js`
  - `3      1     20      2       7 addErrorPages@826-832@src/lib/trafficSummary.js`
  - `5      1     32      2       5 intakeError@119-123@src/site/lib/intakeApi.js`
  - `4      1     13      0       4 script.onerror@49-52@src/site/lib/loadSiteEditor.js`

- **maintainability** WARN (recommended, 3 stars): Estimated maintainability rating 3.26 to 3.46 (3 of 5 stars) over 1350 file(s). 4 stars or more passes, 3 stars warns.
  - `Duplication: ★★★★☆ 4.3 (javascript 5.0, php 4.3, vue 3.8)`
  - `Unit size: ★★★★☆ 4.4 (javascript 5.0, php 4.1, vue 4.9)`
  - `Unit complexity: ★★★☆☆ 3.3 (javascript 4.5, php 2.7, vue 3.7)`
  - `Unit interfacing: ★★★☆☆ 2.8 (javascript 4.6, php 1.5, vue 5.5)`
  - `Module coupling: ★★★★★ 4.6 (php 4.6)`
  - `Component independence: ★☆☆☆☆ 1.1 (php 1.1)`
  - `Component entanglement: ★★★★☆ 3.6 (php 3.6)`
  - `Lever: module coupling in php, about 1605 lines to fix for half a star`
  - `Lever: unit complexity in vue`
  - `Lever: duplication in vue, about 2211 lines to fix for half a star`

- **testcoderatio** WARN (recommended, 3 stars): Test code ratio 96.8% (3 of 5 stars).
  - `Test code ratio: 96.8%`
  - `Test code: 154,232 lines in 1,217 files`
  - `Production code: 159,341 lines in 1,598 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **owaspsecurecoding** FAIL (mandatory, 1 stars): Multiple risky coding patterns were detected (23 findings across 1598 scanned source files, failing from 16). Review them against OWASP secure coding guidance.
  - `Unsafe HTML injection: woo/static/js/1040.ffe8e2aa.chunk.js`
  - `Possible hardcoded secret: woo/static/js/6034.53d260e7.chunk.js`
  - `Possible hardcoded secret: woo/static/js/8591.83c9b2b3.js`
  - `Unsafe HTML injection: woo/static/js/9178.28a1eb09.chunk.js`
  - `Unsafe HTML injection: woo/static/js/common-315ae690.f6177006.js`
  - `Dynamic code execution: woo/static/js/runtime.1b18a5fe.js`
  - `Possible hardcoded secret: woo/static/js/shared.1e9e4efd.js`
  - `Unsafe HTML injection: woo/static/js/vendors-03f2294b.c2598c5e.js`
  - `Unsafe HTML injection: woo/static/js/vendors-0d41bb17.f4610485.js`
  - `Unsafe HTML injection: woo/static/js/vendors-0e5d714b.2655b636.js`
  - `Unsafe HTML injection: woo/static/js/vendors-536eaa00.69df62e0.js`
  - `Unsafe HTML injection: woo/static/js/vendors-5a94f17d.8927702b.js`
  - `Dynamic code execution: woo/static/js/vendors-69bdd742.74d7fa5d.js`
  - `Dynamic code execution: woo/static/js/vendors-a0aa3bc1.0b0e47d1.js`
  - `Unsafe HTML injection: woo/static/js/vendors-afec7163.5e19955b.js`
  - `... 8 more`

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Service layer (back-end microservice); Integration layer (gateway / NLX); Process layer (orchestration / BFF); Data layer (data store / register). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### decidiq (93, rating 4.3)

- **maintainability** WARN (recommended, 3 stars): Estimated maintainability rating 3.29 to 3.41 (3 of 5 stars) over 749 file(s). 4 stars or more passes, 3 stars warns.
  - `Duplication: ★★★★☆ 3.8 (javascript 5.2, php 4.3, vue 2.6)`
  - `Unit size: ★★★★☆ 3.7 (javascript 4.9, php 3.4, vue 4.1)`
  - `Unit complexity: ★★★★☆ 3.7 (javascript 4.8, php 3.2, vue 4.6)`
  - `Unit interfacing: ★★★☆☆ 3.3 (javascript 4.9, php 2.3, vue 5.5)`
  - `Module coupling: ★★★★★ 5.2 (php 5.2)`
  - `Component independence: ★☆☆☆☆ 1.1 (php 1.1)`
  - `Component entanglement: ★★★☆☆ 3.1 (php 3.1)`
  - `Lever: unit complexity in php, about 1687 lines to fix for half a star`
  - `Lever: duplication in php, about 1488 lines to fix for half a star`
  - `Lever: unit interfacing in php, about 1200 lines to fix for half a star`

- **opensourcehealth** WARN (recommended, 3 stars): Open Source Health 3 of 5 stars over 402 production dependencies (npm). 4 stars or more passes, 3 stars warns.
  - `Vulnerabilities: ★★★☆☆ 0 critical, 1 high, 1 moderate`
  - `Freshness: ★★★☆☆ >1.0y 15.5%, >2.0y 9.7%, >3.5y 4.5%, >5.0y count 11`
  - `Activity: ★★☆☆☆ >1.0y 41.5%, >2.0y 30.1%, >4.0y 11.4%, >5.5y count 27`
  - `Licence use: ★★★☆☆ 0 critical, 0 high, 1 medium, 0 low risk (18 excluded by scope.yaml)`
  - `Management: ★★★★★ 0 unmanaged of 402 dependencies`
  - `Vulnerable: npm:dompurify@2.5.9 (high)`

- **testcoderatio** FAIL (recommended, 2 stars): Test code ratio 47.0% (2 of 5 stars).
  - `Test code ratio: 47.0%`
  - `Test code: 103,057 lines in 778 files`
  - `Production code: 219,376 lines in 818 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **adrvalidator** WARN (mandatory, 3 stars): No API specification could be resolved for ADR linting. Provide an API specification URL/path.

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Service layer (back-end microservice); Interaction layer (frontend / portal); Integration layer (gateway / NLX); Data layer (data store / register); Process layer (orchestration / BFF). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### buildiq (88, rating 4.3)

Endorsement blocked by: Mandatory criterion fails: Code coverage

- **coverage** FAIL (mandatory, 1 stars): Code coverage is 79.92%, below the required 80% threshold.
  - `coverage-summary.json: 79.92%`

- **maintainability** WARN (recommended, 3 stars): Estimated maintainability rating 3.21 to 3.40 (3 of 5 stars) over 573 file(s). 4 stars or more passes, 3 stars warns.
  - `Duplication: ★★★★☆ 4.3 (javascript 5.0, php 4.4, vue 4.0)`
  - `Unit size: ★★★★☆ 3.6 (javascript 4.9, php 2.5, vue 4.9)`
  - `Unit complexity: ★★★☆☆ 3.1 (javascript 5.2, php 1.4, vue 5.0)`
  - `Unit interfacing: ★★★☆☆ 3.4 (javascript 5.1, php 1.9, vue 5.3)`
  - `Module coupling: ★★★★★ 5.0 (php 5.0)`
  - `Component independence: ★☆☆☆☆ 1.1 (php 1.1)`
  - `Component entanglement: ★★★★☆ 3.9 (php 3.9)`
  - `Lever: module coupling in php, about 1127 lines to fix for half a star`
  - `Lever: duplication in vue, about 1289 lines to fix for half a star`
  - `Lever: duplication in php`

- **opensourcehealth** WARN (recommended, 3 stars): Open Source Health 3 of 5 stars over 528 production dependencies (npm). 4 stars or more passes, 3 stars warns.
  - `Vulnerabilities: ★★★★☆ 0 critical, 0 high, 1 moderate`
  - `Freshness: ★★★☆☆ >1.0y 15.7%, >2.0y 9.7%, >3.5y 5.5%, >5.0y count 17`
  - `Activity: ★★☆☆☆ >1.0y 53.6%, >2.0y 40.3%, >4.0y 20.5%, >5.5y count 82`
  - `Licence use: ★★★☆☆ 0 critical, 0 high, 1 medium, 0 low risk (18 excluded by scope.yaml)`
  - `Management: ★★★★★ 0 unmanaged of 528 dependencies`

- **testcoderatio** WARN (recommended, 3 stars): Test code ratio 92.8% (3 of 5 stars).
  - `Test code ratio: 92.8%`
  - `Test code: 102,539 lines in 716 files`
  - `Production code: 110,539 lines in 614 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **adrvalidator** WARN (mandatory, 3 stars): No API specification could be resolved for ADR linting. Provide an API specification URL/path.

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Integration layer (gateway / NLX); Service layer (back-end microservice); Process layer (orchestration / BFF); Data layer (data store / register). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### keepiq (89, rating 4.3)

- **complexity** FAIL (informative, 1 stars): Lizard analysis ran. Avg CCN 1.75 (threshold: 15); Max CCN 49.00 (threshold: 30). Threshold exceeded: MaxCCN 49.00 > 30.
  - `Analyzer: lizard`
  - `Lizard returned non-zero exit code 1, but summary metrics were parsed.`
  - `Average CCN: 1.75 (threshold: 15)`
  - `Max CCN: 49.00 (threshold: 30)`
  - `3      1     30      1       3 (anonymous)::onError@117-119@mobile/android/app/src/androidTest/java/nl/conduction/keepiq/android/autofilltest/PasskeyClientActivity.java`
  - `3      1     30      1       3 (anonymous)::onError@139-141@mobile/android/app/src/androidTest/java/nl/conduction/keepiq/android/autofilltest/PasskeyClientActivity.java`
  - `3      1     19      3       3 (anonymous)::onReceivedSslError@33-35@mobile/android/app/src/androidTest/java/nl/conduction/keepiq/android/autofilltest/WebFormActivity.java`
  - `2      1     27      1       3 getError@239-241@mobile/android/app/src/main/kotlin/nl/conduction/keepiq/android/passkey/CredentialProviderActivity.kt`
  - `2      1     27      1       3 createErrorIntent@241-243@mobile/android/app/src/main/kotlin/nl/conduction/keepiq/android/passkey/CredentialProviderActivity.kt`
  - `23      5    192      1      27 createError@243-269@mobile/android/app/src/main/kotlin/nl/conduction/keepiq/android/passkey/CredentialProviderActivity.kt`

- **maintainability** WARN (recommended, 3 stars): Estimated maintainability rating 3.43 to 3.52 (3 of 5 stars) over 1124 file(s). 4 stars or more passes, 3 stars warns.
  - `Duplication: ★★★★☆ 4.2 (javascript 5.1, php 3.5, python 5.5, typescript 5.4, vue 3.9)`
  - `Unit size: ★★★★☆ 4.2 (javascript 4.4, php 3.6, python 5.3, typescript 5.1, vue 4.7)`
  - `Unit complexity: ★★★★☆ 4.4 (javascript 4.3, php 4.3, python 5.5, typescript 4.6, vue 4.9)`
  - `Unit interfacing: ★★★☆☆ 3.3 (javascript 4.8, php 1.5, python 5.3, typescript 5.0, vue 5.5)`
  - `Module coupling: ★★★☆☆ 3.1 (php 3.1, python 5.5)`
  - `Component independence: ★☆☆☆☆ 1.1 (php 1.1, python 5.5)`
  - `Component entanglement: ★★★★★ 4.5 (php 4.5, python 5.5)`
  - `Lever: module coupling in php, about 124 lines to fix for half a star`
  - `Lever: duplication in php, about 1594 lines to fix for half a star`
  - `Lever: duplication in vue`

- **opensourcehealth** WARN (recommended, 3 stars): Open Source Health 3 of 5 stars over 453 production dependencies (composer, npm). 4 stars or more passes, 3 stars warns.
  - `Vulnerabilities: ★★★★☆ 0 critical, 0 high, 1 moderate`
  - `Freshness: ★★★☆☆ >1.0y 14.6%, >2.0y 9.1%, >3.5y 4.2%, >5.0y count 11`
  - `Activity: ★★☆☆☆ >1.0y 40.6%, >2.0y 29.8%, >4.0y 13.2%, >5.5y count 39`
  - `Licence use: ★★★☆☆ 0 critical, 0 high, 1 medium, 0 low risk (21 excluded by scope.yaml)`
  - `Management: ★★★★★ 0 unmanaged of 453 dependencies`

- **testcoderatio** WARN (recommended, 3 stars): Test code ratio 91.8% (3 of 5 stars).
  - `Test code ratio: 91.8%`
  - `Test code: 180,110 lines in 1,123 files`
  - `Production code: 196,206 lines in 1,255 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **owaspsecurecoding** WARN (mandatory, 3 stars): Potential OWASP secure coding issues were detected (1 findings across 1243 scanned source files, failing from 13).
  - `Possible hardcoded secret: src/utils/favicon.js`

- **adrvalidator** WARN (mandatory, 3 stars): API Design Rules warnings found across 1 specification(s): 0 error(s), 536 warning(s), 0 info, 0 hint(s).
  - `API specification was auto-discovered by the checker: openapi.json`
  - `Discovery source: auto-discovered`
  - `Spectral ruleset source: https://static.developer.overheid.nl/adr/ruleset.yaml`
  - `WARNING use-problem-schema x47 (shown in evidence; e.g. paths./admin.get.responses.401.content)`

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Service layer (back-end microservice); Data layer (data store / register); Integration layer (gateway / NLX); Process layer (orchestration / BFF). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### hermiq (93, rating 4.4)

- **maintainability** WARN (recommended, 3 stars): Estimated maintainability rating 3.25 to 3.33 (3 of 5 stars) over 751 file(s). 4 stars or more passes, 3 stars warns.
  - `Duplication: ★★★★☆ 3.6 (javascript 5.4, php 3.5, vue 3.3)`
  - `Unit size: ★★★★☆ 3.8 (javascript 4.9, php 3.6, vue 4.6)`
  - `Unit complexity: ★★★★★ 4.5 (javascript 4.8, php 4.4, vue 4.8)`
  - `Unit interfacing: ★★☆☆☆ 2.3 (javascript 5.2, php 1.5, vue 5.5)`
  - `Module coupling: ★★★★★ 4.6 (php 4.6)`
  - `Component independence: ★☆☆☆☆ 1.1 (php 1.1)`
  - `Component entanglement: ★★★☆☆ 3.0 (php 3.0)`
  - `Lever: unit interfacing in php, about 465 lines to fix for half a star`
  - `Lever: duplication in php, about 1227 lines to fix for half a star`
  - `Lever: duplication in vue, about 1507 lines to fix for half a star`

- **adrvalidator** WARN (mandatory, 3 stars): API Design Rules warnings found across 1 specification(s): 0 error(s), 6 warning(s), 0 info, 0 hint(s).
  - `API specification was auto-discovered by the checker: openapi.json`
  - `Discovery source: auto-discovered`
  - `Spectral ruleset source: https://static.developer.overheid.nl/adr/ruleset.yaml`
  - `WARNING use-problem-schema x6 (shown in evidence; e.g. paths./openapi.json.get.responses.404.content)`

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Service layer (back-end microservice); Data layer (data store / register); Integration layer (gateway / NLX); Process layer (orchestration / BFF). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### humaniq (93, rating 4.4)

- **complexity** FAIL (informative, 1 stars): Lizard analysis ran. Avg CCN 2.22 (threshold: 15); Max CCN 33.00 (threshold: 30). Threshold exceeded: MaxCCN 33.00 > 30.
  - `Analyzer: lizard`
  - `Lizard returned non-zero exit code 1, but summary metrics were parsed.`
  - `Average CCN: 2.22 (threshold: 15)`
  - `Max CCN: 33.00 (threshold: 30)`
  - `3      3     28      1       3 previewError@248-250@src/dialogs/CompCycleRunDialog.vue`
  - `3      1     19      1       3 showError@47-49@tests/js/support/nextcloudFakes.mjs`
  - `7      1     92      0       9 EmployerCostRateControllerTest::testAnUnexpectedFailureIsAServerErrorWithAFixedMessage@402-410@tests/Unit/Controller/EmployerCostRateControllerTest.php`
  - `14      1    154      0      18 LoonbeslagControllerTest::testAFailedLookupLogsANeutralWarning@375-392@tests/Unit/Controller/LoonbeslagControllerTest.php`
  - `17      1    160      0      19 MigrateUserPreferencesTest::testTheReportAndTheWarningOfAMixedWalk@324-342@tests/Unit/Repair/MigrateUserPreferencesTest.php`
  - `4      1     49      0       5 InitializeRegisterTest::testAnImportWithErrorsIsReportedAsAnIssue@88-92@tests/Unit/Repair/InitializeRegisterTest.php`

- **adrvalidator** WARN (mandatory, 3 stars): No API specification could be resolved for ADR linting. Provide an API specification URL/path.

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Service layer (back-end microservice); Process layer (orchestration / BFF); Integration layer (gateway / NLX); Data layer (data store / register). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### versioniq (93, rating 4.5)

- **adrvalidator** WARN (mandatory, 3 stars): API Design Rules warnings found across 2 specification(s): 0 error(s), 87 warning(s), 0 info, 0 hint(s).
  - `API specification was auto-discovered by the checker: openapi.json`
  - `Discovery source: auto-discovered`
  - `Spectral ruleset source: https://static.developer.overheid.nl/adr/ruleset.yaml`
  - `WARNING use-problem-schema x47 (shown in evidence; e.g. paths./apps.get.responses.403.content)`

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Data layer (data store / register); Service layer (back-end microservice); Interaction layer (frontend / portal). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.

### planninq (93, rating 4.4)

- **opensourcehealth** WARN (recommended, 3 stars): Open Source Health 3 of 5 stars over 430 production dependencies (npm). 4 stars or more passes, 3 stars warns.
  - `Vulnerabilities: ★★★★☆ 0 critical, 0 high, 1 moderate`
  - `Freshness: ★★★☆☆ >1.0y 15.6%, >2.0y 10.3%, >3.5y 5.4%, >5.0y count 14`
  - `Activity: ★★☆☆☆ >1.0y 45.6%, >2.0y 34.4%, >4.0y 12.8%, >5.5y count 32`
  - `Licence use: ★★★☆☆ 0 critical, 0 high, 1 medium, 0 low risk (18 excluded by scope.yaml)`
  - `Management: ★★★★★ 0 unmanaged of 430 dependencies`

- **testcoderatio** FAIL (recommended, 2 stars): Test code ratio 36.8% (2 of 5 stars).
  - `Test code ratio: 36.8%`
  - `Test code: 33,727 lines in 353 files`
  - `Production code: 91,682 lines in 366 files`
  - `Bands: >=120% = 5 stars, >=100% = 4 stars, >=75% = 3 stars, >=25% = 2 stars, below 25% = 1 star (100% test code is roughly 80% coverage)`

- **adrvalidator** WARN (mandatory, 3 stars): No API specification could be resolved for ADR linting. Provide an API specification URL/path.

- **fivelayer** WARN (mandatory, 3 stars): Detected layer signals: Interaction layer (frontend / portal); Service layer (back-end microservice); Integration layer (gateway / NLX); Data layer (data store / register); Process layer (orchestration / BFF). This repository appears to span multiple Common Ground layers. Consider splitting responsibilities or clarifying the primary layer.
