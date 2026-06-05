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


## Screenshot

The integration registers in OpenRegister's in-page registry and renders as one of the tabs on the standalone integrations view. The tab is highlighted active here so you can see exactly which surface this leaf controls.

![polls integration tab active in the OpenRegister integrations view](/screenshots/integrations/polls.png)

Captured by [`tests/e2e/leaf-screenshots.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-screenshots.spec.ts) against the seeded `integration-verification` register on the dev container. Empty state (`Nothing linked yet`) is expected on a freshly seeded object — link an upstream entity from the tab's `+ Add` affordance to populate it.

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

## Local verification setup

The leaf-verification harness in [`tests/e2e/leaf-verification.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-verification.spec.ts) probes every advertised provider against the seeded `integration-verification` register; you can reproduce a single-leaf check by hand against any OpenRegister dev container.

### 1. Install the `polls` Nextcloud app

```bash
docker exec -u www-data nextcloud php occ app:install polls
docker exec -u www-data nextcloud php occ app:enable polls
docker exec nextcloud apache2ctl graceful   # bust OPcache so the registry sees the new app
```

Without `polls` enabled, the `polls` provider reports `enabled: false` on the OCS capabilities payload and its sub-resource endpoint refuses to dispatch.

### 2. Probe the registry to confirm the provider is advertised

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  http://localhost:8080/ocs/v2.php/cloud/capabilities?format=json \
  | jq '.ocs.data.capabilities.openregister.integrations.providers[] | select(.id == "polls")'
```

Expected payload — the `enabled` flag flips to `true` once the required app is installed.

### 3. Probe the per-object sub-resource

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  "http://localhost:8080/index.php/apps/openregister/api/objects/21/166/25706ca9-c989-4d6b-9f7b-98cf1cc70639/integrations/polls"
```

Most recent harness run (against the seeded `verification-probe` object on this dev container):

- **Status**: `200` (`list-envelope`)
- **Latency**: 91ms
- **Body**: matches the documented list envelope below

```json
{
  "items": []
}
```

The `OCS-APIRequest: true` header is mandatory — without it, Nextcloud's session-CSRF guard short-circuits with HTTP 412 before the provider runs. An empty `items: []` is the correct response for a freshly-seeded object that hasn't been linked to any upstream `polls` entity yet.

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-polls](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-polls).

## Related

- **[Calendar leaf](./calendar.md)** — once a poll closes on a meeting date, materialise the VEVENT.
- **[Forms leaf](./forms.md)** — for free-form survey responses instead of fixed-option polls.
- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
