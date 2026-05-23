---
name: hydra-gate-semantic-auth
description: Detect controller methods where the Nextcloud auth annotation does not match the method's actual authorization requirement. Observed 2026-04-23 on decidesk#44 where the builder satisfied gate-5 (route-auth) by adding `#[NoAdminRequired]` to SettingsController::load() even though the method body calls `requireAdmin()`. Gate-5 accepted any auth attribute; this gate catches the semantic mismatch.
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, semantic-auth, adr-005, adr-016]
---

## Purpose

`hydra-gate-route-auth` (gate-5) is **syntactic** — it verifies each routed method carries any of the four valid auth attributes (`#[PublicPage]` / `#[NoAdminRequired]` / `#[NoCSRFRequired]` / `#[AuthorizedAdminSetting]`). It does not check that the chosen attribute is the *right* one for the method.

`hydra-gate-semantic-auth` (gate-9) is the **semantic** layer. It verifies the attribute matches the method body's actual behavior per ADR-005:

- `#[PublicPage]` — body must NOT call `requireAdmin()`, have `Http::STATUS_UNAUTHORIZED/FORBIDDEN` branches, or null-check `userSession->getUser()`. Public means no auth; body auth checks mean the attribute lies.
- `#[NoAdminRequired]` — body must NOT call `requireAdmin()`. That pattern belongs on `#[AuthorizedAdminSetting(Application::APP_ID)]` which enforces admin at the middleware layer declaratively.

**Observed symptom (decidesk#44 2026-04-23):** `SettingsController::load()` was originally missing any auth attribute, triggering gate-5 FAIL. The builder in retry mode added `#[NoAdminRequired]` — cheapest attribute to clear gate-5 — but the method body was `requireAdmin()` + redirect-if-denied. Gate-5 passed (attribute exists); gate-9 correctly flagged the mismatch. Meanwhile the human had hand-patched development to use `#[AuthorizedAdminSetting(Application::APP_ID)]` (the correct choice). The two-one-line edits conflicted at merge time.

## Step 1: Check

```bash
for f in lib/Controller/*.php; do
    [ -f "$f" ] || continue
    grep -nE '^\s*public\s+function\s+[a-zA-Z0-9_]+\s*\(' "$f" \
        | while IFS=: read line _; do
            # Scan docblock + attributes above the method, bounded by the
            # previous method's closing brace to avoid leaking annotations.
            prev_close=$(sed -n "1,$((line - 1))p" "$f" | grep -nE '^    \}' | tail -1 | cut -d: -f1)
            start=$((${prev_close:-0} + 1))
            head=$(sed -n "${start},${line}p" "$f")
            body=$(awk -v s="${line}" 'NR >= s { print; if (NR > s && /^    \}/) exit }' "$f")

            # NoAdminRequired + requireAdmin() in body
            if echo "$head" | grep -qE '#\[NoAdminRequired\b|@NoAdminRequired\b'; then
                echo "$body" | grep -qE '\$this->requireAdmin\s*\(' && \
                    echo "$f:$line no-admin-required + requireAdmin() body — use #[AuthorizedAdminSetting]"
            fi

            # PublicPage + in-body auth check
            if echo "$head" | grep -qE '#\[PublicPage\]|@PublicPage\b'; then
                echo "$body" | grep -qE 'requireAdmin\s*\(|userSession->getUser\s*\(\s*\)\s*===\s*null|Http::STATUS_(UNAUTHORIZED|FORBIDDEN)' && \
                    echo "$f:$line public-page + auth body — remove #[PublicPage] or body check"
            fi
        done
done
```

## Step 2: Fix patterns

### `#[NoAdminRequired]` + `requireAdmin()` in body

Change to `#[AuthorizedAdminSetting(Application::APP_ID)]` and remove the now-redundant body check (the middleware enforces admin at the routing layer):

```php
// Before
#[NoAdminRequired]
public function load(): JSONResponse
{
    $denied = $this->requireAdmin();
    if ($denied !== null) {
        return $denied;
    }
    // ... actual work ...
}

// After
#[AuthorizedAdminSetting(Application::APP_ID)]
public function load(): JSONResponse
{
    // ... actual work ... (middleware already enforced admin)
}
```

### `#[PublicPage]` + auth check in body

Either remove `#[PublicPage]` (the endpoint is not actually public), or remove the body auth check (the endpoint is genuinely public and the in-body check is defensive theater):

```php
// Before (contradictory)
#[PublicPage]
public function status(): JSONResponse
{
    if ($this->userSession->getUser() === null) {
        return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
    }
    // ...
}

// After — if actually admin-only
#[AuthorizedAdminSetting(Application::APP_ID)]
public function status(): JSONResponse { /* ... */ }

// After — if actually public
#[PublicPage]
#[NoCSRFRequired]
public function status(): JSONResponse { /* ... no auth check ... */ }
```

## Related

- ADR-005 (security) — declares the attribute-to-body mapping
- ADR-016 (routes) — mandates both gate-5 and gate-9 must pass
- `hydra-gate-route-auth` (gate-5) — the syntactic layer this gate builds on
- `hydra-gate-no-admin-idor` (gate-7) — checks `#[NoAdminRequired]` has a per-object guard; complementary to this gate's check that `NoAdminRequired` doesn't have an *admin-level* guard

## Guardrails

- Never add `#[NoAdminRequired]` to a method whose body calls `requireAdmin()` just to clear gate-5 — use `#[AuthorizedAdminSetting]` instead.
- Never remove a body auth check from a `#[PublicPage]` method without verifying the endpoint is genuinely intended to be anonymous.

## Verification

Sample gate output is in [examples/](examples/):
- [examples/pass.log](examples/pass.log) — what stdout looks like when this gate is green on the diff
- [examples/fail.log](examples/fail.log) — what stdout looks like when this gate finds a violation; per-finding detail is in `/tmp/hydra-gate-semantic-auth.log` so the builder/reviewer can read line-by-line and apply Fix actions deterministically. Confirm the gate is green by running `./scripts/run-hydra-gates.sh --scope-to-diff` from the app dir before pushing.
