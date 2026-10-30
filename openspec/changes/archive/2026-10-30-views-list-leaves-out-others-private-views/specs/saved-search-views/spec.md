# saved-search-views

## ADDED Requirements

### Requirement: Listing views never includes another user's private view (REQ-VIEW-LIST-01)
Listing views SHALL return only the caller's own views, views shared with the caller or a group they belong to, and public views. This SHALL hold for an administrator too. An administrator's access to another user's view by id is not changed by this requirement.

#### Scenario: an administrator's list
- **GIVEN** user A's view with no shares and `isPublic` false
- **WHEN** user B, who is in the admin group, lists views
- **THEN** A's view is absent from the list
- @e2e exclude {list query, covered by tests/Unit/Db/ViewMapperListsOnlyReachableViewsTest.php}
