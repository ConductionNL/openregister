## 1. Route and controller

- [x] 1.1 Register `POST .../presence` as `objects#presenceDepartByBeacon`
- [x] 1.2 `presenceDepartByBeacon` departs on `_method=DELETE`, answers 400 otherwise, keeps CSRF

## 2. Tests

- [x] 2.1 A marked POST departs like the DELETE
- [x] 2.2 An unmarked POST is refused and departs nobody
- [x] 2.3 The POST route is registered and points at a method that exists (fails on the old code)
