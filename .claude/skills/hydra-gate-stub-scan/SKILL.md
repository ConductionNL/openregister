---
name: hydra-gate-stub-scan
description: Detect stub code — "In a complete implementation" comments, empty `run()` bodies in BackgroundJob files, unused injected dependencies, hardcoded fetch stubs in Vue `fetch*()` methods. Invoked by the builder before push, the reviewer's mandatory block, and the fixer during retry. Mirrors the orchestrator's `stub-scan` quality gate.
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, stub-scan, spec-coherence]
---

## Purpose

A stub is code that passes syntax checks but doesn't do the work: a background job `run()` method that only logs, a controller returning hardcoded `['status' => 'open', 'items' => []]`, an injected dependency never referenced outside the constructor, a Vue `fetchMailAccounts()` that returns `[{label: 'Default'}]` instead of calling the backend. Caught by the `stub-scan` stage of `run-quality.sh` and (more importantly) by the applier.

## Step 1: Check

```bash
FAIL=0

# 1. Literal stub markers in source
if grep -rn 'In a complete implementation' lib/ src/ 2>/dev/null | head; then
    echo "FAIL stub-scan: 'In a complete implementation' marker comments"
    FAIL=1
fi

# 2. BackgroundJob run() bodies with no non-logger statements
for f in lib/BackgroundJob/*.php; do
    [ -f "$f" ] || continue
    body=$(sed -n '/public function run/,/^    }/p' "$f")
    non_log=$(echo "$body" | grep -vE '^\s*(//|\*|function run|->info|->warning|->debug|->error|return;?|}|$)' | wc -l)
    if [ "$non_log" -lt 2 ]; then
        echo "FAIL stub-scan: $f — run() body has no non-logger statements"
        FAIL=1
    fi
done

# 3. Unused injected dependencies (defined in constructor but never used)
for f in lib/Service/*.php lib/Controller/*.php lib/BackgroundJob/*.php; do
    [ -f "$f" ] || continue
    grep -oP 'private\s+\S+\s+\$\K[a-zA-Z_]+' "$f" 2>/dev/null | while read v; do
        uses=$(grep -c "\$this->$v" "$f")
        if [ "$uses" -le 1 ]; then  # 1 = only the constructor assignment
            echo "FAIL stub-scan: $f — unused dependency \$$v"
            FAIL=1
        fi
    done
done

# 4. Vue fetch*() methods with hardcoded single-entry stubs
find src -name '*.vue' 2>/dev/null | while read vue; do
    if grep -qE 'fetch[A-Z][A-Za-z]*\s*\(\s*\)\s*\{' "$vue" &&
       grep -qE "return\s*\[\s*\{\s*label:\s*'(Default|Personal|Test|Demo)" "$vue"; then
        echo "FAIL stub-scan: $vue — fetch*() returns hardcoded single-entry stub"
        FAIL=1
    fi
done

exit $FAIL
```

## Step 2: Fix findings

- **`In a complete implementation` marker**: delete the marker and either implement the code OR explicitly uncheck the related task in `tasks.md` and note the scope reduction in `design.md`'s `status:` line. A stub that ships with an unchecked task is fine; a stub with an `[x]` task is a lie.
- **Empty `run()` body**: implement the job's work or uncheck the task.
- **Unused injected dependency**: either use it, or remove it from the constructor (most apps don't want unused DI wiring).
- **Hardcoded Vue fetch stub**: replace with a real API call using `axios` from `@nextcloud/axios`. If the backend endpoint doesn't exist yet, that's a missing controller — add it (per Rule 2a of the builder prompt, every frontend fetch must have a matching `appinfo/routes.php` route).

## Spec coherence angle

This skill is the runtime half of the spec coherence audit (builder Rule 2a). The builder emits `task-audit.json` describing which `[x]` tasks reference stub files; this skill detects those stubs independently. A `[x]` task pointing at a stub-scan failure is the worst kind of drift — the task claims done, the code doesn't work, and the applier has to catch it.

## Guardrails

- Never mark a task `[x]` complete while the stub it covers still exists in code — the gate catches this drift.
- Never suppress a stub-scan finding with a comment; apply the fix or explicitly uncheck the task.

## Verification

Sample gate output is in [examples/](examples/):
- [examples/pass.log](examples/pass.log) — what stdout looks like when this gate is green on the diff
- [examples/fail.log](examples/fail.log) — what stdout looks like when this gate finds a violation; per-finding detail is in `/tmp/hydra-gate-stub-scan.log` so the builder/reviewer can read line-by-line and apply Fix actions deterministically. Confirm the gate is green by running `./scripts/run-hydra-gates.sh --scope-to-diff` from the app dir before pushing.
