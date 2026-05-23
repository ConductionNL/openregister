---
name: hydra-gate-no-admin-idor
description: Detect controller methods with `#[NoAdminRequired]` / `@NoAdminRequired` that lack a per-object or admin authorization guard in the method body. Without a guard, any authenticated user can invoke the endpoint on arbitrary object IDs — classic IDOR (OWASP A01:2021 / ADR-005 Rule 3). Observed 2026-04-21 on decidesk#44 where 4 MinutesController methods shipped without guards.
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, idor, no-admin-required, owasp-a01]
---

## Purpose

`#[NoAdminRequired]` / `@NoAdminRequired` removes the default admin-only posture — it opens the endpoint to every authenticated Nextcloud user. Without a per-object or explicit admin check in the method body, any authed user can invoke the endpoint with any object ID. Covered by ADR-005 + builder CLAUDE.md Rule 3.

A valid guard is ANY of:
- `OCSForbiddenException` thrown conditionally on the auth check
- `isAdmin(` check that returns/throws on fail
- A service method call starting with `authorize*` / `require*` / `ensure*` / `check*` that throws
- An explicit owner comparison using `->getUID()` against the object's `createdBy`/`assigneeUserId`/similar
- A call to a helper that resolves to one of the above (e.g., `$this->workflow->isTransitionAllowed(...)` in a conditional that throws on false)

If none of these patterns appear in the method body, the method is flagged.

**Observed symptom (decidesk#44):** `MinutesController::generateALVDraft()` annotated `@NoAdminRequired` but the body went straight from receiving `$minutesId` to generating the draft. Any authenticated non-admin user could generate ALV drafts for any minutes object by guessing/iterating the ID. Same pattern on three other sibling methods in the same controller. Security reviewer flagged all four as CRITICAL; no path to applier pass.

## Step 1: Check

```bash
FAIL=0
for f in lib/Controller/*.php; do
    [ -f "$f" ] || continue

    # Find every `public function X(` whose preceding 20 lines contain
    # #[NoAdminRequired] or @NoAdminRequired (attribute or docblock form).
    grep -nE "^\s*public\s+function\s+[a-zA-Z0-9_]+\s*\(" "$f" \
        | while IFS=: read line_no _; do
        method=$(sed -n "${line_no}p" "$f" | grep -oE 'function\s+[a-zA-Z0-9_]+' | awk '{print $2}')
        [ -z "$method" ] && continue

        start=$((line_no > 20 ? line_no - 20 : 1))
        head_block=$(sed -n "${start},${line_no}p" "$f")

        # Only evaluate if NoAdminRequired is present (attribute OR docblock)
        echo "$head_block" | grep -qE '#\[NoAdminRequired\b|@NoAdminRequired\b' || continue

        # Extract the method body — from the opening `{` after signature to
        # the first closing `}` at the same indent. sed approximation: start
        # at def line, stop at the first line matching `^    }`.
        body=$(awk -v start="${line_no}" 'NR >= start { print; if (NR > start && /^    \}/) exit }' "$f")

        # Body must contain one of the recognised guard patterns
        if ! echo "$body" | grep -qE 'OCSForbiddenException|isAdmin\s*\(|->\s*(authorize|require|ensure)[A-Z][a-zA-Z0-9_]*\s*\(|#\[PublicPage\]|@PublicPage\b'; then
            echo "FAIL no-admin-idor: ${f}:${line_no} method=${method} rule=no-auth-guard-in-body (has @NoAdminRequired but no OCSForbiddenException / isAdmin / authorize*/require*/ensure* call)"
            FAIL=1
        fi
    done
done

exit $FAIL
```

## Step 2: Fix findings

Pick the pattern that matches the intended authorisation model:

### Admin + per-object ownership (most common for citizen/non-admin endpoints)

```php
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IUserSession;
use OCP\AppFramework\OCS\OCSForbiddenException;

#[NoAdminRequired]
public function generateALVDraft(string $minutesId): JSONResponse {
    $user = $this->userSession->getUser();
    if ($user === null) {
        throw new OCSForbiddenException('Authentication required');
    }
    $minutes = $this->objectService->findObject(register: 'decidesk', schema: 'minutes', id: $minutesId);
    if ($minutes === null) {
        return new JSONResponse(['message' => 'Not found'], 404);
    }
    $this->authorizeMinutesMutation($minutes, $user);  // service method that throws on fail
    // … proceed with the draft
}
```

### Admin-only (when the endpoint is an admin tool that forgot the attribute)

```php
#[AuthorizedAdminSetting(Application::APP_ID)]
public function distributeALVMinutes(string $minutesId): JSONResponse { ... }
```

### Truly public

```php
#[PublicPage]
#[NoCSRFRequired]
public function publicState(string $id): JSONResponse { ... }
```

The gate passes on any of the three — it only fails when `NoAdminRequired` is present AND none of the recognised guard shapes appear.

## Scope + false positives

- **Read-only listing endpoints** that should be viewable by all authenticated users MAY legitimately have no admin/IDOR guard if the result is scoped by the authed user's identity downstream (e.g., always filtered by `userSession->getUser()->getUID()` inside the service). This gate doesn't see into service calls — if you hit a false positive, either: (a) add a `$user = $this->userSession->getUser(); if ($user === null) throw new OCSForbiddenException(...)` at the top of the controller method (always appropriate), or (b) demonstrate the per-object check lives in the service by calling `$this->service->authorizeRead($user)` so the gate recognises the pattern.
- **Protected/private methods** are skipped — only public entry points are analysed.
- **Constructor + DI methods** (no annotation precedes them) are skipped by construction.
- **Attribute vs docblock**: both forms are accepted. Apps mid-migration can use either.

## Guardrails

- Never add `#[NoAdminRequired]` to a mutation endpoint without pairing it with a per-object authorization guard.
- Never suppress this gate finding by removing the NoAdminRequired annotation — the endpoint then silently becomes admin-only, breaking non-admin flows.

## Verification

Sample gate output is in [examples/](examples/):
- [examples/pass.log](examples/pass.log) — what stdout looks like when this gate is green on the diff
- [examples/fail.log](examples/fail.log) — what stdout looks like when this gate finds a violation; per-finding detail is in `/tmp/hydra-gate-no-admin-idor.log` so the builder/reviewer can read line-by-line and apply Fix actions deterministically. Confirm the gate is green by running `./scripts/run-hydra-gates.sh --scope-to-diff` from the app dir before pushing.
