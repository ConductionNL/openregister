# Tasks

## 1. Client

- [x] 1.1 `RegisterSchemaCard` mounts the schema-row action menus on the card's first `pointerenter` or `focusin` (`prepareRowActions()`); until then each row shows a stand-in trigger.
- [x] 1.2 Activating a stand-in mounts the menus and opens and focuses that row's menu (`openRowActions()`).

## 2. Verification

- [x] 2.1 Jest: `RegisterSchemaCard.render.spec.js` (stand-ins before interaction, menus after pointer or focus, a stand-in opens its own row's menu).
- [x] 2.2 Live on the sandbox reference stack: Registers total and first content before and after, in the PR body.
