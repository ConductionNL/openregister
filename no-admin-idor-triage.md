# Security triage — `no-admin-idor` (gate-7 / OWASP A01:2021 / ADR-005 Rule 3)

Issue: **Conduction/openregister#325**. Branch: `fix/no-admin-idor-triage` (off `origin/development`).

Gate-7 (`hydra-gate-no-admin-idor`) flagged **103** controller methods carrying
`#[NoAdminRequired]` / `@NoAdminRequired` with no per-object or admin
authorization guard visible in the method body. Each finding was traced through
its actual call chain (service/mapper internals read, not assumed) and
classified into one of four buckets. Gate-7 is now **0** (was 103); gates 5
(route-auth), 8 (unsafe-auth-resolver) and 9 (semantic-auth) still PASS.

## Classification counts

| Bucket | Meaning | Count | Action |
|---|---|---|---|
| **(a)** GUARDED-DOWNSTREAM | Delegates to a service/mapper that already enforces per-object authz | **14** | `@no-admin-idor-exempt` documenting the real guard |
| **(b)** GENUINE-IDOR | Any authed user could read/mutate arbitrary objects by id — no check anywhere | **14** | Real guard added + unit test |
| **(c)** PUBLIC-BY-DESIGN / NO-OBJECT | No per-object resource (capability probe, session-scoped list, external proxy, static catalog, 405/deprecated stub) | **46** | `@no-admin-idor-exempt` documenting why no guard is needed |
| **(d)** WRONG-ANNOTATION | Should be admin-only (tenant-wide analytics, instance config, batch maintenance) | **29** | Admin guard added (`isCurrentUserAdmin()` / `requireAdmin()`); some also drop `@NoAdminRequired` |
| **Total** | | **103** | gate-7 → 0 |

## Genuine IDORs fixed (bucket b) — the security core

| File:method | Leak | Guard added |
|---|---|---|
| `AuditTrailController::objects` | Per-object audit trail (actor UID, IP, per-field diffs) for any object id | `requireAdmin()` + `@NoAdminRequired` removed (aligns with admin-only index/show/export) |
| `EmailsController::search` / `::bySender` | Scanned **all** users' mailboxes for a sender → OR object links in other users' mail | `EmailService::findMessageIdsBySender` now `JOIN oc_mail_accounts ma … AND ma.user_id = ?` (session UID); returns [] for anon |
| `ContactsController::objects` | OR object links for arbitrary (enumerable) CardDAV contact UIDs across all users | `ContactService::getObjectsForContact` filters links to the caller's own addressbook IDs (`getAddressBooksForUser`); [] for anon |
| `DeckController::objects` | OR object links for any Deck board id, bypassing Deck's ACL | `DeckCardService::getObjectsForBoard` gates on Deck `BoardService::find` (board ACL, fail-closed) |
| `FileExtractionController::extract` | Forced text/PII extraction of any file by id (unscoped `FileMapper::getFile` / `IRootFolder::getById`) | `hasFileAccess()` — resolves via caller's `getUserFolder`; 404 if not accessible |
| `FileSidebarController::getObjectsForFile` / `::getExtractionStatus` | Object links + extraction status/PII-risk for any file id | `hasFileAccess()` guard (404) |
| `FileTextController::extractFileText` | Forced re-extraction of any file by id | `hasFileAccess()` guard (404) |
| `LinkedEntityController::reverseLookup` | Cross-tenant object-link scan (RBAC + multitenancy deliberately disabled; TODO #1273) | `isCurrentUserAdmin()` → 403 until per-tenant scoping lands |
| `ObjectIntegrationsController::index/show/create/update` | OR object id forwarded to pluggable providers with **no** OR-side authz | `guardObjectAccess()` resolves the object via `ObjectService` (register RBAC + multitenancy); 404 if inaccessible |

## Wrong-annotation fixes (bucket d) — admin-gated

- `ConfigurationController` ×7 (`enrichDetails`, `discover`, `getGitHub{Branches,Repositories,Configurations}`, `getGitLab{Branches,Configurations}`) — reach external repo content via the instance-wide admin GitHub/GitLab credential; added the sibling `isCurrentUserAdmin()` → 403 guard.
- `DashboardController` ×9 (`index`, `calculate`, 4 chart endpoints, `getAuditTrailStatistics`, `getAuditTrailActionDistribution`, `getMostActiveObjects`) — tenant-wide analytics across all registers/schemas; injected `IUserSession`+`IGroupManager`, added `isCurrentUserAdmin()` gate. Dashboard `page()` shell stays open.
- `AuditTrailController::verify`, `SearchTrailController::export` — tenant-wide audit/search PII; `@NoAdminRequired` removed + `requireAdmin()` (matches sibling posture; gate-9 clean).
- `DeletedController::topDeleters` — cross-user deletion analytics; `isCurrentUserAdmin()` gate.
- `FileExtractionController::{discover,extractAll,retryFailed,cleanup,vectorizeBatch}`, `FileTextController::bulkExtract` — instance-wide batch/maintenance; `isCurrentUserAdmin()` gate.
- `WorkflowEngineController::index/show` — instance-wide integration-engine metadata (internal baseUrl/healthStatus); `isCurrentUserAdmin()` gate (write siblings already gated).
- `LinkedEntityController::addRegisterLink/addSchemaLink` — register/schema config mutation; `isCurrentUserAdmin()` → 403.

## Residual / follow-ups (documented, not silently suppressed)

- `MessageDispatchController::smsSend` / `::whatsappSend` — classified (c): dispatch is bounded (admin-owned base URL, per-channel source allowlist) and keys no OR object. **Open risk**: no rate-limit/quota, so any authed user can trigger paid sends. Recommend a follow-up issue for a per-user rate limiter (not an IDOR; out of gate-7 scope).
- `FileSearchController::semanticSearch` — (c) free-text query (no id). The underlying vector search is not per-user scoped; cross-tenant snippet exposure is a separate concern to track.
- `LinkedEntityController::reverseLookup` — admin-gated as a stop-gap; the proper fix is per-tenant scoping in `LinkedEntityService` (TODO #1273).
- `ObjectIntegrations` providers (e.g. `NotesProvider`) still rely on their downstream NC-app service for entity-level ownership; the controller now authorizes the **OR object**, but a per-provider entity-authz audit is worthwhile.

## How gate-7 is satisfied honestly

- **(b)/(d)** carry a real body guard: `isCurrentUserAdmin()` / `requireAdmin()` (Pattern-1 helper delegation) or a per-resource check (`hasFileAccess()`, `guardObjectAccess()`, `userCanAccessBoard()` — all guard-predicate names the gate recognises), returning 403 (admin) or 404 (per-object, existence-hiding).
- **(a)/(c)** carry a reason-bearing `@no-admin-idor-exempt <reason>` docblock tag (the sanctioned exemption; precedent: `SourcesController`, `ApplicationsController`) naming the downstream guard or explaining the absence of a per-object resource. No blanket suppression — every reason reflects the real authorization story and is reviewer-verifiable.

## Full per-finding table

Classification key: **a** guarded-downstream · **b** genuine-IDOR (fixed) · **c** public/no-object · **d** wrong-annotation (admin-gated).

| # | File:method | Class | Mechanism / fix |
|---|---|---|---|
| 1 | AgentsController::tools | c | Tool-registry metadata (ToolRegistry::getAllTools); no id — exempt |
| 2 | AnalyticsLinksController::available | c | Integration availability probe — exempt |
| 3 | AuditTrailController::update | c | 405 immutability stub, no object access — exempt |
| 4 | AuditTrailController::objects | b | **FIX** requireAdmin() (per-object audit = cross-tenant PII) |
| 5 | AuditTrailController::destroy | c | 405 immutability stub — exempt |
| 6 | AuditTrailController::destroyMultiple | c | 405 immutability stub — exempt |
| 7 | AuditTrailController::verify | d | **FIX** requireAdmin() (tenant-wide hash-chain) |
| 8 | BookmarkLinksController::available | c | Session-scoped bookmarks probe — exempt |
| 9 | CalendarEventsController::listCalendars | c | Session-scoped calendar list — exempt |
| 10 | CalendarEventsController::listCalendarEvents | a | calendarUri resolved only against the caller's own principal calendars — exempt |
| 11 | ChatController::getChatStats | a | Counts scoped to caller's active organisation — exempt |
| 12 | CollectiveLinksController::available | c | Collectives availability probe — exempt |
| 13 | CollectiveLinksController::collectives | c | Session-scoped collectives list — exempt |
| 14 | CompanyLookupController::kvkCompany | c | External KVK proxy, no OR id — exempt |
| 15 | CompanyLookupController::kvkSearch | c | External KVK search proxy — exempt |
| 16 | CompanyLookupController::openCorporatesSearch | c | External OpenCorporates proxy — exempt |
| 17 | ConfigurationController::enrichDetails | d | **FIX** isCurrentUserAdmin() (admin GitHub/GitLab credential) |
| 18 | ConfigurationController::discover | d | **FIX** isCurrentUserAdmin() |
| 19 | ConfigurationController::getGitHubBranches | d | **FIX** isCurrentUserAdmin() |
| 20 | ConfigurationController::getGitHubRepositories | d | **FIX** isCurrentUserAdmin() |
| 21 | ConfigurationController::getGitHubConfigurations | d | **FIX** isCurrentUserAdmin() |
| 22 | ConfigurationController::getGitLabBranches | d | **FIX** isCurrentUserAdmin() |
| 23 | ConfigurationController::getGitLabConfigurations | d | **FIX** isCurrentUserAdmin() |
| 24 | ContactsController::objects | b | **FIX** ContactService scopes to caller's addressbooks |
| 25 | ContactsController::match | c | Free-text contact matching, no id — exempt |
| 26 | CospendLinksController::available | c | Session-scoped Cospend projects — exempt |
| 27 | DashboardController::index | d | **FIX** isCurrentUserAdmin() (tenant-wide analytics) |
| 28 | DashboardController::calculate | d | **FIX** isCurrentUserAdmin() |
| 29 | DashboardController::getAuditTrailActionChart | d | **FIX** isCurrentUserAdmin() |
| 30 | DashboardController::getObjectsByRegisterChart | d | **FIX** isCurrentUserAdmin() |
| 31 | DashboardController::getObjectsBySchemaChart | d | **FIX** isCurrentUserAdmin() |
| 32 | DashboardController::getObjectsBySizeChart | d | **FIX** isCurrentUserAdmin() |
| 33 | DashboardController::getAuditTrailStatistics | d | **FIX** isCurrentUserAdmin() |
| 34 | DashboardController::getAuditTrailActionDistribution | d | **FIX** isCurrentUserAdmin() |
| 35 | DashboardController::getMostActiveObjects | d | **FIX** isCurrentUserAdmin() |
| 36 | DataSubjectRequestController::subjectData | a | Objects loaded via MagicMapper::find(_rbac,_multitenancy) — exempt |
| 37 | DataSubjectRequestController::accessExport | a | Same MagicMapper RBAC scoping — exempt |
| 38 | DataSubjectRequestController::rectify | a | loadByIdentifier → MagicMapper RBAC; 404 on miss — exempt |
| 39 | DataSubjectRequestController::erase | a | Per-object RBAC load; saveObject re-scoped — exempt |
| 40 | DataSubjectRequestController::restrict | a | setMarker → MagicMapper RBAC — exempt |
| 41 | DataSubjectRequestController::objection | a | setMarker → MagicMapper RBAC — exempt |
| 42 | DeckController::objects | b | **FIX** DeckCardService gates on Deck BoardService::find |
| 43 | DeckLinksController::boards | c | Deck BoardService returns only the caller's boards — exempt |
| 44 | DeckLinksController::stacks | a | Deck StackService::findAll → PermissionService::checkPermission — exempt |
| 45 | DeletedController::topDeleters | d | **FIX** isCurrentUserAdmin() (cross-user analytics) |
| 46 | EmailLinksController::accounts | c | oc_mail_accounts filtered by session UID — exempt |
| 47 | EmailsController::search | b | **FIX** EmailService scopes mailbox scan to caller's accounts |
| 48 | EmailsController::bySender | b | **FIX** same EmailService scoping |
| 49 | FileExtractionController::extract | b | **FIX** hasFileAccess() (per-user file resolution) |
| 50 | FileExtractionController::discover | d | **FIX** isCurrentUserAdmin() (instance-wide) |
| 51 | FileExtractionController::extractAll | d | **FIX** isCurrentUserAdmin() |
| 52 | FileExtractionController::retryFailed | d | **FIX** isCurrentUserAdmin() |
| 53 | FileExtractionController::stats | c | Aggregate counters, no id — exempt |
| 54 | FileExtractionController::cleanup | d | **FIX** isCurrentUserAdmin() |
| 55 | FileExtractionController::fileTypes | c | Aggregate stub, no id — exempt |
| 56 | FileExtractionController::vectorizeBatch | d | **FIX** isCurrentUserAdmin() |
| 57 | FileSearchController::semanticSearch | c | Free-text query, no id — exempt (cross-tenant scoping = follow-up) |
| 58 | FileSidebarController::getObjectsForFile | b | **FIX** hasFileAccess() |
| 59 | FileSidebarController::getExtractionStatus | b | **FIX** hasFileAccess() |
| 60 | FileTextController::getFileText | c | Deprecated 404 stub, no access — exempt |
| 61 | FileTextController::extractFileText | b | **FIX** hasFileAccess() |
| 62 | FileTextController::bulkExtract | d | **FIX** isCurrentUserAdmin() |
| 63 | FileTextController::getStats | c | Aggregate counters — exempt |
| 64 | FileTextController::deleteFileText | c | 501 stub — proactively guarded with hasFileAccess() for when implemented |
| 65 | FormLinksController::available | c | Session-scoped Forms — exempt |
| 66 | GitHubIssuesController::index | a | GitHubGuards::enforceRepoAllowlist (repo allowlist + rate-limit) — exempt |
| 67 | GraphQLController::explorer | c | Static GraphiQL HTML page — exempt |
| 68 | GraphQLSubscriptionController::subscribe | a | SubscriptionService per-event verifyEventRBAC → hasPermission — exempt |
| 69 | HeartbeatController::heartbeat | c | Liveness probe — exempt |
| 70 | LinkedEntityController::addRegisterLink | d | **FIX** isCurrentUserAdmin() (register config mutation) |
| 71 | LinkedEntityController::addSchemaLink | d | **FIX** isCurrentUserAdmin() (schema config mutation) |
| 72 | LinkedEntityController::reverseLookup | b | **FIX** isCurrentUserAdmin() (RBAC-disabled cross-tenant scan; TODO #1273) |
| 73 | MapLinksController::available | c | Session-scoped Maps POIs — exempt |
| 74 | MappingsController::test | c | Stateless mapping evaluation, no id — exempt |
| 75 | McpController::discoverCapability | a | Closed hardcoded capability allowlist — exempt |
| 76 | MessageDispatchController::smsSend | c | Bounded dispatch (admin base URL + source allowlist), no id — exempt (rate-limit = follow-up) |
| 77 | MessageDispatchController::whatsappSend | c | Same bounded dispatch — exempt |
| 78 | ObjectIntegrationsController::index | b | **FIX** guardObjectAccess() (ObjectService RBAC) |
| 79 | ObjectIntegrationsController::show | b | **FIX** guardObjectAccess() |
| 80 | ObjectIntegrationsController::create | b | **FIX** guardObjectAccess() |
| 81 | ObjectIntegrationsController::update | b | **FIX** guardObjectAccess() |
| 82 | OrganisationController::index | c | Caller's own organisations only — exempt |
| 83 | OrganisationController::setActive | a | setActiveOrganisation enforces membership (hasUser) — exempt |
| 84 | OrganisationController::getActive | c | Caller's active org only — exempt |
| 85 | OrganisationController::create | c | Self-service org creation, no id read — exempt |
| 86 | OrganisationController::clearCache | c | Clears only the caller's own caches — exempt |
| 87 | PersonLookupController::brpPerson | c | External BRP proxy (BSN), no OR id — exempt |
| 88 | PhotoLinksController::available | c | Session-scoped Photos albums — exempt |
| 89 | PollLinksController::available | c | Session-scoped polls — exempt |
| 90 | RegistersController::stats | a | RegisterMapper::find(_multitenancy:true); metadata read open by design — exempt |
| 91 | SearchTrailController::export | d | **FIX** requireAdmin() + @NoAdminRequired removed |
| 92 | TalkLinksController::rooms | c | Talk rooms the caller participates in — exempt |
| 93 | TasksController::allUserTasks | c | Calendars resolved from session UID — exempt |
| 94 | TimeTrackerLinksController::available | c | Session-scoped TimeTracker clients — exempt |
| 95 | UrnController::resolve | c | URN→URL address translation, no object body — exempt |
| 96 | UrnController::lookup | c | URL→URN address translation — exempt |
| 97 | UrnController::bulk | c | Batch URN translation (capped 1000) — exempt |
| 98 | WebPushController::vapidPublicKey | c | Public VAPID key only (private key never exposed) — exempt |
| 99 | WebhooksController::events | c | Static webhook event-type catalogue — exempt |
| 100 | WorkflowEngineController::index | d | **FIX** isCurrentUserAdmin() (instance-wide engine metadata) |
| 101 | WorkflowEngineController::show | d | **FIX** isCurrentUserAdmin() |
| 102 | XwikiLinksController::available | c | Admin-configured XWiki source reachability probe — exempt |
| 103 | XwikiLinksController::search | c | Object-independent free-text KB search — exempt |
