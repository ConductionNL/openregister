# unified-search-provider

## ADDED Requirements

### Requirement: The provider searches file content beside object data

The unified search provider SHALL return hits from the extracted text of
files attached to register objects beside hits from object data, in one
result list, each hit carrying a `kind` of `object` or `file`, the owning
object's deep link and, for a file hit, the file name and a matching excerpt.
File hits SHALL respect the same RBAC and `searchable` predicate as object
hits.

#### Scenario: a word that occurs only inside an attached file is found

- **GIVEN** an object of a searchable schema with an attached text file containing "waterschapsheffing" and no object field containing that word
- **WHEN** the user searches "waterschapsheffing"
- **THEN** the result list holds one hit of kind `file` naming the file and linking to the object
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/ci/content-search-index.spec.ts when the provider ships}

### Requirement: The provider accepts scopes and advertises them

The unified search provider SHALL accept zero or more scopes of the form
`app:<id>`, `register:<slug>`, `schema:<slug>` or `files`, SHALL narrow the
searched schemas to the scopes before the chunked schema loop, and SHALL
advertise the scopes available to the current user in its OCS capability.

#### Scenario: a schema scope hides other schemas

- **GIVEN** two searchable schemas `case` and `contact` each holding an object titled "Vergunning"
- **WHEN** the user searches "Vergunning" with scope `schema:case`
- **THEN** only the `case` hit is returned
- @e2e exclude {scope narrowing is asserted in unit tests on the provider}

### Requirement: The same contract holds with and without a search backend

The provider SHALL answer the same query shape whether a search backend is
configured or not, using the database full-text path and the file-chunk
table when none is, and SHALL name the backend used in the response.

#### Scenario: an instance without a backend still returns file hits

- **GIVEN** an instance with no search backend configured and an object with an attached file containing "dijkgraaf"
- **WHEN** the user searches "dijkgraaf"
- **THEN** a file hit is returned and the response names the database backend
- @e2e exclude {backend absence is a fixture condition covered by unit tests}
