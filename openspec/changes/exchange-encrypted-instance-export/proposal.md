---
kind: code
depends_on: [import-preview-and-conflict-policy]
---

# Proposal: exchange-encrypted-instance-export

## Summary

A functional administrator who serialises the instance, or who lets a destruction write its restorable copy to outside storage, can have that file encrypted. They choose a passphrase they type for one export, or an export key kept in the credential broker for unattended copies. Someone who finds the file on a share or a backup disk cannot read it without that key, and cannot change it without the load noticing. Loading the file into another instance asks for the same passphrase or key, checks the whole file before it writes anything, and refuses a file that was altered.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | x-backup-encrypt | Encrypt backups so a copy in outside storage cannot be read without a key. | no |

**x-backup-encrypt** (openregister's matrix)

- Demand: feature request, https://github.com/pocketbase/pocketbase/issues/7706 (the row's origin).
- Competitor yes cells:
  - strapi (Strapi), no evidence URL, source path cited: "source read at v5.55.1, not driven: the strapi export CLI encrypts the archive by default with a key given on the prompt or by the key option strapi:packages/core/strapi/src/cli/commands/export/command.ts:25-36, and import decrypts it (packages/core/strapi/src/cli/commands/import/action.ts); note the cipher is aes-128-ecb, a weak mode, and there is no scheduled backup, only this manual export".

## Why

The copies that leave the instance are specified, and none of them is encrypted.

- `import-preview-and-conflict-policy` specifies both copies that land outside Open Register: REQ-IPC-004, "a restorable copy to a configured location outside the application" before a destruction, and REQ-IPC-005, "registers, schemas, objects, files and configuration into a portable set" (`openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md:67` and `:87`). Its design D-5 excludes secrets from the set. Neither says a word about encrypting the set, and its tasks 4.1 to 4.3 and 5.1 to 5.3 are unbuilt (`openspec/changes/import-preview-and-conflict-policy/tasks.md`).
- The row's evidence holds: no backup route in `appinfo/routes.php`, and Nextcloud's server-side encryption covers stored files, not the database tables that hold the records.
- Open Register's own encryption does not travel. Field-level encryption uses Nextcloud's `ICrypto` keyed off the instance secret (`lib/Service/FieldEncryptionHandler.php:32-50`), so a value encrypted on one instance cannot be read on another, and a serialisation that copied the ciphertext would carry values nobody can restore.

## What changes

- An encrypted container for the instance set and for the copy before destruction: libsodium `secretstream` (XChaCha20-Poly1305) in 64 KiB chunks, so every chunk is authenticated and a large set streams.
- Two ways to hold the key. A passphrase typed for one export and never stored, stretched with Argon2id. Or an export key held as an organisation credential in the credential broker, for the unattended copy before destruction.
- The background job never receives a passphrase. The derived key is held in the broker as a one-use credential bound to the job and deleted when the job ends.
- Loading checks every chunk before it writes a single row, and refuses an altered or truncated file.
- Inside an encrypted set, field-encrypted values are carried as plaintext under the set's encryption and re-encrypted with the receiving instance's key on load. In a plain set they are left out and the set records that, so they are never exported readable or unreadable-forever.
- The destruction settings can require an encrypted copy. A destruction whose copy cannot be encrypted then does not run.

## Consumers

- No fleet app calls the serialisation directly. Every leaf app's records and files are in the set because they live in Open Register.
- filinq's archiving process relies on the copy before destruction (`import-preview-and-conflict-policy` task 7.2), and the encrypted copy is what it points at.

## ADRs

- openregister ADR-004 (credential broker custody): the export key and the one-use job key live in the broker, never in a job argument, a setting or a log.
- openregister ADR-003 (immutable audit trail): creating, rotating and using an export key, and every encrypted export and load, are audit rows.
- hydra ADR-005 (security): authenticated encryption only; no unauthenticated mode, no home-made cipher.
- hydra ADR-069 (background jobs): export and load stay the bulk jobs `import-preview-and-conflict-policy` specifies; this change adds a layer to them.
- hydra ADR-090 (dependency integrity): no new library. `ext-sodium` is declared in `composer.json`.

## Impact

- Extends the capability `data-import-export` (where REQ-IPC-004 and REQ-IPC-005 land).
- Affected code: new `lib/Service/Exchange/EncryptedSetWriter.php`, `EncryptedSetReader.php`, `ExportKeyService.php`, a provider entry in `lib/Settings/credential-providers.json`, `composer.json`, and the serialisation, load and destruction-copy code that `import-preview-and-conflict-policy` tasks 4.1 and 5.1 to 5.3 add.
- Backwards compatible. Encryption is opted into per export, or required by a destruction setting that defaults to off.
- Size: M.

## Out of scope

- Scheduled full backups of the instance. Backups of the database and data directory remain the hosting layer's; this change encrypts the two copies Open Register itself writes.
- Key escrow. A lost passphrase means a lost set. The screen says so before the export starts.
- Encrypting what stays inside the instance. That is field-level encryption and Nextcloud's server-side encryption.
