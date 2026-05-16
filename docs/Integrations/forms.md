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

## Local verification setup

The leaf-verification harness in [`tests/e2e/leaf-verification.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-verification.spec.ts) probes every advertised provider against the seeded `integration-verification` register; you can reproduce a single-leaf check by hand against any OpenRegister dev container.

### 1. Install the `forms` Nextcloud app

```bash
docker exec -u www-data nextcloud php occ app:install forms
docker exec -u www-data nextcloud php occ app:enable forms
docker exec nextcloud apache2ctl graceful   # bust OPcache so the registry sees the new app
```

Without `forms` enabled, the `forms` provider reports `enabled: false` on the OCS capabilities payload and its sub-resource endpoint refuses to dispatch.

### 2. Probe the registry to confirm the provider is advertised

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  http://localhost:8080/ocs/v2.php/cloud/capabilities?format=json \
  | jq '.ocs.data.capabilities.openregister.integrations.providers[] | select(.id == "forms")'
```

Expected payload — the `enabled` flag flips to `true` once the required app is installed.

### 3. Probe the per-object sub-resource

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  "http://localhost:8080/index.php/apps/openregister/api/objects/21/166/25706ca9-c989-4d6b-9f7b-98cf1cc70639/integrations/forms"
```

Most recent harness run (against the seeded `verification-probe` object on this dev container):

- **Status**: `200` (`list-envelope`)
- **Latency**: 83ms
- **Body**: matches the documented list envelope below

```json
{
  "items": []
}
```

The `OCS-APIRequest: true` header is mandatory — without it, Nextcloud's session-CSRF guard short-circuits with HTTP 412 before the provider runs. An empty `items: []` is the correct response for a freshly-seeded object that hasn't been linked to any upstream `forms` entity yet.

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-forms](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-forms).

## Related

- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Polls leaf](./polls)** — for collective decision-making instead of free-form submissions.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
