# Design: Integration — Cospend

> Umbrella decisions apply
>
> **Cross-repo note**: file paths under `nextcloud-vue/src/...` or bare component names (`CnXxxTab`, `CnXxxCard`) are **expected locations** in the `@conduction/nextcloud-vue` shared library, not binding spec. The frontend implementation PR lands in that separate repo and MAY choose different paths.. Niche integration — kept minimal.

## Approach

Link table maps objects to Cospend projects OR individual bills. Totals denormalized similarly to time-tracker for fast dashboard rendering.

## Architecture Decisions

### AD-1: Link either a project or a bill, not both as one

**Decision**: Link row type is either `project_link` (sums all bills in project) or `bill_link` (single bill). Not a hybrid.

**Why**: Clear semantics. "All expenses on this project" vs "this specific bill." Hybrid would confuse totals.

### AD-2: Minimal scope — lean on Cospend UI for details

**Decision**: Tab rows are clickable; detail view opens Cospend app. OR doesn't reimplement bill/split visualization.

**Why**: Cospend's finance UX is comprehensive; duplicating is over-investment for a niche integration.

## Files Affected

### Backend (new)
- `CospendService`, `CospendController`, `CospendLink` entity + mapper + migration, `CospendProvider`, unit tests

### Backend (modified)
- `Application.php`, `routes.php`

### Frontend (new)
- `CnCospendTab/*`, `CnCospendCard/*`, `src/integrations/builtin/cospend.js`, barrels + tests

## Risks

| Risk | Mitigation |
|---|---|
| Currency mismatch across linked bills | Display each bill in its own currency; aggregate only same-currency totals |
| Cospend API stability | Version-pinned adapter; graceful degrade if mismatched |
