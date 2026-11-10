# saved-search-views Delta: saved-view-delete-owner-only

## ADDED Requirements

### Requirement: Only the owner or an administrator deletes a view

`DELETE /api/views/{id}` SHALL delete the view only when the caller owns it or
administers the instance, and SHALL check this before anything is deleted. Any
other caller, including one who may read the view because it is public or
shared with their group, SHALL get the 404 of a missing view and the view SHALL
remain.

#### Scenario: a reader of a public view cannot delete it

- **GIVEN** a public view owned by A
- **WHEN** user B sends `DELETE /api/views/{id}`
- **THEN** the response is 404 and the view still exists
- @e2e exclude {guard, covered by tests/Unit/Service/ViewServiceDeleteTest.php}

#### Scenario: a write member cannot delete a shared view

- **GIVEN** a view owned by A shared `write` with group `desk`, and B in `desk`
- **WHEN** B sends `DELETE /api/views/{id}`
- **THEN** the response is 404 and the view still exists
- @e2e exclude {guard, covered by tests/Unit/Service/ViewServiceDeleteTest.php}

#### Scenario: the owner deletes

- **GIVEN** a view owned by A
- **WHEN** A sends `DELETE /api/views/{id}`
- **THEN** the response is 204 and the view is gone
- @e2e exclude {covered by tests/Unit/Service/ViewServiceDeleteTest.php}
