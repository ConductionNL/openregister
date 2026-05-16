---
title: Talk
sidebar_position: 23
description: Link NC Talk (spreed) conversations to Open Register objects. Provider stub today.
keywords:
  - Open Register
  - Integrations
  - Talk
  - spreed
  - Chat
---

import {LeafCard, Pair} from '@conduction/docusaurus-preset/components';

# Talk integration

<LeafCard
  id="talk"
  label="Chat"
  icon="ChatOutline"
  group="comms"
  requiredApp="spreed"
  storage="link-table"
  status="stub"
  description="Link NC Talk conversations to Open Register objects. Note: NC Talk's app id is 'spreed', not 'talk'. Provider stub today." />

Tie an NC Talk conversation to an Open Register object so the chat is one click away from the object's sidebar. The Chat tab will surface linked conversations with their last-message preview; clicking opens the conversation in NC Talk. Provider registers today; the wrapping service + link table land in a follow-up.

:::note App id is `spreed`, not `talk`
NC Talk's internal app id is `spreed` (the project name). The integration's `requiredApp: 'spreed'` is what `IAppManager::isInstalled()` checks against.
:::

<Pair
  leftLabel="Open Register"
  leftCaption="object · Chat tab"
  rightLabel="NC Talk (spreed)"
  rightCaption="conversations + messages"
  rightColor="cobalt-700"
  bridgeLabel="link-table (pending)" />

## What it will do

- Lists Talk conversations linked to each Open Register object on the **Chat** sidebar tab.
- Shows the last-message preview, participant count, and unread badge.
- Lets users link an existing conversation or create one inline (with the object's title as the conversation name).
- Resolves a `referenceType: 'talk'` schema property to a single-entity chip with the conversation name + participant count.

## Setup

### 1. Install NC Talk

Install the **Talk** Nextcloud app (its internal id is `spreed`). The Chat tab appears once it's enabled.

### 2. Use it on an object

Open any object whose schema declares `linkedTypes: ['talk']`. The **Chat** tab appears. Today it renders the empty state until the wrapping service lands.

## Configuration

| Field | Open Register side | NC Talk side |
|---|---|---|
| **Storage** | `link-table` (`openregister_talk_links`, pending) | NC Talk's `oc_talk_rooms` |
| **Mapping** | conversation token, cached name + participant count | Talk REST API |
| **Refresh** | Per render (last-message updates live) | — |
| **Permissions** | Inherits from object RBAC | Talk's conversation membership |

## Local verification setup

The leaf-verification harness in [`tests/e2e/leaf-verification.spec.ts`](https://github.com/ConductionNL/openregister/blob/development/tests/e2e/leaf-verification.spec.ts) probes every advertised provider against the seeded `integration-verification` register; you can reproduce a single-leaf check by hand against any OpenRegister dev container.

### 1. Install the `spreed` Nextcloud app

```bash
docker exec -u www-data nextcloud php occ app:install spreed
docker exec -u www-data nextcloud php occ app:enable spreed
docker exec nextcloud apache2ctl graceful   # bust OPcache so the registry sees the new app
```

Without `spreed` enabled, the `talk` provider reports `enabled: false` on the OCS capabilities payload and its sub-resource endpoint refuses to dispatch.

### 2. Probe the registry to confirm the provider is advertised

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  http://localhost:8080/ocs/v2.php/cloud/capabilities?format=json \
  | jq '.ocs.data.capabilities.openregister.integrations.providers[] | select(.id == "talk")'
```

Expected payload — the `enabled` flag flips to `true` once the required app is installed.

### 3. Probe the per-object sub-resource

```bash
curl -s -u admin:admin -H 'OCS-APIRequest: true' \
  "http://localhost:8080/index.php/apps/openregister/api/objects/21/166/25706ca9-c989-4d6b-9f7b-98cf1cc70639/integrations/talk"
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

The `OCS-APIRequest: true` header is mandatory — without it, Nextcloud's session-CSRF guard short-circuits with HTTP 412 before the provider runs. An empty `items: []` is the correct response for a freshly-seeded object that hasn't been linked to any upstream `talk` entity yet.

## Current status

Provider registered. Wrapping service + link table tracked under [openspec/changes/integration-talk](https://github.com/ConductionNL/openregister/tree/development/openspec/changes/integration-talk).

## Related

- **[Email leaf](./email.md)** — async written communication; pairs well with sync Talk.
- **[Leaf integration system](./leaf-system.md)** — how every leaf wires the same way.
- **[Pluggable integration registry](./pluggable-integration-registry.md)** — the full ADR-019 contract.
