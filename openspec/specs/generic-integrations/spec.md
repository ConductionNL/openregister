# generic-integrations Specification

## Purpose
TBD - created by archiving change integration-collectives. Update Purpose after archive.
## Requirements
### Requirement: Collectives Provider Registration

`CollectivesProvider` SHALL be registered with id='collectives', group='docs', requiredApp='collectives', storage='link-table'.

#### Scenario: Provider exposes the expected registry metadata

- **GIVEN** the OR container has built and the integration registry has been resolved
- **WHEN** `CollectivesProvider::getId()` / `getGroup()` / `getRequiredApp()` / `getStorageStrategy()` are called
- **THEN** the values MUST be 'collectives' / 'docs' / 'collectives' / 'link-table' respectively

### Requirement: Link-Only (No Create)

The integration SHALL support linking existing pages; page creation lives in Collectives.

#### Scenario: Provider exposes no create() path

- **GIVEN** a caller has resolved the Collectives provider from the registry
- **WHEN** the caller invokes `create()` on the provider
- **THEN** the provider MUST raise `NotImplementedException` (inherited from `AbstractIntegrationProvider`)
- **AND** no upstream Collectives page MUST be created

### Requirement: Markdown Preview in Tab

The Tab SHALL render page content via markdown (safe subset) with collapsible overflow.

#### Scenario: Tab renders a linked page's markdown body

- **GIVEN** an OR object with one linked Collectives page whose body is markdown
- **WHEN** `CnCollectivesTab` renders for the object
- **THEN** the page's body MUST be rendered as sanitised markdown
- **AND** content exceeding the visible viewport MUST collapse behind a "Show more" affordance

### Requirement: Detail-Page Surface Renders Inline Content

Unlike other integrations, `CnCollectivesCard` at `surface='detail-page'` SHALL render the most-recent linked page's content inline.

#### Scenario: One linked page renders in detail-page surface

- **GIVEN** an object with one linked Collectives page
- **WHEN** `CnCollectivesCard` renders with `surface='detail-page'`
- **THEN** the page's markdown content MUST be rendered inline
- **AND** a "Read more" link MUST point to the page in Collectives

#### Scenario: Multiple linked pages show tabs

- **GIVEN** an object with 3 linked pages
- **WHEN** `CnCollectivesCard` renders with `surface='detail-page'`
- **THEN** 3 tabs MUST appear — one per page
- **AND** the most-recently-linked page MUST be selected by default

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'collectives'` SHALL render a page-title chip at single-entity surface.

#### Scenario: A schema property typed referenceType='collectives' renders a chip

- **GIVEN** an object property declared with `referenceType: 'collectives'` and a non-empty page id
- **WHEN** `CnDetailGrid` renders the property at the single-entity surface
- **THEN** the property MUST render as a page-title chip
- **AND** clicking the chip MUST open the page in Collectives

### Requirement: Permission Inheritance

`CollectivesProvider::requiresPermission()` MUST return null; Collectives ACLs apply transitively.

#### Scenario: Permission gate defers to Collectives

- **GIVEN** a user whose access to a collective has been revoked after a page was linked
- **WHEN** the registry resolves the provider for that user
- **THEN** the provider MUST NOT impose its own permission check
- **AND** the underlying Collectives ACL MUST decide whether the page is visible

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When an underlying page in NC Collectives is missing, inaccessible, or the backing service is down, the provider SHALL surface the documented exception types rather than leaking generic errors.

#### Scenario: Linked page's collective access revoked

- **GIVEN** a user whose access to a collective was revoked after a page was linked
- **WHEN** `CnCollectivesTab` renders for that user
- **THEN** the inaccessible page MUST render a "No access to this page" placeholder
- **AND** the link record MUST NOT be removed (another user may still have access)

#### Scenario: NC Collectives app is uninstalled

- **GIVEN** an instance where NC Collectives is not installed
- **WHEN** the registry resolves `CollectivesProvider` and calls `list()`
- **THEN** the provider MUST return an empty array
- **AND** `health()` MUST report `status: unavailable` with a `'NC Collectives app is not installed'` message
- **AND** no exception MUST propagate to the caller

