<?php

/**
 * SchemaImportController — REST surface for importing register schemas from
 * external standards (Schema.org, GGM).
 *
 * Exposes discovery over the bundled snapshots, snapshot version info, the
 * import action (which persists the mapped schema and optionally associates it
 * with a register), and the guarded update-from-source action. Every method is
 * admin-gated by the Nextcloud framework default (no `@NoAdminRequired` /
 * `@PublicPage`): importing or re-importing a schema definition requires the
 * same authority as creating a schema, and the SecurityMiddleware rejects
 * non-admins before the controller runs.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\SchemaImportException;
use OCA\OpenRegister\Service\SchemaImport\ImportedSchema;
use OCA\OpenRegister\Service\SchemaImport\ImportOptions;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for standards-based schema import.
 */
class SchemaImportController extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName        App name.
     * @param IRequest            $request        Request.
     * @param SchemaImportService $importService  Standards import orchestrator.
     * @param SchemaMapper        $schemaMapper   Schema persistence.
     * @param RegisterMapper      $registerMapper Register lookup/association.
     * @param LoggerInterface     $logger         Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly SchemaImportService $importService,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Search importable types/objecttypes for a dialect over the bundled snapshot.
     *
     * @param string $dialect The dialect key (schema.org | ggm).
     *
     * @return JSONResponse The discovery results.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public function types(string $dialect): JSONResponse
    {
        $query = (string) ($this->request->getParam('q', ''));

        try {
            return new JSONResponse($this->importService->discover($dialect, $query));
        } catch (SchemaImportException $e) {
            return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
        }

    }//end types()

    /**
     * The bundled snapshot version info for a dialect.
     *
     * @param string $dialect The dialect key.
     *
     * @return JSONResponse The snapshot info.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public function snapshot(string $dialect): JSONResponse
    {
        try {
            return new JSONResponse($this->importService->snapshotInfo($dialect));
        } catch (SchemaImportException $e) {
            return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
        }

    }//end snapshot()

    /**
     * Import a type/objecttype from a dialect's snapshot, persisting the
     * resulting schema and optionally associating it with a register.
     *
     * @param string $dialect The dialect key (schema.org | ggm).
     *
     * @return JSONResponse The created schema, or a structured error.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public function import(string $dialect): JSONResponse
    {
        $reference = $this->request->getParam('reference');
        if (is_string($reference) === false || $reference === '') {
            return new JSONResponse(['error' => 'A "reference" (type IRI / bare name / objecttype id) is required.'], Http::STATUS_BAD_REQUEST);
        }

        $options = ImportOptions::fromArray($this->request->getParams());

        try {
            $imported = $this->importService->import($dialect, $reference, $options);
        } catch (SchemaImportException $e) {
            return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
        }

        try {
            $schema = $this->persistNewSchema(imported: $imported);
        } catch (\Throwable $e) {
            return $this->failPersist(e: $e);
        }

        $registerId = $options->targetRegister;
        if ($registerId !== null) {
            $this->associateWithRegister(registerId: $registerId, schemaId: (int) $schema->getId());
        }

        return new JSONResponse(
            [
                'schema'           => $schema->jsonSerialize(),
                'unknownRequested' => $imported->unknownRequested,
            ],
            Http::STATUS_CREATED
        );

    }//end import()

    /**
     * Update an imported schema from its recorded source (three-way merge).
     *
     * Defaults to a non-mutating preview; pass `apply: true` to persist. When
     * conflicts exist they must be confirmed per-property via
     * `resolveConflicts: ["propName", ...]` or the apply is refused.
     *
     * @param int $id The schema id.
     *
     * @return JSONResponse The classified diff (preview) or the updated schema.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     *
     * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
     */
    public function reimport(int $id): JSONResponse
    {
        try {
            $schema = $this->schemaMapper->find($id);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Schema not found'], Http::STATUS_NOT_FOUND);
        }

        $configuration = ($schema->getConfiguration() ?? []);
        $importSource  = ($configuration['importSource'] ?? null);
        if (is_array($importSource) === false) {
            return new JSONResponse(
                ['error' => 'This schema was not imported from a standard; nothing to update from.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $resolveConflicts = $this->request->getParam('resolveConflicts', []);
        if (is_array($resolveConflicts) === false) {
            $resolveConflicts = [];
        }

        $resolveConflicts = array_values(array_filter($resolveConflicts, 'is_string'));

        try {
            $diff = $this->importService->previewUpdateFromSource(
                importSource: $importSource,
                currentProperties: $schema->getProperties(),
                resolvedConflicts: $resolveConflicts
            );
        } catch (SchemaImportException $e) {
            return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
        }

        $apply = filter_var($this->request->getParam('apply', false), FILTER_VALIDATE_BOOLEAN);
        if ($apply === false) {
            return new JSONResponse($this->previewPayload(diff: $diff));
        }

        if ($diff['applied'] === false) {
            return new JSONResponse(
                [
                    'error'     => 'Unresolved conflicts. Confirm each conflicting property via "resolveConflicts" before applying.',
                    'conflicts' => $diff['conflicts'],
                ],
                Http::STATUS_CONFLICT
            );
        }

        try {
            $schema = $this->applyMerge(schema: $schema, diff: $diff);
        } catch (\Throwable $e) {
            return $this->failPersist(e: $e);
        }

        return new JSONResponse(
            [
                'schema'  => $schema->jsonSerialize(),
                'applied' => $this->previewPayload(diff: $diff),
            ]
        );

    }//end reimport()

    /**
     * Persist a freshly-imported schema.
     *
     * @param ImportedSchema $imported The mapped schema.
     *
     * @return Schema The persisted schema entity.
     */
    private function persistNewSchema(ImportedSchema $imported): Schema
    {
        $payload = $imported->toSchemaArray();

        $schema = new Schema();
        $schema->hydrate($payload);

        return $this->schemaMapper->insert($schema);

    }//end persistNewSchema()

    /**
     * Apply a confirmed merge to an imported schema and persist it.
     *
     * @param Schema               $schema The schema.
     * @param array<string, mixed> $diff   The merge result.
     *
     * @return Schema The updated schema entity.
     */
    private function applyMerge(Schema $schema, array $diff): Schema
    {
        $schema->setProperties($diff['merged']);

        // Refresh the import baseline + jsonld block from the new source so the
        // next update-from-source diffs against what was actually applied.
        $configuration = ($schema->getConfiguration() ?? []);
        if (isset($diff['incomingSource']) === true && is_array($diff['incomingSource']) === true) {
            $incomingSource = $diff['incomingSource'];
            $incomingSource['baseline']    = $diff['merged'];
            $configuration['importSource'] = $incomingSource;
        }

        if (isset($diff['jsonld']) === true && is_array($diff['jsonld']) === true && $diff['jsonld'] !== []) {
            $configuration['jsonld'] = $diff['jsonld'];
        }

        $schema->setConfiguration($configuration);

        return $this->schemaMapper->update($schema);

    }//end applyMerge()

    /**
     * Associate a newly-imported schema with a target register (best-effort).
     *
     * @param int $registerId The register id.
     * @param int $schemaId   The schema id.
     *
     * @return void
     */
    private function associateWithRegister(int $registerId, int $schemaId): void
    {
        try {
            $register = $this->registerMapper->find($registerId);
            if (($register instanceof Register) === false) {
                return;
            }

            $schemas = $register->getSchemas();
            if (in_array($schemaId, $schemas, false) === false) {
                $schemas[] = $schemaId;
                $register->setSchemas($schemas);
                $this->registerMapper->update($register);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[SchemaImportController] Could not associate imported schema with register',
                ['register' => $registerId, 'schema' => $schemaId, 'error' => $e->getMessage()]
            );
        }

    }//end associateWithRegister()

    /**
     * Shape the merge result for an API response (drops the merged map).
     *
     * @param array<string, mixed> $diff The merge result.
     *
     * @return array<string, mixed> The response-safe diff summary.
     */
    private function previewPayload(array $diff): array
    {
        return [
            'added'     => $diff['added'],
            'removed'   => $diff['removed'],
            'changed'   => $diff['changed'],
            'keptLocal' => $diff['keptLocal'],
            'conflicts' => $diff['conflicts'],
            'applied'   => $diff['applied'],
        ];

    }//end previewPayload()

    /**
     * Log and map a persistence failure to a 500 response.
     *
     * @param \Throwable $e The failure.
     *
     * @return JSONResponse The error response.
     */
    private function failPersist(\Throwable $e): JSONResponse
    {
        $this->logger->error(
            '[SchemaImportController] Failed to persist imported schema',
            ['error' => $e->getMessage()]
        );
        return new JSONResponse(['error' => 'Failed to persist the imported schema: '.$e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);

    }//end failPersist()
}//end class
