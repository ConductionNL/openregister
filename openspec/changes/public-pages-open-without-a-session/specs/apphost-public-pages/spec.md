# apphost-public-pages

## ADDED Requirements

### Requirement: A page opens without a session only when the app declares it public (REQ-PUB-001)

The system SHALL serve a leaf app's SPA shell to a caller with no session
only for a path the app itself declared public in its bundled
`src/manifest.json`, by giving the page `config.mode: "public"` AND a route
under `/public/`. Both conditions SHALL be required. The shell SHALL carry
no record data, and the route SHALL be added only to an app that asks for
it.

#### Scenario: a citizen opens a status page from a link

- **GIVEN** an app declaring a page with `config.mode: "public"` on `/public/status/:token`
- **WHEN** a browser carrying no session opens that path
- **THEN** the app shell is served
- @e2e exclude {needs an adopting leaf app installed beside openregister; the leaf half is task 6.1 and carries this scenario in its own change}

#### Scenario: the flag alone opens nothing

- **GIVEN** a page flagged `config.mode: "public"` whose route is `/cases/:id`
- **WHEN** the declared routes are read
- **THEN** the page is not among them
- @e2e exclude {a manifest reading, asserted in PublicPageResolverTest}

#### Scenario: the prefix alone opens nothing

- **GIVEN** a page on `/public/report` carrying no public mode
- **WHEN** the declared routes are read
- **THEN** the page is not among them
- @e2e exclude {a manifest reading, asserted in PublicPageResolverTest}

#### Scenario: an app that does not ask keeps the old route table

- **GIVEN** `Routes::standard()` called without the public flag
- **WHEN** the route names are read
- **THEN** no public page route is present
- @e2e exclude {route table assertion, covered by the unit test}

### Requirement: An undeclared path keeps the login (REQ-PUB-002)

The system SHALL refuse an undeclared path to a caller with no session by
redirecting to the login and carrying the requested address. A signed-in
caller SHALL receive the ordinary authenticated shell for such a path. A
manifest that is missing, unreadable or invalid JSON SHALL declare nothing.
The SPA catch-all route SHALL remain authenticated.

#### Scenario: an anonymous caller guessing an internal page is sent to the login

- **GIVEN** an app with one declared public page
- **WHEN** a caller with no session opens `/public/cases/1`
- **THEN** the answer is the login page, not the shell
- @e2e exclude {needs an adopting leaf app installed beside openregister; asserted in PublicPageResolverTest until the leaf half lands}

#### Scenario: an unreadable manifest declares nothing

- **GIVEN** an app whose manifest is not valid JSON
- **WHEN** a declared path is asked for
- **THEN** nothing is declared and the caller keeps the login
- @e2e exclude {filesystem fault injection, covered by the unit test}

#### Scenario: the catch-all still asks for an account

- **GIVEN** any app page outside `/public/`
- **WHEN** a caller with no session opens it
- **THEN** the answer is not the shell

### Requirement: An anonymous caller reads no more than the access link reader publishes (REQ-PUB-003)

Every surface that answers an anonymous caller with a record SHALL project
that record through the access link reader rather than serialising it.
Timeline entries SHALL be projected onto an allow-list that excludes the
accounts named in the row. The object share token surface SHALL use the
same projection as the access link.

#### Scenario: a share token no longer publishes the platform's bookkeeping

- **GIVEN** an object shared by token
- **WHEN** a caller with no session reads it through the token
- **THEN** `@self.authorization`, `@self.owner`, `@self.organisation` and `@self.folder` are absent

#### Scenario: a public note publishes its text and not its author

- **GIVEN** a public timeline entry written by an employee
- **WHEN** an anonymous caller reads the record
- **THEN** the message is served and `actorId`, `editedBy`, `editedByDisplayName` and `isCurrentUser` are absent
- @e2e exclude {a public timeline entry needs an access link fixture; asserted in PublicTimelineTest::testANoteLeavesWithoutItsAuthor}
