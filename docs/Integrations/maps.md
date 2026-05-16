---
title: Maps
sidebar_position: 42
description: Link NC Maps locations to Open Register objects with cached lat/lon for fast map rendering. Provider stub today.
keywords:
  - Open Register
  - Integrations
  - Maps
  - Geolocation
---

import {LeafCard, Pair} from '@conduction/docusaurus-preset/components';

# Maps integration

<LeafCard
  id="maps"
  label="Location"
  icon="MapMarker"
  group="docs"
  requiredApp="maps"
  storage="link-table"
  status="stub"
  description="Link NC Maps locations to Open Register objects. Cached lat/lon means the map renders without a per-load API call. Detail-page widget shows an inline mini-map. Provider stub today." />

Pin geolocations to an Open Register object via NC Maps. The Location tab will list linked points with an embedded map; the detail-page widget shows an inline mini-map. Geocoding flows through Maps' own backend (Nominatim or configured provider). Provider registers today; the wrapping `MapLocationService` + link table land in a follow-up.

<Pair
  leftLabel="Open Register"
  leftCaption="object · Location tab"
  rightLabel="NC Maps"
  rightCaption="locations + geocoder"
  rightColor="cobalt-700"
  bridgeLabel="link-table + cached lat/lon" />

## What it will do

- Lists locations linked to each Open Register object on the **Location** sidebar tab. Shows an embedded map plus a list view.
- Renders an inline mini-map on the detail-page widget.
- Caches `lat` / `lon` on the link row so the map renders without per-load geocoding.
- Lets users add by address (NC Maps geocodes it) or by clicking on a picker map.
- Lets users unlink. The Maps point stays in Maps.

## Setup

### 1. Install NC Maps

Install the **maps** Nextcloud app. The Location tab appears once it's enabled.

### 2. Use it on an object

Open any object whose schema declares `linkedTypes: ['maps']`. The **Location** tab appears. Today it renders the empty state until the wrapping service lands.

## Configuration

| Field | Open Register side | NC Maps side |
|---|---|---|
| **Storage** | `link-table` (`openregister_map_links`, pending) — with cached `lat` / `lon` | NC Maps' own location store |
| **Geocoding** | Delegated to NC Maps (Nominatim or configured provider) | — |
| **Refresh** | Cached on link; geocode on update | — |

## Local verification setup

The leaf-verification harness in [`tests/e2e/leaf-verification.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-verification.spec.ts) probes every advertised provider against the seeded `integration-verification` register; you can reproduce a single-leaf check by hand against any OpenRegister dev container.

### 1. Install the `maps` Nextcloud app

```bash
docker exec -u www-data nextcloud php occ app:install maps
docker exec -u www-data nextcloud php occ app:enable maps
docker exec nextcloud apache2ctl graceful   # bust OPcache so the registry sees the new app
```

Without `maps` enabled, the `maps` provider reports `enabled: false` on the OCS capabilities payload and its sub-resource endpoint refuses to dispatch.

### 2. Probe the registry to confirm the provider is advertised

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  http://localhost:8080/ocs/v2.php/cloud/capabilities?format=json \
  | jq '.ocs.data.capabilities.openregister.integrations.providers[] | select(.id == "maps")'
```

Expected payload — the `enabled` flag flips to `true` once the required app is installed.

### 3. Probe the per-object sub-resource

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  "http://localhost:8080/index.php/apps/openregister/api/objects/21/166/25706ca9-c989-4d6b-9f7b-98cf1cc70639/integrations/maps"
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

The `OCS-APIRequest: true` header is mandatory — without it, Nextcloud's session-CSRF guard short-circuits with HTTP 412 before the provider runs. An empty `items: []` is the correct response for a freshly-seeded object that hasn't been linked to any upstream `maps` entity yet.

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-maps](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-maps).

## Related

- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
