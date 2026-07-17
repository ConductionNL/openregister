# Retrofit — annotate-only triage, miscellaneous service helpers (2026-05-24)

Annotate-only (Bucket 1 style) triage pass over the `miscellaneous-service-helpers`
sub-cluster: 49 `lib/Service/**` files the architect flagged as "the dregs" —
methods that did not cleanly fit any of the 22 other service sub-clusters and that
aggressive Phase 2/3 triage already drained down to constructors and helpers.

This is NOT a reverse-spec / spec-authoring run. No new capabilities are minted and
no spec deltas are written. Every annotation points at a requirement that already
exists in `openspec/specs/`. No code logic changes.

## Outcome

- **32 methods annotated** to the existing `graphql-api` capability (the GraphQL
  `SchemaGenerator` suite — the architect's one named exception, which turned out to
  already have a home in the `implemented` `graphql-api` spec).
- **All other bare methods DROPped** — constructors, trivial getters/predicates,
  private helpers with no standalone contract, and substantive methods that belong to
  OTHER sub-clusters (out of scope for this dregs pass).
- **0 future-pass flags** — the GraphQL suite was the only candidate for a future
  reverse-spec pass, and it was fully covered by the existing `graphql-api` spec, so
  nothing remains to flag.

## Annotated (32 methods → graphql-api)

The `graphql-api` spec (status: `implemented`) names these methods explicitly in its
scenarios, so the annotations are faithful retroactive mappings, not new contracts:

- `lib/Service/GraphQL/SchemaGenerator.php` — `buildSchemaFields`, `buildMutationFields`,
  `initScalars`, `initHandlers`, `getObjectType`, `buildObjectFields`, `resolveRef`,
  `toTypeName`, `toFieldName`, `singularize`, `createSingleResolverPlaceholder`,
  `createListResolverPlaceholder`, `createMutationResolverPlaceholder` (13)
- `lib/Service/GraphQL/SchemaGenerator/TypeMapperHandler.php` — `mapPropertyToGraphQLType`,
  `mapPropertyToInputType`, `getFilterInputType`, `getCreateInputType`, `getUpdateInputType`,
  `buildInputFields`, `getConnectionType`, `getPageInfoType`, `getSortInputType`,
  `getSelfFilterType`, `getPropertyAuthDescriptions` (11)
- `lib/Service/GraphQL/SchemaGenerator/CompositionHandler.php` — `applyComposition`,
  `applyAllOf`, `applyOneOf`, `applyAnyOf`, `resolveCompositionRefs`, `extractSharedFields` (6)
- `lib/Service/GraphQL/Scalar/JsonType.php` — `serialize` (1)
- `lib/Service/GraphQL/GraphQLErrorFormatter.php` — `format` (1)

Constructors in the GraphQL classes were left as DROPs (see below).

## Dropped (plumbing + out-of-sub-cluster)

Nothing below was annotated. Two reasons drive a DROP:

**(a) Pure plumbing — no standalone behavioral contract.** Constructors, DI wiring,
trivial getters/`is*` predicates, and private formatting helpers across:
`ActionExecutor`, `ActionService` (`getNestedValue`), `ApprovalService`,
`AuditHashService`, `AuthorizationAuditService`, `ChatService`, `DateTimeNormalizer`
(constructor), `DeepLinkRegistryService`, `DownloadService` (empty `@todo` stub class,
zero methods), `HookExecutor`, `LinkedEntityService`, `McpDiscoveryService`
(`getBaseUrl`, `getCapabilityIds`), `OasService` (constructor, `getLastValidationReport`),
`RetentionService`, `TenantLifecycleService` (`isValidStatus`), `ToolRegistry`,
`WorkflowEngineRegistry`, and the constructors of every GraphQL class. These are
implementation scaffolding, not contracts a spec should pin.

**(b) Substantive but owned by another sub-cluster.** Several files carry real
behavioral methods, but those belong to one of the other 22 service sub-clusters and
are (or will be) annotated there — annotating them here would steal scope and create
duplicate/competing `@spec` homes. Left untouched for their owning pass:

- `AuthorizationService`, `AuthenticationService` residue → **auth-system** /
  **migrate-auth-system**
- `AvgComplianceService`, `AvgRetentionService` → **avg-verwerkingsregister** /
  **retention-management**
- `CalendarEventService`, `CalendarLinkService` → **calendar-provider** /
  **integration-calendar**
- `ContactMatchingService`, `ContactService` → **contacts-actions** /
  **integration-contacts**
- `DeckLinkService` → **integration-deck**
- `EmailService` → **mail-sidebar** / **integration-email**
- `ExportService`, `ImportService` → **data-import-export**
- `FileService`, `FileSidebarService` → **file-actions** / **files-sidebar-tabs**
- `FlowLinkService` → **integration-flow**
- `LogService` → log/audit-trail caps
- `ManifestService` → **openregister-adopt-app-manifest**
- `PropertyRbacHandler` → **row-field-level-security** / **rbac-scopes**
- `RegisterService` → register CRUD caps (`register-resolver-service` et al.)
- `SchemaService` (schema-exploration suite, ~30 methods) →
  **openregister-runtime-schema-api** / schema-exploration caps
- `SearchTrailService` → search/analytics caps
- `TenantKeyService` → **tenant-isolation-audit** / tenant key caps
- `TextExtractionService` → **text-extraction-eml** /
  **text-extraction-office-completeness**
- `TmloService` → **tmlo-metadata**

These are recorded as out-of-scope DROPs for THIS sub-cluster, not as gaps.

## Future reverse-spec passes

None required out of this bundle. The architect's single flagged candidate (the
GraphQL `SchemaGenerator`) was already covered by the existing `graphql-api`
capability and is now annotated. The "owned by another sub-cluster" items above are
not gaps — they have existing capability homes and belong to their own passes.

Source: `/tmp/or-scan/bundle-svc-misc-annotate.json` (sub-cluster
`miscellaneous-service-helpers`, mode `human-triage`).
See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
