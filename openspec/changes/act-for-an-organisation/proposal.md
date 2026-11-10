## Why

Portal callers are not Nextcloud users. A citizen or an employer signs in to a portal with a portal account, and the portal app (portaliq) verifies that account's organisation from a signed session or a signed claim. OpenRegister never sees that organisation: portaliq reads with `_rbac: false` and `_multitenancy: false` and then checks each row against the claim itself. learniq does the same for its employer pages (learniq#2012, `EmployerCompanyTenancy` and `PortalClaimScopeListener`) and portaliq added an event so that every app can check its own rows (portaliq#1926, `PortalClaimScopeEvent`).

So the tenancy boundary for portal reads is written in the apps, once per app. OpenRegister owns multitenancy (ADR-022), and Ruben decided on 3 November 2026 that portal requests get their tenancy from OpenRegister: building it twice is not acceptable.

## What changes

- **A scoped organisation context.** `ObjectService::runForOrganisation(string $organisationUuid, callable $operation)` runs the callable acting for one organisation. Inside it, that organisation is the caller's organisation for every tenancy decision: the organisation filter, the `$organisation` token in read rules, and the organisation stamped on new objects.
- **Only app code can open it.** It is a static PHP scope (`OrganisationContext`), like `SystemOperationContext` and `AnonymousEvaluationContext`. Nothing in a request opens or closes it. An organisation that does not exist is refused before the callable runs.
- **The caller is anonymous inside it.** The callable runs as `runAsAnonymous()`: the Nextcloud session user, an API token's grant and the system scope do not apply. With RBAC on, only `public` rules decide.
- **The organisation filter always applies inside it.** `_multitenancy: false`, the schema's own multitenancy setting, the RBAC waiver, the admin bypass and rules marked `crossOrganisation: true` do not lift or widen it. The filter admits the organisation, its parent chain and its shared master data holders, the same set a member of that organisation reads.
- **Writes are stamped with the context's organisation.** A new object gets the context's organisation; a client-supplied `@self.organisation` for another organisation is not honoured. The owner is the explicit `_owner` the app passes (for example `portal:<subjectRef>`), otherwise the system identity.
- **Audit rows name the organisation.** A row written inside the context records the user as the system and carries `resultSummary.organisationContext`, inside the sealed part of the row.

## Impact

- New: `OrganisationContext`, `ObjectService::runForOrganisation()`, `OrganisationService::findContextOrganisation()`, `Audit\OrganisationContextAttribution`.
- Changed: `OrganisationService::getActiveOrganisation()`, `MagicOrganizationHandler::resolveOrganizationScope()`, `ConditionMatcher`'s organisation memo, and the organisation filter decisions in `MagicRbacHandler` and `MagicSearchHandler` (built with the `rbac-cross-organisation-is-explicit` change, which owns those methods).
- No behaviour changes outside a context.
- Follow-ups in other repos, stacked: portaliq reads every claim-scoped path inside the context instead of with `_rbac`/`_multitenancy` off; learniq runs its employer writes and the occ invite inside it, and the app-side tenancy checks that the context makes redundant are removed.
