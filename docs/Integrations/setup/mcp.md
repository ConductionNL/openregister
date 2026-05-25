---
title: AI-agent / MCP setup
sidebar_position: 3
description: How an AI agent reaches every Open Register integration leaf through one MCP tool — openregister.integrations. The Tier-4 capstone that makes MCP-invisible Nextcloud apps addressable for free.
keywords:
  - Open Register
  - Integrations
  - MCP
  - AI agents
  - openregister.integrations
  - Tier 4
---

# AI-agent / MCP setup

This is the headline tier. Open Register exposes the whole integration registry to AI agents through a **single MCP tool**, so an agent can discover, read, link, and create across 24 integrations — including apps that ship **no MCP of their own** (Deck, Forms, Maps, Cospend, Polls, TimeManager, Collectives, Photos, Bookmarks, and more).

The tool is `openregister.integrations`, provided by `IntegrationsToolProvider` ([openregister#1853](https://github.com/ConductionNL/openregister/pull/1853)). It is registered alongside Open Register's other built-in MCP tools, so any MCP client pointed at Open Register's MCP surface gets it automatically.

## Why this matters

Most Nextcloud apps are invisible to an AI agent: they have no MCP server, so the agent has no way to see or touch them. The integration registry routes them all through Open Register. **One tool, one connection, 24 integrations.** Adding a leaf is the cheapest way to make a previously MCP-silent app addressable.

## The tool

`openregister.integrations` takes a single `action` argument that selects the operation:

| Action | Purpose | Required arguments |
|---|---|---|
| `list-integrations` | Enumerate every registered integration | _(none)_ |
| `list` | List linked things for one object | `integrationId`, `register`, `schema`, `objectId` |
| `get` | Fetch one linked thing | `integrationId`, `register`, `schema`, `objectId`, `entityId` |
| `link` | Attach an existing thing | `integrationId`, `register`, `schema`, `objectId`, `payload` |
| `create` | Create + attach a new thing | `integrationId`, `register`, `schema`, `objectId`, `payload` |

Optional arguments: `filters` (`_limit` / `_page` / `_search`) on `list`, and `payload` (new-thing fields) on `link` / `create`.

## 1. Discover what exists

The agent's entry point. `list-integrations` enumerates every registered integration with the same view the OCS capability surface advertises:

```json
{
  "action": "list-integrations"
}
```

Returns:

```json
{
  "registered": ["files", "notes", "tags", "tasks", "audit-trail", "calendar", "contacts", "deck", "..."],
  "integrations": [
    {
      "id": "deck",
      "label": "Cards",
      "group": "workflow",
      "enabled": true,
      "requiredApp": "deck",
      "storageStrategy": "link-table"
    }
  ]
}
```

`enabled: false` means the backing Nextcloud app is not installed — the agent should skip operating on that leaf. `storageStrategy` tells the agent whether writes are possible: `query-time` leaves (audit-trail, activity, shares) are read-only.

## 2. Read linked things on an object

```json
{
  "action": "list",
  "integrationId": "deck",
  "register": "projects",
  "schema": "case",
  "objectId": "25706ca9-c989-4d6b-9f7b-98cf1cc70639",
  "filters": { "_limit": 10, "_search": "review" }
}
```

Returns `{ "items": [ … ] }`. Fetch one by id with `get` + `entityId`.

## 3. Link an existing thing

```json
{
  "action": "link",
  "integrationId": "deck",
  "register": "projects",
  "schema": "case",
  "objectId": "25706ca9-c989-4d6b-9f7b-98cf1cc70639",
  "payload": { "cardId": 4821 }
}
```

## 4. Create a new thing

```json
{
  "action": "create",
  "integrationId": "deck",
  "register": "projects",
  "schema": "case",
  "objectId": "25706ca9-c989-4d6b-9f7b-98cf1cc70639",
  "payload": { "title": "Follow up with applicant", "stackId": 12 }
}
```

The tool delegates straight to the matched provider, so it inherits that provider's storage-strategy semantics. A `link` / `create` against a query-time / list-only leaf throws `NotImplementedException`, which the MCP layer surfaces as a structured **error envelope** — not a fatal. The agent should fall back to `list` / `get` for those leaves.

## Connecting an MCP client

Point your MCP client at Open Register's MCP surface (the same surface that serves Open Register's other built-in tools). On connect, `openregister.integrations` appears in the tool list with the input schema above. A typical agent loop:

1. Call `list-integrations` once to learn what is wired and which leaves are `enabled`.
2. For a given object, call `list` per relevant leaf to gather context.
3. Use `link` / `create` to act, respecting each leaf's `storageStrategy`.

## The payoff

Because every leaf is registered, this one tool reaches Deck cards, Maps locations, Cospend bills, Forms responses, Calendar events, Contacts, xWiki articles, OpenProject work packages, and 16 more — **none of which ship their own MCP server**. That is the Tier-4 value the whole [tier model](../tiers.md) builds toward: MCP-invisible apps made MCP-addressable for free.

## Related

- [Maturity tiers](../tiers.md) — Tier 4 is the MCP-enablement capstone.
- [Leaf status](../status.md) — which leaves are MCP-readable vs. MCP-writable.
- [Pluggable integration registry (ADR-019)](../pluggable-integration-registry.md) — the registry the tool delegates to.
- [Verification report](../verification-report.md) — live 24/24 provider status.
