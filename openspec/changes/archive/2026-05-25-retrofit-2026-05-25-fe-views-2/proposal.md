# Retrofit — frontend coverage, views (chunk 2)

## Why

The coverage scan flagged 223 methods across 18 `src/views/**/*.vue` files as carrying no `@spec` annotation. Under ADR-003 every code unit must point at a spec task or be explicitly excluded with a reason. This change is a documentation-only retrofit: it brings the chunk's 223 methods to fully-tagged status, overwhelmingly via JSDoc `@spec exclude <reason>` tags.

These views are list/detail/settings UI plumbing — pagination, row selection, formatting helpers, lifecycle fetches, store wiring, navigation, and clipboard/download glue. The observable user-facing behavior they render is already owned by existing capabilities (`object-lifecycle`, `linked-entity-types`, `archivering-vernietiging`, `zoeken-filteren`, `ai-chat-companion`, `auth-system`, `tenant-lifecycle`, `registers-management`, `oas-validation`, schema workflow). None of the 223 methods expresses a novel contract that warrants a new REQ, so no spec delta is minted.

## What

- Tag all 223 methods with `@spec exclude <reason>` (reason REQUIRED per ADR-003; bare `exclude` is invalid).
- No new REQs. No spec delta directory. This is a documentation-only retrofit ghost change (supported by the retrofit playbook).

## Counts

| Bucket | Count |
|---|---|
| Methods in chunk | 223 |
| Reverse-spec'd (new REQs) | 0 |
| Excluded (`@spec exclude`) | 223 |
| New REQs minted | 0 |

## Files (method counts)

| File | Methods |
|---|---|
| `src/views/Endpoint/EndpointDetails.vue` | 1 |
| `src/views/account/sections/ActivitySection.vue` | 3 |
| `src/views/account/sections/NotificationsSection.vue` | 2 |
| `src/views/agents/AgentsIndex.vue` | 9 |
| `src/views/application/ApplicationDetails.vue` | 1 |
| `src/views/application/ApplicationsIndex.vue` | 9 |
| `src/views/chat/ChatIndex.vue` | 26 |
| `src/views/deleted/DeletedIndex.vue` | 33 |
| `src/views/entities/EntitiesIndex.vue` | 15 |
| `src/views/entities/EntityDetail.vue` | 7 |
| `src/views/files/FilesIndex.vue` | 18 |
| `src/views/logs/SearchTrailIndex.vue` | 16 |
| `src/views/object/ObjectsList.vue` | 4 |
| `src/views/organisation/OrganisationDetails.vue` | 19 |
| `src/views/register/RegistersIndex.vue` | 24 |
| `src/views/schemas/SchemaWorkflowTab.vue` | 6 |
| `src/views/settings/sections/OrganisationConfiguration.vue` | 11 |
| `src/views/settings/sections/PermissionMatrix.vue` | 17 |
| **Total** | **223** |

## Out of scope

- Reshaping or fixing observed behavior. Drift and TODOs (e.g. `viewSource`/`exportFilteredItems`/`removeMember` stubs) are left as-is.
- Methods outside this chunk's 18 files.

Source: coverage scan batch `fw-fe-views-2.json` (2026-05-25). See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
