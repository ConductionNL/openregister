## ADDED Requirements

### Requirement: importFromApp auto-creates a Register for application-type configurations

The `ImportHandler::importFromApp` flow SHALL, when the imported
configuration root carries `x-openregister.type=application`, create
or reconcile a Register entity using the document's
`x-openregister.app` as `slug`, `info.title` as `title`, and
`info.description` as `description`. Lookup MUST be idempotent per
`(slug, organisationId)` so a re-import on the same slug and
organisation updates the existing register rather than inserting a
duplicate. Every schema created or matched during the same import call
MUST have its numeric ID appended to the resulting Register's
`schemas[]` field if not already present.

For configurations that do NOT declare
`x-openregister.type=application` (including legacy configurations
that pre-date the marker, and `library`-type configurations), the
pre-spec behaviour MUST be preserved: importFromApp creates only the
Configuration + Schemas rows and the caller is responsible for any
Register wiring.

#### Scenario: Application import without an existing register
- **WHEN** `importFromApp` runs against a configuration carrying
  `x-openregister.type=application`, `x-openregister.app=openbuilt`,
  `info.title='OpenBuilt'`, `info.description='Citizen developer surface'`,
  and 3 schemas where no Register row exists for `(openbuilt, currentOrg)`
- **THEN** a new Register row is inserted with slug=`openbuilt`,
  title=`OpenBuilt`, description=`Citizen developer surface`, and
  `schemas` set to the 3 newly-created schema IDs

#### Scenario: Application re-import on the same organisation
- **WHEN** `importFromApp` runs against the same configuration in the
  same organisation a second time
- **THEN** the existing Register row identified by
  `(slug=openbuilt, organisationId=currentOrg)` is loaded, its
  `schemas[]` field is reconciled to include any newly-imported schema
  IDs without duplicating existing entries, and no second Register row
  is inserted

#### Scenario: Library or untyped import
- **WHEN** `importFromApp` runs against a configuration whose root has
  no `x-openregister.type` field, or where the field is set to
  `library`
- **THEN** no Register row is auto-created; the Configuration row and
  Schemas are persisted as before
