# PHPMD Suppressions Requiring Future Refactoring

This document tracks all `@SuppressWarnings` annotations added during the code quality cleanup.
These suppressions allow the code to pass PHPMD checks but indicate areas that should be refactored properly in the future.

## Priority: HIGH - Architectural Refactoring Needed

### Class-Level Complexity Issues

These classes are too large/complex and should be split into smaller, focused classes:

| File | Suppression | Reason | Refactoring Suggestion |
|------|-------------|--------|------------------------|
| `lib/Controller/ObjectsController.php` | ExcessiveClassLength, ExcessiveClassComplexity, TooManyMethods, TooManyPublicMethods, CouplingBetweenObjects | Main API controller with many endpoints | Split into ObjectReadController, ObjectWriteController, ObjectSearchController |
| `lib/Controller/ConfigurationController.php` | ExcessiveClassLength, ExcessiveClassComplexity, TooManyMethods, TooManyPublicMethods, CouplingBetweenObjects | Configuration management controller | Extract ConfigImportController, ConfigExportController |
| `lib/Controller/RegistersController.php` | ExcessiveClassLength, ExcessiveClassComplexity, TooManyPublicMethods, CouplingBetweenObjects | Register management controller | Extract RegisterImportController, RegisterExportController |
| `lib/Controller/SchemasController.php` | ExcessiveClassLength, ExcessiveClassComplexity, TooManyPublicMethods, CouplingBetweenObjects | Schema management controller | Extract SchemaImportController, SchemaValidationController |
| `lib/Controller/SolrController.php` | ExcessiveClassLength, ExcessiveClassComplexity, TooManyPublicMethods | Solr integration controller | Extract SolrSearchController, SolrAdminController |
| `lib/Controller/WebhooksController.php` | ExcessiveClassLength, ExcessiveClassComplexity, TooManyPublicMethods | Webhook management | Extract WebhookExecutionController |

### Method Complexity Issues (NPathComplexity)

These methods have too many execution paths and should be simplified:

| File | Method | Issue | Suggestion |
|------|--------|-------|------------|
| `lib/Controller/ObjectsController.php` | `extractUploadedFiles` | NPathComplexity | Extract file validation to separate method |
| `lib/Controller/ObjectsController.php` | `index` | NPathComplexity | Use query builder pattern |
| `lib/Controller/ObjectsController.php` | `create` | NPathComplexity | Extract validation, file handling, saving to separate methods |
| `lib/Controller/ObjectsController.php` | `update` | NPathComplexity | Extract validation, file handling, saving to separate methods |
| `lib/Controller/RegistersController.php` | `index` | NPathComplexity | Use query builder pattern |
| `lib/Controller/RegistersController.php` | `publishToGitHub` | NPathComplexity | Extract GitHub API calls to GitHubService |
| `lib/Controller/SearchTrailController.php` | Various | NPathComplexity | Use request parameter DTO |

## Priority: MEDIUM - Code Quality Improvements

### Excessive Parameter Lists

These constructors have too many DI parameters. Consider using service aggregators:

| File | Class | Parameters | Suggestion |
|------|-------|------------|------------|
| `lib/Controller/ObjectsController.php` | `__construct` | 16 | Create ObjectsControllerServices aggregate |
| `lib/Controller/RegistersController.php` | `__construct` | 16 | Create RegistersControllerServices aggregate |
| `lib/Controller/SchemasController.php` | `__construct` | 13 | Create SchemasControllerServices aggregate |
| `lib/Controller/ChatController.php` | `__construct` | 11 | Create ChatControllerServices aggregate |
| `lib/Controller/ConversationController.php` | `__construct` | 10 | Create ConversationControllerServices aggregate |
| `lib/Service/Object/SaveObject.php` | `__construct` | Many | Create SaveObjectDependencies aggregate |
| `lib/Service/Object/QueryHandler.php` | `__construct` | Many | Create QueryHandlerDependencies aggregate |

### Static Access

These use static methods which reduces testability:

| File | Class | Static Call | Suggestion |
|------|-------|-------------|------------|
| Various | `Uuid::v4()` | Symfony UUID | Inject UuidGenerator service |
| Various | `Uuid::isValid()` | Symfony UUID | Inject UuidValidator service |
| Various | `DatabaseConstraintException::createFromError()` | Exception factory | Use `new DatabaseConstraintException()` with error wrapping |

## Priority: LOW - Acceptable Suppressions

### Unused Formal Parameters

These are acceptable due to framework requirements:

- Nextcloud IJob `run($argument)` - parameter required by interface
- Route callback parameters - required by routing framework
- Future extension points - parameters reserved for future use

### Boolean Argument Flags

These are acceptable for API flexibility:

- Force flags for override operations
- Toggle parameters for optional features
- Include/exclude flags for data export

## Action Items

1. **Short term**: Create tickets for HIGH priority items
2. **Medium term**: Implement service aggregator pattern for parameter lists
3. **Long term**: Split large controllers into focused controllers

## Tracking

- Date created: 2025-01-05
- Created by: Automated code quality cleanup
- Total suppressions: ~100+
- Files affected: ~50+

## Related Resources

- PHPMD documentation: https://phpmd.org/rules/index.html
- Nextcloud coding guidelines: https://docs.nextcloud.com/server/latest/developer_manual/
