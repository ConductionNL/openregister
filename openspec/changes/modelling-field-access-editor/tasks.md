# Tasks: modelling-field-access-editor

## 1. Component

- [ ] 1.1 nextcloud-vue `CnPropertyAccessEditor`: group table with Read and Change, `public` and `authenticated` rows, read-only display of rules that carry `match`. Verify: component test in nextcloud-vue.
- [ ] 1.2 Required-field warning computed from schema authorization and the property rule. Verify: component test with a required field a creating group may not change.

## 2. Open Register

- [ ] 2.1 Section "Who may see and change this field" in `EditSchemaProperty.vue` using the component, writing the `authorization` block. Verify: `tests/e2e/field-access-editor.spec.ts` hides a field from a group and a user of that group no longer sees it in the object detail.
- [ ] 2.2 `@self.readOnlyProperties` in the object render for the current user, and a typed exception in `SaveObject` (today a plain `Exception` at `lib/Service/Object/SaveObject.php:3222`) that the controller answers as 403 naming the properties. Verify: render unit test, and API test that a PATCH of a listed property answers 403 naming it.
- [ ] 2.3 Object form disables inputs listed in `@self.readOnlyProperties`. Verify: same e2e locks a field for a group and sees it disabled.

## 3. Consumers and docs

- [ ] 3.1 buildiq issue to embed the component in its field editor (buildiq change, linked here).
- [ ] 3.2 `docs/` section on field access with a salary example.

Acceptance:
- A property without `authorization` renders, saves and reads exactly as before.
- The API refuses a forbidden read or write whether or not the UI shows the field.
