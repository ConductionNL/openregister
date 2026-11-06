# file-actions (delta)

## RENAMED Requirements

- FROM: `### Requirement: Uploaded files screened for executable content and ownership repaired safely`
- TO: `### Requirement: Uploaded files screened for executable content and access gated on readability`

<!-- The old title names a repair the requirement now forbids; the behaviour change is in the MODIFIED block below. -->

## MODIFIED Requirements

### Requirement: Uploaded files screened for executable content and access gated on readability

`FileValidationHandler` MUST screen uploaded content for executables and MUST
gate file access on readability without forcing content reads.

(Previously: `ownFile(node)` set the OR-side ownership record via
`FileMapper::setFileOwnership()`, a direct write that renamed the file's whole storage;
the requirement allowed it for explicit ownership-management flows, none of which called it.)

- `blockExecutableFile(fileName, fileContent)` MUST reject the file when its
  extension is in the dangerous-extension list (Windows/Unix executables,
  scripts including `php`/`js`/`py`/`sh`, packages, mobile/macOS installers) and,
  when content is non-empty, MUST delegate to `detectExecutableMagicBytes()`.
- `detectExecutableMagicBytes(content, fileName)` MUST reject content beginning
  with known executable signatures (`MZ`, `\x7FELF`, shell/bash/env shebangs,
  `<?php`, Java `\xCA\xFE\xBA\xBE`), MUST scan the first 1 KiB for a
  script shebang (`#!.../sh|bash|...|node`), and MUST reject embedded
  `<?php`/`<?=`/`<script language="php">` markers — throwing a descriptive
  `Exception` on any match.
- `checkOwnership(node)` MUST gate access on `Node::isReadable()` alone (a pure
  permission-bitmask check that does NOT read content and does NOT acquire an NC
  lock, so it is safe in a hot listing loop). Access is **ownership-agnostic**:
  a node the session can read MUST be allowed regardless of its owner — including
  a node owned by another user and reached through a Nextcloud file share, or
  owned by the `openregister` system user. It MUST throw `NotPermittedException`
  only when the node is not readable. It MUST NOT deny on owner mismatch and MUST
  NOT repair ownership.
- The handler MUST NOT expose an ownership "repair" that writes `oc_storages` or
  `oc_mounts` directly: `ownFile()` and `FileMapper::setFileOwnership()` are gone.
  The former rewrote `oc_storages.id` of the file's whole storage to
  `home::<session user>`, which collided with that user's own storage
  (`storages_id_index` unique violation on every attachment a sync touched) or,
  when none existed, re-homed the entire `openregister` storage. Moving a user's
  own upload into the system account is the job of
  `FileOwnershipHandler::transferFileOwnershipIfNeeded()` (storage `chown` plus a
  share back), never of a direct DB write.

#### Scenario: Executable extension is rejected
- **GIVEN** a file named `payload.exe`
- **WHEN** `blockExecutableFile("payload.exe", content)` runs
- **THEN** it MUST throw an `Exception` identifying the executable extension before any node is created

#### Scenario: Renamed executable is caught by magic bytes
- **GIVEN** a file named `notes.txt` whose content begins with `MZ`
- **WHEN** `blockExecutableFile()` runs
- **THEN** `detectExecutableMagicBytes()` MUST throw because the content signature is a Windows executable

#### Scenario: PHP content is rejected regardless of name
- **GIVEN** content beginning with `<?php`
- **WHEN** `detectExecutableMagicBytes()` runs
- **THEN** it MUST throw, blocking the upload

#### Scenario: Unreadable node is refused
- **GIVEN** a node where `isReadable()` returns false
- **WHEN** `checkOwnership()` runs
- **THEN** it MUST throw `NotPermittedException` and MUST NOT attempt ownership repair

#### Scenario: Readable node owned by another user is allowed
- **GIVEN** a readable node whose owner is a different user (e.g. reached via a file share) or `null`
- **WHEN** `checkOwnership()` runs
- **THEN** it MUST return without error and MUST NOT consult or change the owner

#### Scenario: A sync run by a human user leaves the storage table alone
- **GIVEN** attachments owned by the `openregister` system user, reached by `admin` through the share mount
- **WHEN** a synchronization run by `admin` creates, publishes and lists those attachments
- **THEN** no `ownFile` or `checkOwnership` error or warning MUST be logged
- **AND** `oc_storages.id` and `oc_mounts.user_id` MUST be unchanged
