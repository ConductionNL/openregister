# Design: workflow-in-import

## Architecture Overview

OpenRegister's `ImportService` currently processes JSON import files in two phases: schemas, then objects. This change adds a workflow phase between them, creating a four-phase pipeline:

```
Import JSON file
       |
   1. Schemas     ── create/update schemas via SchemaMapper
       |
   2. Workflows   ── deploy to engines via WorkflowEngineInterface  [NEW]
       |
   3. Hooks       ── wire attachTo config to schema hooks            [NEW]
       |
   4. Objects     ── create objects (hooks now active)
```

The workflow phase uses the `WorkflowEngineInterface` (from the `workflow-engine-abstraction` change) to deploy definitions to n8n, Windmill, or any registered engine. The hook phase uses the Schema Hooks API (from the `schema-hooks` change) to register the deployed workflows as event handlers on schemas.

## DeployedWorkflow Entity

A new entity tracks each workflow that has been deployed through the import system:

```php
class DeployedWorkflow extends Entity {
    protected string $name;              // Human-readable name from import
    protected string $engine;            // Engine identifier: "n8n", "windmill"
    protected string $engineWorkflowId;  // ID returned by the engine after deploy
    protected string $sourceHash;        // SHA-256 of json_encode($workflowDefinition)
    protected ?string $attachedSchema;   // Schema slug or UUID (null if no attachTo)
    protected ?string $attachedEvent;    // Hook event: "creating", "created", etc.
    protected string $importSource;      // Filename or identifier of the import
    protected int $version;              // Incremented on each update (starts at 1)
    protected ?string $uuid;             // UUID for external reference
}
```

The corresponding `DeployedWorkflowMapper` extends Nextcloud's `QBMapper` and provides:

- `findByNameAndEngine(string $name, string $engine): ?DeployedWorkflow` -- for re-import matching
- `findBySchema(string $schemaSlug): array` -- for export (find all workflows attached to a schema)
- `findByImportSource(string $source): array` -- for cleanup of an entire import

## Import Processing Flow

### Phase 2: Workflow Deployment

```php
foreach ($importData['workflows'] as $workflowEntry) {
    $hash = hash('sha256', json_encode($workflowEntry['workflow']));
    $existing = $deployedWorkflowMapper->findByNameAndEngine(
        $workflowEntry['name'],
        $workflowEntry['engine']
    );

    if ($existing !== null && $existing->getSourceHash() === $hash) {
        // Unchanged — skip deployment
        $summary['workflows']['unchanged'][] = $workflowEntry['name'];
        continue;
    }

    $engine = $workflowEngineManager->getEngine($workflowEntry['engine']);
    if ($engine === null) {
        $summary['workflows']['failed'][] = [
            'name' => $workflowEntry['name'],
            'engine' => $workflowEntry['engine'],
            'error' => 'Engine not available',
        ];
        continue;
    }

    try {
        if ($existing !== null) {
            // Update existing workflow
            $engine->updateWorkflow($existing->getEngineWorkflowId(), $workflowEntry['workflow']);
            $existing->setSourceHash($hash);
            $existing->setVersion($existing->getVersion() + 1);
            $deployedWorkflowMapper->update($existing);
            $summary['workflows']['updated'][] = [...];
        } else {
            // Deploy new workflow
            $engineId = $engine->deployWorkflow($workflowEntry['workflow']);
            $deployed = new DeployedWorkflow();
            $deployed->setName($workflowEntry['name']);
            $deployed->setEngine($workflowEntry['engine']);
            $deployed->setEngineWorkflowId($engineId);
            $deployed->setSourceHash($hash);
            $deployed->setImportSource($importSource);
            $deployed->setVersion(1);
            $deployedWorkflowMapper->insert($deployed);
            $summary['workflows']['deployed'][] = [...];
        }
        $deployedWorkflows[$workflowEntry['name']] = $deployed ?? $existing;
    } catch (\Exception $e) {
        $summary['workflows']['failed'][] = [...];
    }
}
```

### Phase 3: Hook Wiring

```php
foreach ($importData['workflows'] as $workflowEntry) {
    if (!isset($workflowEntry['attachTo'])) {
        continue;
    }

    $attachTo = $workflowEntry['attachTo'];
    $schema = $schemaMapper->findBySlug($attachTo['schema']);

    if ($schema === null) {
        $warnings[] = "Cannot attach '{$workflowEntry['name']}' — schema '{$attachTo['schema']}' not found";
        continue;
    }

    $deployed = $deployedWorkflows[$workflowEntry['name']] ?? null;
    if ($deployed === null) {
        continue; // Deployment failed — skip hook wiring
    }

    $hookService->registerHook(
        schema: $schema,
        event: $attachTo['event'],
        mode: $attachTo['mode'] ?? 'async',
        engine: $workflowEntry['engine'],
        workflowId: $deployed->getEngineWorkflowId(),
        order: $attachTo['order'] ?? 0,
        timeout: $attachTo['timeout'] ?? 30,
        onFailure: $attachTo['onFailure'] ?? 'allow',
        onTimeout: $attachTo['onTimeout'] ?? 'allow',
        onEngineDown: $attachTo['onEngineDown'] ?? 'allow',
    );

    $deployed->setAttachedSchema($attachTo['schema']);
    $deployed->setAttachedEvent($attachTo['event']);
    $deployedWorkflowMapper->update($deployed);
}
```

## Hash-Based Idempotency

The source hash is computed from the canonical JSON encoding of the `workflow` field (the engine-native definition). This means:

- Changing `name`, `description`, or `attachTo` without changing the workflow definition does NOT trigger a re-deploy to the engine (the engine definition is unchanged)
- Changing `attachTo` DOES update the hook wiring even if the workflow is not re-deployed
- The hash comparison happens before any engine calls, keeping re-imports fast

On re-import with a changed `attachTo` but unchanged workflow definition:
1. Hash matches -- workflow is not re-deployed
2. `attachTo` is compared -- if different, the old hook is removed and the new one is registered
3. `DeployedWorkflow` record is updated with new schema/event

## Export: Fetching Workflow Definitions

When exporting a schema, the export service queries `DeployedWorkflowMapper::findBySchema()` to find all workflows attached to that schema. For each, it fetches the full definition from the engine:

```php
$deployedWorkflows = $deployedWorkflowMapper->findBySchema($schema->getSlug());
$exportWorkflows = [];

foreach ($deployedWorkflows as $deployed) {
    $engine = $workflowEngineManager->getEngine($deployed->getEngine());
    $definition = $engine->getWorkflow($deployed->getEngineWorkflowId());

    $exportWorkflows[] = [
        'name' => $deployed->getName(),
        'engine' => $deployed->getEngine(),
        'workflow' => $definition,
        'attachTo' => [
            'schema' => $deployed->getAttachedSchema(),
            'event' => $deployed->getAttachedEvent(),
        ],
    ];
}
```

This enables round-trip: export produces a valid import file that can be re-imported idempotently.

## File Structure Changes

```
openregister/lib/
  Db/
    DeployedWorkflow.php           [NEW] — Entity class
    DeployedWorkflowMapper.php     [NEW] — QBMapper with find methods
  Migration/
    VersionXXXXDate.php            [NEW] — Creates oc_openregister_deployed_workflows table
  Service/
    ImportService.php              [MODIFIED] — Add workflow + hook phases
    ExportService.php              [MODIFIED] — Include workflows in export
```

## Decisions

### 1. Match workflows by name + engine (not UUID)

**Chosen**: Re-import matching uses `(name, engine)` as the composite key.

**Alternative**: Generate a UUID for each workflow and require it in the import file.

**Rationale**: Import files are human-authored. Requiring UUIDs adds friction and makes files less readable. The `(name, engine)` pair is naturally unique within an import context and matches how users think about their workflows.

### 2. Non-fatal workflow failures

**Chosen**: A failed workflow deployment logs an error and continues with the remaining import.

**Alternative**: Fail the entire import on any workflow error.

**Rationale**: Partial success is more useful than total failure. Schemas and objects may be valid even if a workflow engine is temporarily down. The import summary clearly reports what succeeded and what failed.

### 3. Separate hook wiring phase

**Chosen**: Hooks are wired in a separate phase after all workflows are deployed.

**Alternative**: Wire each hook immediately after its workflow is deployed.

**Rationale**: Separating the phases keeps the code cleaner and allows future optimizations (e.g., batch hook registration). It also means all workflows are available before any hooks are wired, which could matter if hooks reference other workflows.

## Trade-offs

| Risk | Mitigation |
|------|-----------|
| Engine is down during import -- workflows fail | Non-fatal; re-import later picks up where it left off via hash comparison |
| Import file grows large with embedded workflow definitions | Workflow definitions are typically 1-5 KB; import files are already large with objects |
| Hash comparison uses JSON encoding which may not be stable across PHP versions | Use `json_encode` with `JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE` for consistency |
| Exported workflow definitions may differ from original import (engine normalisation) | Hash will differ on re-import, triggering an update -- acceptable since the engine has the canonical version |
