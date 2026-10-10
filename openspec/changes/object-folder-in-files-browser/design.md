# Design: object-folder-in-files-browser

Written before a decision; see the proposal's "Decision needed". This file holds
the facts each option rests on, checked on openregister `development` at
`7ddac1b08d` and nextcloud-vue `development`.

## Facts

- `FolderManagementHandler::getOpenRegisterUserFolder()` returns the `openregister`
  account's home for every caller (`object-files-follow-object-access` task 2.1).
- `FilesController` checks the object (`read` for reads, `update` for changes)
  before any file work and then works as the `openregister` account.
- `ObjectGrantResolver` resolves object grants from user, group and remote shares
  whose node is the object folder, per request, never cached.
- `ObjectSharingService::withoutReshare()` documents why a grant must not carry the
  re-share bit: a recipient could re-share the folder through core and mint a
  valid object grant. A mirror share for readers has the same shape of problem.
- `resolveObjectFolder()` (nextcloud-vue) reads `@self.folder` from the object and
  runs a DAV `SEARCH` by file id under `/files/<uid>`; null means "fall back to the
  object's files endpoint", which `CnFilesTab` renders as the legacy list.

## Invariant every option keeps

No Nextcloud share is created to mirror a read rule. A share on an object folder
is a grant, and a grant is an access decision a person made, not a cache of one
the rules made.
