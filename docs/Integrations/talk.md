---
title: Talk
sidebar_position: 23
description: Link NC Talk (spreed) conversations to Open Register objects. Provider stub today.
keywords:
  - Open Register
  - Integrations
  - Talk
  - spreed
  - Chat
---

import {LeafCard, Pair} from '@conduction/docusaurus-preset/components';

# Talk integration

<LeafCard
  id="talk"
  label="Chat"
  icon="ChatOutline"
  group="comms"
  requiredApp="spreed"
  storage="link-table"
  status="stub"
  description="Link NC Talk conversations to Open Register objects. Note: NC Talk's app id is 'spreed', not 'talk'. Provider stub today." />

Tie an NC Talk conversation to an Open Register object so the chat is one click away from the object's sidebar. The Chat tab will surface linked conversations with their last-message preview; clicking opens the conversation in NC Talk. Provider registers today; the wrapping service + link table land in a follow-up.

:::note App id is `spreed`, not `talk`
NC Talk's internal app id is `spreed` (the project name). The integration's `requiredApp: 'spreed'` is what `IAppManager::isInstalled()` checks against.
:::

<Pair
  leftLabel="Open Register"
  leftCaption="object · Chat tab"
  rightLabel="NC Talk (spreed)"
  rightCaption="conversations + messages"
  rightColor="cobalt-700"
  bridgeLabel="link-table (pending)" />

## What it will do

- Lists Talk conversations linked to each Open Register object on the **Chat** sidebar tab.
- Shows the last-message preview, participant count, and unread badge.
- Lets users link an existing conversation or create one inline (with the object's title as the conversation name).
- Resolves a `referenceType: 'talk'` schema property to a single-entity chip with the conversation name + participant count.

## Setup

### 1. Install NC Talk

Install the **Talk** Nextcloud app (its internal id is `spreed`). The Chat tab appears once it's enabled.

### 2. Use it on an object

Open any object whose schema declares `linkedTypes: ['talk']`. The **Chat** tab appears. Today it renders the empty state until the wrapping service lands.

## Configuration

| Field | Open Register side | NC Talk side |
|---|---|---|
| **Storage** | `link-table` (`openregister_talk_links`, pending) | NC Talk's `oc_talk_rooms` |
| **Mapping** | conversation token, cached name + participant count | Talk REST API |
| **Refresh** | Per render (last-message updates live) | — |
| **Permissions** | Inherits from object RBAC | Talk's conversation membership |

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-talk](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-talk).

## Related

- **[Email leaf](./email.md)** — async written communication; pairs well with sync Talk.
- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
