# content-versioning

## ADDED Requirements

### Requirement: A draft can be previewed on an outside site through an expiring link

A schema MAY declare `previewUrl`, a URL template that may use `{uuid}`, `{version}`
and `{token}`. A user who may see a draft SHALL be able to create a preview link for
it: a read-only access link scoped to that draft with a required expiry. The link's
public route SHALL return the draft merged onto the published version, and MUST stop
answering after the expiry or a revocation.

#### Scenario: A web editor previews a draft on the municipal website

- **GIVEN** the schema `nieuwsberichten` with `previewUrl` `https://www.voorbeeld.nl/preview/{uuid}?versie={version}&token={token}`
- **AND** a draft `herziening` of a news item
- **WHEN** a web editor on the Drafts tab chooses Preview
- **THEN** a new tab opens the website URL with the draft's uuid, `herziening` and a token
- **AND** the website's call to the access link route with that token returns the draft's title, not the published one
- @e2e exclude {specified only; task 5.2 adds the preview check to tests/e2e/record-drafts.spec.ts}

#### Scenario: An expired preview link shows nothing

- **GIVEN** a preview link created with an expiry of one hour
- **WHEN** the website calls the access link route two hours later
- **THEN** the response is 404
- @e2e exclude {specified only; task 4.2 adds the API test}

### Requirement: Drafts are managed on the object page

The object detail page SHALL offer a Drafts tab that lists the drafts the user may
see with their key, name, creator and changed fields, and SHALL let the user edit,
compare with the published version, preview, promote and discard a draft.

#### Scenario: A caseworker promotes a named draft

- **GIVEN** a published permit with status `nieuw` and a draft `status-update` that sets status `in_behandeling`
- **WHEN** a caseworker with write access opens the permit, goes to the Drafts tab and promotes `status-update`
- **THEN** the permit's published status is `in_behandeling`
- **AND** the draft no longer appears in the tab
- **AND** a colleague who opened the permit before promotion saw status `nieuw`
- @e2e exclude {specified only; task 5.1 adds tests/e2e/record-drafts.spec.ts}

### Requirement: Drafts never appear as objects in lists or search

Drafts SHALL be stored apart from objects, so that no list, count, facet, search or
relation query returns a draft as an object. A caller MAY ask with `_drafts=true` for
the keys of the drafts it may see on each returned object, in `@self.drafts`, without
changing which objects are returned.

#### Scenario: A list is the same with and without drafts

- **GIVEN** a schema with 40 objects, three of which have drafts
- **WHEN** a caseworker lists the schema with and without `_drafts=true`
- **THEN** both lists contain the same 40 objects
- **AND** with `_drafts=true` the three objects carry their draft keys in `@self.drafts`
- @e2e exclude {specified only; task 2.3 adds the API test}
