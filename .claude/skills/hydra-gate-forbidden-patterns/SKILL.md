---
name: hydra-gate-forbidden-patterns
description: Scan lib/ for forbidden debug helpers (var_dump / die / error_log / print_r / dd / dump) that should not ship. Invoked by the builder before push, by the reviewer as Mandatory Step 2, and by the fixer during a retry. Mirrors the orchestrator's `forbidden-patterns` quality gate.
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, forbidden-patterns]
---

## Purpose

Debug helpers like `var_dump()` / `die()` / `dd()` routinely leak from local debugging into committed code. The orchestrator's `run-quality.sh` has a `forbidden-patterns` stage that fails the recheck if any occur under `lib/`. Common cause of PR rejection.

## Check

```bash
# Word boundary is MANDATORY. Plain 'dd(' matches 'add(', 'odd(', etc.
# grep -E with \b provides a word-boundary anchor (ERE).
for p in var_dump die error_log print_r dd dump; do
    n=$(grep -rnE "\b${p}\(" lib/ 2>/dev/null | grep -v vendor/ | wc -l)
    if [ "$n" -gt 0 ]; then
        echo "FAIL forbidden-patterns: $n × ${p}("
        grep -rnE "\b${p}\(" lib/ 2>/dev/null | grep -v vendor/
    fi
done
```

## Why word-boundary matters

A prior regression on decidesk#71/#72 showed why: the literal `grep 'dd('` matched `$jobList->add(...)` (because `add(` contains the substring `dd(`) and every retry cycle flagged a "1 forbidden function call" that was actually a completely legitimate `->add()` method call. It kept the issues pinned in `needs-input` across 3+ retry loops.

Always use `grep -E '\b${fn}\('` with word boundaries when scanning for forbidden function names.

## Fix action

For each FAIL line (file + line number):

1. Read the file, check the flagged line
2. **Real debug leak?** Delete the call entirely, or replace with a logger call:
   - `die('msg')` → `throw new \RuntimeException('msg');`
   - `var_dump($x)` → `$this->logger->debug('...', ['x' => $x]);`
   - `error_log('...')` → `$this->logger->warning('...');`
3. **False positive?** (rare with word-boundary grep.) If the match is a legitimate user-facing function that happens to share a name, rename locally or add a `// phpcs:ignore` — but audit carefully first.
4. Re-run the Check.

Bounded mechanical fix; always in-scope for the builder and reviewer.

## Related orchestrator gate

`scripts/run-quality.sh` stage `forbidden-patterns` runs the same check. If this skill's output is clean, the orchestrator's recheck will be clean too.

## Verification

Sample gate output is in [examples/](examples/):
- [examples/pass.log](examples/pass.log) — what stdout looks like when this gate is green on the diff
- [examples/fail.log](examples/fail.log) — what stdout looks like when this gate finds a violation; per-finding detail is in `/tmp/hydra-gate-forbidden-patterns.log` so the builder/reviewer can read line-by-line and apply Fix actions deterministically. Confirm the gate is green by running `./scripts/run-hydra-gates.sh --scope-to-diff` from the app dir before pushing.
