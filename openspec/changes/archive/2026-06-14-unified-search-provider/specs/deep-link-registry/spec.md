unified-search-provider
---
status: draft
---
# Deep Link Registry — display-name extension (delta)

## Purpose

Extend the existing deep-link registry so a registration also answers
"what should this app's search results be called", not only "where do
they link to". Consumed by the unified search provider for per-app
result labeling. Backward compatible: the parameter is optional and
existing listeners (pipelinq, procest) keep working unchanged.

## ADDED Requirements

### Requirement: Deep link registrations SHALL carry an optional display name

`DeepLinkRegistration` SHALL accept an optional `displayName` (string,
default null) alongside appId, registerSlug, schemaSlug, urlTemplate,
and icon. `DeepLinkRegistryService::register()` and the
`DeepLinkRegistrationEvent::register()` convenience SHALL accept the
same optional trailing parameter. The service SHALL expose
`resolveDisplayName(int $registerId, int $schemaId): ?string`
returning the registration's `displayName`, falling back to its
`appId` when no display name was provided, and `null` for unclaimed
(register, schema) pairs.

#### Scenario: Registration with a display name
- GIVEN pipelinq registers `pipelinq::client` with `displayName: 'Pipelinq'`
- WHEN `resolveDisplayName()` is called for that register/schema pair
- THEN it returns `'Pipelinq'`

#### Scenario: Registration without a display name falls back to the app id
- GIVEN procest registers `case-management::case` without a display name
- WHEN `resolveDisplayName()` is called for that pair
- THEN it returns `'procest'`

#### Scenario: Unclaimed pair resolves to null
- GIVEN no registration exists for `case-management::audit-log`
- WHEN `resolveDisplayName()` is called for that pair
- THEN it returns `null`

#### Scenario: Existing listeners remain source-compatible
- GIVEN a consuming app's listener calls the event's `register()` with the pre-extension argument list
- WHEN OpenRegister dispatches `DeepLinkRegistrationEvent` during boot
- THEN the registration succeeds with `displayName = null` and no deprecation or error
