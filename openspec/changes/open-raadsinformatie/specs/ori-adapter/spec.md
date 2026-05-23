## ADDED Requirements

### Requirement: The system SHALL provide an OpenRegister adapter for Open Raadsinformatie data sources

OpenRegister SHALL expose an admin interface for registering and managing Open Raadsinformatie data sources. Each registered source SHALL be associated with a dedicated OpenRegister register and schema.

The adapter SHALL:

1. **Authenticate** — Read ORI endpoint credentials from OCP vault (`passwords` table) and authenticate using the configured auth method (token, OAuth2, or basic auth).
2. **Discover entity types** — On source registration, fetch the list of available ORI entity types (documents, decisions, etc.) from the ORI API.
3. **Sync entities incrementally** — Implement scheduled pull-based sync: fetch new or updated ORI entities since the last cursor, translate them to OpenRegister objects, and persist them to the target register. Sync SHALL be read-only; edits in OpenRegister do not push back to ORI.
4. **Map entities to objects** — Translate ORI entity fields to object properties according to the target register's schema. ORI URLs SHALL be stored in the object's `_sourceUrl` metadata field.
5. **Validate before persist** — Before inserting or updating an object, validate it against the target register's schema. On validation failure, record the error in the sync result and skip the object; the sync continues (fail-open semantics).
6. **Report sync status** — Store sync metadata in the register (`_syncSource`, `_syncCursor`, `_syncStatus`, entity counts) so admins can monitor sync health.

#### Scenario: Admin registers an ORI data source
- **GIVEN** the admin navigates to Integrations / External adapters
- **WHEN** the admin clicks "Add ORI Source" and enters endpoint, auth credentials, entity type, and target register
- **THEN** the system SHALL validate the endpoint connectivity and auth
- **AND** the system SHALL fetch available entity types from ORI and display them
- **AND** the source SHALL be created in a paused state (admin must start the first sync manually)

#### Scenario: Sync pulls new entities and validates them
- **GIVEN** an ORI source is registered and active
- **WHEN** the scheduled sync runs at the configured interval
- **THEN** the adapter SHALL fetch entities from ORI using the stored cursor (or from the beginning if no cursor)
- **AND** each entity SHALL be translated to an object array and validated against the schema
- **AND** valid objects SHALL be persisted via `ObjectService.saveObject()`
- **AND** invalid objects SHALL be recorded in the sync result with validation error details
- **AND** the sync result SHALL be stored in the register's metadata (entity counts, cursor, timestamp, error summary)

#### Scenario: Incremental sync resumes from cursor
- **GIVEN** a source has completed one successful sync with cursor `C1`
- **WHEN** the next sync runs
- **THEN** the adapter SHALL request only entities created or modified since `C1`
- **AND** the new cursor `C2` (server-supplied) SHALL be stored for the next run
- **AND** the system SHALL NOT re-sync entities that were already synced in the previous run

#### Scenario: Sync failure is recoverable
- **GIVEN** a sync fails (e.g., ORI endpoint is temporarily down)
- **WHEN** the next scheduled sync runs
- **THEN** the adapter SHALL retry with exponential backoff (1s, 2s, 4s, max 60s)
- **AND** if all retries exhaust, the source SHALL transition to "paused" state
- **AND** the admin SHALL receive a notification that the source requires attention

### Requirement: The system SHALL store ORI source configuration in the admin interface

OpenRegister's admin panel for Integrations / External adapters SHALL include:

1. **Data source list** — table showing all registered ORI sources with columns: name, endpoint, entity type, target register, sync status (active/paused), last sync time, next sync time.
2. **Add source form** — input fields for: ORI endpoint URL, auth method (dropdown: token / OAuth2 / basic), credentials (vault reference or inline), entity type (dropdown populated from ORI discovery), target register (dropdown of existing registers), sync interval (seconds, default 3600).
3. **Source detail view** — shows: configuration, sync history (timestamps, entity counts, errors), next scheduled run, manual sync button, pause/resume controls, delete option.
4. **Sync history table** — per-source, show last 10 sync runs with: timestamp, status (success/failure), entities fetched, objects created, objects updated, validation errors count.

#### Scenario: Admin edits a source configuration
- **GIVEN** an ORI source is registered
- **WHEN** the admin edits the sync interval or auth credentials
- **THEN** the changes SHALL take effect on the next scheduled sync
- **AND** the cursor SHALL be preserved so sync continues from where it left off

#### Scenario: Admin manually triggers a sync
- **GIVEN** an ORI source is active and configured
- **WHEN** the admin clicks the "Sync Now" button on the source detail page
- **THEN** the system SHALL immediately run the sync (outside the normal schedule)
- **AND** the UI SHALL show a progress indicator until sync completes
- **AND** the sync result (entities, counts, errors) SHALL be displayed to the admin

### Requirement: The system SHALL integrate ORI data sources with opencatalogi and softwarecatalog search APIs

ORI-sourced objects in OpenRegister registers SHALL be discoverable by dependent apps:

- **opencatalogi** — can query and search ORI objects via the existing `/api/v1/objects` endpoint (no changes to API surface; ORI objects appear as regular OpenRegister objects)
- **softwarecatalog** — can link to ORI-sourced data when the schema includes integration markers

No changes to existing opencatalogi or softwarecatalog specs are required; ORI data is transparent to them once synced into OpenRegister.

#### Scenario: opencatalogi searches ORI data
- **GIVEN** OpenRegister has synced ORI council decisions into a register named "council-decisions"
- **WHEN** opencatalogi calls `GET /apps/openregister/api/v1/objects?register=council-decisions&search=water`
- **THEN** the API SHALL return council decisions matching "water" in any indexed field
- **AND** each object SHALL include `_sourceUrl` metadata pointing back to the original ORI record

#### Scenario: softwarecatalog links ORI data
- **GIVEN** a software record in softwarecatalog references a related policy document
- **WHEN** that policy is also available in OpenRegister as a synced ORI object
- **THEN** softwarecatalog can construct a link via OpenRegister's object query API
