---
title: Flow
sidebar_position: 64
description: Scope NC Flow rules to an Open Register schema or object. Provider stub today.
keywords:
  - Open Register
  - Integrations
  - Flow
  - workflowengine
  - Automation
---

import {LeafCard, Pair} from '@conduction/docusaurus-preset/components';

# Flow integration

<LeafCard
  id="flow"
  label="Automation"
  icon="RobotOutline"
  group="workflow"
  requiredApp="workflowengine"
  storage="link-table"
  status="stub"
  description="Scope NC Flow rules to a specific Open Register schema or object. Surfaces the rules plus their recent fire events. Provider stub today." />

NC `workflowengine` is part of Nextcloud core (always present, sometimes disabled). The Flow leaf surfaces flow rules scoped to an Open Register schema or object, plus the recent fire events those rules emitted. Provider registers today; the wrapping service + link table land in a follow-up.

<Pair
  leftLabel="Open Register"
  leftCaption="schema + object · Automation tab"
  rightLabel="NC workflowengine"
  rightCaption="rules + events"
  rightColor="cobalt-700"
  bridgeLabel="link-table + read-time event aggregation" />

## What it will do

- Lists flow rules scoped to this schema/object on the **Automation** sidebar tab.
- Shows the recent fire events ("rule X fired N times today on this object's children").
- Lets users link an existing rule, or create one via the NC core flow-rule UI.
- Integrates with Open Register's own workflow engine so the events show up on Open Register's read path too.

## Setup

### 1. Make sure `workflowengine` is enabled

It ships with NC core, but admins sometimes disable it. Check on Apps → Disabled.

### 2. Use it on an object

Open any object whose schema declares `linkedTypes: ['flow']`. The **Automation** tab appears. Today it renders the empty state until the wrapping service lands.

## Configuration

| Field | Open Register side | NC workflowengine side |
|---|---|---|
| **Storage** | `link-table` (`openregister_flow_links`, pending) | NC `flow_operations`, `flow_checks` |
| **Aggregation** | Read-time aggregation from NC Flow's fire events | — |
| **Refresh** | Per render | — |

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-flow](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-flow).

## Related

- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
