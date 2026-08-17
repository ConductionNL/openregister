## Purpose

Lets a consuming Nextcloud app expose a single OpenRegister (register, schema) pair as its own named entry in the Smart Picker and unified search, by subclassing two reusable abstract base classes instead of hand-rolling URL parsing, RBAC-safe search, and rich-preview formatting.

## ADDED Requirements

### Requirement: AbstractSchemaReferenceProvider MUST expose one configured (register, schema) pair as a discoverable, searchable Smart Picker entry

`AbstractSchemaReferenceProvider` SHALL extend `OCP\Collaboration\Reference\ADiscoverableReferenceProvider` and implement `OCP\Collaboration\Reference\ISearchableReferenceProvider`. A subclass MUST implement exactly two abstract methods, `getRegisterSlug(): string` and `getSchemaSlug(): string`; it MUST NOT supply, and cannot override, the provider id, the supported search-provider id, the title, or the icon — those are computed by the abstract base class, as described in the following requirements.

#### Scenario: Subclass supplies slugs only
- **WHEN** a consuming app creates a subclass of `AbstractSchemaReferenceProvider` implementing only `getRegisterSlug()` and `getSchemaSlug()`
- **THEN** the subclass requires no other method overrides to appear in the Smart Picker as its own entry, distinct from OpenRegister's generic "Register Objects" entry

#### Scenario: Multiple schema-scoped providers coexist
- **GIVEN** two consuming apps each register a subclass of `AbstractSchemaReferenceProvider` for two different (register, schema) pairs
- **WHEN** the Smart Picker modal is opened
- **THEN** both entries appear alongside each other and alongside OpenRegister's generic "Register Objects" entry, each with its own computed id, title, and icon

### Requirement: The provider id and supported search-provider id MUST be computed deterministically by the base class, not supplied by the subclass

`AbstractSchemaReferenceProvider::getId()` and `getSupportedSearchProviderIds()` SHALL be declared `final` so no subclass can override them. `getId()` SHALL return `openregister-ref-{registerSlug}-{schemaSlug}`, consistent with the existing generic reference provider's `openregister-ref-objects` id. `getSupportedSearchProviderIds()` SHALL return a single-element array containing `openregister_objects_{registerSlug}_{schemaSlug}`, consistent with the existing generic search provider's `openregister_objects` id and matching the id the paired `AbstractSchemaSearchProvider` subclass computes for the same (register, schema) pair.

#### Scenario: Provider id is derived from register and schema slugs
- **GIVEN** a subclass of `AbstractSchemaReferenceProvider` with `getRegisterSlug()` returning `pipelinq` and `getSchemaSlug()` returning `lead`
- **WHEN** `getId()` is called
- **THEN** it SHALL return `openregister-ref-pipelinq-lead`

#### Scenario: Two different schemas never collide on id
- **GIVEN** two subclasses configured for different (register, schema) slug pairs
- **WHEN** each subclass's `getId()` is called
- **THEN** the two returned ids SHALL differ, because register/schema slugs are unique and the id is derived, never hand-picked

#### Scenario: A subclass cannot override the computed id
- **GIVEN** a subclass of `AbstractSchemaReferenceProvider`
- **WHEN** the subclass attempts to declare its own `getId()` or `getSupportedSearchProviderIds()` method
- **THEN** this SHALL fail to compile/lint as a fatal error, because both methods are declared `final` on the abstract base class

### Requirement: Title and icon MUST be read live from the schema's own metadata, not supplied by the subclass

`AbstractSchemaReferenceProvider::getTitle()` SHALL return the schema's current title as returned by `SchemaMapper::find()->getTitle()`. `getIconUrl()` SHALL resolve the schema's icon using the same MDI-icon resolution `ObjectsProvider::resolveSchemaIcon()` already performs, rendered via the existing `openregister.icon.mdi` route (`MdiIconRenderer`). Both methods SHALL be declared `final`.

#### Scenario: Title reflects the schema's current title
- **GIVEN** a schema-scoped provider configured for a schema whose title is "Lead"
- **WHEN** an administrator renames the schema to "Sales Lead" via the Schema settings UI
- **THEN** the Smart Picker entry's title SHALL show "Sales Lead" on the next request, without any change to the consuming app's code
- **AND** `getTitle()` SHALL NOT be overridable by the subclass

#### Scenario: Icon is resolved from the schema's configured icon
- **GIVEN** a schema with an MDI icon configured
- **WHEN** `getIconUrl()` is called on a schema-scoped provider for that schema
- **THEN** it SHALL return a URL through the `openregister.icon.mdi` route resolving to that schema's icon, using the same resolution logic as the generic `ObjectsProvider`

### Requirement: AbstractSchemaReferenceProvider MUST only match and resolve references belonging to its configured schema

`matchReference()` and `resolveReference()` SHALL reuse OpenRegister's existing URL-parsing and rich-preview-building logic for hash-routed, API, and direct-route object URLs. After parsing a candidate URL, the parsed register id and schema id MUST be compared against the instance's configured pair. When they do not match, `matchReference()` SHALL return `false` and `resolveReference()` SHALL return `null`, even though the URL is a syntactically valid OpenRegister object reference.

#### Scenario: URL for the configured schema matches
- **GIVEN** a schema-scoped provider configured for register 5, schema 12
- **WHEN** `matchReference()` receives an OpenRegister object URL parsing to register 5, schema 12
- **THEN** it SHALL return `true`

#### Scenario: URL for a different schema does not match
- **GIVEN** a schema-scoped provider configured for register 5, schema 12
- **WHEN** `matchReference()` receives a syntactically valid OpenRegister object URL parsing to register 5, schema 20
- **THEN** it SHALL return `false`
- **AND** `resolveReference()` for the same URL SHALL return `null`

#### Scenario: Resolved reference reuses the shared preview-formatting behavior
- **GIVEN** a schema-scoped provider configured for register 5, schema 12, and a matching object URL for an object the caller may read
- **WHEN** `resolveReference()` is called
- **THEN** the returned `IReference` SHALL carry the same rich-object shape (title, description, schema, register, url, icon_url, updated, properties) that the generic `ObjectReferenceProvider` produces for the same object
- **AND** an object the caller may not read (RBAC failure) SHALL cause `resolveReference()` to return `null` without leaking object metadata, matching the generic provider's behavior

### Requirement: AbstractSchemaSearchProvider MUST search only within its configured schema under the same RBAC and multitenancy contract as the generic search provider

`AbstractSchemaSearchProvider` SHALL implement `OCP\Search\IProvider`. A subclass MUST implement `getRegisterSlug(): string` and `getSchemaSlug(): string`; it MUST NOT supply, and cannot override, the provider id or display name, which the abstract base class computes as `final` methods (mirroring `AbstractSchemaReferenceProvider`). `search()` SHALL delegate to OpenRegister's object search with the configured schema forced into the query (`@self.schema`), with RBAC (`_rbac: true`) and multitenancy (`_multitenancy: true`) enforcement equivalent to the generic `openregister_objects` provider. The provider SHALL apply no second access filter beyond that delegation.

#### Scenario: Search-provider id is derived from register and schema slugs
- **GIVEN** a subclass of `AbstractSchemaSearchProvider` with `getRegisterSlug()` returning `pipelinq` and `getSchemaSlug()` returning `lead`
- **WHEN** `getId()` is called
- **THEN** it SHALL return `openregister_objects_pipelinq_lead`, matching the id the paired `AbstractSchemaReferenceProvider` subclass declares in `getSupportedSearchProviderIds()`

#### Scenario: Display name reflects the schema's current title
- **GIVEN** a schema-scoped search provider configured for a schema whose title is "Lead"
- **WHEN** `getName()` is called
- **THEN** it SHALL return the schema's current title read live via `SchemaMapper::find()->getTitle()`, not a value the subclass supplies

#### Scenario: Search results are confined to the configured schema
- **GIVEN** a schema-scoped search provider configured for schema 12
- **WHEN** a user searches via the Smart Picker
- **THEN** every returned result SHALL belong to schema 12, regardless of the search term
- **AND** objects from other schemas SHALL never appear in the results

#### Scenario: RBAC and multitenancy are enforced identically to the generic provider
- **GIVEN** a schema-scoped search provider configured for schema 12, and a caller who lacks read access to some objects in schema 12
- **WHEN** the caller searches via the Smart Picker
- **THEN** the result set SHALL exclude objects the caller is not authorized to read, and SHALL exclude objects outside the caller's active organisation, exactly as the generic `openregister_objects` provider would for the same caller and schema

### Requirement: A `smartPickerEnabled` schema flag MUST gate schema-scoped provider functionality, but not the registered picker entry's list-visibility

`Schema` SHALL carry a boolean `smartPickerEnabled` column, default `false`, with a matching `SchemaMapper` query method for enabled schema ids, mirroring the existing `searchable` flag's implementation pattern (`SchemaMapper::findSearchableIds()`/`findNonSearchableIds()`). Each `AbstractSchemaReferenceProvider`/`AbstractSchemaSearchProvider` instance MUST consult its own configured schema's `smartPickerEnabled` value before matching, resolving, or searching. When `smartPickerEnabled` is `false`: `matchReference()` SHALL return `false` for every input, `resolveReference()` and `resolveReferencePublic()` SHALL return `null`, and `search()` SHALL return an empty (but successfully completed) `SearchResult`. Because Nextcloud resolves the Smart Picker's "Select provider" list from boot-time class registration (`IRegistrationContext::registerReferenceProvider()`/`registerSearchProvider()`), which never consults this runtime flag, disabling `smartPickerEnabled` MUST NOT be relied upon, and MUST NOT be documented, as a way to remove a provider's entry from that list.

#### Scenario: Flag enabled — provider matches, resolves, and searches normally
- **GIVEN** a schema-scoped provider pair for a schema with `smartPickerEnabled = true`
- **WHEN** a caller with read access matches, resolves, or searches through either provider
- **THEN** they behave exactly as described in the preceding requirements

#### Scenario: Flag disabled — entry still visible in the picker list but returns no results or matches
- **GIVEN** a consuming app has registered a schema-scoped reference provider and search provider for a schema, and that schema's `smartPickerEnabled` is subsequently set to `false`
- **WHEN** an admin opens the Smart Picker's "Select provider" list
- **THEN** the schema's entry SHALL still appear in the list, because its class remains registered from app boot
- **BUT WHEN** the admin selects that entry and searches, or pastes a matching object URL
- **THEN** `search()` SHALL return zero results, and `matchReference()`/`resolveReference()` SHALL fail to match or resolve the URL, so the provider is functionally inert while still listed

#### Scenario: Toggling the flag back on restores functionality without any redeployment
- **GIVEN** a schema-scoped provider pair for a schema with `smartPickerEnabled = false`
- **WHEN** an administrator sets `smartPickerEnabled` back to `true` for that schema
- **THEN** the next search or reference resolution through that provider SHALL behave normally again, with no code change or app restart required

### Requirement: Existing generic providers MUST remain unaffected by the introduction of schema-scoped providers

The existing `openregister_objects` search provider and `openregister-ref-objects` reference provider MUST keep their provider ids, registration, and observable behavior unchanged. Any shared formatting logic extracted to support the new abstract base classes MUST be behavior-preserving for the existing generic providers.

#### Scenario: Generic provider ids and behavior are unchanged
- **GIVEN** the refactor that extracts shared icon-resolution, deep-link-resolution, and preview/excerpt-formatting logic into shared internal services
- **WHEN** the generic `ObjectsProvider` (`openregister_objects`) and `ObjectReferenceProvider` (`openregister-ref-objects`) are exercised with the same inputs as before the refactor
- **THEN** they SHALL produce the same provider ids, titles, icons, and result/reference formatting as before

#### Scenario: A schema without a subclassed provider is unaffected
- **GIVEN** a schema that no consuming app has exposed via a schema-scoped provider
- **WHEN** its objects are searched or referenced
- **THEN** they continue to appear only through the generic `openregister_objects` / `openregister-ref-objects` providers, exactly as before this change
