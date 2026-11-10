---
title: Collectives
sidebar_position: 41
description: Link Collectives pages (NC-native markdown wiki) to Open Register objects. Provider stub today.
keywords:
  - Open Register
  - Integrations
  - Collectives
  - Markdown
  - Wiki
---

import {LeafCard, Pair} from '@conduction/docusaurus-preset/components';

# Collectives integration

<LeafCard
  id="collectives"
  label="Knowledge"
  icon="BookOpenPageVariant"
  group="docs"
  requiredApp="collectives"
  storage="link-table"
  status="stub"
  description="Link Collectives markdown pages to Open Register objects. NC-native alternative to xWiki — no external service, no OpenConnector. Provider stub today." />

NC-native alternative to xWiki. Link Collectives markdown pages to an Open Register object; the Knowledge tab lists them with a markdown preview. No external service, no OpenConnector. The provider registers today — the wrapping service + link table land in a follow-up.

<Pair
  leftLabel="Open Register"
  leftCaption="object · Knowledge tab"
  rightLabel="NC Collectives"
  rightCaption="markdown pages"
  rightColor="cobalt-700"
  bridgeLabel="link-table (pending)" />


## Screenshot

The integration registers in OpenRegister's in-page registry and renders as one of the tabs on the standalone integrations view. The tab is highlighted active here so you can see exactly which surface this leaf controls.

![collectives integration tab active in the OpenRegister integrations view](/screenshots/integrations/collectives.png)

Captured by [`tests/e2e/leaf-screenshots.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-screenshots.spec.ts) against the seeded `integration-verification` register on the dev container. Empty state (`Nothing linked yet`) is expected on a freshly seeded object — link an upstream entity from the tab's `+ Add` affordance to populate it.

## What it will do

- Lists Collectives pages linked to each Open Register object on the **Knowledge** sidebar tab.
- Renders a markdown preview (the first ~500 chars of the page body) on the detail-page widget.
- Lets users link a page by collective id + page path, or unlink.
- Resolves a `referenceType: 'collectives'` schema property to a single-entity chip with collective + page title.

## Setup

### 1. Install NC Collectives

Install the **collectives** Nextcloud app. The Knowledge tab appears once it's enabled.

### 2. Use it on an object

Open any object whose schema declares `linkedTypes: ['collectives']`. The **Knowledge** tab appears. Today it renders the empty state until the wrapping service lands.

## Configuration

| Field | Open Register side | NC Collectives side |
|---|---|---|
| **Storage** | `link-table` (`openregister_collective_links`, pending) | Collectives' own page store |
| **Mapping** | collective id, page path, page id | Collectives REST API |
| **Refresh** | Per render | — |
| **Permissions** | Inherits from object RBAC | Collectives' own ACL |

## Why two wiki leaves?

xWiki and Collectives both link "long-form pages" to an object, but they serve different deployments:

- **Collectives** is NC-native and ships in the Nextcloud app store. No external service, no credentials. Use it when the wiki lives inside Nextcloud.
- **xWiki** is an external wiki. Use it when the team already runs xWiki and you want Open Register to surface its content without migrating.

Schemas usually declare one or the other in `linkedTypes`, not both. The two leaves don't interact.

## Local verification setup

The leaf-verification harness in [`tests/e2e/leaf-verification.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-verification.spec.ts) probes every advertised provider against the seeded `integration-verification` register; you can reproduce a single-leaf check by hand against any OpenRegister dev container.

### 1. Install the `collectives` Nextcloud app

```bash
docker exec -u www-data nextcloud php occ app:install collectives
docker exec -u www-data nextcloud php occ app:enable collectives
docker exec nextcloud apache2ctl graceful   # bust OPcache so the registry sees the new app
```

Without `collectives` enabled, the `collectives` provider reports `enabled: false` on the OCS capabilities payload and its sub-resource endpoint refuses to dispatch.

### 2. Probe the registry to confirm the provider is advertised

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  http://localhost:8080/ocs/v2.php/cloud/capabilities?format=json \
  | jq '.ocs.data.capabilities.openregister.integrations.providers[] | select(.id == "collectives")'
```

Expected payload — the `enabled` flag flips to `true` once the required app is installed.

### 3. Probe the per-object sub-resource

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  "http://localhost:8080/index.php/apps/openregister/api/objects/21/166/25706ca9-c989-4d6b-9f7b-98cf1cc70639/integrations/collectives"
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

The `OCS-APIRequest: true` header is mandatory — without it, Nextcloud's session-CSRF guard short-circuits with HTTP 412 before the provider runs. An empty `items: []` is the correct response for a freshly-seeded object that hasn't been linked to any upstream `collectives` entity yet.

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-collectives](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-collectives).

## Related

- **[xWiki leaf](./xwiki.md)** — the external alternative.
- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
