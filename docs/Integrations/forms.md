---
title: Forms
sidebar_position: 65
description: Link NC Forms responses to Open Register objects. Provider stub today.
keywords:
  - Open Register
  - Integrations
  - Forms
  - Survey
---

import {LeafCard, Pair} from '@conduction/docusaurus-preset/components';

# Forms integration

<LeafCard
  id="forms"
  label="Forms"
  icon="ClipboardText"
  group="workflow"
  requiredApp="forms"
  storage="link-table"
  status="stub"
  description="Link NC Forms responses to Open Register objects. Read-only response display plus 'link a form for future responses' flow. Provider stub today." />

Surface NC Forms responses on an Open Register object. The Forms tab will show linked responses read-only; admins can also link a form so future responses auto-attach. Provider registers today; the wrapping `FormResponseService` + link table land in a follow-up.

<Pair
  leftLabel="Open Register"
  leftCaption="object · Forms tab"
  rightLabel="NC Forms"
  rightCaption="forms + responses"
  rightColor="cobalt-700"
  bridgeLabel="link-table (pending)" />

## What it will do

- Lists linked Forms responses on the **Forms** sidebar tab. Read-only.
- Lets admins link a form so future submissions auto-attach to a target object (via a URL parameter or a hidden form field).
- Renders a response-count summary on the detail-page widget.
- Resolves a `referenceType: 'forms'` schema property to a single-entity chip with form title + response count.

## Setup

### 1. Install NC Forms

Install the **forms** Nextcloud app. The Forms tab appears once it's enabled.

### 2. Use it on an object

Open any object whose schema declares `linkedTypes: ['forms']`. The **Forms** tab appears. Today it renders the empty state until the wrapping service lands.

## Configuration

| Field | Open Register side | NC Forms side |
|---|---|---|
| **Storage** | `link-table` (`openregister_form_links`, pending) | NC Forms own tables |
| **Mapping** | form id, response id, object uuid | Forms REST API |
| **Refresh** | Per render | — |
| **Permissions** | Inherits from object RBAC | Forms' own ACL (response visibility) |

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-forms](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-forms).

## Related

- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Polls leaf](./polls)** — for collective decision-making instead of free-form submissions.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
