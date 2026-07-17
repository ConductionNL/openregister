## Tasks

This is a reverse-spec annotation pass. Each task pins a cluster of already-shipped
methods to the spec delta that documents their observed behaviour. No code behaviour
changes — only `@spec` docblock annotations are added.

### 1. computed-fields — JSON-AST calculation evaluator
- [x] Annotate `CalculationEvaluator::evaluate()` — single-key AST dispatch over the v1 operator vocabulary (`prop`, `lit`, `concat`, `if`, `not`, `and`, `or`, `+ - * / %`, `eq/ne/lt/lte/gt/gte`, `now`, `diffDays`, `formatDate`, `dateDiff`).
- [x] Annotate `CalculationEvaluator::propValue()` — dotted-path + `@self.<field>` property resolution, missing path → null.
- [x] Annotate the arithmetic/boolean/comparison/date helpers (`concat`, `ifExpr`, `boolEval`, `reduceBool`, `arith`, `subOrNeg`, `divide`, `modulo`, `compare`, `normaliseForCompare`, `now`, `diffDays`, `formatDate`, `dateDiff`, `toDateOrNull`) — fail-fast `EvaluationException` on malformed args; non-numeric / zero-divisor / unknown-unit rejection; ISO-date coercion for ordering.

### 2. computed-fields — calculation annotation validator
- [x] Annotate `CalculationAnnotationValidator::validate()` — schema-save validation of `x-openregister-calculations`: per-calc `type`/`expression` shape, prop-reference resolution against schema properties + sibling calcs, `@self.<field>` allowlist, and cross-calc cycle detection.
- [x] Annotate `walk()` / `walkDateDiff()` / `findCycle()` — recursive AST walk collecting errors + dependency edges; DFS-colouring cycle finder over calc-to-calc edges only.

### 3. profile-actions — UserService self-service backend
- [x] Annotate the profile-data builders (`buildUserDataArray`, `getCustomNameFields`, `setCustomNameFields`, `buildQuotaInformation`, `getUsedSpaceMemorySafe`, `getLanguageAndLocale`, `getAdditionalProfileInfo`, `getAccountManagerPropertiesSelectively`, `updateUserProperties`, `updateStandardUserProperties`, `updateProfileProperties`, `determineChangedFields`, `getDefaultPropertyScope`).
- [x] Annotate `changePassword()` — backend-capability gate, current-password verify, policy-failure → typed exception.
- [x] Annotate `uploadAvatar()` / `deleteAvatar()` — MIME allowlist, 5 MB cap, `canChangeAvatar()` gate.
- [x] Annotate `exportPersonalData()` — GDPR Art. 20 assembly, once-per-hour rate limit.
- [x] Annotate `getNotificationPreferences()` / `setNotificationPreferences()` — defaulted prefs, `emailDigest` enum validation, bool-string round-trip.
- [x] Annotate `getUserActivity()` — audit-trail-by-actor projection with pagination + type/date filters.
- [x] Annotate the API-token lifecycle (`createApiToken`, `listApiTokens`, `revokeApiToken`, `getStoredTokens`, `storeTokens`, `parseExpiration`) — SHA-256-at-rest, once-only plaintext return, MAX_TOKENS cap, malformed-`expiresIn` rejection.
- [x] Annotate the deactivation lifecycle (`requestDeactivation`, `getDeactivationStatus`, `cancelDeactivation`) — pending-request store, duplicate guard, cancel.

### 4. tenant-lifecycle — organisation membership & active-org resolution
- [x] Annotate the membership-resolution methods (`getUserOrganisations`, `getActiveOrganisation`, `setActiveOrganisation`, `joinOrganisation`, `leaveOrganisation`, `hasAccessToOrganisation`, `getUserOrganisationStats`, `getOrganisationForNewEntity`, `getUserActiveOrganisations`) — per-user membership, last-org leave guard, admin-bypass access check, parent-chain resolution.
- [x] Annotate the default-org bootstrap methods (`ensureDefaultOrganisation`, `fetchDefaultOrganisationFromDatabase`, `createOrganisation`, `getDefaultOrganisationUuid`, `getDefaultOrganisationId`, `setDefaultOrganisationId`, `getOrganisationSettingsOnly`, `generateSlug`, `addAdminUsersToOrganisation`, `addAdminGroupToAuthorization`, `hasAdminGroupInAuthorization`, `getAdminGroupUsers`) — auto-create default, slug-collision recovery, admin RBAC seeding.
- [x] Annotate the active-org cache lifecycle (`cacheActiveOrganisation`, `reconstructOrganisationFromCache`, `clearActiveOrganisationCache`, `clearCache`, `clearDefaultOrganisationCache`, `cacheDefaultOrganisation`, `fetchActiveOrganisationFromDatabase`, `formatCreatedDate`, `formatUpdatedDate`) — session + static caching with 15-minute TTL, stale-access invalidation, oldest-org fallback.

### 5. built-in-dashboards — dashboard aggregation service
- [x] Annotate the stats aggregators (`getStats`, `getOrphanedStats`, `getRegistersWithSchemas`, `getAuditTrailStatistics`, `getAuditTrailActionDistribution`, `getMostActiveObjects`) — per-register/-schema rollups, system-totals + orphaned pseudo-registers, fail-soft zero envelopes.
- [x] Annotate the size-recalculation methods (`recalculateSizes`, `recalculateLogSizes`, `recalculateAllSizes`, `calculate`, `fetchRegister`, `fetchSchema`, `buildResponseScope`, `calculateSuccessRate`) — re-save-to-recompute pass, processed/failed tallies, success-rate summary.
- [x] Annotate the chart-data assemblers (`getAuditTrailActionChartData`, `getObjectsByRegisterChartData`, `getObjectsBySchemaChartData`, `getObjectsBySizeChartData`) — labels+series envelope delegated to mappers, fail-soft empty envelope.

### 6. Validation
- [x] `php -l` clean on all five touched files.
- [x] `openspec validate retrofit-2026-05-24-b-svc-compute-profile-org --strict` passes.
