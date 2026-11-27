# Tasks: flow-tag-object-step

## 1. Tagging handler

- [x] 1.1 `hasObjectTag()`, colour on create through `updateTag()`, colour on an uncoloured existing tag only, and a no-op remove. Verify: a new `tests/Unit/Service/File/TaggingHandlerTest.php` for each case, including a tag an administrator already coloured.

## 2. Node

- [x] 2.1 `TagObjectNode` with config validation, target resolution, the acting identity, the `update` check and idempotence. Verify: `tests/Unit/Service/Flow/Nodes/TagObjectNodeTest.php` for add, remove, no-op, missing identity, forbidden object, bad colour, `createIfMissing: false`.
- [x] 2.2 Register the node and its config form; it appears in `GET /api/flow/node-catalog` for administrators and users. Verify: `FlowNodeRegistryTest` asserts the node in both palettes.

## 3. Tests and docs

- [x] 3.1 Add `tests/e2e/ci/flow-tag-object.spec.ts`: a flow on lead created with a filter on `value` and a tag step with a colour; create a lead over and one under the threshold; assert only the first carries the tag through `GET /api/objects/{register}/{schema}/{id}/tags`, and that a second run adds nothing. (live pass, decision 139)
      > 2026-11-25: 2 passed against a sandbox Nextcloud 34 on MariaDB, php -S. The first live run found that the step could not find the object an object trigger hands it (the item has `@self.id`, no `uuid`), so every item failed; `TagObjectNode::targetOf()` now falls back to the envelope id, with three unit tests. With main's `TagObjectNode` the spec fails. "A second run" is the same flow's `object.updated` trigger: the flow runs `sync`, so no worker is needed.
- [x] 3.2 Document the step in the flow steps documentation under `docs/`, with the "Large deal" example and a screenshot of the node form.
      > 2026-11-25: `docs/features/flows.md`, `openregister.tag-object`: which object the step tags (template, item `uuid`, else the object envelope's `@self.id`), the "Large deal" flow, and `flow-tag-object-step-form.png`, taken from the sandbox flow editor. Seen there: the editor draws "Create the tag when it does not exist" switched off when `createIfMissing` is absent, while the step treats an absent key as on.

Acceptance:

- A flow triggered on `tag.assigned` that adds the same tag runs once, not in a loop.
