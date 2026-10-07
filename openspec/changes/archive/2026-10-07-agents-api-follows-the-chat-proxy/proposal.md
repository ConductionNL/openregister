---
kind: spec
---

## Why

With hermiq installed and OpenRegister's chat proxy on (`openregister.chat.proxyTo`
unset, or set to `hermiq`), every call the agent limits screen makes to
`/apps/openregister/api/agents` is answered by hermiq, not by OpenRegister. The
screen then lists hermiq's agents and its edits land in hermiq. Ruben ruled
this intended (build-all decision 92, 7 Oct 2026): when hermiq is present, it
owns agents.

REQ-007 in `chat-ai` already forwards the agents API, but only says so in a
note. A reader of the requirement could take the agents limits screen for an
OpenRegister-only surface and "fix" the forward. This change writes the rule
into the requirement itself, with scenarios for both directions.

## What Changes

- REQ-007 (`chat-ai`) is MODIFIED: it states that the agents API, including
  `/api/agents/tools` and `PATCH /api/agents/{id}`, follows the chat proxy,
  and that this is the intended ownership rule, not a side effect.
- Every existing REQ-007 scenario is carried over unchanged.
- Two scenarios are added: hermiq installed and proxy on, the agents API is
  answered by hermiq and the limits screen shows hermiq's agents; proxy `off`,
  OpenRegister answers.

## What does not change

No code. `ChatCompatMiddleware::CHAT_FAMILY_CONTROLLERS` already lists
`AgentsController`, and only `page()` is excluded. This change describes the
behaviour on `development` today, so it is archived in the same PR.

## Impact

- Spec: `openspec/specs/chat-ai/spec.md` (REQ-007).
- Related: `agent-tool-governance` (the limits screen from
  `2026-09-30-ai-agent-limits-screen`), `2026-10-05-or-chat-proxy-deprecation`.
