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

## Local verification setup

The leaf-verification harness in [`tests/e2e/leaf-verification.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-verification.spec.ts) probes every advertised provider against the seeded `integration-verification` register; you can reproduce a single-leaf check by hand against any OpenRegister dev container.

### 1. Install the `cospend` Nextcloud app

```bash
docker exec -u www-data nextcloud php occ app:install cospend
docker exec -u www-data nextcloud php occ app:enable cospend
docker exec nextcloud apache2ctl graceful   # bust OPcache so the registry sees the new app
```

Without `cospend` enabled, the `cospend` provider reports `enabled: false` on the OCS capabilities payload and its sub-resource endpoint refuses to dispatch.

### 2. Probe the registry to confirm the provider is advertised

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  http://localhost:8080/ocs/v2.php/cloud/capabilities?format=json \
  | jq '.ocs.data.capabilities.openregister.integrations.providers[] | select(.id == "cospend")'
```

Expected payload — the `enabled` flag flips to `true` once the required app is installed.

### 3. Probe the per-object sub-resource

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  "http://localhost:8080/index.php/apps/openregister/api/objects/21/166/25706ca9-c989-4d6b-9f7b-98cf1cc70639/integrations/cospend"
```

Most recent harness run (against the seeded `verification-probe` object on this dev container):

- **Status**: `200` (`list-envelope`)
- **Latency**: 87ms
- **Body**: matches the documented list envelope below

```json
{
  "items": []
}
```

The `OCS-APIRequest: true` header is mandatory — without it, Nextcloud's session-CSRF guard short-circuits with HTTP 412 before the provider runs. An empty `items: []` is the correct response for a freshly-seeded object that hasn't been linked to any upstream `cospend` entity yet.

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-cospend](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-cospend).

## Related

- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Time-tracker leaf](./time-tracker)** — link time entries to the same objects.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
