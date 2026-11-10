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

use OCA\OpenRegister\Controller\Support\SchemaImportControllerServices;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\SchemaImportException;
use OCA\OpenRegister\Service\Schema\IncomingDefinition;
use OCA\OpenRegister\Service\Schema\SchemaChangeRecord;
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\SchemaImport\SourceUpdate;
use OCA\OpenRegister\Service\SchemaImport\ImportedSchemaInterface;
use OCA\OpenRegister\Service\SchemaImport\ImportOptions;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for standards-based schema import.
 */
class SchemaImportController extends Controller {
	/**
	 * Standards import orchestrator.
	 *
	 * @var SchemaImportServiceInterface
	 */
	private readonly SchemaImportServiceInterface $importService;

	/**
	 * Schema persistence.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Register lookup/association.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Classifies, versions and logs a merged definition change (#4102).
	 *
	 * @var ?SchemaVersioningService
	 */
	private readonly ?SchemaVersioningService $schemaVersioning;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param SchemaImportControllerServices $services The schema import controller services.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		SchemaImportControllerServices $services,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->importService = $services->importService;
		$this->schemaMapper = $services->schemaMapper;
		$this->registerMapper = $services->registerMapper;
		$this->schemaVersioning = $services->schemaVersioning;

	}//end __construct()

	/**
	 * Search importable types/objecttypes for a dialect over the bundled snapshot.
	 *
	 * @param string $dialect The dialect key (schema.org | ggm).
	 *
	 * @return JSONResponse The discovery results.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function types(string $dialect): JSONResponse {
		$query = (string)($this->request->getParam('q', ''));

		try {
			return new JSONResponse($this->importService->discover($dialect, $query));
		} catch (SchemaImportException $e) {
			return $this->importFailure(e: $e);
		}

	}//end types()

	/**
	 * The bundled snapshot version info for a dialect.
	 *
	 * @param string $dialect The dialect key.
	 *
	 * @return JSONResponse The snapshot info.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function snapshot(string $dialect): JSONResponse {
		try {
			return new JSONResponse($this->importService->snapshotInfo($dialect));
		} catch (SchemaImportException $e) {
			return $this->importFailure(e: $e);
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
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function import(string $dialect): JSONResponse {
		$reference = $this->request->getParam('reference');
		if (is_string($reference) === false || $reference === '') {
			return new JSONResponse(['error' => 'A "reference" (type IRI / bare name / objecttype id) is required.'], Http::STATUS_BAD_REQUEST);
		}

		$options = ImportOptions::fromArray($this->request->getParams());

		try {
			$imported = $this->importService->import($dialect, $reference, $options);
		} catch (SchemaImportException $e) {
			return $this->importFailure(e: $e);
		}

		return $this->persistImported(imported: $imported, registerId: $options->targetRegister);

	}//end import()

	/**
	 * Persist an imported schema, associate it with the target register, and answer 201.
	 *
	 * @param ImportedSchemaInterface $imported The mapped schema.
	 * @param int|null $registerId The register to associate it with, or null.
	 *
	 * @return JSONResponse The created schema, or the 500.
	 */
	private function persistImported(ImportedSchemaInterface $imported, ?int $registerId): JSONResponse {
		try {
			$schema = $this->persistNewSchema(imported: $imported);
		} catch (\Throwable $e) {
			return $this->failPersist(e: $e);
		}

		if ($registerId !== null) {
			$this->associateWithRegister(registerId: $registerId, schemaId: (int)$schema->getId());
		}

		return new JSONResponse(['schema' => $schema->jsonSerialize(), 'unknownRequested' => $imported->unknownRequested()], Http::STATUS_CREATED);
	}//end persistImported()

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
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 */
	public function reimport(int $id): JSONResponse {
		$schema = $this->importedSchema(id: $id);
		if ($schema instanceof JSONResponse) {
			return $schema;
		}

		$diff = $this->sourceDiff(schema: $schema);
		if ($diff instanceof JSONResponse) {
			return $diff;
		}

		$apply = filter_var($this->request->getParam('apply', false), FILTER_VALIDATE_BOOLEAN);
		if ($apply === false) {
			return new JSONResponse($this->previewPayload(diff: $diff));
		}

		return $this->applyConfirmed(schema: $schema, diff: $diff);

	}//end reimport()

	/**
	 * The schema, when it exists and was imported from a standard.
	 *
	 * @param int $id The schema id.
	 *
	 * @return Schema|JSONResponse The schema, or a 404/422 refusal.
	 */
	private function importedSchema(int $id): Schema|JSONResponse {
		try {
			$schema = $this->schemaMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Schema not found'], Http::STATUS_NOT_FOUND);
		}

		if (is_array($this->importSourceOf(schema: $schema)) === false) {
			return new JSONResponse(
				['error' => 'This schema was not imported from a standard; nothing to update from.'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return $schema;
	}//end importedSchema()

	/**
	 * The recorded import source of a schema, if any.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return mixed The `importSource` configuration entry, or null.
	 */
	private function importSourceOf(Schema $schema): mixed {
		return (($schema->getConfiguration() ?? [])['importSource'] ?? null);
	}//end importSourceOf()

	/**
	 * The three-way merge of the schema with its source, honouring the confirmed conflicts.
	 *
	 * @param Schema $schema The imported schema.
	 *
	 * @return array<string, mixed>|JSONResponse The merge result, or the import failure.
	 */
	private function sourceDiff(Schema $schema): array|JSONResponse {
		$resolveConflicts = $this->request->getParam('resolveConflicts', []);
		if (is_array($resolveConflicts) === false) {
			$resolveConflicts = [];
		}

		try {
			return $this->importService->previewUpdateFromSource(
				update: new SourceUpdate(
					importSource: $this->importSourceOf(schema: $schema),
					currentProperties: $schema->getProperties(),
					resolvedConflicts: array_values(array_filter($resolveConflicts, 'is_string'))
				)
			);
		} catch (SchemaImportException $e) {
			return $this->importFailure(e: $e);
		}
	}//end sourceDiff()

	/**
	 * Apply a merge whose conflicts were all confirmed, or refuse it.
	 *
	 * @param Schema $schema The schema.
	 * @param array<string, mixed> $diff The merge result.
	 *
	 * @return JSONResponse The updated schema, a 409 for unresolved conflicts, or the 500.
	 */
	private function applyConfirmed(Schema $schema, array $diff): JSONResponse {
		if ($diff['applied'] === false) {
			return new JSONResponse(
				['error' => 'Unresolved conflicts. Confirm each conflicting property via "resolveConflicts" before applying.', 'conflicts' => $diff['conflicts']],
				Http::STATUS_CONFLICT
			);
		}

		try {
			$schema = $this->applyMerge(schema: $schema, diff: $diff);
		} catch (\Throwable $e) {
			return $this->failPersist(e: $e);
		}

		return new JSONResponse(['schema' => $schema->jsonSerialize(), 'applied' => $this->previewPayload(diff: $diff)]);
	}//end applyConfirmed()

	/**
	 * Persist a freshly-imported schema.
	 *
	 * @param ImportedSchemaInterface $imported The mapped schema.
	 *
	 * @return Schema The persisted schema entity.
	 */
	private function persistNewSchema(ImportedSchemaInterface $imported): Schema {
		// Use the canonical creation path so the schema gets its uuid, slug,
		// version, facet configuration and delta extraction. Hand-rolling
		// hydrate()+insert() bypasses cleanObject() and inserts a NULL uuid,
		// which the NOT NULL constraint on oc_openregister_schemas rejects.
		return $this->schemaMapper->createFromArray($imported->toSchemaArray());
	}//end persistNewSchema()

	/**
	 * Apply a confirmed merge to an imported schema and persist it.
	 *
	 * The caller confirmed each conflict, but the merge still changes the
	 * definition, so it is classified, versioned and recorded as every
	 * definition update is (#4102). Classified before the properties move.
	 *
	 * @param Schema $schema The schema.
	 * @param array<string, mixed> $diff The merge result.
	 *
	 * @return Schema The updated schema entity.
	 */
	private function applyMerge(Schema $schema, array $diff): Schema {
		$changeSet = $this->classifyMerge(schema: $schema, merged: $diff['merged']);

		$schema->setProperties($diff['merged']);
		$schema->setConfiguration($this->refreshedConfiguration(configuration: ($schema->getConfiguration() ?? []), diff: $diff));

		$schema = $this->schemaMapper->update($schema);
		if ($changeSet !== null) {
			$this->recordMerge(schema: $schema, changeSet: $changeSet);
		}

		return $schema;
	}//end applyMerge()

	/**
	 * Record a persisted source merge in the schema changelog.
	 *
	 * @param Schema $schema The persisted schema.
	 * @param SchemaChangeSet $changeSet The classified change set.
	 *
	 * @return void
	 */
	private function recordMerge(Schema $schema, SchemaChangeSet $changeSet): void {
		$this->schemaVersioning?->recordChange(
			schemaId: (int)$schema->getId(),
			record: new SchemaChangeRecord(changeSet: $changeSet, acknowledged: false, version: $schema->getVersion(), origin: 'source merge')
		);
	}//end recordMerge()

	/**
	 * Classify the merged definition and bump the schema's version when it changes anything.
	 *
	 * @param Schema $schema The schema, still holding its current definition.
	 * @param array<string, mixed> $merged The merged properties.
	 *
	 * @return SchemaChangeSet|null The change set, or null without versioning.
	 */
	private function classifyMerge(Schema $schema, array $merged): ?SchemaChangeSet {
		$changeSet = $this->schemaVersioning?->classify(
			existing: $schema,
			incoming: new IncomingDefinition(definition: ['properties' => $merged, 'required' => ($schema->getRequired() ?? [])])
		);
		if ($changeSet !== null && $changeSet->hasChanges() === true) {
			$schema->setVersion($this->schemaVersioning->nextVersion(existing: $schema, changeSet: $changeSet));
		}

		return $changeSet;
	}//end classifyMerge()

	/**
	 * Refresh the import baseline + jsonld block from the new source.
	 *
	 * So the next update-from-source diffs against what was actually applied.
	 *
	 * @param array<string, mixed> $configuration The schema's configuration.
	 * @param array<string, mixed> $diff The merge result.
	 *
	 * @return array<string, mixed> The refreshed configuration.
	 */
	private function refreshedConfiguration(array $configuration, array $diff): array {
		$incomingSource = $this->arrayAt(diff: $diff, key: 'incomingSource');
		if ($incomingSource !== null) {
			$incomingSource['baseline'] = $diff['merged'];
			$configuration['importSource'] = $incomingSource;
		}

		$jsonld = $this->arrayAt(diff: $diff, key: 'jsonld');
		if ($jsonld !== null && $jsonld !== []) {
			$configuration['jsonld'] = $jsonld;
		}

		return $configuration;
	}//end refreshedConfiguration()

	/**
	 * An array entry of the merge result, or null when it is absent or not an array.
	 *
	 * @param array<string, mixed> $diff The merge result.
	 * @param string $key The entry.
	 *
	 * @return array<string, mixed>|null The entry, or null.
	 */
	private function arrayAt(array $diff, string $key): ?array {
		if (isset($diff[$key]) === true && is_array($diff[$key]) === true) {
			return $diff[$key];
		}

		return null;
	}//end arrayAt()

	/**
	 * Associate a newly-imported schema with a target register (best-effort).
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId The schema id.
	 *
	 * @return void
	 */
	private function associateWithRegister(int $registerId, int $schemaId): void {
		try {
			$this->addSchemaTo(register: $this->registerMapper->find($registerId), schemaId: $schemaId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[SchemaImportController] Could not associate imported schema with register',
				['register' => $registerId, 'schema' => $schemaId, 'error' => $e->getMessage()]
			);
		}

	}//end associateWithRegister()

	/**
	 * Add a schema to a register's schema list, when it is not on it yet.
	 *
	 * @param Register $register The register.
	 * @param int $schemaId The schema id.
	 *
	 * @return void
	 */
	private function addSchemaTo(Register $register, int $schemaId): void {
		$schemas = $register->getSchemas();
		if (in_array($schemaId, $schemas, false) === false) {
			$schemas[] = $schemaId;
			$register->setSchemas($schemas);
			$this->registerMapper->update($register);
		}
	}//end addSchemaTo()

	/**
	 * Shape the merge result for an API response (drops the merged map).
	 *
	 * @param array<string, mixed> $diff The merge result.
	 *
	 * @return array<string, mixed> The response-safe diff summary.
	 */
	private function previewPayload(array $diff): array {
		return [
			'added' => $diff['added'],
			'removed' => $diff['removed'],
			'changed' => $diff['changed'],
			'keptLocal' => $diff['keptLocal'],
			'conflicts' => $diff['conflicts'],
			'applied' => $diff['applied'],
		];

	}//end previewPayload()

	/**
	 * Log and map a persistence failure to a 500 response.
	 *
	 * @param \Throwable $e The failure.
	 *
	 * @return JSONResponse The error response.
	 */
	private function failPersist(\Throwable $e): JSONResponse {
		$this->logger->error(
			'[SchemaImportController] Failed to persist imported schema',
			['error' => $e->getMessage()]
		);
		return new JSONResponse(['error' => 'Failed to persist the imported schema: ' . $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failPersist()

	/**
	 * Map a standards-import failure onto its HTTP status.
	 *
	 * @param SchemaImportException $e The failure.
	 *
	 * @return JSONResponse The error response.
	 */
	private function importFailure(SchemaImportException $e): JSONResponse {
		return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
	}//end importFailure()
}//end class
