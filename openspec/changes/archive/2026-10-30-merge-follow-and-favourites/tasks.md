# Tasks: merge-follow-and-favourites

## 1. Storage

- [x] 1.1 `Version1Date20261009130000`: add `notify` to `openregister_watchers`; copy favourites in with `notify` false, existing follows win (D-2). `Version1Date20261009130100`: drop `openregister_favourites`. Remove `ObjectFavourite` and `ObjectFavouriteMapper`.
- [x] 1.2 `Watcher` carries `notify`; `WatcherMapper::subscribe()` takes `notify` (null keeps an existing row), `notifyMapForUser()`, `findByObject(notifyingOnly)`.

## 2. Service and API

- [x] 2.1 `WatcherService`: `watch()` with `notify`, `notifyForCaller()`, `followAssigned()`, `watcherUids()` returns notifying followers only. Unit tests in `WatcherServiceTest`.
- [x] 2.2 `ObjectWatchersController::watch()` reads `notify`; the list omits it. `ObjectFavouriteController` and `FavouriteService` become the deprecated aliases of D-3 with the `Deprecation` header.
- [x] 2.3 `RenderObject`: `@self.watchNotify`, and `@self.favourite` mirrors `@self.watching`.

## 3. Lenses

- [x] 3.1 `_watching=true` and `_favourite=true` resolve to `_watchingFor`; `MagicSearchHandler` applies it as `EXISTS` on `openregister_watchers`. Update `SearchQueryHandlerWatchingLensTest` and `SearchQueryHandlerPersonalLensesTest`.

## 4. Assignment

- [x] 4.1 `AssigneeFollowListener` on created and updated objects (D-6), registered in `Application`. Unit test `AssigneeFollowListenerTest`.

## 5. Close

- [x] 5.1 `ConsistencyCheckService`: `orphan-follows` on `openregister_watchers` (D-7).
- [x] 5.2 Amend `record-star-follow-and-unread-on-screen` to one Follow control and the Following, Recent and Unread filters.
- [x] 5.3 `@spec` tags on every touched method; `openspec validate merge-follow-and-favourites --strict`.
