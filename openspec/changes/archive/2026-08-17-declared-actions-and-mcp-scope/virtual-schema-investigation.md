# Virtual schemas over Nextcloud apps — investigation

Measured live on the dev instance (`:8080`), 2026-08-17.

## Headline: the seam is not a proposal, it already ships

The design asked to "prove ONE surface end to end: `file` as a virtual schema
over Files". That surface exists and serves, and so do five more. The
investigation's real finding is that this work was already done under
`virtual-schema-semantic-providers`, and the design was written as though it
were ahead of us.

| Register | id | Backing app | Schema | Live query |
|---|---|---|---|---|
| `directory` | 2429 | openregister | 4332, 4333 | — |
| `contacts` | 2430 | contacts | `nc-contact` 4335 | 200, 2 results |
| `calendar` | 2431 | calendar | `nc-event` 4336 | 200, 2 results |
| `files` | 2432 | files | `nc-file` 4337 | 200, real files |
| `deck` | 2433 | deck | `nc-card` 4338 | 200, 0 results |
| `talk` | 2434 | spreed | `nc-conversation` 4339 | — |
| `tasks` | 2435 | tasks | `nc-task` 4340 | 200, 2 results |

The `file` proof, in full — `GET /api/objects/2432/4337?_limit=5`:

```json
{"results":[
  {"id":"71","name":"Templates credits.md","path":"/Templates credits.md",
   "mimetype":"text/markdown","size":3168,"mtime":1778647922,
   "@self":{"id":"71","register":"2432","schema":"4337"}},
  {"id":"73","name":"Nextcloud.png","path":"/Nextcloud.png",
   "mimetype":"image/png","size":50598,"mtime":1778647923, ...}
]}
```

Real files, from the Files app, through the ordinary object endpoint, carrying
the ordinary `@self` envelope. Nothing about the call site knows it is not a
stored object.

⚠️ Deck returning **0 results with status 200** is the designed behaviour, not a
failure: the ADR-048 app-enabled gate degrades a projection to an empty list
rather than erroring. Worth stating because "0 results" and "not working" look
identical from the outside, and only the status code separates them.

## RBAC parity with `hermiq.listFiles` / `readFile`

`FilesObjectSourceProvider` resolves the caller through
`IRootFolder::getUserFolder()` and `IUserSession`. `HermiqToolProvider` resolves
it through `IRootFolder::getUserFolder($uid)`. Same API, same authority: the
Files app's own per-user permissions, with a caller seeing exactly their own
home. This is parity by construction rather than by two implementations that
happen to agree — there is no second permission story to keep in step.

One asymmetry worth naming: the provider implements `ObjectSourceProvider`, not
`WritableObjectSourceProvider`. Every NC-app projection is **read-only**. This is
a feature here — it is structurally impossible for an irreversible operation to
arrive disguised as a row insert — but it bounds what can be retired.

## Which tools this could retire, and which it cannot

⚠️ **The design's "53 special tools" is stale.** It recorded 154 tools of which 53
had no RBAC meaning. Live today: **202 tools, of which 15 are `special`**. The
group/right normalisation the design anticipated has largely already happened —
154 read, 19 create, 9 update, 5 delete, 15 special. Any plan sized against 53 is
sized against a number that no longer exists.

### Retirable — a virtual schema already covers, or could cover, the surface

| Tool | Becomes | Status |
|---|---|---|
| `hermiq_listFiles` | `search` on `nc-file` | **available today** (2432/4337) |
| `hermiq_searchContacts` | `search` on `nc-contact` | available today (2430/4335) |
| `hermiq_listCalendarEvents` | `search` on `nc-event` | available today (2431/4336) |
| `hermiq_listDeckBoards` | `search` on `nc-card` | available today (2433/4338) |

Four, not five. `hermiq_readFile` looked retirable and is not — checked rather
than assumed, because the two surfaces have the same shape and different
contents:

- `nc-file` projects `name`, `path`, `mimetype`, `size`, `mtime` — a **metadata**
  row.
- `HermiqToolProvider::readFile()` returns `{path, content, truncated}`, where
  `content` is `$node->getContent()` capped at `MAX_READ_BYTES`.

Swapping them would hand a caller expecting a file's bytes a description of the
file instead — a substitution that returns 200 and looks like data. Retiring
`readFile` needs the projection to serve content, which is a separate decision
about payload size, not a mapping exercise.

### Not retirable — and the reasons differ

| Tool | Why not |
|---|---|
| `hermiq_sendMail` | 🔴 An irreversible external side effect. Mapping it to `create` on a `message` schema would make a send look like an ordinary row insert — exactly the flattening this design exists to avoid. It stays a **declared action**, which the vocabulary gate now makes a legitimate thing to be. |
| `hermiq_readFile` | Returns file **contents**; the projection returns metadata. See above — the substitution would succeed and return the wrong thing. |
| `hermiq_createCalendarEvent` | A write. Every NC-app provider is read-only; retiring it needs a `WritableObjectSourceProvider` that does not exist yet. |
| `hermiq_upsertContact` | Same — a write, and already classified `special`. |
| `hermiq_listMailAccounts`, `hermiq_listMailMessages`, `hermiq_readMailMessage` | **No Mail provider exists at all.** The design listed Mail alongside Files and Contacts as though it were the same distance away; it is not. These three are the only read surfaces here with no seam behind them. |
| The remaining `special` tools — `requestToolAccess`, `rememberMemory`, `recallMemory`, `forgetMemory`, `delegateAgent`, `recommendCourses`, `promoteVersion`, `upsertSchema`, `upsertPage`, `upsertMenuItem`, `generateCorrespondence`, `convertDocumentToPdf`, `pipelineForecast`, `logContactmoment` | Operations and computed answers, not row reads. A forecast is a calculation over leads; `delegateAgent` starts a run; `convertDocumentToPdf` writes a new file. None of them are a table with a WHERE clause behind them, and dressing them as one would cost the honesty the action vocabulary just bought. |

## What this means for the change

Nothing here needs building. The recommendation is a **retirement plan, not a
construction plan**: four tools can be replaced by projections that are already
live. Mail is the only read gap that would need a new provider, and it should be
scoped on its own rather than assumed to arrive alongside Files.

Two numbers in the design should be corrected before anything is planned against
them: the tool count (154 → 202) and the special-tool count (53 → 15).
