---
kind: code
---

# Proposal: enforce-organisation-special-rights

## Summary

Decision 239 (Ruben, 14 Oct): OpenRegister's special rights `object_publish`,
`agent_use`, `dashboard_view` and `llm_use` were stored and editable by any
member and checked nowhere. Enforce each where it applies, and let only an
administrator or the organisation's owner change who holds a special right.

`agent_use` and `llm_use` name actions this app performs, and are enforced.
`object_publish` and `dashboard_view` name none (objects have no publish
action; the dashboard API is administrator only). Ruben retired both
(decisions 247 and 248). Editing is restricted for the three special rights
that remain: `agent_use`, `llm_use` and `grant_manage` (#4601).

## What changes

- `OrganisationSpecialRights` (the reader from #4601) gains `mayUse()`, the
  answer for a right a caller uses, and `openToEveryoneInEveryOrganisation()`,
  the upgrade writer. No second reader.
- `agent_use` is checked when a chat message runs an agent
  (`POST /api/chat/send`, `POST /api/chat/stream`) and when an MCP session acts
  as an agent (`initialize`, `tools/list`, `tools/call`). It is read from the
  agent's organisation.
- `llm_use` is checked on every chat message, before the message is stored or
  a model is called. It is read from the conversation's organisation.
- `*` under `agent_use` or `llm_use` means every signed-in user. It is not
  accepted under `grant_manage`.
- A new organisation starts with `["*"]` under `agent_use` and `llm_use`.
- `object_publish` and `dashboard_view` are retired: removed from the
  organisation's defaults, the editor's Special Rights tab, the repair step and
  the docs. An organisation that still stores them loads as before; nothing
  reads them and nothing writes them.
- Semantic and hybrid search, which embed the query with an embedding model,
  do not need `llm_use` (decision 249).
- On upgrade, the repair step `OpenSpecialRightsToEveryone` adds `*` to
  `agent_use` and `llm_use` in every organisation, once.
- `PUT`/`PATCH /api/organisations/{uuid}` refuses a change to any special right
  from anybody but an administrator or the organisation's owner (403, naming
  the rights). The editor's Special Rights tab is read-only for them, and
  offers "Everyone" as a choice.
- `docs/Features/organisations.md` no longer shows the `hasSpecialRight()`
  call that does not exist.

## Upgrade notes: what becomes refused for whom

Nothing on the day of the upgrade. Before this change every signed-in user
could chat with the model and run any agent they could see, whatever the lists
said. The stored lists said otherwise (`[]` on most new organisations,
`["admin"]` after the admin-group backfill), so enforcing them as stored would
have refused nearly everybody. The repair step writes `*` beside the stored
groups in every organisation, which keeps that behaviour.

After the upgrade, a right is narrowed by taking `*` out of its list in the
Special Rights tab (administrator or owner). From then on:

- a user in none of the listed groups gets 403 from `POST /api/chat/send`, a
  `forbidden` frame from `POST /api/chat/stream`, and a JSON-RPC error from an
  MCP `initialize` (or a later call) that names the agent;
- an administrator is never refused;
- a later upgrade does not reopen a narrowed right (the step runs once, guarded
  by app config key `special_rights_opened_to_everyone`).

Members who are not the owner can no longer change `agent_use`, `llm_use` or
`grant_manage`.

`object_publish` and `dashboard_view` were never enforced, so retiring them
refuses nobody and grants nobody anything. They disappear from the editor;
values already stored stay in the organisation's JSON and are ignored.

## What does not change

- Semantic search: it embeds the query and does not need `llm_use`.
- `grant_manage` keeps the rules from #4601: no wildcard, nobody by default.
- The create, read, update and delete lists in the organisation's block, and
  who may edit them.
- An MCP session that declares no agent keeps the signed-in user's rights.

## Not verified

No live instance was used. The 403 is asserted from the caller's side in unit
tests that drive the real controller and service over the real reader; both
controllers are plain `Controller`s, so OCSMiddleware does not rewrite their
status.
