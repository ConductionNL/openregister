# data-import-export

## ADDED Requirements

### Requirement: An instance set can be encrypted with a passphrase or an export key

When an administrator serialises the instance, they SHALL be able to encrypt the set with a passphrase of at least 12 characters, stretched with Argon2id, or with an export key held in the credential broker. The set SHALL be written as authenticated chunks of XChaCha20-Poly1305 with a cleartext header that carries only the format, the key derivation parameters or the key fingerprint, and no instance name, count or date. A passphrase SHALL NOT be stored anywhere, including a background job's arguments.

#### Scenario: an administrator exports a register encrypted

- **GIVEN** register `zaken` holding a case titled `Kapvergunning Dorpsstraat 12`
- **WHEN** a functional administrator starts an instance export, chooses "Encrypt with a passphrase", enters and confirms a passphrase, and confirms the lost-key warning
- **THEN** the export job produces a file that starts with `ORSETENC1`
- **AND** the text `Kapvergunning Dorpsstraat 12` does not occur anywhere in the file
- **AND** no job argument, setting, audit row or log line contains the passphrase
- @e2e exclude {specified only; task 5.2 adds tests/e2e/ci/encrypted-instance-export.spec.ts}

### Requirement: A load checks the whole set before writing anything

Loading an encrypted set SHALL ask for the same passphrase or export key, SHALL decrypt and authenticate every chunk up to the final chunk before the load writes a single row, and SHALL refuse a set that was altered, truncated or opened with the wrong key with one message that does not say which of the three it was.

#### Scenario: a wrong passphrase writes nothing

- **GIVEN** an encrypted set and an empty receiving instance
- **WHEN** an administrator loads it with the wrong passphrase
- **THEN** the load is refused with "This file was changed or the key is wrong"
- **AND** the receiving instance holds no register, schema or object from the set
- @e2e exclude {specified only; task 5.2 adds tests/e2e/ci/encrypted-instance-export.spec.ts}

#### Scenario: a truncated file is not read as a smaller set

- **GIVEN** an encrypted set whose last 64 KiB were cut off in transfer
- **WHEN** an administrator loads it with the right passphrase
- **THEN** the load is refused and nothing is written
- @e2e exclude {specified only; task 1.1 covers it in tests/Unit/Service/Exchange/EncryptedSetRoundTripTest.php}

### Requirement: Export keys are kept in the credential broker

An administrator SHALL be able to create, rotate and delete export keys. An export key SHALL be a random 32-byte key stored as an organisation-scoped credential in the credential broker, shown once as a recovery string on creation, and never shown again. Creating, rotating, deleting and using a key SHALL each write an audit row that carries the key's fingerprint and never the key. A key SHALL only be usable by its own organisation.

#### Scenario: an administrator creates an export key

- **GIVEN** a functional administrator in the Open Register admin settings
- **WHEN** they create an export key in the "Export keys" section
- **THEN** the recovery string is shown once with the advice to store it elsewhere
- **AND** after a reload the section lists the key by fingerprint and creation date, without the recovery string
- @e2e exclude {specified only; task 5.2 adds tests/e2e/ci/encrypted-instance-export.spec.ts}

### Requirement: Field-encrypted values leave the instance only inside encryption

In an encrypted set, a property flagged for field-level encryption SHALL be written as its plaintext inside the encrypted stream and SHALL be re-encrypted with the receiving instance's key when loaded. In a plain set, such a property SHALL be omitted, and the set SHALL list each omitted schema and property.

#### Scenario: a protected field moves to a new host

- **GIVEN** schema `persoon` with property `bsn` flagged for field-level encryption, and one person
- **WHEN** an administrator exports encrypted and loads the set into a second instance with the right passphrase
- **THEN** reading the person on the second instance returns the `bsn` value
- **AND** the stored value on the second instance is an envelope of the second instance's key
- @e2e exclude {specified only; task 3.1 covers it in tests/Integration/EncryptedInstanceMoveTest.php}

#### Scenario: a plain set says what it left out

- **GIVEN** the same schema
- **WHEN** an administrator exports without encryption
- **THEN** the set holds no `bsn` value and its manifest lists `persoon.bsn` as omitted because it is encrypted at rest
- @e2e exclude {specified only; task 3.1 covers it in tests/Unit/Service/Exchange/EncryptedSerialisationTest.php}

### Requirement: The copy before a destruction can be required to be encrypted

The archival setting `destructionCopyEncryption` SHALL accept `off`, `when-key` and `required`, with `off` as the default. With `when-key` or `required` and a usable export key, the copy written before a destruction SHALL be encrypted with it, and the destruction record SHALL name the copy and the key's fingerprint. With `required` and no usable export key, the destruction SHALL NOT run and the report SHALL name the missing key.

#### Scenario: no key, no destruction

- **GIVEN** `destructionCopyEncryption` set to `required` and no export key for the organisation
- **WHEN** an approved destruction list is carried out
- **THEN** no record is destroyed and the report says the copy could not be encrypted because no export key exists
- @e2e exclude {specified only; task 3.2 covers it in tests/Unit/Service/Exchange/DestructionCopyEncryptionTest.php}
