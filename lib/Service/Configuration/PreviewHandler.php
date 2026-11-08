<?php

/**
 * OpenRegister Preview Handler
 *
 * This file contains the handler class for previewing configuration changes
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Configuration;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

/**
 * Class PreviewHandler
 *
 * Handles previewing configuration changes before import.
 * Provides methods to compare current vs. proposed configurations
 * and preview the impact of importing configurations.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class PreviewHandler {

	/**
	 * Register mapper for database operations.
	 *
	 * @var RegisterMapperInterface The register mapper instance.
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema mapper for database operations.
	 *
	 * @var SchemaMapperInterface The schema mapper instance.
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Logger for logging operations.
	 *
	 * @var LoggerInterface The logger interface.
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Fetch handler for fetching remote configuration data.
	 *
	 * @var FetchHandler The fetch handler.
	 */
	private readonly FetchHandler $fetchHandler;

	/**
	 * Object mapper, to find the local object a remote one would update.
	 *
	 * @var MagicMapperInterface The object mapper.
	 */
	private readonly MagicMapperInterface $objectMapper;

	/**
	 * The component sections every preview starts with, in output order.
	 *
	 * @var array<string, array>
	 */
	private const EMPTY_PREVIEW = [
		'registers' => [],
		'schemas' => [],
		'objects' => [],
		'endpoints' => [],
		'sources' => [],
		'mappings' => [],
		'jobs' => [],
		'synchronizations' => [],
		'rules' => [],
	];

	/**
	 * Constructor for PreviewHandler.
	 *
	 * @param PreviewMappers  $mappers      The register, schema and object mappers.
	 * @param LoggerInterface $logger       The logger interface.
	 * @param FetchHandler    $fetchHandler The fetch handler for remote data fetching.
	 */
	public function __construct(
		PreviewMappers $mappers,
		LoggerInterface $logger,
		FetchHandler $fetchHandler,
	) {
		$this->registerMapper = $mappers->registerMapper;
		$this->schemaMapper = $mappers->schemaMapper;
		$this->logger = $logger;
		$this->fetchHandler = $fetchHandler;
		$this->objectMapper = $mappers->objectMapper;
	}//end __construct()

	/**
	 * Preview configuration changes.
	 *
	 * This method fetches remote configuration and previews what would change
	 * if it were imported. It shows additions, updates, and deletions for
	 * registers, schemas, and objects.
	 *
	 * @param Configuration $configuration The configuration to preview.
	 *
	 * @return ((array|null|string)[]|int|mixed|null|string)[][]|JSONResponse
	 *
	 * @throws Exception If configuration service not set.
	 *
	 * @phpstan-return array{
	 *     registers: array,
	 *     schemas: array,
	 *     objects: array,
	 *     metadata: array,
	 *     endpoints: array,
	 *     sources: array,
	 *     mappings: array,
	 *     jobs: array,
	 *     synchronizations: array,
	 *     rules: array
	 * }|JSONResponse
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 */
	public function previewConfigurationChanges(Configuration $configuration): array|JSONResponse {
		$remoteData = $this->fetchHandler->fetchRemoteConfiguration($configuration);
		if ($remoteData instanceof JSONResponse) {
			return $remoteData;
		}

		$preview = self::EMPTY_PREVIEW;
		$preview['registers'] = $this->definitionRows(remoteData: $remoteData, kind: 'registers');
		$preview['schemas'] = $this->definitionRows(remoteData: $remoteData, kind: 'schemas');

		// An empty object list still reads the local registers and schemas.
		$remoteObjects = ($remoteData['components']['objects'] ?? null);
		if (is_array($remoteObjects) === true) {
			$preview['objects'] = $this->previewObjects(remoteObjects: $remoteObjects, preview: $preview);
		}

		$preview['metadata'] = self::previewMetadata(configuration: $configuration, remoteData: $remoteData, preview: $preview);

		return $preview;
	}//end previewConfigurationChanges()

	/**
	 * The metadata block of a preview: which configuration, which versions, when, and how many changes.
	 *
	 * @param Configuration $configuration The configuration being previewed.
	 * @param array         $remoteData    The remote document.
	 * @param array         $preview       The preview rows so far.
	 *
	 * @return array<string, mixed>
	 */
	private static function previewMetadata(Configuration $configuration, array $remoteData, array $preview): array {
		return [
			'configurationId' => $configuration->getId(),
			'configurationTitle' => $configuration->getTitle(),
			'sourceUrl' => $configuration->getSourceUrl(),
			'remoteVersion' => $remoteData['version'] ?? $remoteData['info']['version'] ?? null,
			'localVersion' => $configuration->getLocalVersion(),
			'previewedAt' => (new DateTime())->format('c'),
			'totalChanges' => (count($preview['registers']) + count($preview['schemas']) + count($preview['objects'])),
		];
	}//end previewMetadata()

	/**
	 * The preview rows of the remote registers or schemas, in document order.
	 *
	 * @param array  $remoteData The remote document.
	 * @param string $kind       `registers` or `schemas`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function definitionRows(array $remoteData, string $kind): array {
		$rows = [];
		foreach (self::remoteComponent(remoteData: $remoteData, kind: $kind) as $slug => $data) {
			if ($kind === 'registers') {
				$rows[] = $this->previewRegisterChange(slug: $slug, registerData: $data);
				continue;
			}

			$rows[] = $this->previewSchemaChange(slug: $slug, schemaData: $data);
		}

		return $rows;
	}//end definitionRows()

	/**
	 * One component map of a remote document, or an empty list when it is absent or not a map.
	 *
	 * @param array  $remoteData The remote document.
	 * @param string $kind       The component, e.g. `registers`.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 */
	private static function remoteComponent(array $remoteData, string $kind): array {
		$component = ($remoteData['components'][$kind] ?? null);
		if (is_array($component) === false) {
			return [];
		}

		return $component;
	}//end remoteComponent()

	/**
	 * The preview rows of the remote objects, matched against the local registers and schemas.
	 *
	 * Registers and schemas the same import creates count as present, so their
	 * objects are created too rather than skipped as "not found locally".
	 *
	 * @param array $remoteObjects The remote objects.
	 * @param array $preview       The preview so far, with its register and schema rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 */
	private function previewObjects(array $remoteObjects, array $preview): array {
		$registersBySlug = self::bySlug(entities: $this->registerMapper->findAll());
		$schemasBySlug = self::bySlug(entities: $this->schemaMapper->findAll());

		$compare = fn (array $current, array $proposed): array => $this->compareArrays(current: $current, proposed: $proposed);
		$objectPreview = new ObjectChangePreview(objectMapper: $this->objectMapper, compare: $compare);
		$planned = ['registers' => $objectPreview->createdSlugs(rows: $preview['registers'])];
		$planned['schemas'] = $objectPreview->createdSlugs(rows: $preview['schemas']);

		$rows = [];
		foreach ($remoteObjects as $objectData) {
			$rows[] = $objectPreview->preview(
				objectData: (array)$objectData, registersBySlug: $registersBySlug, schemasBySlug: $schemasBySlug, planned: $planned
			);
		}

		return $rows;
	}//end previewObjects()

	/**
	 * Local registers or schemas by lowercased slug.
	 *
	 * @param array<Register|Schema> $entities The registers or schemas.
	 *
	 * @return array<string, Register|Schema>
	 */
	private static function bySlug(array $entities): array {
		$bySlug = [];
		foreach ($entities as $entity) {
			$bySlug[strtolower($entity->getSlug() ?? '')] = $entity;
		}

		return $bySlug;
	}//end bySlug()

	/**
	 * Preview changes for a single register.
	 *
	 * This method compares a register from remote configuration with the existing
	 * local register and determines if it would be created, updated, or skipped.
	 *
	 * @param string $slug The register slug.
	 * @param array $registerData The register data from remote configuration.
	 *
	 * @return array Preview information for this register.
	 *
	 * @phpstan-return array{
	 *     type: string,
	 *     action: string,
	 *     slug: string,
	 *     title: string,
	 *     current: array|null,
	 *     proposed: array,
	 *     changes: array
	 * }
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 */
	public function previewRegisterChange(string $slug, array $registerData): array {
		$slug = strtolower($slug);

		$existingRegister = null;
		try {
			$existingRegister = $this->registerMapper->find($slug);
		} catch (Exception $e) {
			// Register doesn't exist.
		}

		return $this->definitionChange(type: 'register', slug: $slug, proposed: $registerData, existing: $existingRegister);
	}//end previewRegisterChange()

	/**
	 * Preview changes for a single schema.
	 *
	 * This method compares a schema from remote configuration with the existing
	 * local schema and determines if it would be created, updated, or skipped.
	 *
	 * @param string $slug The schema slug.
	 * @param array $schemaData The schema data from remote configuration.
	 *
	 * @return array Preview information for this schema.
	 *
	 * @phpstan-return array{
	 *     type: string,
	 *     action: string,
	 *     slug: string,
	 *     title: string,
	 *     current: array|null,
	 *     proposed: array,
	 *     changes: array
	 * }
	 */
	private function previewSchemaChange(string $slug, array $schemaData): array {
		$slug = strtolower($slug);

		$existingSchema = null;
		try {
			$existingSchema = $this->schemaMapper->find($slug);
		} catch (Exception $e) {
			// Schema doesn't exist.
		}

		return $this->definitionChange(type: 'schema', slug: $slug, proposed: $schemaData, existing: $existingSchema);
	}//end previewSchemaChange()

	/**
	 * The preview row of one register or schema: created when there is no local one, else
	 * updated when the remote version is newer and skipped when it is not.
	 *
	 * @param string               $type     `register` or `schema`.
	 * @param string               $slug     The lowercased slug.
	 * @param array                $proposed The remote definition.
	 * @param Register|Schema|null $existing The local definition, if any.
	 *
	 * @return array<string, mixed>
	 */
	private function definitionChange(string $type, string $slug, array $proposed, Register|Schema|null $existing): array {
		$action = 'update';
		if ($existing === null) {
			$action = 'create';
		}

		$preview = [
			'type' => $type, 'action' => $action, 'slug' => $slug, 'title' => $proposed['title'] ?? $slug,
			'current' => null, 'proposed' => $proposed, 'changes' => [],
		];
		if ($existing === null) {
			return $preview;
		}

		return $this->versionedChange(preview: $preview, existing: $existing);
	}//end definitionChange()

	/**
	 * Fill in the current definition, and skip or list the changes by version.
	 *
	 * @param array<string, mixed> $preview  The row with the proposed definition.
	 * @param Register|Schema      $existing The local definition.
	 *
	 * @return array<string, mixed>
	 */
	private function versionedChange(array $preview, Register|Schema $existing): array {
		$currentData = $existing->jsonSerialize();
		$preview['current'] = $currentData;

		$currentVersion = $existing->getVersion() ?? '0.0.0';
		$proposedVersion = $preview['proposed']['version'] ?? '0.0.0';
		if (version_compare($proposedVersion, $currentVersion, '<=') === true) {
			$preview['action'] = 'skip';
			$preview['reason'] = sprintf('Remote version (%s) is not newer than current version (%s)', $proposedVersion, $currentVersion);
		}

		if (version_compare($proposedVersion, $currentVersion, '>') === true) {
			$preview['changes'] = $this->compareArrays(current: $currentData, proposed: $preview['proposed']);
		}

		return $preview;
	}//end versionedChange()

	/**
	 * List the fields a proposed definition changes against the current one.
	 *
	 * Only keys the proposal carries are compared, so a field the remote side
	 * leaves out is not reported as removed (the import does not remove it
	 * either). Nested maps are compared by dotted path; lists are compared
	 * whole. A row's own `id`, `uuid`, `created` and `updated` are ignored.
	 *
	 * @param array<array-key, mixed> $current Current definition.
	 * @param array<array-key, mixed> $proposed Proposed definition.
	 * @param string $prefix Path of the parent key, for nested maps.
	 *
	 * @return array<int, array{field: string, current: mixed, proposed: mixed}>
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-configuration-preview-names-what-an-import-would-change
	 */
	public function compareArrays(array $current, array $proposed, string $prefix = ''): array {
		$changes = [];
		foreach ($proposed as $key => $proposedValue) {
			if (in_array($key, ['id', 'uuid', 'created', 'updated'], true) === true) {
				continue;
			}

			$field = (string)$key;
			if ($prefix !== '') {
				$field = $prefix . '.' . $key;
			}

			$changes = array_merge($changes, $this->fieldChanges(current: $current, key: $key, field: $field, proposedValue: $proposedValue));
		}

		return $changes;
	}//end compareArrays()

	/**
	 * The changes of one proposed field: added, changed, or (for two maps) its nested changes.
	 *
	 * @param array<array-key, mixed> $current       Current definition.
	 * @param int|string              $key           The field's key.
	 * @param string                  $field         The field's dotted path.
	 * @param mixed                   $proposedValue The proposed value.
	 *
	 * @return array<int, array{field: string, current: mixed, proposed: mixed}>
	 */
	private function fieldChanges(array $current, int|string $key, string $field, mixed $proposedValue): array {
		if (array_key_exists($key, $current) === false) {
			return [['field' => $field, 'current' => null, 'proposed' => $proposedValue]];
		}

		$currentValue = $current[$key];
		if ($this->bothMaps(current: $currentValue, proposed: $proposedValue) === true) {
			return $this->compareArrays(current: $currentValue, proposed: $proposedValue, prefix: $field);
		}

		if ($proposedValue !== $currentValue) {
			return [['field' => $field, 'current' => $currentValue, 'proposed' => $proposedValue]];
		}

		return [];
	}//end fieldChanges()
	/**
	 * Whether both values are maps, which compareArrays compares by path rather than whole.
	 *
	 * @param mixed $current Current value.
	 * @param mixed $proposed Proposed value.
	 *
	 * @return bool
	 */
	private function bothMaps(mixed $current, mixed $proposed): bool {
		return is_array($current) === true && is_array($proposed) === true
			&& array_is_list($current) === false && array_is_list($proposed) === false;
	}//end bothMaps()
}//end class
