---
kind: code
---

## Why

A reader who closes a tab tells the server they left with a beacon
(`useObjectPresence` in `@conduction/nextcloud-vue`). A beacon is always a POST,
so the client sends `POST .../presence?_method=DELETE`. Nextcloud does not read
`_method`, and OpenRegister registered only `PUT`, `DELETE` and `GET` on the
presence url, so every beacon answered **405**. Seen on cloud.conduction.nl on
every dossiq case page (round-5 cloud check). The closed tab then stayed on
everybody else's screen for a whole presence window.

## What Changes

- `POST /api/objects/{register}/{schema}/{id}/presence` is registered and points
  at `ObjectsController::presenceDepartByBeacon`.
- That method departs only when the request carries `_method=DELETE`, and then
  does exactly what `presenceDepart` does. A POST without the marker answers 400
  and departs nobody.
- CSRF stays on. The client sends the request token in the beacon's form body,
  which Nextcloud's CSRF check reads (`requesttoken` POST field). That client
  half ships in `@conduction/nextcloud-vue`.

## Impact

- `appinfo/routes.php`, `lib/Controller/ObjectsController.php`,
  `tests/Unit/Controller/ObjectsControllerPresenceTest.php`.
- Until apps ship a nextcloud-vue with the token in the beacon, their beacons
  answer 412 (CSRF) instead of 405. Both are best effort: the presence window
  still expires a closed tab.
