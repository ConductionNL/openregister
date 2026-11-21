# organisation-special-rights

## ADDED Requirements

### Requirement: Running an agent needs the special right agent_use (REQ-OSR-001)

A caller MUST hold `agent_use` in the agent's organisation to run the agent:
to send a chat message in a conversation that has the agent
(`POST /api/chat/send`, `POST /api/chat/stream`) and to act as the agent in an
MCP session (`initialize`, `tools/list`, `tools/call`). The right MUST be read
on every call, so narrowing it applies to an open MCP session. A refused chat
MUST NOT store the message or call a model.

#### Scenario: A holder runs the agent
- **GIVEN** an agent of organisation `org-agent`, whose `agent_use` lists `ai-users`
- **WHEN** a user in group `ai-users` sends a chat message in a conversation with that agent
- **THEN** the message is answered

#### Scenario: A non-holder is refused with 403
- **GIVEN** the same agent
- **WHEN** a user in group `staff` only sends a chat message through `POST /api/chat/send`
- **THEN** the response status is 403 and names `agent_use`
- **AND** no model is called

#### Scenario: A non-holder cannot open an MCP session as the agent
- **WHEN** that user sends MCP `initialize` with `agent` set to the agent's uuid
- **THEN** the answer is a JSON-RPC error naming `agent_use`, and no session is created

### Requirement: Having a language model called needs the special right llm_use (REQ-OSR-002)

A caller MUST hold `llm_use` in the conversation's organisation to send a chat
message, because every message calls a language model on the caller's behalf
(the answer, and the title and summary calls made with it). A refused message
MUST NOT be stored and no model MUST be called. `POST /api/chat/send` MUST
answer 403. `POST /api/chat/stream`, whose headers are sent first, MUST answer
with an `error` frame of code `forbidden` naming the right.

#### Scenario: A non-holder is refused before the model is called
- **GIVEN** a conversation of organisation `org-chat`, whose `llm_use` lists `ai-users`
- **WHEN** a user in group `staff` only sends a message through `POST /api/chat/send`
- **THEN** the response status is 403 and names `llm_use`
- **AND** the message is not stored and no model is called

### Requirement: How a right a caller uses is read (REQ-OSR-003)

For `agent_use` and `llm_use`:

- A Nextcloud administrator MUST always hold the right.
- The right MUST be read from the organisation the used thing belongs to. When
  it names none, the instance's default organisation MUST be read. When there
  is no default organisation either, the right MUST hold.
- A named organisation that cannot be loaded MUST refuse.
- An organisation that does not carry the key MUST NOT narrow the right.
- `*` MUST admit every signed-in user, including one in no group. A list of
  group ids MUST admit a user in one of them. An empty list MUST admit only
  administrators. A value that is not a list MUST refuse.
- `*` MUST NOT be honoured under `grant_manage`, and `grant_manage` MUST NOT
  be answered by this rule.
- A new organisation MUST start with `["*"]` under `agent_use` and `llm_use`,
  and `[]` under `grant_manage`.

#### Scenario: An administrator is never refused
- **GIVEN** `llm_use` is `[]` in the conversation's organisation
- **WHEN** an administrator sends a chat message
- **THEN** the message is answered

#### Scenario: Everyone means every signed-in user
- **GIVEN** `agent_use` is `["*"]`
- **WHEN** a user in no group runs the agent
- **THEN** the user may

### Requirement: The upgrade that starts enforcing refuses nobody (REQ-OSR-004)

On upgrade, a repair step MUST add `*` to `agent_use` and `llm_use` in every
organisation, keeping the groups already listed, and MUST NOT change
`grant_manage` or a retired right. It MUST run once, so a `*`
an administrator removes afterwards stays removed. A failure MUST NOT abort the
upgrade and MUST leave the step to run again on the next upgrade.

#### Scenario: Every user who could chat before the upgrade still can
- **GIVEN** organisations storing `llm_use` as `[]`, as `["admin"]` and as `["ai-users"]`
- **AND** a user in group `staff` only, who could chat before the upgrade
- **WHEN** the upgrade runs the step registered in `appinfo/info.xml`
- **THEN** that user may chat in each of them
- **AND** `["ai-users"]` became `["ai-users", "*"]`

#### Scenario: A narrowed right is not reopened
- **GIVEN** the step ran, and an administrator then set `llm_use` back to `["ai-users"]`
- **WHEN** a later upgrade runs
- **THEN** `llm_use` stays `["ai-users"]`

### Requirement: object_publish and dashboard_view are retired (REQ-OSR-006)

`object_publish` and `dashboard_view` MUST NOT be special rights. They MUST NOT
be in a new organisation's authorization block, in the organisation editor or
in what the upgrade writes, and no code path MUST read them. An organisation
whose stored block still carries them MUST load and be served unchanged.

#### Scenario: An organisation saved before the retirement still loads
- **GIVEN** an organisation whose stored block holds `object_publish: ["*"]` and `dashboard_view: ["*"]`
- **WHEN** it is loaded, and a member saves it with those keys left out
- **THEN** it loads, the save is not refused, and `llm_use` is answered as before

### Requirement: Search does not need llm_use (REQ-OSR-007)

A semantic or hybrid search, which embeds the caller's query with an embedding
model, MUST NOT require `llm_use` (decision 249). `llm_use` guards calls to a
language model on the caller's behalf, which happen only in chat.

#### Scenario: A user without llm_use searches
- **GIVEN** `llm_use` lists `ai-users` and a user in group `staff` only
- **WHEN** that user runs a semantic search
- **THEN** the search answers as it did before this change

### Requirement: Only an administrator or the owner changes special rights (REQ-OSR-005)

`PUT` and `PATCH /api/organisations/{uuid}` MUST refuse, with status 403 and
the keys it would change, a request that would change who holds
`agent_use`, `llm_use` or `grant_manage`,
unless the caller is an administrator or the organisation's owner. A key left
out of the request MUST count as its default. A member MUST still be able to
edit the rest of the organisation. The organisation editor MUST show the
Special Rights tab read-only to anybody else, saying why.

#### Scenario: A member narrows llm_use
- **WHEN** a member who is not the owner sends `llm_use: ["ai-users"]`
- **THEN** the response status is 403 and names `llm_use`, and nothing is stored

#### Scenario: A member renames the organisation
- **WHEN** the same member sends a new name and the special rights unchanged
- **THEN** the organisation is renamed
