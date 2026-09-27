# Design: exchange-encrypted-instance-export

Read at openregister development c53dd0685c.

This change is a layer. The serialisation, the load and the copy before destruction are specified by `import-preview-and-conflict-policy` (REQ-IPC-004 and REQ-IPC-005) and are not built at this sha: that change's tasks 4.1 to 4.3 and 5.1 to 5.3 are open. This design names the classes it adds and the seams it needs from those tasks; it does not guess the routes or files those tasks will create.

## D-1: the container

A new format, written by `lib/Service/Exchange/EncryptedSetWriter.php` and read by `EncryptedSetReader.php`:

```
ORSETENC1\n                       magic and format version
{header JSON}\n                   cleartext, authenticated
[secretstream header, 24 bytes]
[chunk][chunk]...[final chunk]    64 KiB of plaintext each, XChaCha20-Poly1305
```

The header carries only what a reader needs to start: format version, cipher `xchacha20poly1305-secretstream`, chunk size, and either the passphrase parameters (`kdf: argon2id13`, `opslimit`, `memlimit`, a 16-byte `salt`) or the export key's fingerprint (the first 16 hex characters of the key's BLAKE2b hash). It carries no instance name, no counts and no dates, because the header is readable by whoever holds the file. The header's bytes are passed as additional data to the first chunk, so changing a KDF parameter or the fingerprint makes the first chunk fail.

Every chunk is authenticated. The last is tagged `TAG_FINAL`, so a truncated file is detected as truncated and not read as a shorter valid set. This is libsodium's `sodium_crypto_secretstream_xchacha20poly1305_*` family, in PHP's bundled `ext-sodium`, declared in `composer.json`. Nextcloud's `ICrypto` is not used because it is keyed off the instance secret (`lib/Service/FieldEncryptionHandler.php:38-41`) and a set must open on another instance.

## D-2: two kinds of key

- **Passphrase.** Typed by the administrator when they start an export or a load. At least 12 characters. Stretched with `sodium_crypto_pwhash` (Argon2id, `OPSLIMIT_MODERATE`, `MEMLIMIT_MODERATE`) into a 32-byte key. Never stored.
- **Export key.** A random 32-byte key created by `ExportKeyService` and stored as an organisation-scoped, inject-only credential in the credential broker, under a new provider `openregister-export-key` in `lib/Settings/credential-providers.json`. It is read back only through `CredentialBrokerService::resolveInjectable()` (`lib/Service/Credential/CredentialBrokerService.php:353`) with the organisation asserted in-process. On creation the key is shown once as a recovery string (base64url) for the administrator to keep somewhere else, because a set encrypted with it can only be opened where that key is present.

An export key can be rotated: a new key is created, new sets use it, the old one stays for loading old sets until an administrator deletes it. Creating, rotating, deleting and each use are audit rows.

## D-3: a background job never sees a passphrase

The serialisation and the load are background jobs (REQ-IPC-005, and design D-6 of `import-preview-and-conflict-policy`). A job's arguments are stored in Nextcloud's job table, so a passphrase there would be a secret in a database row. Instead, the request that starts an encrypted export or load derives the key from the passphrase at once and places it in the broker as a one-use organisation credential named for the job. The job resolves it through the broker, uses it, and deletes it in a `finally`. A sweep deletes any one-use key older than 24 hours, in case a job died. The passphrase itself goes no further than the request.

## D-4: load verifies before it writes

`EncryptedSetReader::verify()` decrypts every chunk to a null sink and checks the final tag before the load job writes anything. Only then does the load stream the set again and apply it. That doubles the read, and it is the price of the guarantee that an altered, truncated or wrong-key file writes nothing: a failure answers "this file was changed or the key is wrong", naming no chunk offset that would help an attacker.

## D-5: field-encrypted values travel only inside encryption

Properties flagged for field-level encryption are stored as `openregister:enc:v1:` envelopes (`lib/Service/FieldEncryptionHandler.php:66`) that only the source instance can open.

- In an **encrypted** set, the serialisation decrypts them with `FieldEncryptionHandler` and writes the plaintext inside the encrypted stream. On load, the receiving instance's save path re-encrypts them with its own key, because the property is still flagged. They arrive readable where they belong and were never on disk in the clear.
- In a **plain** set, they are left out, and the set's manifest lists the schema and property of each omitted field, the same way D-5 of `import-preview-and-conflict-policy` records excluded secrets. Copying the ciphertext would export values nobody can ever restore; copying the plaintext would put protected data in a file anyone can read.

## D-6: the copy before destruction can require encryption

REQ-IPC-004 writes a restorable copy before a destruction. That copy is written unattended, so it can only use an export key (D-2). A new archival setting, `destructionCopyEncryption`, takes `off` (the default, the copy is written as REQ-IPC-004 specifies), `when-key` (encrypt when an export key exists), or `required`. With `required` and no usable export key, the destruction does not run, and the report names the missing key, following REQ-IPC-004's own rule that no copy means no destruction. The recorded destruction names the copy and the fingerprint of the key it was encrypted with.

## D-7: the seams this needs from the serialisation

From `import-preview-and-conflict-policy` tasks 5.1 and 5.2: the writer and reader must take a PHP stream, not a file path, so `EncryptedSetWriter` can sit between them and the destination. The start requests must accept an `encryption` block: `{"mode": "passphrase", "passphrase": "<PASSPHRASE>"}` or `{"mode": "key", "keyId": "<credential uuid>"}`. From task 4.1: the copy writer must take the same stream. This change's tasks 3.1 and 3.2 wire these in once those tasks land, and tasks 1.x and 2.x are buildable before.

## Declarative-vs-imperative decision

Imperative. Encryption is a layer on how a copy is written, chosen per export or by one archival setting (`destructionCopyEncryption`, D-6). It declares nothing on a schema and changes no lifecycle rule: the destruction's own rule, no copy means no destruction (REQ-IPC-004), stays as it is, and this change only adds "no encryptable copy" as a way for the copy to fail.

## Risks

- **Security.** Authenticated encryption per chunk, a cleartext header that says nothing about the contents, no passphrase in a job argument or a log, the export key only in the broker. A wrong key and an altered file give the same answer.
- **Lost keys.** No escrow by design. The export screen states that a lost passphrase or export key means the set cannot be opened, and asks the administrator to confirm before an encrypted export starts.
- **Performance.** Argon2id at the moderate limits costs about a second and 256 MiB once per export or load. Chunk encryption streams, so memory does not grow with the set. The load reads the file twice (D-4).
- **Multitenancy.** An export key is an organisation credential, so one organisation's key cannot open another organisation's copies, and the broker's access guard applies to every use.
