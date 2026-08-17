---
kind: mixed
---

## Why

Agent tool rights and RBAC are two permission systems over the same data, and
they disagree about what a permission *is*.

RBAC's action vocabulary is closed to CRUD. `Schema::$groups` documents its keys
as `create`/`read`/`update`/`delete`; `rbac-scopes` names five canonical actions
(adding `list`); and it is enforced, not merely documented — docudesk's register
history records that declaring the action `write` made OpenRegister **reject the
whole schema on import**, and the fix was to expand it into create/update/delete.

Measured on the dev instance, that vocabulary covers **71 of 154** distinct MCP
tools — the `app.subject.verb` ones, where `search`/`get` → read, `create` →
create, and so on, exactly. The other **53** are `app.toolName` shaped
(`hermiq.sendMail`, `docudesk.convertDocumentToPdf`) and have no CRUD meaning at
all. There is nowhere in RBAC to say "this group may send mail", so those rights
live in a second system — `Agent.tools` — that RBAC cannot see.

Two consequences:

- **Nothing can reason about the whole permission surface.** A schema cannot say
  what an agent could be allowed to do with it; only what a *user* may.
- **Custom actions are invisible to workflow.** An app that adds a meaningful
  operation cannot hang a lifecycle, notification or audit rule on it, because
  the system has no idea the operation exists.

## What Changes

- **A schema MAY declare additional actions** in `x-openregister-action`, each
  with a `name` and a `description`. ⚠️ **Gated**: an authorization block may
  only reference an action that is `create`/`read`/`update`/`delete`/`list` or is
  declared here. An undeclared action is an import error, exactly as `write` is
  today — the vocabulary becomes extensible, not open.
- **Declared actions raise events**, so an app can attach workflow to its own
  operations the same way it does to CRUD.
- **A fourth special group, `mcp`**, beside `public`, `authenticated` and
  `admin`. It names *what an agent may be offered*, not what any particular agent
  holds.
- **A cached, instance-wide index of grantable rights**, built from every
  schema's CRUD plus declared actions, invalidated on schema change.
- **Virtual schemas over Nextcloud apps** (files, mail, contacts, calendar),
  through the existing `x-openregister-object-source` seam, so those surfaces
  become ordinary registers with ordinary CRUD instead of bespoke tools.

## What does NOT change

🔴 **Enforcement stays in Hermiq, per agent.** RBAC resolves through Nextcloud
groups, which are per USER — an `mcp` group cannot distinguish two agents owned
by the same person, and per-agent separation is the whole point of the grant list
and the request-and-approve flow.

So `mcp` answers *"what could this schema offer an agent?"*. `ToolGrantResolver`
keeps answering *"what does THIS agent hold?"*. This change describes the
possible rights; it does not decide them.

Naming: **`mcp`, not `agents`** — "agents" is a plausible domain group in a
commercial application (sales agents, field agents), and a special token that
collides with real data is a trap.

## Why virtual schemas matter here

`dbal-virtual-registers` (done) already serves objects live from an external
source through `x-openregister-object-source`, and `openregister:tables:sync`
already reconciles a virtual register against the Nextcloud Tables app. The seam
exists and is proven.

Pointed at Nextcloud's own apps it collapses most of the 53 special tools: Hermiq
ships `listFiles`, `readFile`, `searchContacts`, `listCalendarEvents`,
`listMailMessages`, `readMailMessage` and more, each a hand-written tool with its
own permission story. As virtual schemas they are `search`/`get` on a `file`, a
`contact`, a `message` — inside RBAC, inside the derived-tool machinery, inside
this change's action vocabulary, with no bespoke code and no second permission
system.

⚠️ Not all of them: `sendMail` and `convertDocumentToPdf` are operations, not
reads, and stay declared actions. That is the point of having both halves.

## Capabilities

### New Capabilities
- `declared-actions` — extensible, gated action vocabulary; the `mcp` scope; the
  grantable-rights index.

### Modified Capabilities
- `rbac-scopes` — action vocabulary is no longer fixed to five.
