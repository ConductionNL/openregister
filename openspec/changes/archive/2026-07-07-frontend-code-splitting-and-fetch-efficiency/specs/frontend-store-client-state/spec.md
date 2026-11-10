## ADDED Requirements

### Requirement: Views are loaded via route-level code splitting

Application views SHALL be registered as async components so that a page load
only downloads and evaluates the code for the routes it uses. The initial bundle
SHALL NOT eagerly include every view and its heavy dependencies.

#### Scenario: Heavy view code loads on demand

- **WHEN** a user opens the default landing page
- **THEN** the code for unrelated heavy views (charts, editors, chat) is not part
  of the initial chunk
- **AND** it is fetched only when its route is visited

### Requirement: List and detail views avoid N+1 fetches

Views SHALL resolve collections of related resources via bulk endpoints or the
store cache, not one request per item in a loop.

#### Scenario: Opening a detail view issues bounded requests

- **WHEN** a user opens a view that needs many related resources (users, schemas,
  webhook stats)
- **THEN** the resources are fetched in a bounded number of requests, not one per
  item

### Requirement: Lists are server-paginated and mutations patch local state

List views SHALL request a bounded page from the server rather than fetching the
whole collection and slicing client-side. A single-row create/update/delete SHALL
patch local store state rather than refetching the entire list.

#### Scenario: Source list pages from the server

- **WHEN** the Sources list is opened
- **THEN** it requests a bounded page via `_limit`/`_page`
- **AND** deleting one source updates local state without refetching the whole list
