# Fail closed on unresolvable authorization, and close the annotation-vocabulary drift

## Why

Three defects in OpenRegister — the data abstraction every Conduction app
inherits. All three were verified against HEAD before this change was written.

1. **CWE-863 silent fail-open (critical).** `PermissionHandler::getRegisterAuthorization()`
   caught `\Throwable`, returned `null` **without logging**, and **cached** that
   null. Every caller tests `empty($authorization)` and reads "no rules
   configured" — i.e. **open** — so a transient register/mapper failure granted
   **full permissions to every caller**, and the cached null froze that verdict
   for the rest of the request. `MagicRbacHandler` shares the path: `empty($authorization)`
   there means "schema is open to all" / `bypass => true`, so the same error also
   dropped RBAC **SQL filtering**.

2. **`x-openregister-seed` is a phantom.** In `ANNOTATION_VOCABULARY`, so it
   round-tripped and looked supported — but **no engine reads it**. OR's real,
   engine-backed seed path is `components.objects` / top-level `objects`
   (`ImportHandler`). Consequence: `trust_configuration_register.json` declared
   its 6 MDM trust rules under `components.schemas.trustConfiguration.x-openregister-seed.objects`
   and they were **never planted** — the known "MDM trust register shipped with no
   importer → tiers silently never applied" incident, root-caused.

3. **`x-openregister-processing` is dropped.** Read by `ProcessingLogService::ANNOTATION_KEY`
   but **absent** from `ANNOTATION_VOCABULARY`, so `setConfiguration()` silently
   dropped it and per-schema AVG `logReads` could never be enabled. Register-level
   logReads worked, which masked the gap — the capability was PARTIAL, not absent.

## What Changes

- Authorization resolution **fails closed**: a resolver that cannot determine
  permissions throws `AuthorizationUnresolvableException`, **logs**, and does
  **not** cache the failure. Every caller routes it to a deny.
- `getRegisterForSchema()` carried the same shape and is fixed too — it *logged*
  and still returned `null` → open. **Logging a fail-open does not make it safe.**
- `MagicRbacHandler` clamps to its existing deny-all predicate instead of "open".
- Vocabulary: **remove** the engine-less `x-openregister-seed`; **add** the
  engine-read `x-openregister-processing`.
- Relocate the 6 MDM trust rules to `components.objects` so they actually plant.

## Impact

- Affected specs: `authorization-rbac`
- Affected code: `lib/Service/Object/PermissionHandler.php`,
  `lib/Db/MagicMapper/MagicRbacHandler.php`, `lib/Db/Schema.php`,
  `lib/Exception/AuthorizationUnresolvableException.php`,
  `lib/Settings/trust_configuration_register.json`
- **Behaviour change:** where an authorization outage previously granted access,
  it now denies. That is the point. Correctly-configured registers are unaffected.
- Fleet: `x-openregister-seed` declarations now surface a dropped-key warning
  instead of silently no-oping (scholiq declares it 22× — all empty arrays, no
  data at risk). See design.md for the fleet evidence behind the decision.
