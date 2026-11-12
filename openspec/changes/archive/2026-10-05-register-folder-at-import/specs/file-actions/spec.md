## ADDED Requirements

### Requirement: An app-imported register has its Files folder when the import returns (REQ-RFAI-001)

When an app configuration import (`ConfigurationService::importFromApp()`) creates, updates or leaves unchanged a register, the system SHALL ensure that register has a Files folder before the import returns, the way a register created through the API gets one at creation. The folder id SHALL be recorded as bookkeeping (REQ-RFFU-002): no register-updated event, no version change, no organisation check. Provisioning SHALL be idempotent, SHALL only touch the registers the import returned, and SHALL NOT fail the import: a folder that cannot be made is logged and left for the first upload.

#### Scenario: A register created by an app import gets its folder

- **GIVEN** an app configuration that ships a register the instance does not have yet
- **WHEN** the app imports it through `importFromApp()`
- **THEN** the register has a recorded folder id when the import returns
- **AND** no register-updated event is dispatched for the folder
- @e2e exclude {backend provisioning during app install with no OpenRegister UI; covered by PHPUnit on ImportHandler::importFromApp and RegisterFolderProvisioner}

#### Scenario: Re-importing leaves an existing folder alone

- **GIVEN** a register whose recorded folder still resolves
- **WHEN** the app import runs again
- **THEN** no new folder is created and the recorded folder id is unchanged
- @e2e exclude {backend idempotency, covered by PHPUnit}

#### Scenario: A folder that cannot be made does not fail the import

- **GIVEN** an app import whose folder provisioning fails for a register
- **WHEN** the import runs
- **THEN** the import still returns its result
- **AND** the failure is logged with the register id
- @e2e exclude {failure injection is a unit concern, covered by PHPUnit}

### Requirement: A repair step provisions folders for registers imported earlier (REQ-RFAI-002)

A post-migration repair step SHALL ensure a Files folder for every register on the instance, across organisations, using the same bookkeeping write. It SHALL report how many folders it provisioned, found present and could not make, and SHALL NOT throw.

#### Scenario: An upgrade provisions a missing folder

- **GIVEN** a register imported before this change, with no folder
- **WHEN** the post-migration repair steps run
- **THEN** the register has a recorded folder id
- **AND** the step reports one provisioned folder
- @e2e exclude {runs from occ upgrade; covered by PHPUnit on CreateMissingRegisterFolders}

#### Scenario: The repair step skips when its services are unavailable

- **GIVEN** a container that cannot build the register mapper or the provisioner
- **WHEN** the repair step runs
- **THEN** it reports that it skipped and does not throw
- @e2e exclude {container failure, covered by PHPUnit}
