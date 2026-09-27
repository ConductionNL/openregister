# Tasks: exchange-encrypted-instance-export

## 1. Container

- [ ] 1.1 Add `lib/Service/Exchange/EncryptedSetWriter.php` and `EncryptedSetReader.php` with the format in design D-1, the header as additional data, and `verify()` that reads to the final tag without writing (D-4); declare `ext-sodium` in `composer.json`. Verify: `tests/Unit/Service/Exchange/EncryptedSetRoundTripTest.php` round-trips 10 MiB, and refuses a flipped byte in a chunk, a changed header field, a missing final chunk and a wrong key, each with the same message.

## 2. Keys

- [ ] 2.1 Add `ExportKeyService`: passphrase stretching with Argon2id (minimum 12 characters), and export keys as organisation-scoped inject-only credentials under provider `openregister-export-key`, with create (showing the recovery string once), rotate, delete and audit rows. Verify: `tests/Unit/Service/Exchange/ExportKeyServiceTest.php` asserts the key never appears in a log context or an audit row and that another organisation's key is refused by the broker guard.
- [ ] 2.2 Add the one-use job key (design D-3): derived at request time, stored as a one-use broker credential named for the job, deleted in a `finally`, and swept after 24 hours. Verify: `tests/Unit/Service/Exchange/OneUseJobKeyTest.php` asserts the job argument holds only the credential id and the credential is gone after success and after a thrown job.

## 3. Wiring into the serialisation

- [ ] 3.1 Once `import-preview-and-conflict-policy` tasks 5.1 and 5.2 land: accept the `encryption` block on export and load, wrap the set stream, verify before load, and carry field-encrypted values per design D-5 (plaintext inside encryption, omitted and listed in a plain set). Verify: `tests/Unit/Service/Exchange/EncryptedSerialisationTest.php`; `tests/Integration/EncryptedInstanceMoveTest.php` exports a register with an encrypted field and loads it into a second fixture instance where the field reads back.
- [ ] 3.2 Once task 4.1 of that change lands: the `destructionCopyEncryption` setting (`off`, `when-key`, `required`), refusing a destruction under `required` without a key, and naming the copy's key fingerprint in the destruction record (D-6). Verify: `tests/Unit/Service/Exchange/DestructionCopyEncryptionTest.php`.

## 4. Screens

- [ ] 4.1 Add the encryption choice to the export and load screens that the serialisation adds (passphrase with confirmation, or an export key) with the lost-key warning, and a new `src/views/settings/sections/ExportKeysConfiguration.vue` section in the Open Register admin settings to create, rotate and delete keys. Verify: `src/views/settings/sections/ExportKeysConfiguration.spec.js` shows the recovery string once and never again on reload; the export screen's test asserts the lost-key warning must be confirmed before an encrypted export starts.

## 5. Docs and end-to-end test

- [ ] 5.1 Document the format, the two key kinds, key rotation, the lost-key rule, the field-encryption rule and the destruction setting in `docs/features/data-import-export.md`. Verify: `npm run build` in `docs/` succeeds.
- [ ] 5.2 Add `tests/e2e/ci/encrypted-instance-export.spec.ts`: an administrator exports a register encrypted with a passphrase, sees that the file does not contain a known record title in the clear, loads it with the wrong passphrase and is refused with nothing written, then loads it with the right one. Verify: the spec runs green in the Playwright CI project.

## Acceptance

- No passphrase or key value appears in a job argument, a setting, an audit row or a log line.
- A set that fails verification writes nothing.
