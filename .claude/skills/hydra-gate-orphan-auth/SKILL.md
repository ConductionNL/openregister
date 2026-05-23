---
name: hydra-gate-orphan-auth
description: Detect authorization/validation service methods that are defined but never called. Observed 2026-04-21 on decidesk#60 — `isTransitionAllowed()`, `requiresChairAuthorization()`, `validateQuorum()` were all implemented but never invoked from the state-change caller, leaving dead auth code while the pipeline reported green. A defined-but-unused auth method is identical to having no check at all (OWASP A01:2021).
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, orphan-auth, owasp-a01, dead-code]
---

## Purpose

The existing `hydra-gate-stub-scan` catches stubs and **unused constructor dependencies**. It does NOT catch the subtler case where a service method is fully implemented but never invoked — a shape that's indistinguishable from "check skipped" at runtime.

This gate narrows the hunt to method names that are *probably* guard rails:

- `is*`  — boolean predicates (`isAllowed`, `isOwner`, `isAdmin`)
- `requires*` / `require*` — hard assertions (`requiresChair`, `requireAuth`)
- `validate*` — input / invariant checks (`validateQuorum`, `validateSchema`)
- `authorize*` — explicit authorization (`authorizeMutation`)
- `check*` — general guards (`checkAccess`, `checkRateLimit`)
- `ensure*` / `verify*` / `assert*` — same family

Scope: public methods in `lib/Service/*.php` and `lib/Controller/*.php`. Private/protected helpers may legitimately be internal and are skipped to keep signal high.

**Observed symptom (decidesk#60):** `WorkflowService::isTransitionAllowed()` fully implemented — 40 lines of role-based checks — but `MeetingService::transition()` never calls it before applying the state change. Any authenticated user could POST the state change endpoint and bypass the check. Security reviewer flagged it; applier blocked on it; fixable with one `if (!$this->workflow->isTransitionAllowed(...)) throw ...` line.

## Check

```bash
FAIL=0
AUTH_VERB='is|requires?|validate|authorize|check|ensure|verify|assert'

for f in lib/Service/*.php lib/Controller/*.php; do
    [ -f "$f" ] || continue

    # Public methods matching the auth-verb prefix
    grep -nE "^\s*public\s+function\s+(${AUTH_VERB})[A-Z][a-zA-Z0-9_]*\s*\(" "$f" \
        | while IFS=: read line_no _; do
        method=$(sed -n "${line_no}p" "$f" \
            | grep -oE 'function\s+[a-zA-Z0-9_]+' \
            | awk '{print $2}')
        [ -z "$method" ] && continue

        # Count external callers — exclude the defining file itself (internal
        # delegation doesn't count; an auth method that's only self-referential
        # is by definition not protecting anything.)
        callers=$(grep -rnE "->${method}\s*\(" lib/ src/ 2>/dev/null \
            | grep -v "^${f}:" | wc -l)

        if [ "$callers" -eq 0 ]; then
            echo "FAIL orphan-auth: $f:$line_no method=$method rule=defined-but-never-called"
            FAIL=1
        fi
    done
done

exit $FAIL
```

## Fix action

Two legitimate outcomes, pick one:

1. **Wire the call in**. The method exists because the spec needed the check; find the caller that should enforce it and add the invocation.

   ```php
   // In MeetingService::transition():
   if (!$this->workflow->isTransitionAllowed($meeting, $userId, $newStatus)) {
       throw new OCSForbiddenException('Transition not allowed for this user/status');
   }
   ```

2. **Delete the method**. If on reflection the check isn't actually required (redundant, superseded, or scoped out), remove the dead code — don't ship uncalled auth paths. Inherited-debt is never a reason to leave the method in place.

Neither "rename the method" nor "mark it internal with a comment" passes — the runtime effect is identical to having no check.

## Scope + false positives

- **Private + protected methods** are skipped. Services often have internal helpers matching the verb prefix (`checkState()` called only from public methods in the same class); these are legitimate and don't indicate a missing guard.
- **Self-calls are ignored**. The defining file's own occurrences don't count as callers — an auth method that only references itself is still orphaned from the caller's perspective.
- **Magic call sites**: methods dispatched via NC `__call`, reflection, or closures are NOT detected. Rare in service/controller classes; if you hit a false positive, narrow the grep by adding the caller in a comment or mark the method `// phpcs:ignore` near the definition.
- **Test-only use**: `tests/*` isn't searched — production callers are the bar. A method called only from tests is still orphan from production's point of view.

## Verification

Sample gate output is in [examples/](examples/):
- [examples/pass.log](examples/pass.log) — what stdout looks like when this gate is green on the diff
- [examples/fail.log](examples/fail.log) — what stdout looks like when this gate finds a violation; per-finding detail is in `/tmp/hydra-gate-orphan-auth.log` so the builder/reviewer can read line-by-line and apply Fix actions deterministically. Confirm the gate is green by running `./scripts/run-hydra-gates.sh --scope-to-diff` from the app dir before pushing.
