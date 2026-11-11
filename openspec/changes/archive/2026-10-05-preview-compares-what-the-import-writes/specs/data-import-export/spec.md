# data-import-export

## MODIFIED Requirements

### Requirement: The configuration preview names what an import would change @e2e exclude backend preview rows read by the preview modal — covered by PHPUnit

`PreviewHandler::previewConfigurationChanges()` MUST return one row per remote
object with `type` `object`, the object's `register`, `schema` and `slug` as
the remote document names them, a `title`, and an `action`. These three slugs
are the `register:schema:slug` key the preview modal posts back as the
selection, so a row without them cannot be selected. The object MUST be looked
up the way the import looks it up: by slug in the local register and schema,
without RBAC or multitenancy. A missing object MUST be `create`; an object whose
remote version is strictly newer MUST be `update`; any other existing object,
and an object whose slug, register or schema is missing or not present locally,
MUST be `skip` with a `reason`. Every `update` row, for registers and schemas as
well as objects, MUST list the fields it changes as `{field, current, proposed}`:
only keys the remote side carries are compared, nested maps by dotted path,
lists whole, and a row's own `id`, `uuid`, `created` and `updated` are ignored.
An object row MUST compare what the import would write: its `@self.version`
(which only gates the update) is not a change, and the seed format's top-level
`uuid` and `slug` are not compared unless the schema declares a property of
that name, because the import strips them from the data.

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
