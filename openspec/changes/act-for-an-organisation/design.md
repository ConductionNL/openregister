## Context

Portal accounts are not Nextcloud users. portaliq verifies a portal account's organisation from an HMAC-signed bearer or a signed assertion, and today reads OpenRegister with `_rbac: false` and `_multitenancy: false`, checking each row against the claim in app code. This change lets the app hand that verified organisation to OpenRegister, so the organisation filter is OpenRegister's again.

The organisation filter itself is reworked by `rbac-cross-organisation-is-explicit` (every read rule keeps the filter unless it carries `crossOrganisation: true`). This change does not touch that rule; it changes where the caller's organisation comes from, and makes the filter unconditional inside the context.

## Decisions

### D1. One entry point, a static scope underneath

`ObjectService::runForOrganisation(string $organisationUuid, callable $operation)` is the API. It sits next to `runAs()`, `runAsSystem()` and `runAsAnonymous()`, which apps already call on the same service. Underneath, `OrganisationContext` is a static scope holder in the style of `SystemOperationContext` and `AnonymousEvaluationContext`: a stack of uuids, pushed before the callable and popped in a `finally`. The innermost scope applies; an outer one is back in force when the inner callable ends or throws.

A static scope has no request-side representation. `_rbac` and `_multitenancy` travel in the query dict and are stripped from request parameters by the controllers; a scope cannot be reached that way at all.

### D2. Who may open it: app code that verified the organisation

The method is a PHP call. Its caller is responsible for having verified the organisation (a signed session, a signed claim, an occ argument an administrator typed). OpenRegister checks only that the organisation exists, and refuses an unknown or empty uuid before the callable runs. Refusing is the fail-closed answer: running the callable with no organisation would read nothing, and an app would not be able to tell that from an empty result.

### D3. RBAC: the caller is anonymous

Inside the context the callable runs as `runAsAnonymous()`. The session user is hidden (incognito mode), an API token's grant is suspended, and `AnonymousEvaluationContext` is open, so the system scope yields. With `_rbac: true` only `public` rules hold. `$user.*` tokens resolve to null and deny. An app that decides access itself may still pass `_rbac: false`; that is a PHP-side flag no request can set.

A browser that holds a Nextcloud session while it uses a portal is the case this guards: without it an administrator's cookie would turn a portal read into an admin read.

### D4. The organisation filter is unconditional

Inside the context:

- `MagicOrganizationHandler::resolveOrganizationScope()` returns `SCOPE_IN` over the context's organisation, its parent chain and the shared master data holders of the table being read, before the system and admin branches. An unknown organisation gives `SCOPE_NONE`.
- `MagicSearchHandler::resolveMultitenancyFlag()` and `multitenancyApplies()` apply the filter whatever `_multitenancy`, the schema's multitenancy setting or the RBAC waiver say.
- the paths that skip access control when both `_rbac` and `_multitenancy` are false (`MagicMapper::findInRegisterSchemaTable()`, `findAcrossAllMagicTables()`, the deleted-object scans, and the bulk-delete filter `PermissionHandler::filterUuidsForPermissions()`) read the flag through `OrganisationContext::multitenancy()`, which is true inside a context. Inside a context for an unknown organisation the bulk-delete filter keeps only rows with no organisation.
- `MagicRbacHandler::publicReadLiftsOrganisationFilter()` answers false, `hasConditionalRulesBypassingMultitenancy()` answers false and `hasCrossOrganisationBranch()` answers false: a `public` rule does not lift the filter and no rule, marked or not, crosses organisations.

The parent chain is included because it is what a member of the organisation reads; a context must not read more than a member, and reading less would make portal pages differ from the back office for no tenancy reason. The scope is `SCOPE_IN`, not the administrators' `SCOPE_IN_OR_NULL`. Rows with no organisation belong to no tenant and are admitted only where the register cascade admits them, as outside a context.

Configuration entities (registers, schemas) are read as for an anonymous caller. The context scopes object rows.

### D5. Writes

`OrganisationService::getActiveOrganisation()` answers with the context's organisation, so `getOrganisationForNewEntity()` stamps it on every new object. `SaveObject` honours a client `@self.organisation` only for an administrator or a member of that organisation; inside the context there is no user, so such a value is dropped and the context's organisation is stamped. Owner attribution is unchanged: the app's explicit `_owner` (such as `portal:<subjectRef>`), otherwise the system identity.

This answers the open question about learniq's employer bookings, which are saved as the system on a public route: inside the context they carry the employer's organisation instead of the default organisation.

An update looks up the existing object through the same single-object query, so an object of another organisation is not found inside the context and is not updated.

### D6. Audit

A row written inside the context has no Nextcloud user, so `user` and `userName` read `System`, as for any userless write. `OrganisationContextAttribution` adds `resultSummary.organisationContext` with the organisation's uuid. `resultSummary` is inside the canonical JSON the hash chain seals, so the attribution runs in both builders (`buildAuditTrail()` and the insert path) before the row is inserted. Reading the trail stays as it is: the readable-trail lister refuses a caller without a user, and an object's history is reached only through the object's own read, which the context filters.

## Risks

- Lookups OpenRegister makes on behalf of the write (calculation references, relations) are filtered too. A portal write that relies on rows held by another organisation needs those rows declared as shared master data.
- Callers that nest `runAs()` inside the context get no user: incognito mode wins. That is fail-closed and intended.
