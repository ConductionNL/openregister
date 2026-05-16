---
title: Cospend
sidebar_position: 62
description: Link NC Cospend projects and bills to Open Register objects. Provider stub today.
keywords:
  - Open Register
  - Integrations
  - Cospend
  - Cost tracking
---

import {LeafCard, Pair} from '@conduction/docusaurus-preset/components';

# Cospend integration

<LeafCard
  id="cospend"
  label="Costs"
  icon="CurrencyEur"
  group="workflow"
  requiredApp="cospend"
  storage="link-table"
  status="stub"
  description="Link NC Cospend projects and bills to Open Register objects. Detail-page widget shows a Total Spent summary. Provider stub today." />

Track project costs against an Open Register object. The Costs tab will surface linked Cospend projects and individual bills with amount, currency, and split. The detail-page widget shows a total-spent summary. Provider registers today; the wrapping service + link table land in a follow-up.

<Pair
  leftLabel="Open Register"
  leftCaption="object · Costs tab"
  rightLabel="NC Cospend"
  rightCaption="projects + bills"
  rightColor="cobalt-700"
  bridgeLabel="link-table (pending)" />

## What it will do

- Lists Cospend projects and bills linked to each Open Register object on the **Costs** sidebar tab.
- Shows total, currency, payer, and split per row.
- Renders a "Total spent" summary on the detail-page widget.
- Lets users link or unlink. The Cospend project/bill stays in NC Cospend.

## Setup

### 1. Install NC Cospend

Install the **cospend** Nextcloud app. The Costs tab appears once it's enabled.

### 2. Use it on an object

Open any object whose schema declares `linkedTypes: ['cospend']`. The **Costs** tab appears. Today it renders the empty state until the wrapping service lands.

## Configuration

| Field | Open Register side | NC Cospend side |
|---|---|---|
| **Storage** | `link-table` (`openregister_cospend_links`, pending) | NC Cospend project + bill stores |
| **Refresh** | Per render | — |
| **Auth** | None (uses session) | Cospend's user ACL |

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-cospend](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-cospend).

## Related

- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Time-tracker leaf](./time-tracker)** — link time entries to the same objects.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
