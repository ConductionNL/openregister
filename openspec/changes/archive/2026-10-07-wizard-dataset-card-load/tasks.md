# Tasks: wizard-dataset-card-load

## 1. Manifest

- [x] 1.1 Merge the dataset choice step and its run-action load step into one cards step with `loadAction: load-demo-data`
- [x] 1.2 Raise `@conduction/nextcloud-vue` to `^2.65.0` and validate the manifest against its schema

## 2. Server

- [x] 2.1 Accept `{ dataset }` on `load-demo-data`, refuse an unknown dataset, record the pick after a successful load
- [x] 2.2 Report every manifest step id from `/api/setup/status`
- [x] 2.3 Unit tests: the posted dataset, the refusal, a failed load stores nothing, status ids equal manifest ids
