---
title: Bookmarks
sidebar_position: 40
description: Link NC Bookmarks to Open Register objects with URL preview cards and tag chips. Provider stub today.
keywords:
  - Open Register
  - Integrations
  - Bookmarks
  - URLs
---

import {LeafCard, Pair} from '@conduction/docusaurus-preset/components';

# Bookmarks integration

<LeafCard
  id="bookmarks"
  label="Bookmarks"
  icon="Bookmark"
  group="docs"
  requiredApp="bookmarks"
  storage="link-table"
  status="stub"
  description="Link NC Bookmarks to Open Register objects. URL preview cards, tag chips from Bookmarks' own tag system, favicon-and-title compact rendering. Provider stub today." />

Pin reference URLs to an Open Register object via NC Bookmarks. The Bookmarks tab will show preview cards with favicon, title, and Bookmarks' own tags; the widget renders a compact list. The provider is registered today — the wrapped service + link table land in a follow-up.

<Pair
  leftLabel="Open Register"
  leftCaption="object · Bookmarks tab"
  rightLabel="NC Bookmarks"
  rightCaption="bookmark + tag store"
  rightColor="cobalt-700"
  bridgeLabel="link-table (pending)" />

## What it will do

- Lists NC Bookmarks linked to each Open Register object on the **Bookmarks** sidebar tab.
- Shows favicon, title, URL, and Bookmarks' own tags as chips.
- Lets users link an existing bookmark or paste a URL to add one.
- Lets users unlink. The bookmark stays in NC Bookmarks; only the link row is removed.
- Resolves a `referenceType: 'bookmarks'` schema property to a single-entity chip with favicon + title.

## Setup

### 1. Install NC Bookmarks

Install the **bookmarks** Nextcloud app. The Bookmarks tab appears once it's enabled.

### 2. Use it on an object

Open any object whose schema declares `linkedTypes: ['bookmarks']`. The **Bookmarks** tab appears. Today it renders the empty state until the wrapping service lands.

## Configuration

| Field | Open Register side | NC Bookmarks side |
|---|---|---|
| **Storage** | `link-table` (`openregister_bookmark_links`, pending) | NC `oc_bookmarks` |
| **Refresh** | Per render (favicon cached by Bookmarks) | — |
| **Permissions** | Inherits from object RBAC | Bookmarks user ACL |

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-bookmarks](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-bookmarks).

## Related

- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[xWiki leaf](./xwiki.md)** — link external wiki pages too.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
