---
title: Maturity tiers
sidebar_position: 3
description: The five maturity tiers an integration leaf moves through — from a backend provider returning real data (Tier 0) to a documented, MCP-addressable integration (Tier 4). Tier 4 is the capstone where MCP-enablement value is delivered.
keywords:
  - Open Register
  - Integrations
  - Tiers
  - MCP
  - ADR-019
  - ADR-022
---

# Maturity tiers

A leaf is not "done" the moment its provider returns data. It moves through five tiers, each adding a layer the registry depends on. This page documents the tiers explicitly so you can read any leaf's [status](./status.md) and know exactly what it can do.

| Tier | Name | Delivers |
|---|---|---|
| **0** | Backend + surfaces | Provider returns real data; bespoke UI mounts; all four surfaces render |
| **1** | Production-correct | Bug-free; exceptions caught and converted to empty lists |
| **2** | Full feature set | Link table + dedicated service + REST controller + picker UX + inline create |
| **3** | Cross-cutting | Pagination contract, OCS capabilities, MCP discovery, registry-default frontend |
| **4** | Documented + MCP value | This documentation **and** the MCP-enablement value delivered |

## Tier 0 — backend provider + surfaces

The floor. A leaf reaches Tier 0 when:

- Its PHP `IntegrationProvider` returns **real data** from the upstream app (not a stub array).
- A UI tab mounts on the object detail page.
- All **four widget surfaces** render: `detail-page`, `app-dashboard`, `user-dashboard`, `single-entity` (see [the surfaces table](./leaf-system.md#surfaces-ad-19)).

At Tier 0 the leaf is visible and readable, but it may still mount a bespoke component rather than the registry default, and it may not yet write.

## Tier 1 — production-correct

Tier 0 made it render; Tier 1 makes it **never crash**. The provider catches upstream exceptions and converts "nothing here yet" into an empty list rather than a 500. The canonical example: the `tasks` provider threw `NoVtodoCalendarException` when a user had no VTODO-capable calendar — at Tier 1 that becomes an empty list, treating "no calendar yet" as a setup state, not a server error. See the [verification report](./verification-report.md) for the live no-crash proof across all 24 providers.

## Tier 2 — full feature set

A user can do the whole loop from the UI:

- **Link table** — explicit object ↔ upstream bindings persisted in `openregister_{leaf}_links` (or the leaf's storage strategy equivalent).
- **Dedicated service** — the provider delegates to a typed service rather than inlining upstream calls.
- **REST controller** — a sub-resource endpoint under `…/integrations/{providerId}`.
- **Picker UX** — a dialog to choose an existing upstream thing and link it.
- **Inline create** — a dialog to create a new upstream thing and link it in one step.

Query-time / list-only leaves (audit-trail, activity, shares) are Tier 2-complete without picker/create: there is no local store to write to, so `link`/`create` correctly throw `NotImplementedException` (AD-22).

## Tier 3 — cross-cutting registry features

The leaf participates in the registry's shared contracts rather than carrying bespoke plumbing:

- **Pagination contract** — `list()` honours `_limit` / `_page` / `_search` filters and returns the shared list envelope.
- **OCS capabilities** — the leaf appears under `openregister.integrations` in `cloud/capabilities` with `{requiredApp, available, storageStrategy, surfaces}`, so clients discover it without probing routes.
- **MCP discovery** — the leaf is enumerable via the `openregister.integrations` MCP tool's `list-integrations` action.
- **Registry-default frontend** — the leaf mounts the generic `CnIntegrationTab` / `CnIntegrationCard` (a bespoke component is now an explicit override, not a requirement).

## Tier 4 — documented + MCP-enablement value

The capstone, and the deliverable this documentation set represents. Tier 4 means:

- The leaf is **documented** (its own reference page, its tier and capabilities recorded in [status](./status.md)).
- The **MCP-enablement value is delivered**: because the leaf is discoverable through `list-integrations` and operable through `list`/`get`/`link`/`create`, an AI agent can reach it through Open Register's single MCP tool — **even though the backing Nextcloud app ships no MCP of its own**.

This is the point of the whole stack. Tiers 0–3 build the registry; Tier 4 is where a previously MCP-silent app (Deck, Forms, Maps, Cospend, Polls, TimeManager, Collectives, Photos, Bookmarks) becomes addressable by an agent for free. See **[AI-agent / MCP setup](./setup/mcp.md)** for how an agent uses it.

## How tiers map to setup

| You are… | Read |
|---|---|
| An end user linking things in the UI | [Using an integration](./setup/using.md) (Tier 0–2) |
| Connecting xWiki / OpenProject | [External integrations](./setup/external.md) |
| Building an AI agent | [AI-agent / MCP setup](./setup/mcp.md) (Tier 4) |
| An admin | [Admin notes](./setup/admin.md) |

## Related

- [Leaf integration system](./leaf-system.md) — the architecture every tier builds on.
- [Pluggable integration registry (ADR-019)](./pluggable-integration-registry.md) — the full contract.
- [Leaf status](./status.md) — per-leaf tier table.
- [Verification report](./verification-report.md) — live no-crash status for all 24 providers.
