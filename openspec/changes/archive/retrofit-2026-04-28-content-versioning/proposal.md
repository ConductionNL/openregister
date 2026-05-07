# Retrofit — content-versioning

Describes observed behavior of 4 methods under `content-versioning` as 1 new REQ. Code already exists — this change retroactively specifies it.

## Affected code units
- lib/Service/File/FileVersioningHandler.php::listVersions
- lib/Service/File/FileVersioningHandler.php::restoreVersion
- lib/Service/File/FileVersioningHandler.php::isVersioningEnabled
- lib/Service/File/FileVersioningHandler.php::getCurrentUserId (private)

## Approach
- `FileVersioningHandler` provides a wrapper around Nextcloud's `files_versions` app to list and restore file attachment versions. The existing content-versioning spec covers *object* versioning via audit trail (semantic versioning, drafts, rollback) but does not specify file attachment versioning via NC's `IVersionManager`. This change adds REQ-017 to cover that observed behavior.
- 2 methods (`ExecutionHistoryCleanupJob::run` and `MessageHistoryHandler`) appeared in the content-versioning Bucket 2a cluster but are scanner misclassifications: ExecutionHistoryCleanupJob prunes WorkflowExecution records (workflow-engine-abstraction capability) and MessageHistoryHandler manages LLM chat conversation history (no current spec). These are deferred to their respective capability clusters.
- Vue component methods are skipped — @spec convention not established for JS/Vue files.

Source: openspec/coverage-report.md generated 2026-04-23. See retrofit playbook.
