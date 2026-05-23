---
name: hydra-gate-unsafe-auth-resolver
description: Detect `catch (\Throwable) { return null; }` patterns in service methods that resolve auth/authorization/permission/role services. The nullable return shape enables a silent-fail-open pattern where the caller treats "service unavailable" as "check skipped" (OWASP A01:2021 / CWE-863). Observed 2026-04-21 on decidesk#45 where `DecisionApprovalService::getAuthorizationService()` returned null on Throwable and the caller guarded the role check with `if ($auth !== null)`, letting any authed user advance decisions when the service was briefly unavailable.
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, silent-auth-bypass, owasp-a01, cwe-863]
---

## Purpose

In Nextcloud service code, it's tempting to wrap a container lookup in `try/catch (\Throwable) { return null; }` so the app doesn't crash when a dependency is briefly unavailable. That's fine for cache lookups or logging services — it's catastrophic for authorization services, because the nullable return paired with the typical caller pattern `if ($auth !== null) { /* check */ }` degrades auth from "deny on error" to "skip on error" — the definition of fail-open.

This gate flags methods in `lib/Service/*.php` / `lib/Controller/*.php` where:
1. The method name contains `Auth`/`Authorization`/`Permission`/`Role` (case-insensitive, word boundary)
2. The method body contains `catch (\Throwable` (or `Throwable` unqualified)
3. The catch block contains `return null`

The combination is the fail-open shape.

## Step 1: Check

```bash
FAIL=0
for f in lib/Service/*.php lib/Controller/*.php; do
    [ -f "$f" ] || continue

    # Find public/private methods whose name suggests auth/permission resolution
    grep -nE "^\s*(public|private|protected)\s+function\s+[a-zA-Z0-9_]*([Aa]uthori[sz]ation|[Aa]uth|[Pp]ermission|[Rr]ole|[Gg]uard)[a-zA-Z0-9_]*\s*\(" "$f" \
        | while IFS=: read line_no _; do
        method=$(sed -n "${line_no}p" "$f" | grep -oE 'function\s+[a-zA-Z0-9_]+' | awk '{print $2}')
        [ -z "$method" ] && continue

        # Grab the method body (best-effort: def line to first `^    }` after it)
        body=$(awk -v start="${line_no}" 'NR >= start { print; if (NR > start && /^    \}/) exit }' "$f")

        # Does it swallow Throwable AND return null in the same method?
        if echo "$body" | grep -qE 'catch\s*\(\s*\\?Throwable\b' \
           && echo "$body" | grep -qE 'return\s+null\s*;'; then
            echo "FAIL unsafe-auth-resolver: ${f}:${line_no} method=${method} rule=throwable-caught-returns-null (auth/permission resolver with fail-open error path)"
            FAIL=1
        fi
    done
done

exit $FAIL
```

## Step 2: Fix findings

Two options, pick one that matches the intended semantics:

### 1. Fail closed — throw on missing dependency

```php
private function getAuthorizationService(): AuthorizationService {
    try {
        return $this->serverContainer->get(AuthorizationService::class);
    } catch (\Throwable $e) {
        $this->logger->error('AuthorizationService unavailable', ['exception' => $e]);
        throw new \RuntimeException('Authorization subsystem unavailable — refusing to proceed', previous: $e);
    }
}
```

Caller code simplifies:
```php
$this->getAuthorizationService()->requireRole($user, 'approver');  // throws on fail-fail
```

### 2. Optional enforcement, explicit denial on null

If the service is genuinely optional in some contexts (rare for auth):

```php
private function getAuthorizationService(): ?AuthorizationService { /* as before */ }

// In caller:
$auth = $this->getAuthorizationService();
if ($auth === null) {
    throw new OCSForbiddenException('Authorization subsystem unavailable');
}
$auth->requireRole($user, 'approver');
```

**The failure mode the gate catches is specifically `if ($auth !== null)` guarding the check — which silently skips auth when the service isn't around.** Either of the two fixes above resolves it.

## Scope + false positives

- Method name filter is `*Auth*` / `*Authorization*` / `*Permission*` / `*Role*` / `*Guard*` — narrow to cut noise from the much larger set of nullable-Throwable-swallowing methods (e.g., cache, logger, notification) that aren't security-relevant.
- The gate does NOT trace caller semantics. Even the "fail closed" fix above still has `catch (\Throwable)` — but it throws, so a grep for `return null` in the same method body returns zero and the gate passes.
- Test files are not scanned.
- If you hit a false positive, either fix as above OR add a `// phpcs:ignore` comment with a rationale — the gate grep does not skip on comments, so this is an explicit opt-out that a reviewer will see.

## Guardrails

- Never use `catch (\Throwable) { return null; }` in a method that resolves an authorization or permission service — prefer fail-closed (throw) or explicit denial.
- Never suppress this gate finding without demonstrating the caller explicitly denies access when null is returned.

## Verification

Sample gate output is in [examples/](examples/):
- [examples/pass.log](examples/pass.log) — what stdout looks like when this gate is green on the diff
- [examples/fail.log](examples/fail.log) — what stdout looks like when this gate finds a violation; per-finding detail is in `/tmp/hydra-gate-unsafe-auth-resolver.log` so the builder/reviewer can read line-by-line and apply Fix actions deterministically. Confirm the gate is green by running `./scripts/run-hydra-gates.sh --scope-to-diff` from the app dir before pushing.
