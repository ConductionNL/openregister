# saved-search-views

## ADDED Requirements

### Requirement: A saved view can present hierarchical records as a tree

A saved view SHALL accept `presentation.viewType` `tree` only on a schema that declares
`x-openregister-hierarchy`, with a label field and optional extra fields. The save MUST
refuse a tree view on a schema without a hierarchy.

#### Scenario: A functional administrator saves a department tree

- **GIVEN** the schema `afdelingen` with `x-openregister-hierarchy` whose parent is `bovenliggendeAfdeling`
- **WHEN** a functional administrator saves a view with `viewType` `tree` and label field `naam`
- **THEN** the view reads back with that presentation
- @e2e exclude {specified only; task 2.1 adds tests/e2e/tree-view.spec.ts}

#### Scenario: A flat schema cannot be a tree

- **GIVEN** the schema `meldingen` without a hierarchy
- **WHEN** a client saves a tree view on it
- **THEN** the save is refused with a validation error naming the missing hierarchy
- @e2e exclude {specified only; task 1.1 adds the validation test}

### Requirement: The tree loads one level at a time with child counts

A tree view SHALL show the root records with their child counts, and SHALL load a
node's children only when it is opened, paged and filtered like any list. The objects
list SHALL return `@self.childCount` for a schema with a hierarchy when asked with
`_childCount=true`, computed without a query per object.

#### Scenario: A user opens a branch

- **GIVEN** a department tree with 4 directorates and 23 teams under them
- **WHEN** a user opens the tree view and then the directorate `Ruimte`
- **THEN** the first screen shows 4 directorates with their team counts
- **AND** opening `Ruimte` shows its teams, loaded by that one request
- @e2e exclude {specified only; task 2.1 adds tests/e2e/tree-view.spec.ts}

### Requirement: A record under an unreadable parent stays visible

When a readable record's parent is not readable by the viewer, the tree SHALL show the
record at the top level marked as having a hidden parent.

#### Scenario: A team whose directorate is restricted

- **GIVEN** a team the user may read under a directorate the user may not read
- **WHEN** the user opens the tree view
- **THEN** the team appears at the top level with a note that its parent is hidden
- @e2e exclude {specified only; task 1.3 adds the API test}

### Requirement: A user moves through the tree and opens a record from it

A tree view SHALL let a user open and close branches with the mouse and with the keyboard, and choosing a node SHALL open that record's page. Opening a branch SHALL keep the branches already open, and going back from a record SHALL return to the tree as the user left it.

#### Scenario: A user browses to a team and opens it
- **GIVEN** the department tree with the directorate `Ruimte` and its team `Vergunningen`
- **WHEN** a user opens `Ruimte` and chooses `Vergunningen`
- **THEN** the record page of `Vergunningen` opens
- **AND** going back shows the tree with `Ruimte` still open
- @e2e exclude {specified only; task 2.1 adds tests/e2e/tree-view.spec.ts}

#### Scenario: A keyboard user browses the same tree
- **GIVEN** the same tree with focus on `Ruimte`
- **WHEN** the user presses the right arrow, the down arrow and Enter
- **THEN** `Ruimte` opens, focus moves to its first team, and that team's record page opens
- **AND** the left arrow on an open branch closes it
- @e2e exclude {specified only; task 2.2 drives the tree by keyboard in tests/e2e/tree-view.spec.ts}
