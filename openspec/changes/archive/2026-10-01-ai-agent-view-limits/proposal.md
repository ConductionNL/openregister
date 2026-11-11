---
kind: code
depends_on: []
---

# Proposal: ai-agent-view-limits

## Summary

An agent's owner limits which views an AI agent may read, and the agent's chat search then only finds objects inside those views. Today an agent stores `views`, but nothing applies them.

## The rows this closes

- openregister `ai-agent-limits` (Limit which tools and which data an AI agent may use): the data half. The tool half was built by change `ai-agent-limits-screen` (archived 2026-09-30).

## Why

`lib/Service/Chat/ContextRetrievalHandler.php` computes the agent's view filters and then skips them ("TODO: Apply view filters here when view filtering is implemented"). An agent with views therefore searches everything its user can read. Directus and Strapi limit the data an agent reaches; the matrix row counted OpenRegister's view limit as enforced, and it is not.

## What is built today

- `Agent.views` is stored and served by the agents API.
- The chat computes the view filter list and logs it.

## What changes

1. The chat's object search applies the agent's views: an object outside every granted view is not returned as context.
2. The agents screen gets a Views select next to Tools.

## Out of scope

- Views over MCP: an MCP session's data scope stays the user's until a view-scoped search tool exists.
