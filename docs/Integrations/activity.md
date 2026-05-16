---
title: Activity
sidebar_position: 60
description: Surface NC Activity events relevant to an Open Register object. Provider stub today; query-time read path lands in a follow-up.
keywords:
  - Open Register
  - Integrations
  - Activity
  - Audit
---

import {LeafCard, Pair} from '@conduction/docusaurus-preset/components';

# Activity integration

<LeafCard
  id="activity"
  label="Activity"
  icon="Timeline"
  group="workflow"
  requiredApp="activity"
  storage="query-time"
  status="stub"
  description="Filter NC Activity events relevant to a given Open Register object — linked files, linked actors, linked tasks. Read-only by design (events are not editable). Provider stub today." />

Surface NC Activity events scoped to a single Open Register object. The provider is registered today — the **Activity** sidebar tab appears the moment NC Activity is installed — but its `list()` returns an empty list until the filter service ships in a follow-up.

<Pair
  leftLabel="Open Register"
  leftCaption="object · Activity tab"
  rightLabel="NC Activity"
  rightCaption="event stream"
  rightColor="cobalt-700"
  bridgeLabel="query-time filter (pending)" />

## What it will do

- Lists Activity events scoped to this object's linked files, linked actors, and linked tasks on the **Activity** sidebar tab.
- Read-only. Activity events are not editable. `create()` / `update()` / `delete()` throw `NotImplementedException` per AD-22.
- No link table — the filter runs live on every render (`query-time` storage).
- Honours NC Activity's per-user visibility rules.

## Setup

### 1. Install NC Activity

Install the **activity** Nextcloud app. The Activity tab appears once it's enabled. The OCS capabilities reports `enabled: true`.

### 2. Use it on an object

Open any object whose schema declares `linkedTypes: ['activity']`. The **Activity** tab appears in the sidebar. Today it renders the empty state ("Nothing logged yet") until the filter service lands.

## Configuration

| Field | Open Register side | NC Activity side |
|---|---|---|
| **Storage** | `query-time` (no link table) | NC `oc_activity` (read-only) |
| **Refresh** | Per render | — |
| **Permissions** | Inherits from object RBAC + Activity's per-user filter | NC Activity ACL |

## Current status

The provider is registered: the admin row appears, the JS sidebar tab renders, the OCS capability advertises `activity`. The wrapped read path (filter NC's activity stream by associated actors/events) lands in a follow-up issue tracked under [openspec/changes/integration-activity](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-activity).

## Related

- **[Audit trail leaf](./audit-trail)** — Open Register's own change log; ships today.
- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
