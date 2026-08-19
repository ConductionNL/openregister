# Tasks

## 1. Declare actions, and gate them

- [x] Accept `x-openregister-action` on a schema: a map of action key → `{name, description}`
- [x] Reject an authorization block naming an action that is neither canonical nor declared
- [x] Document the vocabulary and the gate beside the existing authorization docs

Acceptance criteria:
- The rejection MUST fail the import, matching today's behaviour for `write` — a schema that declares a permission nobody can satisfy is the bug being prevented.
- ⚠️ Assert the failure names the offending action AND lists what is allowed. "Invalid schema" sends the author looking in the wrong place.
- Prove the gate can say NO before trusting it says YES: a typo'd action (`raed`) must fail, not silently become an ungrantable right.

Verified live: `raed` → 400 naming the action and listing the allowed set;
declared `sendMail` → 201, and on re-read both the declaration and
`authorization.sendMail: ["mcp"]` persisted. Documented in
`docs/Features/access-control.md`.

## 2. Raise events on actions

- [x] Dispatch an event per action carrying register, schema, action, objectId, actor
- [x] Fire on refusals as well as successes

Acceptance criteria:
- A refusal is what an audit listener most wants to hear; an event that fires only on success cannot answer "who tried".
- No listener ships registered by default. Volume is opt-in.

`ActionEvaluatedEvent`, dispatched from `PermissionHandler::hasPermission()`
after the verdict is final. Telemetry only — a throwing listener cannot change
the verdict, pinned by test. Marks a DECISION rather than an attempt (it sits on
the verdict-cache miss path); documented so a listener counting attempts is not
surprised by an under-count. An RBAC bypass is deliberately NOT reported.

## 3. The `mcp` special group

- [x] Recognise `mcp` beside `public`, `authenticated` and `admin`
- [x] Expose the `mcp` surface as "what MAY be offered", never as a grant
- [x] Document that it is descriptive, and that per-agent rights stay in Hermiq

Acceptance criteria:
- 🔴 `mcp` MUST NOT make anything callable by any agent. Assert an agent with no grant still cannot call a tool whose schema lists `mcp`.
- ⚠️ Named `mcp`, not `agents`: "agents" is a credible domain group in a commercial deployment, and a special token colliding with a real group surfaces as a privilege bug.
- Two agents under one owner MUST still be able to hold different rights. That is the property this whole design must not break.

🔴 The collision was REAL, not hypothetical: before the guard, a user in a real
Nextcloud group named `mcp` was admitted by both the bare-string and
`{"group": "mcp"}` match branches. Proven failing first, then fixed.

⚠️ The build surfaced a second hazard the spec had not anticipated, in the
opposite direction. An authorization block is fail-closed once non-empty, so
annotating a schema with `"read": ["mcp"]` would have REVOKED create/update/delete
from every human. The scope is therefore stripped before any verdict is computed,
by both rule interpreters, and an mcp-only block collapses to "no authorization
configured". A descriptive annotation that changes enforcement is not descriptive.

⚠️ The first cut of that strip introduced a fail-open: an already-empty rule list
(`"read": []`) means "grant to nobody" and was being collapsed to "no rule", which
is default-OPEN. Caught by the existing `PermissionHandlerCustomScopeTest`, now
pinned by a test of its own.

## 4. Cache the grantable-rights index

- [x] Build an index of (register, schema, action, source) across all schemas
- [x] Invalidate on schema create/update/delete
- [x] Serve it to Hermiq as the menu of possible rights

Acceptance criteria:
- Measured today: 406 registers and 1,000+ schemas, so this cannot be a per-request walk.
- ⚠️ Invalidate on the WRITE, never on a timer. A stale permission index is a silent permission bug — a revoked right still reads as grantable. Prefer an empty index over a stale one.

`GrantableRightsIndex` + `GrantableRightsInvalidationListener`, served at
`GET /api/mcp/v1/grantable-rights`. Both offer sources are indexed: the `mcp`
scope in an authorization block, and the `x-openregister-mcp` dialect that emits
live tools — an index knowing only one would present itself as complete while
missing half of it.

Invalidation verified LIVE, because it is the half that fails silently:
baseline 241 → POST a schema offering `read` to `mcp` → 242 with the new entry
at `source: authorization` → DELETE it → back to 241. A listener that had not
fired would have read 241 throughout. Anonymous callers get 401.

A failed rebuild serves an EMPTY index and caches nothing: a partial permission
menu is a wrong answer that looks like a right one, and caching it would make one
transient failure permanent.

## 5. Investigate virtual schemas over Nextcloud apps

- [x] Prove ONE surface end to end: `file` as a virtual schema over Files, served through `x-openregister-object-source`
- [x] Compare it against `hermiq.listFiles` / `readFile` for parity and RBAC behaviour
- [x] Report which of the 53 special tools this could retire, and which it cannot

Acceptance criteria:
- One surface proven before the others follow. The seam is proven for external databases and the Tables app; a Nextcloud app is a new consumer of it, not a new mechanism.
- ⚠️ Read first. `sendMail` MUST NOT become `create` on a message schema — an irreversible send would look like an ordinary row insert, and that flattening is what this design exists to avoid.
- Report the tools it CANNOT retire as plainly as the ones it can; a partial answer presented as complete is how the 53 became invisible in the first place.

Full report: [virtual-schema-investigation.md](virtual-schema-investigation.md).

The surface was already built — `nc-file` on the `files` register (2432/4337)
serves live Files data, as do contacts, calendar, deck, talk and tasks. RBAC is
parity by construction: both paths resolve through `IRootFolder::getUserFolder()`.

⚠️ Two of the design's numbers are stale and should be corrected before anything
is planned against them: 154 tools → **202**, and 53 special → **15**.

Four tools are retirable today. `hermiq_readFile` is NOT, though it looks it:
it returns file CONTENTS while the projection returns metadata, so the swap
would succeed and return the wrong thing. Mail has no provider at all — the only
read surface here with no seam behind it. `sendMail` stays a declared action, as
the acceptance criterion requires.
