## Context

OpenRegister's authorization model treats the `public` group as a baseline that authenticated users always inherit. The intent is intuitive — "if it's visible to anyone (public), it's visible to anyone logged in" — and matches the most common case. The behaviour appears in two places:

- **PHP-side** (`PermissionHandler::hasPermission`, line 229-241): after iterating the user's groups and finding no match, the method falls back to evaluating `hasGroupPermission(public, ...)`. This is the explicit inheritance fallback.
- **SQL-side** (`MagicRbacHandler::processConditionalRule`, line 307-309 and `processSimpleRule`, line 268-271): when the rule's group is `public`, the user qualifies for the rule REGARDLESS of authentication state. Match conditions, if any, then apply.

This is a global, hard-coded policy. There's no per-schema or per-tenant way to opt out. For schemas where authentication should be a strict gate — not a superset of public access — operators have to work around it: they cannot grant public conditional rules without those grants leaking to authenticated users.

This change adds a single boolean — `inheritFromPublic` — at the authorization-block level (schema, with cascade to register, with fallback to a tenant-wide IAppConfig default). When `false`, authenticated users do NOT qualify for `public` rules; they qualify only via their own group memberships. Anonymous (unauthenticated) users see no behaviour change. Default stays `true` (backwards-compatible), so existing schemas retain today's semantics.

The implementation is small but spans both RBAC layers — the PHP-side check (per-object) and the SQL-side filter (listing). Both must honour the flag identically; otherwise listings and per-object reads would diverge.

## Goals / Non-Goals

**Goals:**

- Add an `inheritFromPublic` boolean to the authorization block (schema and register), plus a tenant-wide default via `IAppConfig`.
- Resolve the effective value via cascade — schema → register → tenant default → hard-coded `true`.
- When `false`, authenticated users do NOT qualify for `public` rules in either the PHP-side check or the SQL-side filter.
- Anonymous users see no change. Backwards-compatible default.
- Unit-test the four-state matrix (anon × authenticated × flag-on/off) for both layers.

**Non-Goals:**

- Per-rule audience targeting (Option β from exploration). One flag per authorization block; no per-rule control.
- A new first-class `authenticated` group concept.
- A schema-editor UI toggle for the flag. v1 ships as a JSON field on the existing schema authorization editor surface.
- Retroactively re-evaluate decisions on stored objects.
- Per-action variation. The flag covers all actions (read, create, update, delete) uniformly.

## Decisions

### D1. Flag name: `inheritFromPublic`

Reads cleanly: `inheritFromPublic: false` says "don't inherit from public". Alternatives considered:

- `publicAppliesToAuthenticated` — verbose, awkward double-negative ("set to false to mean public doesn't apply to authenticated users").
- `authenticatedInheritsPublic` — flips the subject; reads okay, but `inheritFromPublic` is more in line with how the docs describe the behaviour ("inheritance from public").
- `publicScopedToAnonymous` — different framing entirely, harder to reason about.

`inheritFromPublic` matches the existing comment in `PermissionHandler.php:229` which talks about logged-in users having "the same rights as 'public' users". Operators reading the existing code will recognise the inversion immediately.

### D2. Default `true` (backwards-compatible)

The current behaviour is `inheritFromPublic = true` (implicit). Defaulting to `true` keeps every existing schema running unchanged. Operators who want strict-gate semantics opt-in per-schema (or flip the tenant default).

**Alternative considered:** default `false` (privacy-positive). Rejected for v1 — flipping the global default is a behaviour change for every existing install, and many operators are accustomed to the current semantics. A future change can flip the default if community feedback supports it.

### D3. Cascade: schema → register → tenant default → hard-coded true

The cascade mirrors how `resolveAuthorization` already cascades the rest of the authorization config. The lookup logic:

```
   resolveInheritFromPublic($schema):
     1. if $schema->getAuthorization()['inheritFromPublic'] is set:
          return that value
     2. else if $register->getAuthorization()['inheritFromPublic'] is set:
          return that value
     3. else if IAppConfig has 'openregister.rbac.inherit_from_public_default':
          return that value (parsed as boolean)
     4. else: return true
```

**Rationale:** consistency with the existing authorization-resolution pattern. Operators already mentally model the cascade for the rest of the authorization block; extending it with one more field follows the same shape.

**Caching:** the resolved value is cached per-request keyed by schema ID. Avoids repeated cascade lookups inside a single listing call where the same schema is checked many times.

### D4. PHP-side enforcement: wrap the inheritance fallback

In `PermissionHandler::hasPermission`, the inheritance block at line 229-241 becomes:

```php
// Logged-in users should also have at least the same rights as 'public' users —
// unless inheritFromPublic is disabled for this schema.
if ($this->resolveInheritFromPublic(schema: $schema) === true) {
    if ($this->hasGroupPermission(
            authorization: $authorization,
            groupId: 'public',
            ...
        ) === true
    ) {
        return true;
    }
}
```

When `inheritFromPublic` is `false`, the public fallback is skipped. The user's per-group checks are the ONLY way to grant access. (Owner check at line 543, admin check at line 209, and explicit group membership at the foreach on line 214 still apply normally.)

### D5. SQL-side enforcement: guard the public-qualification check

In `MagicRbacHandler::processConditionalRule` (line 296-328) and `processConditionalRuleSql` (line 857-882) — both:

```php
$userQualifies = false;
if ($group === 'public') {
    if ($inheritFromPublic === false && $userId !== null) {
        $userQualifies = false;  // authenticated user excluded
    } else {
        $userQualifies = true;
    }
} else if ($group === 'authenticated' && $userId !== null) {
    $userQualifies = true;
} else if (in_array($group, $userGroups, true) === true) {
    $userQualifies = true;
}
```

In `processSimpleRule` (line 266-284):

```php
if ($rule === 'public') {
    if ($inheritFromPublic === false && $userId !== null) {
        return false;  // authenticated user does NOT get unconditional public access
    }
    return true;
}
```

The flag is plumbed through from `applyRbacFilters` / `buildRbacConditionsSql`, both of which call `resolveInheritFromPublic($schema)` once at the top and pass the value down to the rule-processing helpers.

### D6. `resolveInheritFromPublic` helper lives on `PermissionHandler`

`PermissionHandler::resolveAuthorization` already implements the schema-then-register cascade. The new helper sits next to it and follows the same pattern:

```php
public function resolveInheritFromPublic(Schema $schema): bool
{
    // Cache per-request keyed by schema ID.
    if (isset($this->cachedInheritFromPublic[$schema->getId()])) {
        return $this->cachedInheritFromPublic[$schema->getId()];
    }

    $value = null;

    $auth = $schema->getAuthorization();
    if (isset($auth['inheritFromPublic'])) {
        $value = (bool) $auth['inheritFromPublic'];
    } else {
        $register = $this->getRegisterForSchema(schema: $schema);
        if ($register !== null) {
            $registerAuth = $this->getRegisterAuthorization(registerId: $register->getId());
            if (isset($registerAuth['inheritFromPublic'])) {
                $value = (bool) $registerAuth['inheritFromPublic'];
            }
        }
    }

    if ($value === null) {
        $value = $this->appConfig->getValueBool(
            app: 'openregister',
            key: 'rbac.inherit_from_public_default',
            default: true
        );
    }

    $this->cachedInheritFromPublic[$schema->getId()] = $value;
    return $value;
}
```

`MagicRbacHandler` reuses the same helper via DI (it already injects `PermissionHandler` for `resolveAuthorization` purposes per `MagicRbacHandler.php:1320`).

### D7. The `authenticated` rule string is unaffected

`MagicRbacHandler::processSimpleRule` already has a special case for `'authenticated'` strings — they grant unconditional access to any logged-in user. That behaviour is independent of `inheritFromPublic` and stays as-is. A schema author who wants "all authenticated users can read" continues to use the literal `'authenticated'` rule. The new flag concerns the `public` group ONLY.

### D8. No new IAppConfig parsing — reuse existing pattern

The tenant-wide default key `openregister.rbac.inherit_from_public_default` follows the existing `IAppConfig` access pattern used elsewhere in OR (e.g. `getValueBool` for boolean settings). No new config-parsing infrastructure needed.

### D9. Schema serialisation preserves the field

`Schema::getAuthorization()` returns the JSON-decoded authorization block. Adding a new field at that level is transparent — the field is preserved through `setAuthorization` / `getAuthorization` round-trips because the Schema entity stores the JSON verbatim. No mapper changes needed.

## Risks / Trade-offs

- **[Behaviour change for tenants who flip the global default]** → Mitigation: documented prominently in CHANGELOG as a deliberate opt-in. A tenant flipping `inherit_from_public_default` to `false` MUST audit existing schemas with public-conditional rules to confirm authenticated users were not relying on those grants. Same risk applies for per-schema flips.
- **[Discoverability]** → The flag is a JSON field on the authorization block; not surfaced in any UI in v1. Mitigation: documented in `docs/`; admin UI is a clear follow-up if adoption is slow.
- **[Inconsistency between PHP-side and SQL-side checks]** → Both must honour the flag identically. Mitigation: shared `resolveInheritFromPublic` helper; unit tests cover the four-state matrix on BOTH layers and verify identical results.
- **[Config not respected when `inheritFromPublic` is set explicitly to `null`]** → JSON allows null. The cascade treats `null` as "unset" (falls through to next level). Documented behaviour; tested explicitly.
- **[Caching staleness when an admin updates the schema]** → The per-request cache is fine within a request. Across requests, a schema update invalidates the in-memory cache automatically (each request is a fresh PHP process). No persistent cache, no cache invalidation needed.
- **[`authenticated` rule could now be the cleaner alternative for some operators]** → If a tenant just wants "all logged-in users can read this", `authenticated` rules already exist and may be simpler than disabling inheritance. Documentation should describe both options and when each is appropriate.

## Migration Plan

1. Land the helper + flag plumbing in PHP and SQL paths. Default is `true` everywhere; behaviour is unchanged for schemas / registers / tenants that don't set it.
2. Add tests for the four-state matrix.
3. Document the flag in `docs/` (extend the RBAC documentation that already exists for `rbac-scopes`).
4. Release. Operators that want the new behaviour set `inheritFromPublic: false` per-schema (or flip the tenant default).

**Rollback:** since the default preserves current behaviour, rolling back is just removing the helper and the guards. If a tenant was relying on `inheritFromPublic: false` for privacy enforcement, rolling back would re-grant authenticated users access to public-conditional rules — operators must revisit their schemas. This is expected for any RBAC change.

## Seed Data

Not applicable — this change extends authorization metadata on existing schemas, not new schemas. Existing seed objects (per ADR-016 in `docudesk_register.json` and similar) work unchanged.

## Open Questions

- **Should `inheritFromPublic` be exposed on the OAS schema?** Probably yes (the JSON field is part of the authorization block, which is an OR-managed schema property). Confirm during apply by inspecting `OasService::expandRolesForOas`.
- **Should `authenticated` rule support match conditions?** Out of scope here, but worth noting: the simple-rule `authenticated` returns `true` unconditionally. A future change could allow `{group: "authenticated", match: {...}}` for parity with `{group: "public", match: {...}}`. Not addressed in this change.
- **Logging when inheritance is disabled at request time?** A debug-level log entry could help operators diagnose "why can't this authenticated user see the object" cases. Provisional: log at debug level when `inheritFromPublic === false` causes a denial that would have succeeded under inheritance. Confirm during apply if log volume is acceptable.
