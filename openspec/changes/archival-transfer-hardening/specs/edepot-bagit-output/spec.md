## ADDED Requirements

### Requirement: BagIt 1.0 SIP serialization option
OpenRegister's `SipPackageBuilder` SHALL offer BagIt 1.0 (RFC 8493) serialization as an output
format option alongside the existing plain-zip SIP layout, selected per e-Depot connection in the
e-Depot settings (default remains plain zip — no behaviour change for existing connections). A
BagIt package MUST contain the bag declaration (`bagit.txt` with version and encoding),
`bag-info.txt` (incl. bagging date, payload oxum, source-organization from the register/settings),
a complete `manifest-sha256.txt` covering every payload file, a `tagmanifest-sha256.txt` covering
the tag files, and the SIP content (per-object MDTO XML, metadata JSON, and payload files) under
`data/`. The same transfer content MUST serialize losslessly in either format.

#### Scenario: Connection configured for BagIt produces a valid bag

@e2e openspec/specs/edepot-bagit-output/spec.md#connection-configured-for-bagit-produces-a-valid-bag

- **WHEN** a transfer executes against an e-Depot connection whose output format is `bagit`
- **THEN** the produced package contains `bagit.txt`, `bag-info.txt`, `manifest-sha256.txt`, `tagmanifest-sha256.txt`, and all SIP content under `data/`
- **AND** every payload file's SHA-256 in the manifest matches its content

#### Scenario: Default connections are unaffected

@e2e exclude serializer default — covered by PHPUnit SipPackageBuilderTest (the existing zip-layout entry assertions are unchanged)

- **WHEN** a transfer executes against a connection with no output format configured
- **THEN** the package is the existing plain-zip SIP layout, byte-structure unchanged

#### Scenario: Manifest completeness is enforced

@e2e exclude build-time guard not UI-observable — covered by PHPUnit SipPackageBuilderTest::testBuildBagitFailsOnUnchecksummableFile

- **WHEN** the builder assembles a bag and any payload file cannot be checksummed
- **THEN** the build fails (no bag with an incomplete manifest is ever handed to a transport)

@e2e An archivist configures an e-Depot connection with BagIt output, runs a transfer for an approved list, and downloads/inspects the produced package showing the bag declaration, manifests, and payload under data/ — while a second connection without the option still produces the plain SIP zip.
