## ADDED Requirements

### Requirement: A declared group MUST exist as a Nextcloud group

Every group id named in a register, schema or property `authorization` block, and every group id declared in a configuration's `components.securitySchemes.oauth2.flows.authorizationCode.scopes` map, SHALL be created as a Nextcloud group if it does not already exist.

This closes a silent-denial gap. `PermissionHandler::hasGroupPermission()` resolves access by membership test alone, so a group that was never created and a group nobody belongs to are indistinguishable: both deny every caller, with no error raised or logged. A typo in an `authorization` block is therefore invisible and reads exactly like a working access control.

Group ids are free-form and SHALL NOT be prefixed or namespaced. Two apps declaring the same id converge on one Nextcloud group by design; a declaring app therefore MUST NOT assume it owns a group it declared.

Provisioning SHALL be create-only: it MUST NOT delete a group, and MUST NOT add or remove members. `admin` and `public` SHALL never be provisioned — `admin` is Nextcloud's built-in group and is short-circuited before any group test, and `public` is a pseudo-principal for anonymous access rather than a membership-bearing group.

#### Scenario: A group named only in a property rule is created

- **GIVEN** a schema whose property `bsn` declares `authorization.read: ["privacy-officers"]` and whose schema-level block never mentions that group
- **AND** no Nextcloud group `privacy-officers` exists
- **WHEN** the configuration is imported
- **THEN** the Nextcloud group `privacy-officers` exists
- **AND** it has no members, so the rule still denies every caller until an administrator populates it

#### Scenario: Reserved principals are never created as groups

- **GIVEN** an authorization block granting `read` to `public` and `delete` to `admin`
- **WHEN** the configuration is imported
- **THEN** no Nextcloud group named `public` is created
- **AND** no attempt is made to create `admin`

#### Scenario: Match conditions are not mistaken for principals

- **GIVEN** a rule `{ "group": "behandelaars", "match": { "status": "open" } }`
- **WHEN** declared groups are collected
- **THEN** only `behandelaars` is provisioned
- **AND** no group named `status` or `open` is created

#### Scenario: Role assignments name groups in their values, not their keys

- **GIVEN** an authorization block with `roles: { "behandelaar": "groep-a", "beheerder": ["groep-b", "groep-c"] }`
- **WHEN** declared groups are collected
- **THEN** `groep-a`, `groep-b` and `groep-c` are provisioned
- **AND** no group named `behandelaar` or `beheerder` is created

#### Scenario: Provisioning survives a refusing group backend

- **GIVEN** three declared groups, of which the second is refused by a read-only group backend
- **WHEN** provisioning runs
- **THEN** the first and third groups are created
- **AND** the refusal is logged against the declaring app rather than aborting the import

### Requirement: Declared groups MUST be provisioned before the import skip check

Provisioning SHALL run on every configuration import, including one that the content-hash check would skip.

The skip means "importing would write exactly what is already stored" — a statement about stored data, which says nothing about whether the Nextcloud groups those authorization blocks name still exist. Provisioning after the skip would mean a group deleted by an administrator is never restored, because the very configuration that declares it is the one being skipped.

#### Scenario: An unchanged re-import restores a hand-deleted group

- **GIVEN** a configuration whose stored content hash matches the incoming document, so the import is skipped
- **AND** a group it declares has since been deleted by an administrator
- **WHEN** the configuration is imported again
- **THEN** the declared group exists again
- **AND** no configuration entities are re-written

### Requirement: Provisioning MUST NOT depend on a leaf app's repair-step wiring

Declared groups SHALL be reconciled by an OpenRegister-owned background sweep in addition to the import path, and that sweep SHALL read the live registers and schemas rather than the shipped configuration files.

Import-time provisioning alone inherits each leaf app's `<repair-steps>` declaration, which is frequently wrong. Nextcloud runs `migrateSchemaOnly()` on a first install (`\OC\Installer::installAppLastSteps`): `<pre-migration>` and `<post-migration>` steps are both skipped, and `<install>` is the only unconditional hook. An app that declares its register import only under `<post-migration>` never imports on a fresh instance — precisely the case where no declared group exists yet.

Reading live entities rather than files additionally covers virtual apps that ship no `register.json`, and restores a group an administrator deleted by hand.

#### Scenario: A fresh install with no `<install>` hook still gets its groups

- **GIVEN** an app whose register import is declared only under `<post-migration>`
- **AND** the app is installed for the first time, so that step never runs
- **WHEN** the reconciliation sweep next runs
- **THEN** every group declared by the live registers and schemas exists

#### Scenario: The sweep reads unfiltered rows

- **GIVEN** the sweep runs from cron, with no logged-in user and no active organisation
- **WHEN** it enumerates registers and schemas
- **THEN** it does so with RBAC and multi-tenancy filtering disabled
- **AND** it therefore does not report a clean pass over rows it never read

### Requirement: An exported configuration MUST declare the groups it depends on

`ExportHandler` SHALL emit `components.securitySchemes.oauth2.flows.authorizationCode.scopes` into the exported configuration document, covering every group named by the exported registers and schemas, at parity with the map `OasService` generates for API consumers.

The scope map is derived from the definitions the document already carries, so it does not recover data the importer could not otherwise derive; what it adds is an explicit, self-describing declaration at the OAS-native location.

#### Scenario: The scope map alone carries every group

- **GIVEN** a configuration with a register-level group, a schema-level group and a property-level group
- **WHEN** it is exported
- **THEN** reading the scope map ALONE — without walking any authorization block — yields all three group ids
- **AND** `admin` is present as a scope
- **AND** `public` is absent unless the configuration grants anonymous access

### Requirement: A declared group with no members MUST be visible

The system SHALL expose, per declared group, whether it exists and how many members it has, so that a declared-but-unpopulated group is discoverable rather than silently denying every caller.

Where the group backend cannot report a count, the member count SHALL be reported as UNKNOWN rather than zero. `OCP\IGroup::count()` returns `int|bool` and yields `false` on backends that cannot count; reporting that as `0` would present a fully populated group as empty and raise the exact false alarm this surface exists to prevent.

#### Scenario: An uncountable backend reports unknown, not empty

- **GIVEN** a declared group on a backend whose `count()` returns `false`
- **WHEN** the declared-group inventory is read
- **THEN** the group's member count is reported as unknown
- **AND** it is NOT reported as having zero members
