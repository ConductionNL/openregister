---
title: Photos
sidebar_position: 43
description: Filter Open Register linked files to image types and enrich with EXIF metadata via NC Photos. Provider stub today.
keywords:
  - Open Register
  - Integrations
  - Photos
  - Images
  - EXIF
---

import {LeafCard, Pair} from '@conduction/docusaurus-preset/components';

# Photos integration

<LeafCard
  id="photos"
  label="Photos"
  icon="Image"
  group="docs"
  requiredApp="photos"
  storage="link-table"
  status="stub"
  description="Filter the object's linked files to image MIME types and enrich with EXIF / taken-at / camera / dimensions via NC Photos. Provider stub today." />

Build on the [Files leaf](../features/files): pick only the image attachments and enrich them with NC Photos metadata (EXIF, taken-at, camera, dimensions). The Photos tab will render a gallery view; the detail-page widget shows a hero image. Provider registers today; the wrapping `PhotoService` lands in a follow-up.

<Pair
  leftLabel="Open Register"
  leftCaption="object · Photos tab"
  rightLabel="NC Photos"
  rightCaption="image metadata + gallery"
  rightColor="cobalt-700"
  bridgeLabel="link-table (reuses files links)" />


## Screenshot

The integration registers in OpenRegister's in-page registry and renders as one of the tabs on the standalone integrations view. The tab is highlighted active here so you can see exactly which surface this leaf controls.

![photos integration tab active in the OpenRegister integrations view](/screenshots/integrations/photos.png)

Captured by [`tests/e2e/leaf-screenshots.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-screenshots.spec.ts) against the seeded `integration-verification` register on the dev container. Empty state (`Nothing linked yet`) is expected on a freshly seeded object — link an upstream entity from the tab's `+ Add` affordance to populate it.

## What it will do

- Reads the object's [linked files](../features/files), filters to image MIME types.
- Enriches each image with NC Photos metadata: EXIF, taken-at, camera, dimensions, location (when present).
- Lists images on the **Photos** sidebar tab in a gallery layout.
- Renders a hero image (the first or a chosen one) on the detail-page widget.
- Lets users mark which file is the hero. The mark lives on the Photos link row, not on the file.

## Setup

### 1. Install NC Photos

Install the **photos** Nextcloud app. The Photos tab appears once it's enabled.

### 2. Use it on an object

Open any object whose schema declares `linkedTypes: ['photos']`. The **Photos** tab appears. Today it renders the empty state until the wrapping service lands.

## Configuration

| Field | Open Register side | NC Photos side |
|---|---|---|
| **Storage** | `link-table` (reuses file links; adds an image-specific overlay table) | NC Photos metadata store |
| **Filter** | MIME type `image/*` on the linked files | — |
| **Metadata** | EXIF + Photos-derived fields cached on the overlay row | NC Photos service |
| **Refresh** | Per render (metadata cached on file upload) | — |

## Why not just use Files?

The Files leaf already lists all attached files including images. The Photos leaf adds three things:

1. **Filter to images** — no PDFs, no docs, just photos.
2. **EXIF / Photos metadata** — taken-at, camera, dimensions, sometimes location.
3. **Gallery + hero** — a layout that's optimised for images, not a file list.

Add `'photos'` to `linkedTypes` only when these matter. Otherwise the Files leaf is enough.

## Local verification setup

The leaf-verification harness in [`tests/e2e/leaf-verification.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-verification.spec.ts) probes every advertised provider against the seeded `integration-verification` register; you can reproduce a single-leaf check by hand against any OpenRegister dev container.

### 1. Install the `photos` Nextcloud app

```bash
docker exec -u www-data nextcloud php occ app:install photos
docker exec -u www-data nextcloud php occ app:enable photos
docker exec nextcloud apache2ctl graceful   # bust OPcache so the registry sees the new app
```

Without `photos` enabled, the `photos` provider reports `enabled: false` on the OCS capabilities payload and its sub-resource endpoint refuses to dispatch.

### 2. Probe the registry to confirm the provider is advertised

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  http://localhost:8080/ocs/v2.php/cloud/capabilities?format=json \
  | jq '.ocs.data.capabilities.openregister.integrations.providers[] | select(.id == "photos")'
```

Expected payload — the `enabled` flag flips to `true` once the required app is installed.

### 3. Probe the per-object sub-resource

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  "http://localhost:8080/index.php/apps/openregister/api/objects/21/166/25706ca9-c989-4d6b-9f7b-98cf1cc70639/integrations/photos"
```

Most recent harness run (against the seeded `verification-probe` object on this dev container):

- **Status**: `200` (`list-envelope`)
- **Latency**: 82ms
- **Body**: matches the documented list envelope below

```json
{
  "items": []
}
```

The `OCS-APIRequest: true` header is mandatory — without it, Nextcloud's session-CSRF guard short-circuits with HTTP 412 before the provider runs. An empty `items: []` is the correct response for a freshly-seeded object that hasn't been linked to any upstream `photos` entity yet.

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-photos](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-photos).

## Related

- **[Files leaf](../features/files)** — the base file attachments.
- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
