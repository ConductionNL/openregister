# Tasks

## 1. Declare actions, and gate them

- [x] Accept `x-openregister-action` on a schema: a map of action key → `{name, description}`
- [x] Reject an authorization block naming an action that is neither canonical nor declared
- [ ] Document the vocabulary and the gate beside the existing authorization docs

Acceptance criteria:
- The rejection MUST fail the import, matching today's behaviour for `write` — a schema that declares a permission nobody can satisfy is the bug being prevented.
- ⚠️ Assert the failure names the offending action AND lists what is allowed. "Invalid schema" sends the author looking in the wrong place.
- Prove the gate can say NO before trusting it says YES: a typo'd action (`raed`) must fail, not silently become an ungrantable right.

## 2. Raise events on actions

- [ ] Dispatch an event per action carrying register, schema, action, objectId, actor
- [ ] Fire on refusals as well as successes

Acceptance criteria:
- A refusal is what an audit listener most wants to hear; an event that fires only on success cannot answer "who tried".
- No listener ships registered by default. Volume is opt-in.

## 3. The `mcp` special group

- [ ] Recognise `mcp` beside `public`, `authenticated` and `admin`
- [ ] Expose the `mcp` surface as "what MAY be offered", never as a grant
- [ ] Document that it is descriptive, and that per-agent rights stay in Hermiq

Acceptance criteria:
- 🔴 `mcp` MUST NOT make anything callable by any agent. Assert an agent with no grant still cannot call a tool whose schema lists `mcp`.
- ⚠️ Named `mcp`, not `agents`: "agents" is a credible domain group in a commercial deployment, and a special token colliding with a real group surfaces as a privilege bug.
- Two agents under one owner MUST still be able to hold different rights. That is the property this whole design must not break.

## 4. Cache the grantable-rights index

- [ ] Build an index of (register, schema, action, source) across all schemas
- [ ] Invalidate on schema create/update/delete
- [ ] Serve it to Hermiq as the menu of possible rights

Acceptance criteria:
- Measured today: 406 registers and 1,000+ schemas, so this cannot be a per-request walk.
- ⚠️ Invalidate on the WRITE, never on a timer. A stale permission index is a silent permission bug — a revoked right still reads as grantable. Prefer an empty index over a stale one.

## 5. Investigate virtual schemas over Nextcloud apps

- [ ] Prove ONE surface end to end: `file` as a virtual schema over Files, served through `x-openregister-object-source`
- [ ] Compare it against `hermiq.listFiles` / `readFile` for parity and RBAC behaviour
- [ ] Report which of the 53 special tools this could retire, and which it cannot

Acceptance criteria:
- One surface proven before the others follow. The seam is proven for external databases and the Tables app; a Nextcloud app is a new consumer of it, not a new mechanism.
- ⚠️ Read first. `sendMail` MUST NOT become `create` on a message schema — an irreversible send would look like an ordinary row insert, and that flattening is what this design exists to avoid.
- Report the tools it CANNOT retire as plainly as the ones it can; a partial answer presented as complete is how the 53 became invisible in the first place.
