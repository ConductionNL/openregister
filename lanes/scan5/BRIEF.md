# Lane brief: SCAN5 (every criterion of an app to 5 stars)

Claimed as a `lane` task (WORKER.md). Payload: `brief=lanes/scan5/BRIEF.md;app=<app>;item=scan;branch=quality/scan5-<app>-<k>;note=<text>`.

## Goal
Scan the app with the checker, then raise every criterion that is below 5 stars and has a real, cheap fix inside the app. One PR per criterion, to development. Leave out:
- maintainability: the arch5 lanes own it.
- fivelayer: 3 stars is a known checker limit (it matches path names in docs); no app fix.
- anything the checker lists as `unmeasured`: record the host or tool in the result, fix nothing for it.

## Setup
```
git clone -q https://github.com/rubenvdlinde/CommonGroundChecker /tmp/lanes/scan/checker
git -C /tmp/lanes/scan/checker checkout -q aa578398248c36aeb9f3d4620772c78993f41c52
cd /tmp/lanes/scan/checker && npm ci --silent
git clone -q https://github.com/ConductionNL/<app> /tmp/lanes/scan5/<app>
cd /tmp/lanes/scan/checker && npm run --silent scan:local -- /tmp/lanes/scan5/<app> --owner ConductionNL --repo <app> --full --out /tmp/lanes/scan5/<app>.json
```
Scan the clone as cloned: no `vendor/`, no `node_modules/`.

## Fixing
- Per criterion below 5 stars: read what holds it down in the scan JSON, fix the cause, not the measurement. Never allowlist, exclude or delete a real finding to raise a score. A test fixture that trips secret scanning may be built at runtime instead.
- Typical real fixes: a dependency override or bump for an advisory (opensourcehealth vulnerabilities), a missing SPDX header, a test for untested code (testcoderatio), splitting one over-complex function (complexity), a real secure-coding finding.
- Each PR: branch `quality/scan5-<app>-<criterion>` from origin/development, the diff check from WORKER.md green, title `fix(<app>): <criterion> to 5 stars` or `chore(deps): ...`, body with the criterion's stars before and after (re-scan the branch).
- Something that is not cheap (over about 400 changed lines, or it needs a design decision): do not build it; name it in the result.

## Result
`task_done <id> "<app>: <criterion> <stars> -> PR #n; ...; not cheap: ...; unmeasured: ..."`.
