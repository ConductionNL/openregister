# remove-file-ownership-storage-rename

## Why

`FileValidationHandler::ownFile()` delegated to `FileMapper::setFileOwnership()`, which
ran `UPDATE oc_storages SET id = 'home::<session user>' WHERE numeric_id = <storage of the
file>` and the same rewrite on `oc_mounts.user_id`. That renames the file's WHOLE storage,
not the file. Attachments live in the `openregister` system user's storage, so for a sync
run by `admin` every attachment produced a `storages_id_index` unique violation (the
`home::admin` storage already exists), three log pairs per file on every WOO environment.
When the session user had no storage yet the UPDATE succeeded and silently re-homed the
entire `openregister` storage and its mounts.

The access path stopped calling `ownFile()` in `file-permission-share-access`
(`checkOwnership()` gates on `isReadable()` alone), which removed the trigger. The
mechanics stayed behind with no caller, still described by the spec as an "explicit
ownership-management flow". Nothing explicit ever called it; one new call would bring the
storage rename back.

## What changes

- `FileValidationHandler::ownFile()` and its private `getUser()` are removed; the handler
  no longer depends on `FileMapperInterface` or `IUserSession`.
- `FileMapper::setFileOwnership()` and the `FileMapperInterface` signature are removed. No
  code in the app writes `oc_storages.id` or `oc_mounts.user_id` any more.
- The app-user transfer that is still wanted (a user's own upload moving into the
  `openregister` account) stays where it already is: `FileOwnershipHandler::
  transferFileOwnershipIfNeeded()` (`chown` on the storage plus a share back).
- Specs: `file-actions` drops the `ownFile()` bullet and is renamed to "…and access gated
  on readability", since its old title named the repair; `object-interactions` replaces the
  scenario that required the direct DB repair during a listing with one that forbids it.
- A unit test asserts that neither method exists, so the path cannot be revived quietly.

## Impact

No route, schema or migration changes. Behaviour on the access path is unchanged (it was
already readability-only). Installations on the 1.x line keep the old code; this ships
with the 2.x release line. Tracked as WOO-582.
