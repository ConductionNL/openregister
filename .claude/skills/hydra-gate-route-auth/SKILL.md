---
name: hydra-gate-route-auth
description: Verify every controller method registered in appinfo/routes.php declares its auth posture via a Nextcloud attribute or docblock tag. Missing `#[PublicPage]` / `#[NoAdminRequired]` / `#[NoCSRFRequired]` / `#[AuthorizedAdminSetting(...)]` silently makes an endpoint unreachable — NC middleware rejects the request before the controller runs. Observed 2026-04-21 on decidesk#47.
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, route-auth, nextcloud, middleware]
---

## Purpose

Every controller method reachable through `appinfo/routes.php` must explicitly declare how NC's middleware stack should treat it:

| Attribute | Effect |
|---|---|
| `#[PublicPage]` / `@PublicPage` | Allow anonymous requests (still needs `NoCSRFRequired` if no session CSRF token). |
| `#[NoAdminRequired]` / `@NoAdminRequired` | Allow authenticated non-admin users (default posture is admin-only). |
| `#[NoCSRFRequired]` / `@NoCSRFRequired` | Skip CSRF token check (usually paired with `PublicPage`). |
| `#[AuthorizedAdminSetting(APP_ID)]` | Admin settings page — wraps admin check + CSRF. |

A method with **no** attribute/tag AND no call to `IGroupManager::isAdmin()` gets default admin-only, which is fine only if every caller is an admin — it is NOT fine for citizen-facing endpoints, projection screens, or non-admin staff actions.

**Observed symptom:** `ProjectionController::publicState()` routed at `/api/meeting/{id}/projection` without `#[PublicPage]`. NC `AuthMiddleware` rejects every unauthenticated request before the method runs. Endpoint 100% unreachable for its intended audience (projection displays without user sessions). Caught by security review, not caught by code review — fixable mechanically.

## Step 1: Check

Assumes `appinfo/routes.php` + `lib/Controller/*.php` follow standard NC layout.

```bash
FAIL=0
ROUTES="appinfo/routes.php"
[ ! -f "$ROUTES" ] && { echo "skip route-auth: no $ROUTES"; exit 0; }

# Extract controller#method pairs from routes.php. NC idiom:
#   ['name' => 'meeting#public_state', 'url' => '...', ...]
# The `name` is lower_snake — we convert to CamelCase for the class,
# and the method is exactly what follows the `#`.
ROUTE_PAIRS=$(grep -oE "'name'\s*=>\s*'[a-z_]+#[a-zA-Z0-9_]+'" "$ROUTES" \
    | grep -oE "[a-z_]+#[a-zA-Z0-9_]+" | sort -u)

[ -z "$ROUTE_PAIRS" ] && { echo "skip route-auth: no controller#method routes in $ROUTES"; exit 0; }

echo "$ROUTE_PAIRS" | while IFS='#' read ctrl method; do
    # Snake → CamelCase: meeting_log → MeetingLogController
    class=$(echo "$ctrl" | awk -F'_' '{for(i=1;i<=NF;i++) printf toupper(substr($i,1,1)) substr($i,2); print ""}')
    path="lib/Controller/${class}Controller.php"
    [ ! -f "$path" ] && { echo "FAIL route-auth: $ROUTES references $ctrl#$method but $path missing"; continue; }

    # Find method definition line
    def_line=$(grep -nE "^\s*public\s+function\s+${method}\s*\(" "$path" | head -1 | cut -d: -f1)
    [ -z "$def_line" ] && { echo "FAIL route-auth: $path missing public function $method"; continue; }

    # Look at the 20 lines immediately above the method for attributes / docblock
    start=$((def_line > 20 ? def_line - 20 : 1))
    head_block=$(sed -n "${start},${def_line}p" "$path")

    if ! echo "$head_block" | grep -qE '#\[(PublicPage|NoAdminRequired|NoCSRFRequired|AuthorizedAdminSetting)\b|@(PublicPage|NoAdminRequired|NoCSRFRequired)\b'; then
        echo "FAIL route-auth: $path:$def_line method=$method rule=missing-auth-attribute"
        FAIL=1
    fi
done

exit $FAIL
```

Heuristics the check accepts:
- PHP 8 attribute form `#[PublicPage]` (preferred, matches ADR-005).
- Legacy docblock tags `@PublicPage` etc. — still valid on older NC versions.
- `AuthorizedAdminSetting(...)` is admin-settings-only; the attribute name alone is enough regardless of its argument.

## Step 2: Fix findings

Pick the attribute that matches the endpoint's audience and add it immediately above the method signature:

```php
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;

#[PublicPage]
#[NoCSRFRequired]
public function publicState(string $id): JSONResponse { ... }
```

If the endpoint is genuinely admin-only, add `#[AuthorizedAdminSetting(Application::APP_ID)]` or leave unmarked but also add an explicit `if (!$this->groupManager->isAdmin($uid)) throw new OCSForbiddenException();` — the gate passes either way, but per ADR-005 the attribute form is strongly preferred.

Remember per ADR-005 + Rule 3: pairing `#[NoAdminRequired]` with a per-object authorization check (IDOR guard) is mandatory for mutation endpoints — route-auth only catches the attribute layer.

## Scope

- Reads `appinfo/routes.php` only. Routes declared via `info.xml` or registered at runtime are out of scope — per ADR-016 (routes.php is the single registration path). If this gate starts seeing unmapped endpoints in production, the fix is to move the registration to routes.php, not to extend the gate.
- PHP 8 attributes and docblock tags are both accepted — apps can migrate at their own pace.
- No cross-file attribute inheritance check; each method stands on its own (matches NC's middleware model).

## Guardrails

- Never leave a routed controller method with no auth attribute — pick the one that matches the endpoint's audience, not just any attribute to silence the gate.
- Never declare `#[PublicPage]` on an endpoint that performs writes without a CSRF exemption (`#[NoCSRFRequired]`) — the two must be paired.

## Verification

Sample gate output is in [examples/](examples/):
- [examples/pass.log](examples/pass.log) — what stdout looks like when this gate is green on the diff
- [examples/fail.log](examples/fail.log) — what stdout looks like when this gate finds a violation; per-finding detail is in `/tmp/hydra-gate-route-auth.log` so the builder/reviewer can read line-by-line and apply Fix actions deterministically. Confirm the gate is green by running `./scripts/run-hydra-gates.sh --scope-to-diff` from the app dir before pushing.
