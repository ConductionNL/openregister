# Retrofit — auth-system

## Why

Describes observed behaviour of ~22 in-scope methods across the
auth-system runtime (`AuthorizationService`, `PropertyRbacHandler`,
plus the RBAC admin Vue surface) as 5 new REQs **extending** the
existing `auth-system` capability spec. Code already exists — this
change retroactively specifies sub-behaviours that the spec leaves
implicit and **flags three observed security drifts** without
silently patching them in the spec.

The cluster scan grouped the cluster as 57 methods / 7 files; 35 of
those are triaged DROP (Solr backend belongs to `search-index`; some
PropertyRbacHandler methods were triaged out of unrelated clusters
and are correctly reattached here). The 22 in-scope methods are
annotated; one method (`RbacConfiguration.vue::showRebaseDialog`)
was already annotated against the 2026-04-23 retrofit and is left
alone.

## What Changes

- Extends the `auth-system` capability spec with five new REQs
  covering: JWT payload time-validation semantics (REQ-AUTH-100),
  the observed OAuth2 Bearer pass-through (REQ-AUTH-101, **security
  drift flag**), Basic-Auth allow-list parameters that are accepted
  but unused (REQ-AUTH-102, **security drift flag**), property-RBAC
  rule-dispatch + PATCH-unchanged short-circuit (REQ-AUTH-103), and
  the admin RBAC matrix Vue surface (REQ-AUTH-104).
- Annotates 22 methods across `lib/Service/AuthorizationService.php`,
  `lib/Service/PropertyRbacHandler.php`,
  `src/components/RbacTable.vue`, and
  `src/views/settings/sections/PermissionMatrix.vue` with `@spec`
  tags pointing at this change's tasks. No behavioural code changes.

## Why an extension, not a new capability

The existing `auth-system` spec already covers the multi-auth
resolution model, the three-level RBAC hierarchy, multi-tenancy
bypass detection, public-endpoint mechanics, and CORS. The
behaviours captured here are sub-contracts under that umbrella —
they tighten the existing model rather than introduce a new one.

## Affected code units

- `lib/Service/AuthorizationService.php` —
  `validatePayload` / `authorizeBasic` / `authorizeOAuth` /
  `corsAfterController` / `authorizeApiKey`
- `lib/Service/PropertyRbacHandler.php` —
  `__construct` / `canReadProperty` / `canUpdateProperty` /
  `filterReadableProperties` / `getUnauthorizedProperties` /
  `checkPropertyAccess` / `checkRule` / `checkConditionalRule` /
  `userQualifiesForGroup` / `isAdmin`
- `src/components/RbacTable.vue` — `hasPermission`
- `src/views/settings/sections/PermissionMatrix.vue` — `loadData`

## Approach

For each method: describe observed inputs, outputs, pre/postconditions,
failure modes. Draft REQs that match the behaviour, not aspirational
extensions. Three of the five REQs surface SECURITY DRIFT observed
against the existing spec; per the security-critical guardrail these
are documented as observed behaviour (with explicit notes) rather
than silently corrected.

## Notes / observed-but-suspicious (SECURITY-FLAGGED)

- **`authorizeOAuth` does not actually validate the bearer token.**
  The method (`lib/Service/AuthorizationService.php` lines 323–339)
  inspects only `str_starts_with($header, 'Bearer')` and then defers
  the trust decision to `$this->userSession->isLoggedIn()`. The
  bearer token itself is never parsed, introspected, or matched
  against a Consumer. This means: when a request reaches this code
  path with an already-populated session (e.g. via Nextcloud session
  cookie or a prior Basic-Auth round), any `Authorization: Bearer …`
  header is accepted regardless of token content. The existing spec
  block "OAuth2 token scopes MUST translate to RBAC verdicts" (lines
  462–488) is therefore **aspirational, not observed** — the
  observed behaviour is "OAuth2 Bearer = session-trust confirmation".
  This is captured verbatim in REQ-AUTH-101; recommend tracking the
  hardening as a follow-up issue against `auth-system`.

- **`authorizeBasic` accepts `$users` / `$groups` allow-list
  parameters but never consults them.** The method
  (`lib/Service/AuthorizationService.php` lines 296–310) takes
  `array $users=[], array $groups=[]` and then delegates entirely
  to `IUserManager::checkPassword()` + `userSession->setUser()`.
  The parameters are dead code at the call site. Captured in
  REQ-AUTH-102; recommend either wiring up the allow-list (call
  sites already pass values) or dropping the parameters.

- **`authorizeApiKey` accepts unbounded key maps and does not
  rate-limit per-key.** Each invocation does an `array_key_exists`
  lookup against the `$keys` map passed by the caller; if the
  caller's map is huge or the lookup is repeated under brute-force
  conditions, no `SecurityService::checkLoginRateLimit()` is
  consulted in the AuthorizationService path. The existing spec
  notes "Per-consumer rate limiting" as Not implemented; this
  retrofit captures the gap on the AuthorizationService side too.
  No new REQ — the existing "Not implemented" block already names
  it. Retained here as a reviewer pointer.

- **`PropertyRbacHandler::getUnauthorizedProperties` short-circuits
  when the incoming value `===` the existing value.** This is
  documented in the code comment (PATCH-friendly) but not in the
  spec. It is a legitimate-looking convenience that, combined with
  the type-strict `===` comparison, has subtle consequences: a
  client that resends a protected field with the *same* value
  bypasses the authorization check entirely. For value-equality
  fields this is benign; for cases where "knowing the value"
  itself confers a side-effect (audit log, ETag echo, replay
  detection), it is a side-channel. Captured in REQ-AUTH-103 as
  observed behaviour with a note.

Source: openspec/coverage-report.md cluster scan generated
2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
