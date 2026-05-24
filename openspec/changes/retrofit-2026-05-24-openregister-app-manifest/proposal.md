# Retrofit — Reverse-spec openregister-app-manifest (partial, PHP backend)

## Why

The `openregister-app-manifest` capability already has a Vue-side spec (MAN-001..MAN-011, drafted by `openregister-adopt-app-manifest`). That change explicitly **deferred** the backend `/api/manifest` endpoint (REQ-OR-MAN-009) — but the backend was subsequently built anyway and shipped:

- `lib/Controller/ManifestController.php` — public `GET /api/manifest/{appId}` endpoint
- `lib/Service/ManifestService.php` — enriches a bundled manifest with a `runtime.user` block sourced from the requesting user's OR profile object

Both files carry `@spec openspec/changes/manifest-user-context/tasks.md` PHPDoc tags pointing at a change that does not exist in `openspec/changes/`. The behaviour is in production but has no canonical spec home.

This retrofit reverse-engineers 5 cohesive requirements from observed PHP behaviour and folds them into the existing `openregister-app-manifest` capability. The Vue-side REQs (MAN-001..MAN-011) are untouched. The deferral language in MAN-009 stays accurate as a historical record of the original Tier 1+2 scope — the new MAN-012..MAN-016 document the follow-up backend work that was subsequently delivered.

## What Changes

Add five new requirements to the `openregister-app-manifest` spec:

- **REQ-OR-MAN-012** — `/api/manifest/{appId}` endpoint loads a host app's bundled `src/manifest.json` and returns it enriched, with `appId` validated and well-known failure modes mapped to HTTP status codes.
- **REQ-OR-MAN-013** — Manifest enrichment is a no-op when the manifest carries no `currentUserSchema`, and emits `runtime.user = null` for anonymous requests.
- **REQ-OR-MAN-014** — Schema-slug supplied by the manifest is validated (length + charset) before any lookup; invalid slugs fail closed to `runtime.user = null`.
- **REQ-OR-MAN-015** — User-profile resolution filters the magic table for the declared schema by `ncUserId`, with Nextcloud's RBAC + multitenancy filters preserved.
- **REQ-OR-MAN-016** — `runtime.user` is populated from an explicit field allowlist plus non-materialised `x-openregister-calculations`; raw profile payload is never merged verbatim.

This is a **retrofit / reverse-spec** change (Bucket 2a per ADR-008): no behaviour is being added or modified — the change records observed implementation as canonical spec and annotates the relevant methods with `@spec` tags.

This pass is **partial**: the batch JSON included 109 methods across 45 files, but most are FPs from class-name token overlap (Registers/Schemas CRUD, generic schema cache handlers, GraphQL schema generation). Only 9 methods are genuinely in scope for the PHP backend manifest capability — those are annotated. The rest are deferred as `future-pass:next` in `tasks.md`.

## Capabilities

### Modified Capabilities

- `openregister-app-manifest` — extended with 5 new requirements (MAN-012..MAN-016) covering the backend enrichment endpoint. The original 11 Vue-side requirements remain unchanged.

## Impact

- **Modified files**:
  - `openspec/specs/openregister-app-manifest/spec.md` — adds MAN-012..MAN-016
  - `lib/Controller/ManifestController.php` — `@spec` tag updated from `manifest-user-context/tasks.md` (orphan) to per-method `@spec openregister-app-manifest#REQ-OR-MAN-012..016`
  - `lib/Service/ManifestService.php` — per-method `@spec` annotations on the 7 service methods covered
- **No behaviour change**. No new tests required (existing behaviour predates this retrofit and either has tests or has been deferred to a future test-coverage pass).
- **Follow-ups**: the deferral block in `tasks.md` enumerates the FP-heavy clusters (Registers/Schemas CRUD, schema cache handlers, GraphQL generation) that were in the batch JSON but do not belong in `openregister-app-manifest`. Each is tagged `future-pass:next` and the methods are listed verbatim so a downstream coverage-scan can re-cluster them.

## Risks

- **High FP rate in the input batch.** Mitigated by being conservative: only methods that literally implement the backend manifest enrichment are annotated. Class-name token overlap (e.g. `RegisterService::find` or `SchemaCacheHandler::cacheSchema`) is explicitly dropped, not silently absorbed.
- **MAN-009 deferral language now drifts from reality.** The original spec says the backend endpoint is deferred to a follow-up. The follow-up shipped (the orphan `manifest-user-context` change). This retrofit does not edit MAN-009's text; the deferral remains historically accurate, and MAN-012 supersedes it for current behaviour. A future cleanup pass can soften MAN-009 ("deferred → delivered in MAN-012") without changing observable behaviour.
- **Orphan `@spec` tag.** The current `@spec openspec/changes/manifest-user-context/tasks.md` PHPDoc tags point at a change that does not exist on disk. This retrofit replaces them with REQ-anchored per-method `@spec` tags.
