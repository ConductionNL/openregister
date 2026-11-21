## MODIFIED Requirements

### Requirement: The configuration preview names what an import would change @e2e exclude backend preview rows read by the preview modal — covered by PHPUnit

`PreviewHandler::previewConfigurationChanges()` MUST return one row per remote
object with `type` `object`, the object's `register`, `schema` and `slug` as
the remote document names them, a `title`, and an `action`. These three slugs
are the `register:schema:slug` key the preview modal posts back as the
selection, so a row without them cannot be selected. The object MUST be looked
up the way the import looks it up: by slug in the local register and schema,
without RBAC or multitenancy. A missing object MUST be `create`, and so MUST an
object whose register and schema each exist locally or are listed as `create`
in the same preview (the import creates them first). An object whose remote
version is strictly newer MUST be `update` when at least one compared field
differs, and `skip` with a `reason` when none does. An object for which the
local register holds more than one row with its slug MUST be `skip` with a
`reason` naming the slug, as the import skips it, and MUST NOT fail the rest of
the preview. Any other existing object, and an object whose slug is missing or
whose register or schema is neither present locally nor created by the same
preview, MUST be `skip` with a `reason`. Every `update` row, for registers and schemas as
well as objects, MUST list the fields it changes as `{field, current, proposed}`:
only keys the remote side carries are compared, nested maps by dotted path,
lists whole, and a row's own `id`, `uuid`, `created` and `updated` are ignored.
An object row MUST compare what the import would write: its `@self.version`
(which only gates the update) is not a change, and the seed format's top-level
`uuid` and `slug` are not compared unless the schema declares a property of
that name, because the import strips them from the data. Other top-level
properties the target schema does not declare are not compared either, because
the import discards them; the row MUST list them under `discarded`.

#### Scenario: A remote object can be selected from its row
- **GIVEN** a remote object `omgevingsvergunning` in register `zaken` and schema `zaaktype`, both present locally, and no such object locally
- **WHEN** the preview is built
- **THEN** its row MUST be `create` with register `zaken`, schema `zaaktype` and slug `omgevingsvergunning`
- **AND** the key `zaken:zaaktype:omgevingsvergunning` built from that row MUST select exactly that object for import

#### Scenario: A newer object shows its diff
- **GIVEN** a local object `bouw` at version `1.0.0` titled `Bouw` and the remote one at `1.1.0` titled `Bouwen`
- **WHEN** the preview is built
- **THEN** its row MUST be `update` and its changes MUST include `title` from `Bouw` to `Bouwen`

#### Scenario: An object that is not newer is skipped
- **GIVEN** a local object at the same version as the remote one
- **WHEN** the preview is built
- **THEN** its row MUST be `skip` with a reason naming the versions and no changes

#### Scenario: A seeded object's identity and version are no change
- **GIVEN** a local object `livepass-lane11-a1` stored at version `0.0.1` with colour `red`, imported from a seed that carries a top-level `slug` and `uuid`
- **WHEN** the preview is built for the seed at version `1.0.1` with colour `blue`
- **THEN** its row MUST be `update` and its changes MUST be exactly `colour` from `red` to `blue`

#### Scenario: Two live rows share a seed slug
- **GIVEN** the local register holds two `consent` objects with slug `standing-consent-verbal-bakker`
- **WHEN** the preview is built
- **THEN** that row MUST be `skip` with a reason naming the slug, and every other object MUST be previewed as usual

#### Scenario: An undeclared property does not keep the preview busy
- **GIVEN** an imported object whose remote data carries `notInSchema`, which the schema does not declare, and otherwise matches the stored object
- **WHEN** the preview is built again with a newer remote version
- **THEN** its row MUST be `skip` with no changes and MUST list `notInSchema` under `discarded`

#### Scenario: The import creates the register and schema too
- **GIVEN** a configuration whose register and schema do not exist locally yet
- **WHEN** the preview is built for the first time
- **THEN** the register and schema MUST be `create` and so MUST their objects
