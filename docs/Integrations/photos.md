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

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-photos](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-photos).

## Related

- **[Files leaf](../features/files)** — the base file attachments.
- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
