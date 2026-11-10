# Tasks: saved-view-presentation-picker

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Cross-repo prerequisite

- [ ] 1.1 nextcloud-vue ships `CnViewPresentationPicker` (design D-1, D-2) in a minor release, and `CnSaveViewDialog` embeds it. Bump `@conduction/nextcloud-vue` in `package.json` to that release. Verify: the component's own test in nextcloud-vue; `npm run build` here.

## 2. Wiring (REQ-VIEW-PRES-06, REQ-VIEW-PRES-07)

- [ ] 2.1 `src/modals/view/EditView.vue`: place `CnViewPresentationPicker` with the view's schema; send `presentation` on save; show a 400 under the named picker (D-4). Verify: `src/modals/view/EditView.spec.js` saves a kanban presentation and renders a refusal.
- [ ] 2.2 `src/sidebars/search/SearchSideBar.vue`: the same picker in the save form; `src/store/modules/views.js` sends `presentation` in create and update. Verify: `src/store/modules/views.spec.js` asserts the payload.
- [ ] 2.3 `src/views/search/SearchIndex.vue`: the header control for the open view (D-3): save on switch for an editor, session-only switch plus "Save as new view" for a reader. Verify: unit test on the switch handler for both cases.

## 3. Tests and docs

- [ ] 3.1 `tests/e2e/ci/saved-view-presentation-picker.spec.ts`: save a board through the form, see columns; switch to calendar, see dated objects; a refused field stays in the modal; a reader's switch leaves the view unchanged.
- [ ] 3.2 `docs/`: "Show a view as a board or a calendar", with the field rules of D-2.
- [ ] 3.3 `openspec validate saved-view-presentation-picker --strict`; `@spec` tags on every touched method; set rows `rec-kanban` and `rec-calendar` to built once 3.1 passes.
