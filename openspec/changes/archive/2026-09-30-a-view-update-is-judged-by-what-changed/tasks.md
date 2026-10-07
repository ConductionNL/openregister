# Tasks: a view update is judged by what changed

## 1. The difference

- [x] 1.1 Add a value comparison to `ViewShareResolver` answering which of the
      judged properties differ between a submitted body and a stored view.
      Order-insensitive for `sharedWith` and for `query` object keys.
- [x] 1.2 `refusedFields()` takes the changed set rather than the submitted
      set.

## 2. The wiring

- [x] 2.1 `ViewsController::update()` calls `refuseForbiddenViewFields()` and
      returns its response before any save.
- [x] 2.2 `refuseForbiddenViewFields()` passes the changed set, not
      `array_intersect_key($data, ...)`.
- [x] 2.3 Keep the unreadable-view deny and the named-fields refusal message.

## 3. Owner and administrator

- [x] 3.1 Confirm `mayAdminister()` short-circuits before the difference is
      computed, so an owner's save costs no extra read.

## 4. Tests

- [x] 4.1 Unit tests for every scenario, including the `EditView.vue` body
      shape with only `query` changed, a reordered `sharedWith`, and a
      pagination key. Doubles use `onlyMethods`.
- [x] 4.2 Replaced (30 Sep, build-all lane 5): the probe with a `write` and a
      `read` member runs in `tests/Unit/Controller/ViewUpdateJudgedByChangeTest.php`
      over the REAL controller, ViewService, reach resolver and share resolver
      (only the mapper and Nextcloud's session and groups are doubles). An
      api-direct Playwright file was not written: this clone has no instance
      to run it against, and a collection never executed claims coverage it
      does not give. It belongs with the live-instance sweep.
- [x] 4.3 Mutation-check: make the diff return every submitted field and see
      the ordinary-edit assertion redden, not a setup line. Done 30 Sep:
      5 of 8 tests red on their status or field assertions.

## 5. What the code at HEAD needed besides (30 Sep)

- [x] 5.1 The update and patch paths looked the view up as the caller's OWN
      (`ViewService::find(id, owner)`), so a `write` member got 404 before the
      guard ran. They now resolve it through `requireReachableView()`
      (owned, shared with the caller's group, public, or an administrator) and
      save it under the view's own owner.
