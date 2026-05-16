---
title: Bookmarks
sidebar_position: 40
description: Link NC Bookmarks to Open Register objects with URL preview cards and tag chips. Provider stub today.
keywords:
  - Open Register
  - Integrations
  - Bookmarks
  - URLs
---

import {LeafCard, Pair} from '@conduction/docusaurus-preset/components';

# Bookmarks integration

<LeafCard
  id="bookmarks"
  label="Bookmarks"
  icon="Bookmark"
  group="docs"
  requiredApp="bookmarks"
  storage="link-table"
  status="stub"
  description="Link NC Bookmarks to Open Register objects. URL preview cards, tag chips from Bookmarks' own tag system, favicon-and-title compact rendering. Provider stub today." />

Pin reference URLs to an Open Register object via NC Bookmarks. The Bookmarks tab will show preview cards with favicon, title, and Bookmarks' own tags; the widget renders a compact list. The provider is registered today — the wrapped service + link table land in a follow-up.

<Pair
  leftLabel="Open Register"
  leftCaption="object · Bookmarks tab"
  rightLabel="NC Bookmarks"
  rightCaption="bookmark + tag store"
  rightColor="cobalt-700"
  bridgeLabel="link-table (pending)" />

## What it will do

- Lists NC Bookmarks linked to each Open Register object on the **Bookmarks** sidebar tab.
- Shows favicon, title, URL, and Bookmarks' own tags as chips.
- Lets users link an existing bookmark or paste a URL to add one.
- Lets users unlink. The bookmark stays in NC Bookmarks; only the link row is removed.
- Resolves a `referenceType: 'bookmarks'` schema property to a single-entity chip with favicon + title.

## Setup

### 1. Install NC Bookmarks

Install the **bookmarks** Nextcloud app. The Bookmarks tab appears once it's enabled.

### 2. Use it on an object

Open any object whose schema declares `linkedTypes: ['bookmarks']`. The **Bookmarks** tab appears. Today it renders the empty state until the wrapping service lands.

## Configuration

| Field | Open Register side | NC Bookmarks side |
|---|---|---|
| **Storage** | `link-table` (`openregister_bookmark_links`, pending) | NC `oc_bookmarks` |
| **Refresh** | Per render (favicon cached by Bookmarks) | — |
| **Permissions** | Inherits from object RBAC | Bookmarks user ACL |

## Local verification setup

The leaf-verification harness in [`tests/e2e/leaf-verification.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-verification.spec.ts) probes every advertised provider against the seeded `integration-verification` register; you can reproduce a single-leaf check by hand against any OpenRegister dev container.

### 1. Install the `bookmarks` Nextcloud app

```bash
docker exec -u www-data nextcloud php occ app:install bookmarks
docker exec -u www-data nextcloud php occ app:enable bookmarks
docker exec nextcloud apache2ctl graceful   # bust OPcache so the registry sees the new app
```

Without `bookmarks` enabled, the `bookmarks` provider reports `enabled: false` on the OCS capabilities payload and its sub-resource endpoint refuses to dispatch.

### 2. Probe the registry to confirm the provider is advertised

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  http://localhost:8080/ocs/v2.php/cloud/capabilities?format=json \
  | jq '.ocs.data.capabilities.openregister.integrations.providers[] | select(.id == "bookmarks")'
```

Expected payload — the `enabled` flag flips to `true` once the required app is installed.

### 3. Probe the per-object sub-resource

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  "http://localhost:8080/index.php/apps/openregister/api/objects/21/166/25706ca9-c989-4d6b-9f7b-98cf1cc70639/integrations/bookmarks"
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

The `OCS-APIRequest: true` header is mandatory — without it, Nextcloud's session-CSRF guard short-circuits with HTTP 412 before the provider runs. An empty `items: []` is the correct response for a freshly-seeded object that hasn't been linked to any upstream `bookmarks` entity yet.

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-bookmarks](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-bookmarks).

## Related

- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[xWiki leaf](./xwiki.md)** — link external wiki pages too.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
