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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
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
	 * @var RegisterMapper The register mapper instance.
	 */
	private readonly RegisterMapper $registerMapper;

	/**
	 * Schema mapper for database operations.
	 *
	 * @var SchemaMapper The schema mapper instance.
	 */
	private readonly SchemaMapper $schemaMapper;

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
	 * @var MagicMapper The object mapper.
	 */
	private readonly MagicMapper $objectMapper;

	/**
	 * Constructor for PreviewHandler.
	 *
	 * @param RegisterMapper $registerMapper The register mapper.
	 * @param SchemaMapper $schemaMapper The schema mapper.
	 * @param LoggerInterface $logger The logger interface.
	 * @param FetchHandler $fetchHandler The fetch handler for remote data fetching.
	 * @param MagicMapper $objectMapper The object mapper, to find the local object a remote one would update.
	 */
	public function __construct(
		RegisterMapper $registerMapper,
		SchemaMapper $schemaMapper,
		LoggerInterface $logger,
		FetchHandler $fetchHandler,
		MagicMapper $objectMapper,
	) {
		$this->registerMapper = $registerMapper;
		$this->schemaMapper = $schemaMapper;
		$this->logger = $logger;
		$this->fetchHandler = $fetchHandler;
		$this->objectMapper = $objectMapper;
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
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Preview comparison requires many conditional change checks
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multi-component preview has many entity type conditions
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Full preview involves registers, schemas, objects, and metadata
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 */
	public function previewConfigurationChanges(Configuration $configuration): array|JSONResponse {
		// Fetch the remote configuration using FetchHandler.
		$remoteData = $this->fetchHandler->fetchRemoteConfiguration($configuration);

		if ($remoteData instanceof JSONResponse) {
			return $remoteData;
		}

		// Initialize preview result.
		$preview = [
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

		// Preview registers.
		if (($remoteData['components']['registers'] ?? null) !== null
			&& is_array($remoteData['components']['registers']) === true
		) {
			foreach ($remoteData['components']['registers'] as $slug => $registerData) {
				$preview['registers'][] = $this->previewRegisterChange(slug: $slug, registerData: $registerData);
			}
		}

		// Preview schemas.
		if (($remoteData['components']['schemas'] ?? null) !== null
			&& is_array($remoteData['components']['schemas']) === true
		) {
			foreach ($remoteData['components']['schemas'] as $slug => $schemaData) {
				$preview['schemas'][] = $this->previewSchemaChange(slug: $slug, schemaData: $schemaData);
			}
		}

		// Preview objects.
		if (($remoteData['components']['objects'] ?? null) !== null
			&& is_array($remoteData['components']['objects']) === true
		) {
			// Local registers and schemas by lowercased slug.
			$registersBySlug = [];
			foreach ($this->registerMapper->findAll() as $register) {
				$registersBySlug[strtolower($register->getSlug() ?? '')] = $register;
			}

			$schemasBySlug = [];
			foreach ($this->schemaMapper->findAll() as $schema) {
				$schemasBySlug[strtolower($schema->getSlug() ?? '')] = $schema;
			}

			// Registers and schemas this same import creates: their objects
			// are created too, not skipped as "not found locally" (live pass O13).
			$planned = [
				'registers' => ObjectChangePreview::createdSlugs(rows: $preview['registers']),
				'schemas'   => ObjectChangePreview::createdSlugs(rows: $preview['schemas']),
			];

			$objectPreview = new ObjectChangePreview(
				objectMapper: $this->objectMapper,
				compare: fn (array $current, array $proposed): array => $this->compareArrays(current: $current, proposed: $proposed)
			);
			foreach ($remoteData['components']['objects'] as $objectData) {
				$preview['objects'][] = $objectPreview->preview(
					objectData: (array)$objectData,
					registersBySlug: $registersBySlug,
					schemasBySlug: $schemasBySlug,
					planned: $planned
				);
			}
		}//end if

		// Add metadata about the preview.
		$preview['metadata'] = [
			'configurationId' => $configuration->getId(),
			'configurationTitle' => $configuration->getTitle(),
			'sourceUrl' => $configuration->getSourceUrl(),
			'remoteVersion' => $remoteData['version'] ?? $remoteData['info']['version'] ?? null,
			'localVersion' => $configuration->getLocalVersion(),
			'previewedAt' => (new DateTime())->format('c'),
			'totalChanges' => (
				count($preview['registers']) + count($preview['schemas']) + count($preview['objects'])
			),
		];

		return $preview;
	}//end previewConfigurationChanges()

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Register preview has multiple version comparison branches
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-request-configuration-via-facets-parameter
	 */
	public function previewRegisterChange(string $slug, array $registerData): array {
		$slug = strtolower($slug);

		// Try to find existing register.
		$existingRegister = null;
		try {
			$existingRegister = $this->registerMapper->find($slug);
		} catch (Exception $e) {
			// Register doesn't exist.
		}

		// Determine action.
		$action = 'update';
		if ($existingRegister === null) {
			$action = 'create';
		}

		$preview = [
			'type' => 'register',
			'action' => $action,
			'slug' => $slug,
			'title' => $registerData['title'] ?? $slug,
			'current' => null,
			'proposed' => $registerData,
			'changes' => [],
		];

		// If register exists, compare versions and build change list.
		if ($existingRegister !== null) {
			$currentData = $existingRegister->jsonSerialize();
			$preview['current'] = $currentData;

			// Check if version allows update.
			$currentVersion = $existingRegister->getVersion() ?? '0.0.0';
			$proposedVersion = $registerData['version'] ?? '0.0.0';

			if (version_compare($proposedVersion, $currentVersion, '<=') === true) {
				$preview['action'] = 'skip';
				$preview['reason'] = sprintf(
					'Remote version (%s) is not newer than current version (%s)',
					$proposedVersion,
					$currentVersion
				);
			}

			if (version_compare($proposedVersion, $currentVersion, '>') === true) {
				// Build list of changed fields.
				$preview['changes'] = $this->compareArrays(current: $currentData, proposed: $registerData);
			}
		}//end if

		return $preview;
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Schema preview has multiple version comparison branches
	 */
	private function previewSchemaChange(string $slug, array $schemaData): array {
		$slug = strtolower($slug);

		// Try to find existing schema.
		$existingSchema = null;
		try {
			$existingSchema = $this->schemaMapper->find($slug);
		} catch (Exception $e) {
			// Schema doesn't exist.
		}

		// Determine action.
		$action = 'update';
		if ($existingSchema === null) {
			$action = 'create';
		}

		$preview = [
			'type' => 'schema',
			'action' => $action,
			'slug' => $slug,
			'title' => $schemaData['title'] ?? $slug,
			'current' => null,
			'proposed' => $schemaData,
			'changes' => [],
		];

		// If schema exists, compare versions and build change list.
		if ($existingSchema !== null) {
			$currentData = $existingSchema->jsonSerialize();
			$preview['current'] = $currentData;

			// Check if version allows update.
			$currentVersion = $existingSchema->getVersion() ?? '0.0.0';
			$proposedVersion = $schemaData['version'] ?? '0.0.0';

			if (version_compare($proposedVersion, $currentVersion, '<=') === true) {
				$preview['action'] = 'skip';
				$preview['reason'] = sprintf(
					'Remote version (%s) is not newer than current version (%s)',
					$proposedVersion,
					$currentVersion
				);
			}

			if (version_compare($proposedVersion, $currentVersion, '>') === true) {
				// Build list of changed fields.
				$preview['changes'] = $this->compareArrays(current: $currentData, proposed: $schemaData);
			}
		}//end if

		return $preview;
	}//end previewSchemaChange()

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

			if (array_key_exists($key, $current) === false) {
				$changes[] = ['field' => $field, 'current' => null, 'proposed' => $proposedValue];
				continue;
			}

			$currentValue = $current[$key];
			if ($this->bothMaps(current: $currentValue, proposed: $proposedValue) === true) {
				$changes = array_merge(
					$changes,
					$this->compareArrays(current: $currentValue, proposed: $proposedValue, prefix: $field)
				);
				continue;
			}

			if ($proposedValue !== $currentValue) {
				$changes[] = ['field' => $field, 'current' => $currentValue, 'proposed' => $proposedValue];
			}
		}//end foreach

		return $changes;
	}//end compareArrays()
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
