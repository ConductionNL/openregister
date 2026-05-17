---
title: Deck Integration
sidebar_position: 2
description: How OpenRegister integrates with the Nextcloud Deck app, including push events and card–object linking
keywords:
  - OpenRegister
  - Deck
  - Kanban
  - notify_push
  - real-time
---

# Deck Integration

OpenRegister integrates with the Nextcloud Deck app to allow Kanban-style task boards to be linked directly to register objects. Deck cards represent tasks or action items; by linking them to objects you get traceable work attached to your data.

## Overview

- Deck cards can be linked to any OpenRegister object via a link table managed by `DeckCardService`.
- When a Deck board or card changes, Deck fires `notify_custom` push events via `notify_push`. Frontend consumers can listen for these events to refresh linked-object views in real time.
- OpenRegister's own push events (`or-object-*` and `or-collection-*`) complement Deck events, providing object-level invalidation alongside card-level updates.

## Backend integration

| Class | Responsibility |
|---|---|
| `OCA\OpenRegister\Service\DeckCardService` | Creates, retrieves, and deletes Deck cards linked to OR objects; wraps the Deck REST API. |
| `OCA\OpenRegister\Controller\DeckController` | REST controller exposing card link endpoints under `/api/objects/{register}/{schema}/{uuid}/deck`. |

The integration uses the Deck REST API (`/api/v1/boards`, `/api/v1/boards/{id}/stacks`, `/api/v1/boards/{id}/stacks/{stackId}/cards`) rather than the Deck PHP service layer, so it does not require Deck to be installed on the same Nextcloud instance — it can target a remote Deck instance.

## Linking cards to objects

A Deck card is linked to an OpenRegister object through the `deck` property on the `ObjectEntity`. The property holds an array of card-link descriptors:

```json
{
  "@self": { "id": "550e8400-e29b-41d4-a716-446655440000" },
  "deck": [
    {
      "boardId": 5,
      "stackId": 12,
      "cardId": 99,
      "title": "Review permit application",
      "url": "https://nextcloud.example.com/apps/deck#/board/5"
    }
  ]
}
```

`DeckCardService` is responsible for hydrating this array and for creating new cards via the API.

The link is stored on the object itself (as part of the JSONB blob). There is no separate join table — the `deck` property is the canonical record of which cards are attached to which object.

## Push events

Deck emits `notify_custom` events via the Nextcloud `notify_push` app. Both constants live in `OCA\Deck\NotifyPushEvents`:

| Event string | Fired when | Payload fields |
|---|---|---|
| `deck_board_update` | A board is updated, a member is added/removed, or ACL changes | `id` (board ID) |
| `deck_card_update` | A card is created, updated, moved, or deleted | `boardId`, `cardId` |

These events are fired server-side by `OCA\Deck\Listeners\LiveUpdateListener`. Connected browser clients receive them via the `notify_push` WebSocket or SSE channel.

**OpenRegister contrast:** OR emits the following event strings (see `OCA\OpenRegister\Push\PushEvents`):

| Event string pattern | Fired when |
|---|---|
| `or-object-{uuid}` | Any object lifecycle event (create, update, delete) |
| `or-collection-{register-slug}-{schema-slug}` | Object created or deleted (collection invalidation) |

Deck and OR events are independent streams. A frontend widget that shows an object with linked Deck cards should subscribe to both.

## Subscribing from the frontend

`@conduction/nextcloud-vue` provides composables for subscribing to `notify_push` events. Use the `useNotifyPush` composable (available from the upcoming `add-live-updates-plugin` change):

```js
import { useNotifyPush } from '@conduction/nextcloud-vue'

const { subscribe } = useNotifyPush()

// Subscribe to object-level OR events
subscribe(`or-object-${objectUuid}`, ({ data }) => {
  const payload = JSON.parse(data)
  // refresh object view
})

// Subscribe to Deck card updates for the board that owns the linked card
subscribe('deck_card_update', ({ data }) => {
  const { boardId, cardId } = JSON.parse(data)
  // refresh card list for the affected board
})
```

Cross-reference: the `add-live-updates-plugin` change in `nextcloud-vue` provides the full composable implementation including reconnection handling and per-user event routing.

## Configuration

The Deck integration requires:

1. **notify_push installed and running** — check the admin settings Push Notifications section in OpenRegister for status.
2. **Deck app installed** — the integration uses Deck's REST API, which must be reachable.
3. **Admin credentials or app password** — `DeckCardService` uses the current user's session; board access follows Deck's own RBAC.

## Related documentation

- [n8n Integration](./n8n.md) — trigger n8n workflows on OR object events
- [Custom Webhooks](./custom-webhooks.md) — push events to arbitrary HTTP endpoints
- [Deck app documentation](https://github.com/nextcloud/deck)
- [notify_push configuration](https://github.com/nextcloud/notify_push#configuration)
