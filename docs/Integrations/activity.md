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

## Local verification setup

The leaf-verification harness in [`tests/e2e/leaf-verification.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-verification.spec.ts) probes every advertised provider against the seeded `integration-verification` register; you can reproduce a single-leaf check by hand against any OpenRegister dev container.

### 1. Install the `activity` Nextcloud app

```bash
docker exec -u www-data nextcloud php occ app:install activity
docker exec -u www-data nextcloud php occ app:enable activity
docker exec nextcloud apache2ctl graceful   # bust OPcache so the registry sees the new app
```

Without `activity` enabled, the `activity` provider reports `enabled: false` on the OCS capabilities payload and its sub-resource endpoint refuses to dispatch.

### 2. Probe the registry to confirm the provider is advertised

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  http://localhost:8080/ocs/v2.php/cloud/capabilities?format=json \
  | jq '.ocs.data.capabilities.openregister.integrations.providers[] | select(.id == "activity")'
```

Expected payload — the `enabled` flag flips to `true` once the required app is installed.

### 3. Probe the per-object sub-resource

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  "http://localhost:8080/index.php/apps/openregister/api/objects/21/166/25706ca9-c989-4d6b-9f7b-98cf1cc70639/integrations/activity"
```

Most recent harness run (against the seeded `verification-probe` object on this dev container):

- **Status**: `200` (`list-envelope`)
- **Latency**: 88ms
- **Body**: matches the documented list envelope below

```json
{
  "items": []
}
```

The `OCS-APIRequest: true` header is mandatory — without it, Nextcloud's session-CSRF guard short-circuits with HTTP 412 before the provider runs. An empty `items: []` is the correct response for a freshly-seeded object that hasn't been linked to any upstream `activity` entity yet.

## Current status

The provider is registered: the admin row appears, the JS sidebar tab renders, the OCS capability advertises `activity`. The wrapped read path (filter NC's activity stream by associated actors/events) lands in a follow-up issue tracked under [openspec/changes/integration-activity](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-activity).

## Related

- **[Audit trail leaf](./audit-trail)** — Open Register's own change log; ships today.
- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
