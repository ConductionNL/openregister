## ADDED Requirements

### Requirement: The search trail picks out the searches that found nothing

The search trail page SHALL mark each recorded search that returned no results, and the trail
statistics SHALL count the searches that found something, so an administrator can see which
searches found nothing.

#### Scenario: An empty search stands out
- **GIVEN** a recorded search for `afvalkalender` with 0 results and one for `paspoort` with 12 results
- **WHEN** an administrator opens the search trail page
- **THEN** the `afvalkalender` row is marked as a search that found nothing
- **AND** the `paspoort` row is not

#### Scenario: The statistics count the searches with results
- **GIVEN** the same two recorded searches
- **WHEN** an administrator reads the search trail statistics
- **THEN** the total is 2 and the number of searches with results is 1
