## ADDED Requirements

### Requirement: A configurable recording mode admin setting controls which searches are recorded

The system SHALL expose an admin retention setting `searchTrailRecordingMode` with the three string values `all`, `_search`, and `none`, defaulting to `_search`. The setting SHALL be persisted in the retention settings, returned by the retention read path, accepted by the retention update path, and honored by the recording gate. The system SHALL combine `searchTrailRecordingMode` with the existing `searchTrailsEnabled` master switch into an effective mode: WHEN `searchTrailsEnabled` is false the effective mode SHALL be `none`; otherwise the effective mode SHALL be the configured `searchTrailRecordingMode`.

#### Scenario: Default recording mode is text-only

- **WHEN** no `searchTrailRecordingMode` has been configured and `searchTrailsEnabled` is true
- **THEN** the effective mode is `_search` and only free-text searches are recorded

#### Scenario: Changing the mode in admin changes which searches are recorded

- **WHEN** an administrator sets `searchTrailRecordingMode` to `all` via the retention admin page and saves
- **THEN** the setting persists and every subsequent paginated search is recorded, not only free-text searches

#### Scenario: Master switch overrides the configured mode

- **WHEN** `searchTrailsEnabled` is false while `searchTrailRecordingMode` is `all`
- **THEN** the effective mode is `none` and no search-trail entry is created for any paginated search

### Requirement: Recording behavior follows the effective mode

WHEN a paginated search executes, the system SHALL record search-trail entries according to the effective mode: in `_search` mode it SHALL record only searches with a non-empty `_search` value; in `all` mode it SHALL record every paginated search call; in `none` mode it SHALL record nothing. Each recorded entry SHALL capture the search term, the result count on the returned page, the total number of matching results, the response time, and the execution type.

#### Scenario: Text search in `_search` mode through the database path records a trail entry

- **WHEN** the effective mode is `_search` and a search with a non-empty `_search` term is served by the database backend
- **THEN** the system creates exactly one search-trail entry whose recorded term equals the `_search` value, whose total-results equals the search's total, and whose execution type identifies the database backend

#### Scenario: Text search in `_search` mode through the SOLR/index path records a trail entry

- **WHEN** the effective mode is `_search` and a search with a non-empty `_search` term is served by the SOLR/index backend
- **THEN** the system creates exactly one search-trail entry whose recorded term equals the `_search` value and whose execution type identifies the index backend

#### Scenario: Non-search list and pagination calls record nothing in `_search` mode

- **WHEN** the effective mode is `_search` and a paginated request runs with no `_search` value (or an empty `_search`), including a subsequent page of a non-search listing
- **THEN** no search-trail entry is created for that request

#### Scenario: Every paginated call records a trail entry in `all` mode

- **WHEN** the effective mode is `all` and a paginated search executes, whether or not it carries a `_search` value
- **THEN** the system creates exactly one search-trail entry for that call

#### Scenario: No recording in `none` mode

- **WHEN** the effective mode is `none` and any paginated search (with or without `_search`) executes
- **THEN** no search-trail entry is created

#### Scenario: Recorded entries surface in popular terms and the searches KPI

- **WHEN** one or more searches have been recorded
- **THEN** the "Popular Search Terms" widget lists the recorded terms with their counts and the "Searches" KPI reflects the recorded total

### Requirement: Search-trail recording honors the enabled setting

WHEN search trails are disabled in the retention settings (`searchTrailsEnabled` false), the system SHALL NOT record search-trail entries even for text searches, regardless of `searchTrailRecordingMode`; WHEN the enabled setting cannot be read, the system SHALL default to recording enabled.

#### Scenario: Disabled setting suppresses recording

- **WHEN** the `searchTrailsEnabled` retention setting is false and a text search executes
- **THEN** no search-trail entry is created

#### Scenario: Unreadable setting defaults to enabled

- **WHEN** the retention setting cannot be read and a text search executes
- **THEN** the system records the search-trail entry (fails safe to enabled) under the default `_search` mode

### Requirement: A recording failure never fails the search

WHEN creating a search-trail entry fails for any reason, the system SHALL log a warning and return the search results normally, so that analytics recording never degrades search availability.

#### Scenario: Trail write error does not break the search

- **WHEN** the search-trail write raises an error while serving a text search
- **THEN** the search response is returned unchanged to the caller and the failure is logged as a warning
