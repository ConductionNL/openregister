---
kind: code
depends_on: [object-files-follow-object-access]
---

# Proposal: per-file-read-restriction

## Why

Since object-files-follow-object-access, every file in an object's folder follows the object's read rule: whoever may
read the object reads all of its files. An app sometimes needs one file in that folder to be seen by fewer people
than the object. dossiq is the first: a case's documents live in the case's folder, and a document can carry an
access exception ("only Pieter Jansen and the Woo team", reason: commercially sensitive prices). Everyone who may
read the case could still list and download that file through OpenRegister's own file endpoints, because nothing in
OpenRegister knows about the exception.

An object-level rule cannot express it: the restriction is on one file, and the object (the case) stays readable to
its whole team.

## What changes

- **A file can carry a read restriction**: a list of users and a list of groups, a reason, who set it and when.
  Stored in OpenRegister, keyed by the Nextcloud file id, so it moves with the file and survives a rename.
- **A restricted file is readable only by** a listed user, a member of a listed group, an administrator, or a caller
  an app admits through `FileRestrictionAdmitEvent` (dossiq admits the case's handler there). The object's read rule
  still applies first; a restriction only ever narrows.
- **Every OpenRegister path that lists or returns an object's file honours it**, with one verdict
  (`FileRestrictionGuard`):
  - the object folder listing (`GET /api/objects/{r}/{s}/{id}/folder`) leaves the file out, and a rename or delete of
    it by node id answers 404;
  - the files list (`GET /api/objects/{r}/{s}/{id}/files`) leaves it out;
  - every per-file endpoint (show, update, delete, rename, copy, move, versions, restore, lock, unlock, preview,
    metadata, labels, Office) answers 404, as for a file that is not there;
  - download by file id (`GET /api/files/{fileId}/download`) answers 404;
  - the object's ZIP download leaves it out;
  - file search hits (`FileReadScope`) leave it out;
  - the integration registry's files leaf leaves it out.
- **Apps set and lift a restriction through `FileRestrictionService`** (PHP). There is no HTTP endpoint in this
  change: who may restrict a file is the app's rule (dossiq: whoever may change the case), so the app decides and
  then calls the service. A restriction needs at least one user or group and a reason.
- **A failed lookup refuses.** If the restriction cannot be read, the file is treated as restricted and refused.

## What does not change

- A file without a restriction is decided exactly as today.
- The object's own read rule, and every object-level path (find, list, relations), are untouched.
- Anonymous access stays "published files only"; a restricted file is also refused anonymously.

## Impact

- New table `openregister_file_restrictions`.
- dossiq's document access exception (dossiq `document-access-exception`) calls the service and listens to the event.
