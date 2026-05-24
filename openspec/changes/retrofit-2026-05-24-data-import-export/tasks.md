# Tasks: retrofit-2026-05-24-data-import-export

## 1. Reverse-spec coverage

- [x] task-1 — REQ-018 "Import templates MUST be downloadable as empty header-only files per schema": annotate `ExportService::buildTemplateSpreadsheet`, `ExportService::buildTemplateCsv`.
- [x] task-2 — REQ-019 "The system MUST expose a per-user personal data export endpoint (GDPR Art. 20)": annotate `UserController::exportData`, `UserService::exportPersonalData`, `ExportSection.vue::exportData`.
- [x] task-3 — REQ-020 "Frontend file-type sniffing MUST route uploads to the correct importer by extension": annotate `ImportRegister.vue::getFileExtension`.
- [x] task-4 — REQ-021 "Configuration import-from-source MUST pre-flight check API token availability": annotate `ImportConfiguration.vue::checkTokenAvailability`.
- [x] task-5 — REQ-022 "Import and export modals MUST reset all form state on close": annotate `ImportConfiguration.vue::closeModal`, `ExportConfiguration.vue::closeModal`, `ExportRegister.vue::closeModal`, `ImportRegister.vue::closeModal`, `ImportService::clearCaches`.
