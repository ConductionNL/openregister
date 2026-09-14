---
kind: code
depends_on: [complete-live-updates]
---

# Proposal: object-presence

## Summary

Show who else has an object open right now. A client that opens an object's
detail sends a heartbeat; the server keeps (user, object, last seen) with a
short lifetime and pushes arrivals and departures over the per-object
notify_push channel that live updates already use. A detail page renders the
other readers as avatars with no polling.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| Q2.31 | Does the product show who else has the case open right now | no | S |

## Why

The register's note: "Rated from the matrix. Row 2.20 is `no`: a 15-second
poll on `case-flow-runs`, no presence, with live updates planned. Deck
records a session per user per board and shows the avatars of the others
present; iTop is `partial` with a lock that ships off; Odoo is `no` and
warns on save only." The best competitor, verbatim from the `best` column:
"Deck 1.18: SessionService and Board.activeSessions
(`_round4/compare/pending-rows-batch6.md`)".

The register's `why`: "who has an object open is a live-updates channel on
the object, the same bus Deck uses". `realtime-updates` already emits
per-object push events on every lifecycle event and resolves the authorised
users through `PermissionHandler`; presence is one more event on that
channel.

## What changes

- `PUT /api/objects/{register}/{schema}/{id}/presence` records or renews
  the caller's presence (a heartbeat every 30 s while the detail is open;
  a row expires after 90 s). `DELETE` ends it on page close.
- `GET .../presence` lists the other present users (uid, display name,
  since) for anyone who may read the object.
- Arrival, renewal past expiry and departure push an `or-object-{uuid}`
  event of kind `presence` to the authorised users, so a client updates
  without a call.
- The live-updates client plugin gains `presence(objectUuid)` returning a
  reactive list and managing the heartbeat, so a detail page mounts one
  component.
- Rows live in a small table pruned by the sweep; nothing is written to the
  object, its audit trail or its versions.

## Consumers

- dossiq: avatars of the other readers in the case header. Specified in
  dossiq by the dossiq lane (register row Q2.31).
- Every detail page in the fleet through nextcloud-vue's detail header, which
  can mount the component from the manifest.
- `edit-lock-on-the-case-page (dossiq)` shows the lock holder; presence
  shows the readers. They compose.

## ADRs

- ADR-022: one presence primitive.
- ADR-071 (shared frontend runtime): the plugin lives once, in the shared
  client.
- openregister ADR-009: the heartbeat is a bounded write on a small table,
  never on the object.

## Impact

- Extends: `realtime-updates` requirement "The system MUST emit per-object
  push events on every lifecycle event" (a `presence` kind) and the SSE and
  notify_push transports.
- Affected code: `openregister_presence` table and migration,
  `lib/Service/PresenceService.php`, `ObjectsController` routes,
  `NotifyPushListener` (a presence push), `src/plugins/liveUpdates`.
- Size: S.
