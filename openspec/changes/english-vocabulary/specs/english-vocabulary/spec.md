## ADDED Requirements

### Requirement: Mock registers SHALL be preserved and marked as wire

A register that mirrors a published external standard SHALL keep that standard's field
and object names, and SHALL carry a marker naming the standard it mirrors. Renaming a
mock's fields destroys the only property that makes it useful.

#### Scenario: A mock register keeps the standard's vocabulary

- **WHEN** a register declares itself as mirroring PDOK BAG, Haal Centraal BRP,
  CIM-OW/IMOW, VNG ODS-Open-Raadsinformatie or the official KVK test environment
- **THEN** its schema names and property names SHALL be preserved exactly
- **AND** `nummeraanduiding`, `verblijfsobject`, `pand` and `ingeschreven-persoon` SHALL
  remain unchanged

#### Scenario: The exemption is recorded on the register itself

- **WHEN** a register is classified as wire
- **THEN** it SHALL carry a marker naming the standard
- **AND** the marker SHALL be present so a later vocabulary sweep does not rename it

#### Scenario: A mock's purpose is treated as the reason for the exemption

- **WHEN** the classification is justified
- **THEN** the reason SHALL be that a connector built against the mock must work against
  the real registry
- **AND** the reason SHALL NOT be that the names merely look foreign

### Requirement: The code layer SHALL be renamed to English

openregister's own classes, methods and file names SHALL be English. The measured scope
is 6 files, 6 classes and 22 methods; the schema layer is already clean and is not part
of this requirement.

#### Scenario: A GDPR concept is internationalised without a statute marker

- **WHEN** a class models the GDPR Article 30 record of processing activities
- **THEN** it SHALL be renamed to `ProcessingActivity` and its mapper and controller to match
- **AND** it SHALL NOT carry an NL statute marker, because the GDPR is EU-wide law

#### Scenario: A rename is checked against an existing English sibling

- **WHEN** `Verwerkingsactiviteit` is renamed to `ProcessingActivity`
- **THEN** the change SHALL verify it does not collide with the existing `ProcessingLog`
  family in `lib/Db/` and `lib/Controller/`
- **AND** the two concepts SHALL remain distinct

#### Scenario: An NL-only archival concept is renamed and marked

- **WHEN** a class computes the Archiefwet archival action date
- **THEN** it SHALL be renamed to an English name
- **AND** it SHALL carry a marker recording the Dutch archival statute

#### Scenario: An external registry's proper name is preserved inside an identifier

- **WHEN** a provider integrates with the BRP registry
- **THEN** the `Brp` element of the identifier SHALL be preserved
- **AND** only the Dutch common noun in the identifier SHALL be renamed

### Requirement: Wire strings SHALL survive the renaming of the code that emits them

Renaming a method SHALL NOT change any string it writes to an external format. The
identifier belongs to the fleet; the emitted element name belongs to the standard.

#### Scenario: An MDTO element name is preserved

- **WHEN** a method that emits the MDTO element `naam` is renamed to English
- **THEN** the emitted element SHALL still be `naam`
- **AND** the same SHALL hold for `waardering`, `bewaartermijn` and `bestand`

#### Scenario: The readability cost is accepted and recorded

- **WHEN** a renamed method's English name differs from the wire element it writes
- **THEN** the divergence SHALL be accepted as the cost of the fleet rule
- **AND** it SHALL be documented rather than resolved by keeping the Dutch method name

### Requirement: Renames in the foundation app SHALL be checked against consuming apps

openregister is the fleet's foundation, so a rename here SHALL be verified against every
consuming app before it lands. A rename in this app is never app-local.

#### Scenario: Consumers are checked before a class rename lands

- **WHEN** a public class or service is renamed
- **THEN** every consuming app SHALL be searched for references to the old name
- **AND** the rename SHALL NOT land while an unmigrated reference exists

#### Scenario: A class-resolution failure is understood as fatal to routing

- **WHEN** a rename could leave a consuming app referencing a class that no longer resolves
- **THEN** the change SHALL treat this as breaking every route in that app, not one feature
- **AND** the verification SHALL cover class headers, not only call sites

#### Scenario: A measurement excludes duplicated trees

- **WHEN** openregister's scope is measured
- **THEN** the gitignored `custom_apps/` tree and any `.bak` directory SHALL be excluded
- **AND** counts SHALL NOT include copies of other apps' registers
