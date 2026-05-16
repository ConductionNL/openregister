---
title: Time tracker
sidebar_position: 67
description: Link NC time-tracking entries (default timemanager) to Open Register objects. Provider stub today.
keywords:
  - Open Register
  - Integrations
  - Time
  - Tracker
  - timemanager
---

import {LeafCard, Pair} from '@conduction/docusaurus-preset/components';

# Time tracker integration

<LeafCard
  id="time-tracker"
  label="Time"
  icon="Clock"
  group="workflow"
  requiredApp="timemanager"
  storage="link-table"
  status="stub"
  description="Link NC time-tracking entries to Open Register objects. Default backing app is timemanager; configurable for sites running a different NC time-tracking app. Provider stub today." />

Tie tracked time to an Open Register object. The Time tab will surface linked time entries with date, user, duration, and a running total. Default backing app is `timemanager`; the leaf's design supports overriding to another NC time-tracking app via a site-level config. Provider registers today; the wrapping service + link table land in a follow-up.

<Pair
  leftLabel="Open Register"
  leftCaption="object · Time tab"
  rightLabel="NC time tracker"
  rightCaption="timemanager (or other)"
  rightColor="cobalt-700"
  bridgeLabel="link-table (pending)" />

## What it will do

- Lists time entries linked to each Open Register object on the **Time** sidebar tab.
- Shows date, user, duration, and the entry's note per row.
- Renders a running total ("12h 30m this week, 47h total") on the detail-page widget.
- Lets users add a time entry inline (date, duration, note); the entry lands in NC time tracker.
- Resolves a `referenceType: 'time-tracker'` schema property to a single-entity chip with cumulative time + user count.

## Setup

### 1. Install your NC time-tracking app

Default is the **timemanager** Nextcloud app. The Time tab appears once it's enabled. To use a different app, override at the openregister site level (config flag, set during install).

### 2. Use it on an object

Open any object whose schema declares `linkedTypes: ['time-tracker']`. The **Time** tab appears. Today it renders the empty state until the wrapping service lands.

## Configuration

| Field | Open Register side | NC time tracker side |
|---|---|---|
| **Storage** | `link-table` (`openregister_time_links`, pending) | timemanager (or alternate) entries |
| **Refresh** | Per render (totals computed live) | — |
| **Auth** | None (uses session) | The tracker's own ACL |
| **Configurable app** | Yes — overridable via site config | — |

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-time-tracker](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-time-tracker).

## Related

- **[Cospend leaf](./cospend.md)** — tracks money against the same objects.
- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
