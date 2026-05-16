---
title: Polls
sidebar_position: 66
description: Link NC Polls to Open Register objects. Provider stub today.
keywords:
  - Open Register
  - Integrations
  - Polls
  - Decisions
---

import {LeafCard, Pair} from '@conduction/docusaurus-preset/components';

# Polls integration

<LeafCard
  id="polls"
  label="Polls"
  icon="Poll"
  group="workflow"
  requiredApp="polls"
  storage="link-table"
  status="stub"
  description="Link NC Polls to Open Register objects for date-finding and group decision-making. Provider stub today." />

Drive a group decision or schedule a meeting from an Open Register object via NC Polls. The Polls tab will surface linked polls with their current vote tallies. Provider registers today; the wrapping service + link table land in a follow-up.

<Pair
  leftLabel="Open Register"
  leftCaption="object · Polls tab"
  rightLabel="NC Polls"
  rightCaption="polls + votes"
  rightColor="cobalt-700"
  bridgeLabel="link-table (pending)" />

## What it will do

- Lists NC Polls linked to each Open Register object on the **Polls** sidebar tab.
- Shows current vote tally, deadline, and the linked poll's URL.
- Lets users link an existing poll or create one inline (date-finding template).
- Renders a "winning option" chip on the detail-page widget once the poll closes.

## Setup

### 1. Install NC Polls

Install the **polls** Nextcloud app. The Polls tab appears once it's enabled.

### 2. Use it on an object

Open any object whose schema declares `linkedTypes: ['polls']`. The **Polls** tab appears. Today it renders the empty state until the wrapping service lands.

## Configuration

| Field | Open Register side | NC Polls side |
|---|---|---|
| **Storage** | `link-table` (`openregister_poll_links`, pending) | NC Polls' poll + vote stores |
| **Refresh** | Per render | — |
| **Permissions** | Inherits from object RBAC | Polls' own ACL |

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-polls](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-polls).

## Related

- **[Calendar leaf](./calendar.md)** — once a poll closes on a meeting date, materialise the VEVENT.
- **[Forms leaf](./forms.md)** — for free-form survey responses instead of fixed-option polls.
- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
