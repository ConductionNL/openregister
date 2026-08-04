# Design: Integration — Shares

> Umbrella decisions apply
>
> **Cross-repo note**: file paths under `nextcloud-vue/src/...` or bare component names (`CnXxxTab`, `CnXxxCard`) are **expected locations** in the `@conduction/nextcloud-vue` shared library, not binding spec. The frontend implementation PR lands in that separate repo and MAY choose different paths.

## Approach

Query-time integration (like Activity — no link table). `ShareService` walks object's linked files and calls `IManager::getSharesBy()` for each. Results merged + deduplicated + presented grouped by share type.

## Architecture Decisions

### AD-1: Query-time, not link-table

**Decision**: Use `query-time` storage strategy. Never store share state in OR — always query NC.

**Why**: Shares mutate outside OR (users click Files UI). A link table would desync immediately.

### AD-2: Read-only + revoke only (no create)

**Decision**: Tab offers list, group-by (user/group/link/federated), and revoke. Creating new shares requires the NC Files UI.

**Why**: Share creation involves permission prompts, password setup, expiry — too much surface to reimplement. Revoke is one-click and unambiguous.

## Files Affected

### Backend (new)
- `ShareService`, `SharesController`, `SharesProvider`, unit tests

### Backend (modified)
- `Application.php`, `routes.php`

### Frontend (new)
- `CnSharesTab/*`, `CnSharesCard/*`, `src/integrations/builtin/shares.js`, barrels + tests

## Risks

| Risk | Mitigation |
|---|---|
| Many linked files × many shares = slow query | Pagination; per-file share count as first-pass, expand on demand |
| Share visibility differs per user (ACLs) | Filter to shares the current user can see — don't expose other admins' private shares |
| Federated share edge cases | Show best-effort info; acknowledge federated shares may be incomplete |
