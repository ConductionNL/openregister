# Tasks: flow-tag-object-step

## 1. Tagging handler

- [ ] 1.1 `hasObjectTag()`, colour on create through `updateTag()`, colour on an uncoloured existing tag only, and a no-op remove. Verify: a new `tests/Unit/Service/File/TaggingHandlerTest.php` for each case, including a tag an administrator already coloured.

## 2. Node

- [ ] 2.1 `TagObjectNode` with config validation, target resolution, the acting identity, the `update` check and idempotence. Verify: `tests/Unit/Service/Flow/Nodes/TagObjectNodeTest.php` for add, remove, no-op, missing identity, forbidden object, bad colour, `createIfMissing: false`.
- [ ] 2.2 Register the node and its config form; it appears in `GET /api/flow/node-catalog` for administrators and users. Verify: `FlowNodeRegistryTest` asserts the node in both palettes.

## 3. Tests and docs

- [ ] 3.1 Add `tests/e2e/ci/flow-tag-object.spec.ts`: a flow on lead created with a filter on `value` and a tag step with a colour; create a lead over and one under the threshold; assert only the first carries the tag through `GET /api/objects/{register}/{schema}/{id}/tags`, and that a second run adds nothing.
- [ ] 3.2 Document the step in the flow steps documentation under `docs/`, with the "Large deal" example and a screenshot of the node form.

Acceptance:

- A flow triggered on `tag.assigned` that adds the same tag runs once, not in a loop.
