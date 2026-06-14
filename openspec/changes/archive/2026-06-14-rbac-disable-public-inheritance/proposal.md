## Why

OpenRegister's RBAC currently treats the `public` group as universally inclusive: every read rule that targets `public` is also evaluated for authenticated users. This is the explicit "logged-in users should also have at least the same rights as 'public' users" semantics in `PermissionHandler::hasPermission` (line 229-241) and the matching qualification logic in `MagicRbacHandler::processConditionalRule` (`if ($group === 'public') $userQualifies = true`). It models authentication as a strict superset of anonymous access.

That superset is the right default for most schemas — if a document is publicly visible, a logged-in colleague should also see it. But there are real cases where it's wrong:

- **Tiered visibility flows.** A tenant wants public users to see a public catalogue (with date-windowed visibility, redactions applied), but logged-in users to access a different curated view that does NOT include the public catalogue's contents — e.g. a "draft / staging" register where logged-in users see drafts and the public sees nothing. With inheritance enabled, the public's empty result set is a non-issue, but with inheritance enabled and a public rule that grants visibility under conditions, logged-in users get those conditional grants too — even when the tenant explicitly wants them gated by their own group memberships.

- **Privacy-strict schemas.** A schema where access is meant to be earned through explicit group membership only — anonymous via the public surface, authenticated only via their assigned groups. Inheritance dilutes that model: every public rule's grant cascades to authenticated users automatically.

This change adds an opt-out: an `inheritFromPublic` boolean on the authorization block (schema-level, with register-level cascade and tenant-wide default). When `false`, authenticated users do NOT qualify for `public` rules — they must qualify via their own group memberships. The default stays `true` (current behaviour) so existing schemas are unaffected.

## What Changes

- **NEW:** `authorization.inheritFromPublic` (boolean, default `true`) field on schema and register authorization blocks. When `false`, the `public` group's rules apply ONLY to anonymous (unauthenticated) users; authenticated users must qualify through their own group memberships.
- **NEW:** Tenant-wide IAppConfig key `openregister.rbac.inherit_from_public_default` (boolean, default `true`). Used as the default when neither the schema's nor the register's authorization block specifies `inheritFromPublic` explicitly. Tenants can flip the global default to `false` for privacy-strict installs.
- **MODIFIED:** `PermissionHandler::hasPermission` — the inheritance fallback at line 229-241 (the "Logged-in users should also have at least the same rights as 'public' users" block) is wrapped in a check on the resolved `inheritFromPublic` flag. Skipped when `false`.
- **MODIFIED:** `MagicRbacHandler::processConditionalRule` and `processSimpleRule` — the `if ($group === 'public') $userQualifies = true` (and the simple-string `if ($rule === 'public') return true`) gain a guard: when `inheritFromPublic` is `false` AND the user is authenticated, the public rule does NOT qualify. Anonymous users see no behaviour change.
- **MODIFIED:** `MagicRbacHandler::processConditionalRuleSql` and the matching simple-rule path in the UNION code — same guard.
- **NEW:** `resolveInheritFromPublic(Schema $schema): bool` helper on `PermissionHandler` (or a similar central place) that resolves the effective flag using the cascade: schema → register → tenant default.
- **NO breaking change.** Default is `true` everywhere, mirroring today's behaviour. Schemas / registers / tenants that don't set the flag see no change.

### Cascade

Effective `inheritFromPublic` is resolved per request as:

```
   schema.authorization.inheritFromPublic       (if set)
   ↓ else
   register.authorization.inheritFromPublic     (if set)
   ↓ else
   IAppConfig['openregister.rbac.inherit_from_public_default']  (default true)
```

The cascade matches how the rest of the authorization config cascades from schema → register today (per `PermissionHandler::resolveAuthorization`).

### Out of scope

- **Per-rule audience flags** (e.g. `audience: "anonymous"` on individual rules). Considered as Option β during exploration; rejected in favour of the simpler schema-level flag (Option α). A per-rule flavor can be added as a follow-up if a real use case appears.
- **A new "authenticated" group concept**. The change DOES NOT introduce an `authenticated` group as a first-class authorization target. (`MagicRbacHandler::processSimpleRule` already recognises the literal string `'authenticated'`, but extending that — e.g. with conditional `'authenticated'` rules with `match` blocks — is out of scope.)
- **Retroactive permission audits**. Existing access decisions on stored objects are not re-evaluated; the flag only affects future RBAC checks.
- **Front-end / admin UI for setting the flag**. v1 surfaces it as a JSON field on the schema / register authorization block, settable via the existing schema editor JSON view. A dedicated UI toggle is a follow-up.
- **Per-action override** (e.g. `inheritFromPublic` differing for read vs write). One flag covers all actions.

## Capabilities

### New Capabilities

(none — this change extends an existing capability rather than introducing a new one.)

### Modified Capabilities

- `rbac-scopes`: the schema/register authorization block gains an optional `inheritFromPublic` boolean. The PHP-side and SQL-side public-group qualification logic honours the flag — when `false`, authenticated users do NOT qualify for `public` rules. Default `true` (unchanged behaviour).

## Impact

- **Code (openregister):**
  - `lib/Db/Schema.php` (or wherever schema authorization is parsed) — accept the new `inheritFromPublic` field; preserve through serialisation.
  - `lib/Service/Object/PermissionHandler.php` — add `resolveInheritFromPublic(Schema $schema): bool` helper using the cascade. Wrap the line 229-241 inheritance fallback in a check on this flag.
  - `lib/Db/MagicMapper/MagicRbacHandler.php` — change `processConditionalRule`, `processConditionalRuleSql`, `processSimpleRule` (and any sibling methods) to accept and respect the flag. Plumb it through from `applyRbacFilters` and `buildRbacConditionsSql`, which resolve it once at the top of the method.
  - Admin settings — surface the tenant default in the existing OR settings UI (where `IAppConfig` keys are exposed). v1 may ship as IAppConfig-only with admin UI follow-up.
- **API contract:** Schema authorization JSON gains an optional `inheritFromPublic` field. Additive, non-breaking. Existing schemas that don't set it retain today's inheriting behaviour. Authorization JSON serialisation includes the field when present.
- **Cross-app:**
  - **DocuDesk**, **OpenCatalogi**, **Softwarecatalog**, **Procest**, **Pipelinq**, **ZaakAfhandelApp** — all consumers of OR's RBAC see no change for schemas that don't set `inheritFromPublic`. Tenants that flip the global default WILL see a behaviour change for any schema with public rules — this is documented in the CHANGELOG as a deliberate opt-in.
  - The OpenCatalogi PublicationsController (the path that surfaced the original use case) automatically benefits — once a publication schema sets `inheritFromPublic: false`, authenticated users seeing publicly-time-windowed objects will be filtered the same way anonymous users are.
- **Privacy / compliance:** Strengthens the privacy-by-design knob set. Tenants with strict authorisation requirements (Wob/Woo with separate authenticated workflows, employee-only registers with public summaries) gain a clean way to gate authenticated access without restructuring their authorization rules.
- **Performance:** Per-RBAC-check overhead is one additional flag lookup (cached per-request). Negligible.
- **Tests:** Unit tests for `resolveInheritFromPublic` cascade behaviour. Unit tests for `hasPermission` covering the four states (anon × inherit-on/off, authenticated × inherit-on/off). SQL-side tests for `applyRbacFilters` with both flag values. Integration test against a schema with `inheritFromPublic: false` to confirm authenticated users don't see public-conditional rows.
- **Migration:** None. Field is additive with a backwards-compatible default. Existing serialised authorization blocks deserialise without modification.
