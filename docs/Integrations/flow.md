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


## Screenshot

The integration registers in OpenRegister's in-page registry and renders as one of the tabs on the standalone integrations view. The tab is highlighted active here so you can see exactly which surface this leaf controls.

![flow integration tab active in the OpenRegister integrations view](/screenshots/integrations/flow.png)

Captured by [`tests/e2e/leaf-screenshots.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-screenshots.spec.ts) against the seeded `integration-verification` register on the dev container. Empty state (`Nothing linked yet`) is expected on a freshly seeded object — link an upstream entity from the tab's `+ Add` affordance to populate it.

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

## Local verification setup

The leaf-verification harness in [`tests/e2e/leaf-verification.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-verification.spec.ts) probes every advertised provider against the seeded `integration-verification` register; you can reproduce a single-leaf check by hand against any OpenRegister dev container.

### 1. Install the `workflowengine` Nextcloud app

```bash
docker exec -u www-data nextcloud php occ app:install workflowengine
docker exec -u www-data nextcloud php occ app:enable workflowengine
docker exec nextcloud apache2ctl graceful   # bust OPcache so the registry sees the new app
```

Without `workflowengine` enabled, the `flow` provider reports `enabled: false` on the OCS capabilities payload and its sub-resource endpoint refuses to dispatch.

### 2. Probe the registry to confirm the provider is advertised

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  http://localhost:8080/ocs/v2.php/cloud/capabilities?format=json \
  | jq '.ocs.data.capabilities.openregister.integrations.providers[] | select(.id == "flow")'
```

Expected payload — the `enabled` flag flips to `true` once the required app is installed.

### 3. Probe the per-object sub-resource

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  "http://localhost:8080/index.php/apps/openregister/api/objects/21/166/25706ca9-c989-4d6b-9f7b-98cf1cc70639/integrations/flow"
```

Most recent harness run (against the seeded `verification-probe` object on this dev container):

- **Status**: `200` (`list-envelope`)
- **Latency**: 85ms
- **Body**: matches the documented list envelope below

```json
{
  "items": []
}
```

The `OCS-APIRequest: true` header is mandatory — without it, Nextcloud's session-CSRF guard short-circuits with HTTP 412 before the provider runs. An empty `items: []` is the correct response for a freshly-seeded object that hasn't been linked to any upstream `flow` entity yet.

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-flow](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-flow).

## Related

- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
