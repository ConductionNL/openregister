# Tasks: adopt-connection-registry

## 1. Declaration

- [x] 1.1 `lib/Settings/connections.json` with the sixteen connections of design D1.
- [x] 1.2 Stable ids `section-llm` and `section-text-extraction` on the two settings section roots.
- [x] 1.3 `tests/Unit/Settings/ConnectionsDeclarationTest.php`: validates against the vendored integriq schema, app id, unique keys, anchors exist, keys equal `ConnectionReporter::KEYS`, refresh map equals the declared config keys, source templates, no em-dash.

## 2. Reports

- [x] 2.1 `lib/Service/Connection/ConnectionReporter.php`: `report()` and `refreshFromSave()` behind `class_exists`, never throws.
- [x] 2.2 Event stubs under `tests/stubs/Integriq/Event/`, loaded by `tests/bootstrap.php`, listed in `psalm.xml`.
- [x] 2.3 `tests/Unit/Service/Connection/ConnectionReporterTest.php`: class present sends, class absent sends nothing and logs nothing, unknown key or status refused, throwing listener caught.
- [x] 2.4 Callers of design D4 with their unit tests.
- [x] 2.5 `lib/BackgroundJob/ConnectionSeamReportJob.php`, declared in `appinfo/info.xml`, with `tests/Unit/BackgroundJob/ConnectionSeamReportJobTest.php`.

## 3. Page

- [x] 3.1 `src/manifest.json`: page `connections`, menu entry `Connections` with `query` and `visibleIf`; `src/menu-layout.json` settings foldout.
- [x] 3.2 `src/services/connectionFormatters.js` and `src/customComponents.js`, passed to `CnAppRoot` from `src/App.vue`.
- [x] 3.3 The seven new page strings in all 37 catalogues, `l10n/*.js` regenerated.
- [x] 3.4 `src/tests/connections-page.spec.js` (jest) and `tests/e2e/connections-page.spec.ts` (written, not run: it needs integriq with the D12 amendments).

## 4. After integriq ships the D12 amendments

- [ ] 4.1 Run the e2e spec against an instance with both apps, then archive this change.
