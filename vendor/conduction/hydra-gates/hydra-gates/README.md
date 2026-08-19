# conduction/hydra-gates

The mechanical quality gates, packaged so **any** repo can run them against
its own diff — without hydra, without hydra's containers, and without
credentials.

> **How many gates are there?** Ask the runner, not a README — and read the
> COVERAGE line, not the banner:
>
> ```
> [hydra-gates] ALL 61 GATES GREEN
> [hydra-gates] COVERAGE: 58 of 61 declared gates reported a result.
> [hydra-gates] GATES THAT DID NOT RUN: 4 24 33
> ```
>
> That is a real run (openbuild#113, 2026-08-04, pinned `v1.0.1`). Three
> defensible numbers for one sentence: **61** declared at that pin, **63**
> declared on `main`, **58** actually executed. The figure a reader needs is
> per-run — it moves with the consumer's pinned ref, their diff and their
> toolchain — so no static count can be right for everyone.
>
> This line used to carry one anyway. A count in prose has nothing to
> reconcile against; the runtime coverage block does. Consumers and other
> repos should write "the Hydra mechanical quality gates" and link here.
> `hydra-gates-require-full-coverage` is ON by default: a gate whose subject
> matter exists but did not report is an error. Gates that are legitimately not
> applicable do not fail it.

This directory is the **single source of truth** for the gate runner
(`scripts/run-hydra-gates.sh`), its ~25 Python/JS helpers (`scripts/lib/`), its
vendored schemas (`scripts/schemas/`) and the distributable entry point
(`bin/hydra-gates`). `ConductionNL/hydra` no longer carries a copy — it
delegates here (see [Why it lives in `.github`](#why-it-lives-in-github)).

---

## Adopting it in a repo

### Option A — the shared CI workflow (no composer needed)

If your repo already calls the shared quality workflow, set one input:

```yaml
jobs:
  quality:
    uses: ConductionNL/.github/.github/workflows/quality.yml@main
    with:
      app-name: yourapp
      enable-hydra-gates: true
```

The `hydra-gates` job checks this repository out, resolves the PR's real base
branch, and runs the gates. It works for any repo — PHP or not.

**It is opt-in and defaults to `false`.** A hard gate switched on for the whole
fleet at once would turn many repos red simultaneously; each repo enables it
when its own diffs are clean.

### Option B — `composer require-dev` (gates inside `check:strict`)

Three edits to `composer.json`, then one `composer update`.

**1. Point composer at this repository.** It is **public**, so this needs no
credentials in any repo or CI runner. `"no-api": true` makes composer clone over
git rather than the GitHub API, so a rate-limited unauthenticated runner cannot
turn into a failed install:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/ConductionNL/.github.git",
        "no-api": true
    }
]
```

**2. Require it:**

```json
"require-dev": {
    "conduction/hydra-gates": "^1.0"
}
```

**3. Add the scripts, and put `gates` in `check:strict`:**

```json
"gates":      "hydra-gates --app-dir .",
"gates:full": "hydra-gates --app-dir . --full"
```

Then `composer update conduction/hydra-gates`.

`vendor/conduction/hydra-gates` lands at about 1.2 MB. The org profile, the
website and the docs tree are `export-ignore`d and do not follow.

### Upgrading to `v1.1.0` from `v1.0.x`

`^1.0` picks this up on the next `composer update`, and **verdicts move**. Three
things to do before you upgrade:

1. **Make `ajv` resolvable before the gates run** — `npm ci`, or
   `npm --prefix <package-dir> install --no-save ajv` (Option A's shared
   workflow already does this). Gate 22 previously fell back to a structural
   lint that checks only the AppHost blocks and reported the result as a PASS;
   it now **fails** with a named reason rather than certifying a manifest it
   never schema-validated. Gate 53 has always refused to run without it.
2. **Expect gate-22 verdicts to move in both directions.** Its verdict used to
   come from the app's own `npm run check:manifest`; it now comes from the
   vendored canonical validator, and the app script is surfaced as an advisory.
   Apps whose local checker was weaker will surface real findings; apps failed
   by a stale app-local page-type enum will go green.
3. **Treat `: SKIPPED` and `: NOT APPLICABLE` as "did not run", not as a pass**,
   if you parse `^\[gate-N\]` lines. Two verdicts, and they differ: `SKIPPED
   (structural|wiring)` is a coverage GAP, `NOT APPLICABLE` means the gate had
   no subject matter here. Neither is a result. See *Reading a green* below.

The upside: **gate 53 becomes usable under `--scope-to-diff`**. It was
previously unenablable on any repo with manifest debt, because a one-line change
reproduced the full-repo finding count exactly.

### Gates 12–22, audited by planting a defect in each (`.github#271`)

Every gate in this band was given one textbook true positive in a real fleet
repo and asked to catch it. Four could not, and their PASS lines meant nothing:

| Gate | What it was doing instead |
|---|---|
| **20** or-objectservice-api | **Had never fired, anywhere.** Its search pattern began with `->`, which `grep` parses as options (`invalid option -- '>'`, exit 2); `2>/dev/null \|\| true` discarded both the message and the status. Now uses `grep -- …`, and the receiver (`$…objectService->`) is part of the pattern rather than "the file mentions ObjectService", which alone would have produced 14 false findings on openregister. |
| **17** redundant-controller | Four of the six names in its ObjectService CRUD list do not exist on ObjectService — they are what gate-20 flags as fabricated — and `return new JSONResponse($this->objectService->findAll(…))` was discarded as "response wrapping" before the call inside it was ever looked at. The commonest pass-through shape was invisible. |
| **14** route-reachability | The ten routes `AppHost\Routes::standard()` supplies are not literals in a leaf's `appinfo/routes.php`, so invariant 2 never judged them. Deleting `SettingsController::update()` from an adopter left `PUT /api/settings` resolving to nothing — a ReflectionException 500, not a 404 — and the gate reported PASS (`#265`, closed). |
| **15, 16, 18** | `2>/dev/null \|\| true` + `wc -l`: a checker that crashed wrote nothing, so the count was 0, so the gate said PASS. With `python3` replaced by a stub that always exits 1, gates 12 and 17 said `SKIPPED (wiring)` and 15/16/18 said `PASS`. They now require the checker's terminal `# count=` marker. |

Also: **gate-22's verdict no longer depends on where the gates were checked
out.** `ajv` was resolved relative to this package, so an app with
`node_modules/ajv` installed in its own root still got
`SCHEMA VALIDATION DID NOT HAPPEN` when the package sat outside its tree —
and the message asserted "no node_modules", which was false. Resolution is now
anchored on the manifest's own repo root, then cwd, then this package, and the
degradation names every directory it searched.

Verdicts move: gate-20 and gate-17 can now produce findings in repos that were
green. Gates 15/16/18 can now report `SKIPPED (wiring)`, which is a coverage
gap, not a pass — see *Reading a green*.

---

## What it needs at runtime

`bash`, `git`, `python3` (about twenty gates are Python helpers) and `node`
(gates 22 and 53 only). PHP is required only because composer is one of the two
delivery mechanisms; **no gate executes PHP**.

**Gates 22 and 53 need `ajv` resolvable, and both now say so instead of
degrading quietly.** `ajv` is already a transitive devDependency in every fleet
app's `package-lock.json`, so a `npm ci` resolves it; a bare checkout without
`node_modules` does not. Gate 53 has always refused to run without it. Gate 22
used to fall back to a structural lint that checks only the AppHost blocks and
report the result as a PASS — a manifest certified without ever being
schema-validated. It now fails with a named reason instead. Set `NODE_PATH` or
run `npm ci` before the gates if you see it.

**No gate needs a Nextcloud runtime.** Nothing under `scripts/` loads
`../../lib/base.php` — that constraint belongs to `phpunit`, not to the gates,
so a repo can gate itself without an instance.

Anything missing is named on stderr along with what it left uncovered. There is
no `|| echo '...skipping'` anywhere in this package: a missing prerequisite is a
loud, visible, stated skip, and a gate that could not run is **never** counted
toward a green.

---

## The exit code

**The exit code is the number of failing gates.** It is not collapsed to 0/1,
because flows route on the count.

| Code | Meaning |
| --- | --- |
| `0` | every gate that ran passed |
| `1`..`n` | that many gates failed |
| `98` | passed, but coverage was incomplete — only with `--require-full-coverage` |
| `99` | **the gates could not run at all** — unresolvable base ref, missing runner, not a git repo |

`99` sits deliberately outside the gate-count range so a configuration error can
never be misread as 99 failing gates.

A caller that wraps this in a 0/1 contract (`composer check:strict`) must
capture and report the gate exit code separately, so a `99` stays visibly
distinct from a gate failure. Nothing was gated is not the same as nothing was
wrong.

---

## Diff scoping and the base ref

The gates are diff-scoped per ADR-020: a PR is judged on what it changed, not on
what it inherited. This matters — openbuild fails 16 gates on a full-repo run
today and passes when scoped to a real diff. `composer gates:full` gives the
audit view and is deliberately not what `check:strict` runs.

Diff scoping is only as trustworthy as the base ref, and a base that resolves to
nothing produces a report of zero failures that is indistinguishable from a
clean one. So:

- The base is resolved from a stated precedence chain and **printed** every run:
  `--base` → `$HYDRA_GATE_BASE_REF` → `origin/HEAD` → `origin/development` →
  `origin/main` → `origin/master`.
- **An unresolvable base stops the run with exit 99.** It is never treated as an
  empty diff, and no green is printed.
- **A base you named explicitly is never silently replaced.** Substituting a
  different one would scope the run to something you did not ask for and would
  not read about.
- **There is no `HEAD~1` fallback.** On a squash-merged mainline `HEAD~1` is the
  previous release, so it would silently scope a PR against the wrong tree.
- **`@{upstream}` is deliberately excluded, and must never be added back.** The
  tracking branch of a feature branch is that same branch on the remote, so
  diffing against it compares the branch to itself and returns an empty set the
  moment the branch is pushed — which is exactly when CI runs. This was not
  theoretical: the first end-to-end run of this package reported **58 gates
  green over 0 changed files**.
- A genuinely empty diff is **stated as empty** rather than reported as a pass.

### When the base IS HEAD: pushes to a mainline branch

A base that resolves to the **same commit as HEAD** is a valid ref, so nothing
in the chain above rejects it — and it makes the diff empty by construction.
This is not a rare misconfiguration. It is what **every push to `development`
or `main` looks like**: `origin/development` and `HEAD` are the same commit.

Two readings of that have shipped, and both were useless:

| version | behaviour on a mainline push | why it is worthless |
|---|---|---|
| ≤ v1.4.0 | empty diff → every gate iterates nothing → **PASS** | permanently green. Measured on shillinq `c64e9fe`: 52 gates green in 22 s; the same commit unscoped fails 18 |
| v1.5.0 | **exit 99**, refuse to pass over nothing | correct about the evidence, and it fires on every mainline push in every repo — permanently red |

A permanently-green gate and a permanently-red gate carry the same amount of
information about the code, and both train readers to stop looking.

From **v1.5.1** the base is not missing on a push — it is simply not the
branch's own name. GitHub supplies the pusher's previous tip as
`github.event.before`, so the honest scope for a push is `before...HEAD`:
what this push actually changed. For a squash-merge that is exactly the
squashed commit's diff; for a merge commit it is everything the merge brought
in.

This engages **only** when the resolved base is already HEAD — i.e. only where
the alternative is a refusal. A push to a feature branch, where
`origin/development` is genuinely behind HEAD, is scoped exactly as before and
ignores the event entirely. Every pull-request run is untouched.

Where the push's previous tip genuinely cannot be used, the run still exits 99,
and the reason is named:

| case | decision |
|---|---|
| `before` is the null SHA (this push **created** the branch) | **99.** There is no previous state; the only available scope is the whole tree, which is the audit mode and would report inherited debt as if this push wrote it |
| `before` == HEAD (a push that moved the ref nowhere) | **99.** Still a self-comparison, whatever supplied it |
| `before` is unreachable (**force-push** abandoned it) | one targeted `git fetch origin <sha>`, then `--unshallow` if the checkout is shallow; if it still cannot be fetched, **99** |
| shallow checkout (`fetch-depth: 1`) | recovered by that same fetch — this is the common, fixable cause |
| no shared history (force-push onto an unrelated tree) | **99** |

`$HYDRA_GATE_PUSH_BEFORE` overrides the event payload. It exists so the
invariant suite can drive each row above without a runner, and so a human can
reproduce a CI run locally with the scope CI used.

### Scope granularity, and where file granularity is not enough

Most gates scope by **file**: a finding in a file the PR did not touch does not
block. Gates 51 (schema-property-titles) and 55 (detail-page-discipline) go
finer and scope by the **changed lines** inside a touched file, so legacy debt
elsewhere in a file you edited does not block either.

Gate 53 (effective-manifest-crossref) used to scope by file, and for that gate
file granularity was indistinguishable from no scoping at all: an app's entire
navigation surface lives in `src/manifest.json` + `src/manifest.d/*`, so
touching any of it re-judged all of it. Measured 2026-08-03 on a one-line
`title` change:

| repo | full-repo | diff-scoped (before) | diff-scoped (after) |
| --- | --- | --- | --- |
| pipelinq | 24 | 24 | 0 blocking, 24 reported PRE-EXISTING |
| shillinq | 246 | 246 | 0 blocking, 397 reported PRE-EXISTING |

Gate 53 now separates two things that are not the same:

- **Answering** a cross-reference needs the whole assembled manifest. You cannot
  resolve `menu[].route` → page id, or check the ADR-044 no-orphan-removal
  invariant, from a diff. That part of the gate is legitimately whole-repo and
  stays so.
- **Blocking** on the answer does not. Every finding carries a JSON pointer that
  resolves to a page id, a menu id or a top-level block, and it blocks only when
  the PR touched that entry.

Findings on untouched entries are **printed as `PRE-EXISTING`, never dropped**,
and their count is reported on stdout, so a scoped green cannot be read as "the
manifest is clean". Findings that address the manifest as a whole (no entry to
attribute them to), and any PR whose scope cannot be determined — a brand-new
fragment untracked at base, a changed register JSON, a parse failure — block
regardless. Unverifiable scope is never treated as narrow scope.

The only part of the suite that is whole-repo **by nature** — as opposed to by
oversight — is that residual set of gate-53 invariants. Everything else measured
on 2026-08-03 (gates 34, 51, 55) narrows correctly, and the two other
whole-manifest checks in the same family, gates 22 and 52, are triggered only
by a change to the artefact they judge.

---

## Reading a green

A green from this package says how much it covers, because a green that
overstates its coverage is the same defect as `|| echo '...skipping'` one layer
up.

Every gate in the runner is wrapped in a prerequisite test (`if [ -d src ]`,
`if [ -f tests/axe/report.json ]`, …). Until 2026-08-03 a gate whose
prerequisite was absent emitted **nothing at all** — no line, no count, no
trace — and the runner still closed with `ALL 63 GATES GREEN`. Measured across
13 fleet repos, **gate 33 (axe-core) had never run in any of them**: the
`tests/axe/report.json` it consumes is produced by a `scripts/run-browser-tests.sh`
that exists in no app, while `axe-core` sits in every app's `devDependencies`
so the prerequisite looks wired. Every green the fleet had ever produced
excluded accessibility runtime checking, and nothing said so. Gate 24
(integration-parity) was absent in most repos for the same structural reason.

Both layers now account for it. A gate that cannot run says so on its own line:

```
[gate-24] integration-parity: SKIPPED — no scripts/check-integration-parity.sh …
[gate-33] axe-core: SKIPPED — no tests/axe/report.json in this repo — axe-core
          never ran against a rendered DOM, so contrast / landmark /
          ARIA-validity / live-region accessibility is UNVERIFIED. …
```

Since 2026-08-04 gate 33's report can actually be produced. The shared quality
workflow (`ConductionNL/.github/.github/workflows/quality.yml`) takes an opt-in
`enable-axe` input that runs `@axe-core/playwright` against the app's routes
inside the Playwright job — the only job with both a booted Nextcloud and a
browser — and hands `tests/axe/report.json` to the gates job as an artifact.
Set `enable-axe: true` (plus `axe-routes` for a settings-only app, which has no
root route) and gate 33 stops reporting SKIPPED.

It is off by default for the same reason `enable-hydra-gates` is: measured
against a **vanilla** Nextcloud 34 with no Conduction app installed at all,
core's own `/apps/files/` and `/settings/user` already carry three
serious/critical violations, so the first run in a repo will be red and part of
that red is not the app's to fix.

The runner that produces the report refuses to write one it has not earned — it
self-tests against a deliberate `button-name` violation on every run, rejects a
route that answered HTTP ≥ 400, and the gates job re-validates the downloaded
file before gate 33 reads it. All of that exists because gate 33 reports **PASS**
on a `tests/axe/report.json` containing exactly `{}`: a report written by a
crashed step would convert this gate's loud skip into a silent false pass, which
is worse than where it started.

and every run — `bin/hydra-gates` **and** a direct `run-hydra-gates.sh`
invocation — ends with the accounting:

```
[hydra-gates] COVERAGE: 60 of 63 declared gates reported a result (2 not applicable
[hydra-gates]           to this repo/diff; 60 of 61 applicable gates ran).
[hydra-gates] NOT APPLICABLE — subject matter absent from this repo or this diff.
[hydra-gates] These do NOT count against coverage. Each stated its own reason above:
[hydra-gates]   gate-4 composer-audit
[hydra-gates]   gate-33 axe-core
[hydra-gates] GATES THAT DID NOT RUN — they inspected NOTHING, and their subject
[hydra-gates] matter is UNVERIFIED by this run:
[hydra-gates]   gate-24 integration-parity
[hydra-gates] 60 GATE(S) GREEN — but 1 of 61 APPLICABLE gates DID NOT RUN (named above).
```

### Three states, not two

Neither a `SKIPPED` nor a `NOT APPLICABLE` line counts as coverage — a gate
reporting that it did nothing did nothing. But only two of the three fail:

| verdict | meaning | counts against coverage? |
|---|---|---|
| `NOT APPLICABLE` | the gate's subject matter does not exist in this repo or this diff — no `src/` at all, or a diff with no composer file under ADR-020 scoping | **no** |
| `SKIPPED (structural)` | the subject matter EXISTS and nothing produced the gate's input — e.g. a repo that registers integration leaves but ships no parity check | yes |
| `SKIPPED (wiring)` | the gate's own machinery is missing — a helper script, a tool not on PATH | yes |

`--require-full-coverage` (ON by default in the shared workflow) turns the last
two into exit 98. It ignores the first — that is what makes it switchable-on at
all: before the split, a Tier-0 app with nothing wrong with it failed on 30 of
63 gates, 25 of them merely because they are wrapped in `if [ -d src ]`.

Two properties stop `NOT APPLICABLE` becoming a mute:

- **Silence still counts against coverage.** A gate that emits nothing at all is
  a gap by default. To stop counting it must *declare* itself not-applicable, by
  name and with a reason.
- **The category is validated.** `na`, `structural` and `wiring` are the only
  accepted values; anything else is a hard gate failure, never a default. A gate
  cannot stop counting by misspelling its reason.

`--axe-enabled` (set by the shared workflow from `enable-axe`) tells the runner
whether the caller undertook to produce gate-33's report. Without it a missing
report means "this repo has not opted into runtime a11y enforcement" — a visible
choice in the caller's workflow, not a hidden gap. With it, a missing report
means the producer broke, and that fails.

The inventory is read out of the runner itself rather than hardcoded, so adding
gate 64 does not silently leave the coverage check measuring against a stale 63.

### Waivers

Gates 16, 19 and 26 honour reason-bearing `@spec exclude`, `@e2e exclude` and
`@visual exclude` tags. That is a better mechanism than a baseline file, because
the justification lives at the point of use — but it shares the failure mode of
`decidesk/phpmd.baseline.xml`, which suppresses nothing while reading as
protection. So every run states how many waivers it honoured:

```
[hydra-gates] WAIVERS: 1 file(s) carry an '@spec exclude <reason>' tag.
[hydra-gates] WAIVERS: 92 file(s) carry an '@e2e exclude <reason>' tag.
```

A green earned by passing is then distinguishable from one earned by waiving.

### Declaring an admin-only endpoint (gate 5)

`@auth admin-only <reason>` is a **declaration**, not a waiver, and it exists
because gate 5 was otherwise unsatisfiable for a whole class of correct code.

In Nextcloud, **admin is the default and is expressed by the ABSENCE of an
attribute**: `#[NoAdminRequired]` *widens* access, so an admin-only controller
method has no attribute available to declare itself with. Gate 5's finding is
precisely "no attribute", so before 2026-08-08 such a method could only be
reported — or silently exempted by the bug in
[#196](https://github.com/ConductionNL/.github/issues/196), where a docblock
*mentioning* `#[NoAdminRequired]` satisfied the grep. Two methods with
identical auth posture got opposite verdicts depending on their prose.

Closing that false negative without adding a declaration would have converted a
silent false negative into a **permanent** false positive on correct code — the
no-honest-end-state shape. So the posture is stated instead:

```php
/**
 * @auth admin-only writes billing state for every tenant; admin posture is the absence of NoAdminRequired
 */
public function update(string $id): JSONResponse
```

The reason must be at least 20 characters and sit at docblock-tag position.
**Making mere absence sufficient was considered and rejected**: absence is the
only thing gate 5 reports, so accepting it would empty the gate completely.
gate 9 (semantic-auth) still owns whether the declared posture matches the
method body.

---

## Testing the package

```
bash hydra-gates/tests/test-hydra-gates-bin.sh     # or: composer test:package

# manifest gate helpers (gate 22's verdict contract, gate 53's diff scoping).
# Install ajv first or the schema paths state a SKIP instead of passing:
npm install --no-save ajv ajv-formats
bash hydra-gates/scripts/lib/test_check_manifest.sh
node hydra-gates/scripts/lib/test_manifest_scope_filter.js
python3 hydra-gates/scripts/lib/test_manifest_diff_scope.py
```

Asserts the invariants — exit-code-is-count, loud unresolvable base, stated
empty diff, self-describing coverage, named unrun gates, one-line-per-verdict —
against a synthesized fixture repo. The positive control runs in **both**
directions: the injected violation must be *named* by the gate that catches it,
and the same fixture must go green once it is removed. A one-directional control
cannot distinguish "the check caught it" from "the check never ran".

`test_check_manifest.sh` was itself an example of that failure until
2026-08-03: it pointed at a `scripts/test-fixtures/manifest-validation/`
directory that had never existed, and `check_manifest.js <missing-path>` prints
"Tier 0, skipping" and exits 0 — which is exactly what two of its three
assertions expected. It had been green its whole life while validating nothing.
The fixtures now ship, and the suite refuses to run at all if they go missing
again.

CI for this package lives in
[`.github/workflows/hydra-gates-package.yml`](../.github/workflows/hydra-gates-package.yml):
it runs the suite, and installs the package from this repository's VCS URL into
a scratch project in a **clean `php:8.3-cli` container** — proving the published
location is installable without credentials, rather than proving a local path
works.

---

## Why it lives in `.github`

The gates need to be **public** to be distributable: `ConductionNL/hydra` is
private, so a `vcs` repository pointed at it would need credentials in every
consuming repo and every CI runner. `ConductionNL/.github` is public, and it
already owns the shared workflows every repo calls — so the same move that
solves distribution also puts the gates into the default checks.

**hydra delegates; it does not keep a copy.** `hydra/scripts/run-hydra-gates.sh`
is now a resolver that locates this package and `exec`s it, and fails closed
(exit 99, no green) when it cannot. Two copies that drift is precisely the
failure mode these gates exist to catch.

The git history was **not** imported. A subtree split would have published the
private orchestrator's commit history into a public repository. The provenance
is recorded here instead: this code was developed in `ConductionNL/hydra` and
packaged in [hydra#504](https://github.com/ConductionNL/hydra/pull/504).
