# Design: example-set-access-links-and-grants

## D-1. Two engine parts

`accessLinks` and `grants` are two new `ExampleSetEngineRows` parts, `ExampleSetAccessLinks`
and `ExampleSetGrants`, registered in `ExampleSetEngineKinds` beside parties, Deck cards and
appointments. Each implements `refusal()`, `write()`, `lost()` and `revert()`, and neither
updates in place (`updatesInPlace()` stays false): a refresh reverts the held row and writes
it again (D-5).

```json
"accessLinks": [
	{
		"key": "woo-fietspad-link-sanne",
		"object": "{{object:woo-fietspad.id}}",
		"capabilities": ["read"],
		"label": "{{cast:sanne.displayName}}",
		"createdBy": "{{cast:pieter.uid}}",
		"expiresAt": "{{datetime:29 23:59}}"
	}
],
"grants": [
	{
		"key": "woo-fietspad-juridische-zaken",
		"object": "{{object:woo-fietspad.id}}",
		"type": "group",
		"shareWith": "{{cast:juridische-zaken.gid}}",
		"permissions": 1,
		"sharedBy": "{{cast:pieter.uid}}",
		"sharedAt": "{{datetime:-11 10:00}}",
		"expiresAt": "{{date:14}}"
	}
]
```

Fields:

| part | required | optional |
|---|---|---|
| accessLinks | `key`, `object`, `createdBy`, `expiresAt` | `capabilities` (default read only), `label` |
| grants | `key`, `object`, `type`, `shareWith`, `sharedBy` | `permissions` (default 1, read), `verbs`, `sharedAt`, `expiresAt` |

A link over a view or a file (`AccessLink::SUBJECT_TYPES`) is out of scope: the boards draw
links over records only. A password is out of scope too: a set never declares a secret.

## D-2. Acting as the declared account

Both services decide who acts: `AccessLinkService::mint()` takes the minting user as an
argument, and `ObjectSharingService::grant()` reads the session through `callerUid()` and
admits the owner, an administrator or a `grant_manage` holder (`requireMayManageGrants()`).
A set runs from `occ` or the daily refresh job, without a session.

- Links: the part calls `mint()` with `createdBy` as the user. No change to the service.
- Grants: the part runs `grant()` inside a scope that sets the session user to `sharedBy`
  for the duration of the call (`IUserSession::setVolatileActiveUser()`, restored in a
  `finally`). `requireMayManageGrants()` then decides for that account as it would for a
  live click, so the set cannot grant what the declared colleague could not. When it
  refuses, the row is reported as not written with the refusal's reason; the install goes
  on.

`createdBy` and `sharedBy` SHALL be accounts the set creates or adopts (REQ-EXS-003). The
check refuses any other value, so a fragment cannot act as an account it does not own.

## D-3. What `grant()` gains

`grant()` writes no expiry and no share time today. It gains two optional arguments,
`?DateTime $expiresAt = null` and `?DateTime $sharedAt = null`, set on the share with
`setExpirationDate()` and `setShareTime()` before `createShare()`. A live caller passes
neither and keeps today's behaviour. The `/shares` POST route may accept `expiresAt` later;
that is not part of this change.

The grants list (`an-object-lists-its-grants-with-their-origin`) reads `sharedBy` from the
share. Its row also needs the share time and the expiry for "op 6 okt · Tot 31 okt"; if the
row does not yet carry them, that change adds `sharedAt` and `expiresAt` to a `shared` row.

## D-4. The object's folder

A grant is a share on the object's folder. A set object written through `ObjectService` may
have no folder yet. The part asks `FolderManagementHandler::getObjectFolder()` (as `resolveFolder()` does), which creates the
folder when it is missing; an object whose folder cannot be resolved is reported as not
written with that reason, never skipped in silence.

## D-5. Record, second install, refresh and remove

The record holds per row: the part, the key, the object uuid and the created id (the link's
id and uuid, or the share's full id `ocinternal:<n>` with its folder id, sharer, principal and share type). It never
holds the anchor. A row under the recorded id is the set's only while those fields match (a link's uuid, a share's
folder, sharer, principal and type); otherwise it counts as gone and is never deleted.

| run | link | grant |
|---|---|---|
| install, not held | mint, record | grant, record |
| install, held, present | write nothing | write nothing |
| install, held, gone or revoked | report missing | report missing |
| `--refresh` | delete the held link, mint again, record the new id | delete the held share, grant again, record the new id |
| remove | delete the held link row | delete the held share |

"Gone" for a link is: no row, or a row with `revokedAt` set. A refresh mints a new anchor,
so a URL copied from an earlier install stops working. That is the point of a refresh: the
expiry moves with the install day, and a demo link revoked during a demonstration is back.

Remove deletes the link row through `AccessLinkMapper` rather than revoking it, because
remove takes away exactly what the install made (REQ-EXS-007); a revoked row would stay
behind. A share is deleted through `IManager::deleteShare()`.

## D-6. The `accessLink` placeholder and the order of writing

`{{accessLink:<key>.<field>}}` names a link the same fragment declares; the fields are `id`,
`uuid` and `url`. The installer writes a fragment's objects, then its access links and
grants, then its changes, as it already does for flows (`{{flow:<key>.uuid}}`). An object
that needs the link (dossiq's `caseShare`) is written first and given the link's fields by
a change:

```json
"changes": [
	{
		"key": "woo-fietspad-link-sanne-names-its-link",
		"object": "{{object:woo-fietspad-link-sanne.id}}",
		"set": {
			"accessLinkId": "{{accessLink:woo-fietspad-link-sanne.id}}",
			"accessLinkUuid": "{{accessLink:woo-fietspad-link-sanne.uuid}}",
			"accessLinkUrl": "{{accessLink:woo-fietspad-link-sanne.url}}"
		}
	}
]
```

The check accepts the placeholder for a declared link before it is minted, and refuses it
when the key names no link of the fragment. On a refresh the change runs after the new mint,
so the object carries the new id.

## D-7. Check

The check refuses, naming the file and the row key:
- any field outside the table in D-1;
- an `object` that is not an object the set writes;
- a capability outside the link vocabulary `AccessLinkService` accepts;
- an `expiresAt` that is not a date or lies before the install day, or a link without one;
- a grant `type` outside `user` and `group` (remote types are not seedable);
- a `shareWith` that is not an account the set creates or adopts, or (type `group`) a group the set created; a
  group that was on the instance before the set is refused, as there is no adopting of groups (D-9);
- a `createdBy` or `sharedBy` that the set does not create or adopt;
- `permissions` outside 1..31.

## D-8. Open questions, answered from the code

**Does `setVolatileActiveUser()` leave anything behind for the daily refresh job?** No session state, and nothing
that can decide for another account.

- Core's `OC\User\Session::setVolatileActiveUser()` only assigns the in-memory `$activeUser`; it writes nothing to
  the session store. `ExampleSetActingAs` reads the previous user first and puts it back in a `finally`, so a throw
  inside `grant()` or `mint()` restores it too. In the CLI and in cron the previous user is null (`CronService`
  swaps in a fresh `Memory` session before running jobs), so after each call `getUser()` answers null again.
- What does stay is keyed by the account it was built for, so it cannot admit anybody else:
  `ObjectGrantResolver`'s per-request memo is keyed by uid (and `grant()` calls `forget()` itself), and
  `OrganisationService` caches the active organisation in the session under a key that ends in the uid. In cron that
  session is the throwaway `Memory` one.
- Test: `ExampleSetAccessLinksAndGrantsTest::testTwoGrantsByTwoAccountsInOneBackgroundRunLeaveNoSession` runs two
  grants by two accounts in one background run and checks each was made as its own account and the session is empty
  afterwards; `testTheActingScopeIsGuardedAndAlwaysRestores` checks the restore after a throw.

**Does core overwrite the share time on create?** Yes. `OC\Share20\DefaultShareProvider::create()` writes `stime`
from `ITimeFactory::now()` and sets that on the share it returns, whatever the caller set; its `update()` never
writes `stime`. So `grant()` does not set the share time before `createShare()`. It writes it after creation through
`ShareTimeWriter`, one `UPDATE share SET stime` on the new share's id and on the per-recipient rows of a group share
(`parent` = that id), the same direct-table pattern as `ObjectFileShareReowner`. Only after the guard admitted the
caller and core created the share; a live caller passes no `sharedAt` and nothing is written. The grants list's
`shared` row now carries `sharedAt` beside `expiresAt` (D-3).

## D-9. Choices made while building

- **Fragment shape.** Rows are `{key, data}` like every other engine part (`tasks`, `emailLinks`), not flat as the
  example in D-1 shows; the fields inside `data` are those of D-1.
- **Where the set may act as an account.** `ExampleSetActingAs` refuses unless the process is the command line
  (`occ`, system cron) or the daily refresh job opened its frame (`ExampleSetActingAs::inBackgroundJob()`, for AJAX
  and web cron), refuses while any user is signed in, and refuses an account that does not exist. The installer is
  also reachable through `ExampleSetInstallerInterface`; a request that calls it gets its links and grants reported
  as not written, never made.
- **Links go through the mint guard too.** `mint()` takes the minting user as an argument and checks nothing about
  the subject; the HTTP controller asks `AccessLinkMintGuard::mayMint()` first. The part does the same, inside the
  acting scope, so a link is minted only by an account that may read the object.
- **The anchor stays out of the record.** A link's record entry is `{id, uuid, object}`. A change that writes
  `{{accessLink:<key>.url}}` would record the address (which carries the anchor) as the value it wrote, so
  `ExampleSetChanges` records such a value as `example-set-secret:sha256:<hash>` and compares by hash in the proof,
  the refresh and remove. The url for the placeholder is read from the live row (`AccessLinkService::linkUrl()`).
- **A group `shareWith`** must be a group the set created: in the record's `groups`, or (in the check, before the
  first install) declared by the set and missing on the instance. A group that existed before the set is refused;
  an opt-in to adopt one (`--adopt-group`) is left out until a set needs it.
- **Where an address may be quoted.** `{{accessLink:<key>.url}}` is accepted only as the text of a change's `set`
  field, where the record keeps the written value as a fingerprint. Anywhere else (a list or map value, a `where`,
  `append`, app config, user preferences, objects or another part) the check refuses it. `id` and `uuid` may be
  quoted anywhere. Set authors must not copy a stored address on through an object field (`{{object:<key>.accessLinkUrl}}`):
  that value is not fingerprinted, and the check cannot tell it from any other field.
- **A grant's identity** (folder, sharer, principal, share type) is taken from the share `grant()` created and
  returns, not read back afterwards. An entry without it is never taken for gone: revert answers `failed`, so the
  share it names is reported.
- **Expiry before the install day** is refused by the check; the link service refuses a past expiry again at mint.
