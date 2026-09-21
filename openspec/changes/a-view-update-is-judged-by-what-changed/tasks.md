# Tasks: a view update is judged by what changed

## 1. The difference

- [ ] 1.1 Add a value comparison to `ViewShareResolver` answering which of the
      judged properties differ between a submitted body and a stored view.
      Order-insensitive for `sharedWith` and for `query` object keys.
- [ ] 1.2 `refusedFields()` takes the changed set rather than the submitted
      set.

## 2. The wiring

- [ ] 2.1 `ViewsController::update()` calls `refuseForbiddenViewFields()` and
      returns its response before any save.
- [ ] 2.2 `refuseForbiddenViewFields()` passes the changed set, not
      `array_intersect_key($data, ...)`.
- [ ] 2.3 Keep the unreadable-view deny and the named-fields refusal message.

## 3. Owner and administrator

- [ ] 3.1 Confirm `mayAdminister()` short-circuits before the difference is
      computed, so an owner's save costs no extra read.

## 4. Tests

- [ ] 4.1 Unit tests for every scenario, including the `EditView.vue` body
      shape with only `query` changed, a reordered `sharedWith`, and a
      pagination key. Doubles use `onlyMethods`.
- [ ] 4.2 `tests/e2e/api-direct/view-update-field-access.spec.ts`, probing
      with a `write` member and then a `read` member, which are the two
      principals that should be refused.
- [ ] 4.3 Mutation-check: make the diff return every submitted field and see
      the ordinary-edit assertion redden, not a setup line.
