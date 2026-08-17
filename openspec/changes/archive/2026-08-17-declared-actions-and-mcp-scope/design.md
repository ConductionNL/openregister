# Design — declared actions, the `mcp` scope, and virtual app schemas

## Context

Measured on the dev instance, 2026-08-17: **154 distinct MCP tools across 18
clusters**, of which

| id shape | count | RBAC meaning |
|---|---|---|
| `app.subject.verb` (`pipelinq.lead.get`) | 71 | exact CRUD |
| `app.toolName` (`hermiq.sendMail`) | 53 | none |
| `app_verb_subject` (`cms_create_page`) | 30 | exact CRUD |

So 101 of 154 already ARE CRUD; 53 are operations RBAC has no word for.

## Decisions

### D1 — The vocabulary extends, but only through a declaration

`x-openregister-action` on a schema:

```json
"x-openregister-action": {
  "sendMail": { "name": "Send mail", "description": "Send a message as the acting user." }
}
```

An authorization block may name an action only if it is one of the five canonical
ones or appears here. Anything else fails the import.

⚠️ **The gate is the feature, not a safety rail bolted on.** An open vocabulary
would let a typo (`raed`) become a permission that is never granted and never
errors — a rule that silently protects nothing, which is worse than a rule that
rejects the schema. Today `write` already fails the import; this keeps that
behaviour and adds a legitimate way to say what you meant.

Declaring an action does NOT create a right. It creates a NAME that an
authorization block, an event listener and the grantable-rights index can all
refer to.

### D2 — Declared actions raise events

Each action dispatches an event carrying `(register, schema, action, objectId,
actor)`. This is why declaration is worth the ceremony: an app that adds
`sendMail` can then hang notification, audit or lifecycle rules on it through the
same machinery CRUD already uses, rather than inventing per-app hooks.

⚠️ The event fires on the ACTION, not on a successful permission check. A denied
action is still an event worth hearing — refusals are exactly what an audit rule
wants.

### D3 — `mcp` is a fourth special group, and it is descriptive

`public`, `authenticated`, `admin` exist today (verified in `PermissionHandler`).
`mcp` joins them and means: *this action, on this schema, may be offered to an
agent.*

🔴 It does NOT mean an agent has it. RBAC resolves through Nextcloud groups,
which are per USER; two agents owned by one person are indistinguishable to it.
Per-agent rights stay in `Agent.tools` and `ToolGrantResolver`, where the
request-and-approve flow already lives.

The relationship is: **`mcp` bounds the menu, Hermiq picks from it.** A tool
absent from the `mcp` surface should not be offerable at all; a tool present is
merely offerable.

⚠️ Named `mcp` rather than `agents` deliberately: "agents" is a credible domain
group in a commercial deployment (sales agents), and a special token that can
collide with a real group is a trap that surfaces as a privilege bug.

### D4 — The grantable-rights index is cached, and invalidated by schema writes

Answering "what could any agent be granted anywhere?" means walking every schema
in every register. Measured today: 406 registers, ~1,000+ schemas. That is not a
per-request query.

So: an index of `(register, schema, action, source)` built once and cached,
invalidated on schema create/update/delete — the same trigger that already
rebuilds derived tools.

⚠️ Invalidate on the WRITE, never on a timer. A stale permission index is a
permission bug, and the failure is silent: a right that was revoked still appears
grantable. Prefer an empty index (rebuild on next read) to a stale one.

### D5 — Virtual schemas reuse the object-source seam, not a new mechanism

`dbal-virtual-registers` is done and serves objects live via
`x-openregister-object-source`; `openregister:tables:sync` already applies it to
the Nextcloud Tables app. Pointing it at Files, Mail, Contacts and Calendar makes
those surfaces ordinary registers.

What that buys: `hermiq.listFiles` / `readFile` / `searchContacts` /
`listCalendarEvents` / `listMailMessages` / `readMailMessage` stop being
hand-written tools with their own permission stories and become `search`/`get` on
a `file`, `contact`, `event`, `message` — inside RBAC, inside the derived-tool
machinery, inside this action vocabulary.

⚠️ **This is a read story first.** A virtual schema over Mail can serve `search`
and `get` honestly. `sendMail` is an operation with an external side effect and
stays a declared action — mapping it to `create` on a `message` schema would make
an irreversible send look like an ordinary row insert, which is precisely the
kind of flattening this design should avoid.

Investigation, not commitment: the spec below asks for a proof on ONE surface
(files) before the others follow.

## Risks

- **Two systems that both look like permissions.** Mitigated by making `mcp`
  descriptive: it never answers "may this agent". The naming and the docs have to
  carry that, because the shapes are similar enough to be confused.
- **Event volume.** Actions fire per object operation; a bulk import could be
  loud. Events are dispatched, listeners opt in, and no listener is registered by
  default.
- **A declared action nobody enforces.** Declaring `sendMail` on a schema does
  not make anything check it — the app still enforces its own operation. The
  declaration is a vocabulary entry, and the spec must say so or it reads as a
  guarantee.

## Declarative-vs-imperative decision (ADR-031)

- The action vocabulary, the `mcp` scope entries, virtual-schema definitions —
  **declarative**, in the register JSON.
- The gate (import-time validation), the event dispatch, the index build —
  **imperative**; they are validation and runtime plumbing, not derivable data.
