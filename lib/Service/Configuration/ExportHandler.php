<?php

/**
 * OpenRegister Export Handler
 *
 * This file contains the handler class for exporting configurations
 * in OpenAPI format from the OpenRegister application.
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
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

namespace OCA\OpenRegister\Service\Configuration;

use Exception;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Authorization\RbacGroupCollector;
use Psr\Log\LoggerInterface;

/**
 * Class ExportHandler
 *
 * Handles exporting configurations, registers, and schemas to OpenAPI format.
 *
 * @package OCA\OpenRegister\Service\Configuration
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class ExportHandler {

	/**
	 * Schema mapper instance for handling schema operations.
	 *
	 * @var SchemaMapper The schema mapper instance.
	 */
	private readonly SchemaMapper $schemaMapper;

	/**
	 * Register mapper instance for handling register operations.
	 *
	 * @var RegisterMapper The register mapper instance.
	 */
	private readonly RegisterMapper $registerMapper;

	/**
	 * Object mapper instance for handling object operations.
	 *
	 * @var MagicMapper The object mapper instance.
	 */
	private readonly MagicMapper $objectEntityMapper;

	/**
	 * Configuration mapper instance for handling configuration operations.
	 *
	 * @var ConfigurationMapper The configuration mapper instance.
	 */
	private readonly ConfigurationMapper $configurationMapper;

	/**
	 * Logger instance for logging operations.
	 *
	 * @var LoggerInterface The logger instance.
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Map of registers indexed by ID during export.
	 *
	 * @var array<int, Register> Registers indexed by ID.
	 */
	private array $registersMap = [];

	/**
	 * Map of schemas indexed by ID during export.
	 *
	 * @var array<int, Schema> Schemas indexed by ID.
	 */
	private array $schemasMap = [];

	/**
	 * The component sections every export starts with, in output order.
	 *
	 * @var array<string, array>
	 */
	private const EMPTY_COMPONENTS = [
		'registers' => [],
		'schemas' => [],
		'endpoints' => [],
		'sources' => [],
		'mappings' => [],
		'jobs' => [],
		'synchronizations' => [],
		'rules' => [],
		'workflows' => [],
		'objects' => [],
	];


	/**
	 * Mapping mapper instance for handling mapping operations.
	 *
	 * @var MappingMapper The mapping mapper instance.
	 */
	private readonly MappingMapper $mappingMapper;


	/**
	 * Constructor for ExportHandler.
	 *
	 * @param SchemaMapper $schemaMapper The schema mapper.
	 * @param RegisterMapper $registerMapper The register mapper.
	 * @param MagicMapper $objectEntityMapper The object entity mapper.
	 * @param ConfigurationMapper $configurationMapper The configuration mapper.
	 * @param MappingMapper $mappingMapper The mapping mapper.
	 * @param LoggerInterface $logger The logger interface.
	 */
	public function __construct(
		SchemaMapper $schemaMapper,
		RegisterMapper $registerMapper,
		MagicMapper $objectEntityMapper,
		ConfigurationMapper $configurationMapper,
		MappingMapper $mappingMapper,
		LoggerInterface $logger,
	) {
		$this->schemaMapper = $schemaMapper;
		$this->registerMapper = $registerMapper;
		$this->objectEntityMapper = $objectEntityMapper;
		$this->configurationMapper = $configurationMapper;
		$this->mappingMapper = $mappingMapper;
		$this->logger = $logger;
	}//end __construct()



	/**
	 * Export configuration to OpenAPI format.
	 *
	 * This method exports a configuration, register, or array to OpenAPI 3.0.0 format,
	 * including all associated registers, schemas, and optionally objects.
	 *
	 * @param array|Configuration|Register $input The input to export (Configuration, Register, or array).
	 * @param bool $includeObjects Whether to include objects in the export. Defaults to false.
	 * @param object|null $openConnectorService Optional OpenConnector service for additional export data.
	 *
	 * @return array The OpenAPI specification array.
	 *
	 * @throws \OCP\DB\Exception If database operations fail.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Toggle to include/exclude objects in export
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-frontend-schema-parameter-for-non-aggregated-facets
	 */
	public function exportConfig(
		array|Configuration|Register $input = [],
		bool $includeObjects = false,
		?object $openConnectorService = null,
	): array {
		// Reset the maps for this export.
		$this->registersMap = [];
		$this->schemasMap = [];

		$configuration = $this->configurationToExport(input: $input);
		$openApiSpec = [
			'openapi' => '3.0.0',
			'components' => self::EMPTY_COMPONENTS,
		] + $this->exportHeader(input: $input);

		$registers = [$input];
		if ($configuration !== null) {
			$registers = $configuration->getRegisters();
		}

		foreach ($registers ?? [] as $register) {
			$register = $this->registerToExport(register: $register);
			$this->registersMap[$register->getId()] = $register;
			$openApiSpec = $this->withRegisterAndSchemas(openApiSpec: $openApiSpec, register: $register);

			if ($includeObjects === true) {
				foreach ($this->exportRegisterObjects(register: $register) as $object) {
					$openApiSpec['components']['objects'][] = $object;
				}
			}

			// Merge the OpenAPI specification over the OpenConnector configuration.
			if ($openConnectorService !== null) {
				$openApiSpec = array_replace_recursive(
					$openConnectorService->exportRegister($register->getId()),
					$openApiSpec
				);
			}
		}//end foreach

		if ($configuration !== null) {
			foreach ($this->exportMappings(configuration: $configuration) as $slug => $mapping) {
				$openApiSpec['components']['mappings'][$slug] = $mapping;
			}
		}

		// Declare the RBAC groups this configuration depends on, in the OAS-native
		// slot. Without this the export is LOSSY: OasService emits the same scope
		// map for API consumers, but the configuration document never carried it,
		// so exporting a register and re-importing it silently dropped every group
		// declaration and left the importer nothing to provision.
		return $this->attachDeclaredScopes(openApiSpec: $openApiSpec);
	}//end exportConfig()

	/**
	 * A configuration's register entry as an entity: an id is looked up, anything else is used as it is.
	 *
	 * @param mixed $register A Register or a register id.
	 *
	 * @return mixed The register.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function registerToExport(mixed $register): mixed {
		if ($register instanceof Register === false && is_int($register) === true) {
			return $this->registerMapper->find($register);
		}

		return $register;
	}//end registerToExport()

	/**
	 * The configuration an export reads its registers and mappings from.
	 *
	 * @param array|Configuration|Register $input The export input; an array names the configuration by `id`.
	 *
	 * @return Configuration|null The configuration, or null for a register export.
	 *
	 * @throws \OCP\DB\Exception If the configuration lookup fails.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function configurationToExport(array|Configuration|Register $input): ?Configuration {
		if ($input instanceof Configuration) {
			return $input;
		}

		if ($input instanceof Register) {
			return null;
		}

		return $this->configurationMapper->find($input['id']);
	}//end configurationToExport()

	/**
	 * The `info` and `x-openregister` blocks of an export.
	 *
	 * Standard OAS properties (title, description, version) go in `info`.
	 * OpenRegister metadata goes in the `x-openregister` extension
	 * (https://swagger.io/docs/specification/v3_0/openapi-extensions/);
	 * instance-specific or automatically managed properties (autoUpdate,
	 * notificationGroups, owner, organisation, registers, schemas, objects,
	 * views, agents, sources, applications) are left out.
	 *
	 * @param array|Configuration|Register $input The export input.
	 *
	 * @return array{info: array<string, mixed>, x-openregister: mixed}
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function exportHeader(array|Configuration|Register $input): array {
		if ($input instanceof Configuration) {
			return [
				'info' => [
					'title' => $input->getTitle(),
					'description' => $input->getDescription(),
					'version' => $input->getVersion(),
				],
				'x-openregister' => [
					'type' => $input->getType(),
					'app' => $input->getApp(),
					'sourceType' => $input->getSourceType(),
					'sourceUrl' => $input->getSourceUrl(),
					'openregister' => $input->getOpenregister(),
					'github' => [
						'repo' => $input->getGithubRepo(),
						'branch' => $input->getGithubBranch(),
						'path' => $input->getGithubPath(),
					],
				],
			];
		}

		if ($input instanceof Register) {
			return [
				'info' => [
					'title' => $input->getTitle(),
					'description' => $input->getDescription(),
					'version' => $input->getVersion(),
				],
				'x-openregister' => ['type' => 'register'],
			];
		}

		return [
			'info' => [
				'title' => $input['title'] ?? 'Default Title',
				'description' => $input['description'] ?? 'Default Description',
				'version' => $input['version'] ?? '1.0.0',
			],
			'x-openregister' => $input['x-openregister'] ?? [
				'title' => $input['title'] ?? null,
				'description' => $input['description'] ?? null,
				'type' => $input['type'] ?? null,
				'app' => $input['app'] ?? null,
				'version' => $input['version'] ?? '1.0.0',
			],
		];
	}//end exportHeader()

	/**
	 * Add a register and its schemas to the export, with schema references as slugs.
	 *
	 * @param array<string, mixed> $openApiSpec The export document so far.
	 * @param Register             $register    The register to add.
	 *
	 * @return array<string, mixed> The document with the register and its schemas.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function withRegisterAndSchemas(array $openApiSpec, Register $register): array {
		$registerSlug = $register->getSlug();
		$openApiSpec['components']['registers'][$registerSlug] = $this->exportRegister(register: $register);
		// The register lists its schemas by slug, filled below.
		$openApiSpec['components']['registers'][$registerSlug]['schemas'] = [];

		$schemas = $this->registerMapper->getSchemasByRegisterId($register->getId());
		$schemaIdsAndSlugsMap = $this->schemaMapper->getIdToSlugMap();
		$regIdSlugMap = $this->registerMapper->getIdToSlugMap();

		foreach ($schemas as $schema) {
			$this->schemasMap[$schema->getId()] = $schema;

			$openApiSpec['components']['schemas'][$schema->getSlug()] = $this->exportSchema(
				schema: $schema,
				schemaIdsAndSlugsMap: $schemaIdsAndSlugsMap,
				regIdSlugMap: $regIdSlugMap
			);
			$openApiSpec['components']['registers'][$registerSlug]['schemas'][] = $schema->getSlug();
		}

		return $openApiSpec;
	}//end withRegisterAndSchemas()

	/**
	 * A register's objects, with their register and schema named by slug where known.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function exportRegisterObjects(Register $register): array {
		$objects = [];
		foreach ($this->objectEntityMapper->findAll(filters: ['register' => $register->getId()]) as $object) {
			$object = $object->jsonSerialize();
			$registerId = (int)$object['@self']['register'];
			$schemaId = (int)$object['@self']['schema'];
			if (isset($this->registersMap[$registerId]) === true) {
				$object['@self']['register'] = $this->registersMap[$registerId]->getSlug();
			}

			if (isset($this->schemasMap[$schemaId]) === true) {
				$object['@self']['schema'] = $this->schemasMap[$schemaId]->getSlug();
			}

			$objects[] = $object;
		}

		return $objects;
	}//end exportRegisterObjects()

	/**
	 * A configuration's mappings by slug, without instance-specific properties.
	 *
	 * A mapping that cannot be loaded is logged and left out.
	 *
	 * @param Configuration $configuration The configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function exportMappings(Configuration $configuration): array {
		$mappings = [];
		foreach ($configuration->getMappings() ?? [] as $mappingId) {
			try {
				$mapping = $this->mappingMapper->find($mappingId);
				$mappingArray = $mapping->jsonSerialize();
				unset(
					$mappingArray['id'],
					$mappingArray['uuid'],
					$mappingArray['organisation'],
					$mappingArray['configurations'],
					$mappingArray['created'],
					$mappingArray['updated']
				);

				$mappings[$mapping->getSlug()] = $mappingArray;
			} catch (Exception $e) {
				$this->logger->warning(
					message: '[ExportHandler] Failed to export mapping',
					context: [
						'mappingId' => $mappingId,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return $mappings;
	}//end exportMappings()

	/**
	 * Attach the declared-group scope map to an exported configuration document.
	 *
	 * Group ids come from the exported registers and schemas themselves, so the
	 * map can never drift from the authorization blocks it accompanies. Reserved
	 * principals are re-added for parity with {@see \OCA\OpenRegister\Service\OasService}:
	 * `admin` is always a valid scope, and `public` is emitted only when the
	 * configuration actually grants anonymous access.
	 *
	 * @param array<string, mixed> $openApiSpec The assembled export document.
	 *
	 * @return array<string, mixed> The document with `components.securitySchemes` populated.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function attachDeclaredScopes(array $openApiSpec): array {
		$collector = new RbacGroupCollector();

		$raw = array_merge(
			$collector->fromAuthorizationOwners(owners: ($openApiSpec['components']['registers'] ?? [])),
			$collector->fromSchemaDefinitions(schemas: ($openApiSpec['components']['schemas'] ?? []))
		);

		$groups = $collector->provisionable(groups: $raw);

		// `admin` always has access to every operation; `public` only appears when
		// the configuration itself named it as a principal.
		$scopeNames = array_merge(['admin'], $groups);
		if (in_array('public', $raw, true) === true) {
			$scopeNames[] = 'public';
		}

		$scopes = [];
		foreach ($scopeNames as $group) {
			$scopes[$group] = $this->describeScope(group: $group);
		}

		$openApiSpec['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes'] = $scopes;

		return $openApiSpec;
	}//end attachDeclaredScopes()

	/**
	 * Human-readable description for one OAuth2 scope.
	 *
	 * Mirrors {@see \OCA\OpenRegister\Service\OasService::getScopeDescription()} so
	 * an exported configuration and a generated API spec describe the same group
	 * identically.
	 *
	 * @param string $group The Nextcloud group id.
	 *
	 * @return string The scope description.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function describeScope(string $group): string {
		if ($group === 'admin') {
			return 'Full administrative access';
		}

		if ($group === 'public') {
			return 'Public (unauthenticated) access';
		}

		return 'Access for ' . $group . ' group';
	}//end describeScope()

	/**
	 * Export a register to OpenAPI format.
	 *
	 * This method converts a Register entity to an array suitable for OpenAPI export,
	 * removing instance-specific properties like id, uuid, and organisation.
	 *
	 * @param Register $register The register to export.
	 *
	 * @return array Register data formatted for OpenAPI export without instance-specific properties.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function exportRegister(Register $register): array {
		// Use jsonSerialize to get the JSON representation of the register.
		$registerArray = $register->jsonSerialize();

		// Unset id, uuid, and organisation if they are present.
		// Organisation is instance-specific and should not be exported.
		unset($registerArray['id'], $registerArray['uuid'], $registerArray['organisation']);

		return $registerArray;
	}//end exportRegister()

	/**
	 * Export a schema to OpenAPI format.
	 *
	 * This method exports a schema and converts internal IDs to slugs for portability.
	 * It handles both the new objectConfiguration structure (with register and schema IDs)
	 * and the legacy register property structure for backward compatibility.
	 *
	 * @param Schema $schema The schema to export.
	 * @param array $schemaIdsAndSlugsMap Map of schema IDs to slugs.
	 * @param array $regIdSlugMap Map of register IDs to slugs.
	 *
	 * @return ((mixed|string[])[]|bool|int|null|string)[]
	 *
	 * @psalm-return array{uri: null|string, slug: null|string,
	 *     title: null|string, description: null|string, version: null|string,
	 *     summary: null|string, icon: null|string, required: array,
	 *     properties: array, archive: array|null, source: null|string,
	 *     hardValidation: bool, immutable: bool, searchable: bool,
	 *     updated: null|string, created: null|string, maxDepth: int,
	 *     owner: null|string, application: null|string,
	 *     groups: array<string, list<string>>|null,
	 *     authorization: array|null, deleted: null|string,
	 *     published: null|string, depublished: null|string,
	 *     configuration: array|null|string, allOf: array|null,
	 *     oneOf: array|null, anyOf: array|null}
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Schema export requires many conditional transformations
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Property handling requires many type and structure checks
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Schema property transformation involves detailed logic
	 */
	private function exportSchema(Schema $schema, array $schemaIdsAndSlugsMap, array $regIdSlugMap): array {
		// Use jsonSerialize to get the JSON representation of the schema.
		$schemaArray = $schema->jsonSerialize();

		// Unset id, uuid, and organisation if they are present.
		// Organisation is instance-specific and should not be exported.
		unset($schemaArray['id'], $schemaArray['uuid'], $schemaArray['organisation']);

		foreach ($schemaArray['properties'] as &$property) {
			// Ensure property is always an array.
			if (is_object($property) === true) {
				$property = (array)$property;
			}

			if (($property['$ref'] ?? null) !== null) {
				$schemaId = $this->getLastNumericSegment(url: $property['$ref']);
				if (($schemaIdsAndSlugsMap[$schemaId] ?? null) !== null) {
					$property['$ref'] = $schemaIdsAndSlugsMap[$schemaId];
				}
			}

			if (($property['items']['$ref'] ?? null) !== null) {
				// Ensure items is an array for consistent access.
				if (is_object($property['items']) === true) {
					$property['items'] = (array)$property['items'];
				}

				$schemaId = $this->getLastNumericSegment(url: $property['items']['$ref']);
				if (($schemaIdsAndSlugsMap[$schemaId] ?? null) !== null) {
					$property['items']['$ref'] = $schemaIdsAndSlugsMap[$schemaId];
				}
			}

			// Handle register ID in objectConfiguration (new structure).
			if (($property['objectConfiguration']['register'] ?? null) !== null) {
				// Ensure objectConfiguration is an array for consistent access.
				if (is_object($property['objectConfiguration']) === true) {
					$property['objectConfiguration'] = (array)$property['objectConfiguration'];
				}

				$registerId = $property['objectConfiguration']['register'];
				if (is_numeric($registerId) === true) {
					$registerIdStr = (string)$registerId;
					if (($regIdSlugMap[$registerIdStr] ?? null) !== null) {
						/*
						 * @var array<int|string, string> $regIdSlugMap
						 */

						$property['objectConfiguration']['register'] = $regIdSlugMap[$registerIdStr];
					}
				}
			}

			// Handle schema ID in objectConfiguration (new structure).
			if (($property['objectConfiguration']['schema'] ?? null) !== null) {
				// Ensure objectConfiguration is an array for consistent access.
				if (is_object($property['objectConfiguration']) === true) {
					$property['objectConfiguration'] = (array)$property['objectConfiguration'];
				}

				$schemaId = $property['objectConfiguration']['schema'];
				if (is_numeric($schemaId) === true) {
					$schemaIdStr = (string)$schemaId;
					if (($schemaIdsAndSlugsMap[$schemaIdStr] ?? null) !== null) {
						/*
						 * @var array<int|string, string> $schemaIdsAndSlugsMap
						 */

						$property['objectConfiguration']['schema'] = $schemaIdsAndSlugsMap[$schemaIdStr];
					}
				}
			}

			// Handle register ID in array items objectConfiguration (new structure).
			if (($property['items']['objectConfiguration']['register'] ?? null) !== null) {
				// Ensure items and objectConfiguration are arrays for consistent access.
				if (is_object($property['items']) === true) {
					$property['items'] = (array)$property['items'];
				}

				if (is_object($property['items']['objectConfiguration']) === true) {
					$property['items']['objectConfiguration'] = (array)$property['items']['objectConfiguration'];
				}

				$registerId = $property['items']['objectConfiguration']['register'];
				if (is_numeric($registerId) === true) {
					$registerIdStr = (string)$registerId;
					if (($regIdSlugMap[$registerIdStr] ?? null) !== null) {
						/*
						 * @var array<int|string, string> $regIdSlugMap
						 */

						$property['items']['objectConfiguration']['register'] = $regIdSlugMap[$registerIdStr];
					}
				}
			}//end if

			// Handle schema ID in array items objectConfiguration (new structure).
			if (($property['items']['objectConfiguration']['schema'] ?? null) !== null) {
				// Ensure items and objectConfiguration are arrays for consistent access.
				if (is_object($property['items']) === true) {
					$property['items'] = (array)$property['items'];
				}

				if (is_object($property['items']['objectConfiguration']) === true) {
					$property['items']['objectConfiguration'] = (array)$property['items']['objectConfiguration'];
				}

				$schemaId = $property['items']['objectConfiguration']['schema'];
				if (is_numeric($schemaId) === true) {
					$schemaIdStr = (string)$schemaId;
					if (($schemaIdsAndSlugsMap[$schemaIdStr] ?? null) !== null) {
						/*
						 * @var array<int|string, string> $schemaIdsAndSlugsMap
						 */

						$property['items']['objectConfiguration']['schema'] = $schemaIdsAndSlugsMap[$schemaIdStr];
					}
				}
			}//end if

			// Legacy support: Handle old register property structure.
			if (($property['register'] ?? null) !== null) {
				if (is_string($property['register']) === true) {
					$registerId = $this->getLastNumericSegment(url: $property['register']);
					$registerIdStr = $registerId;
					if (($regIdSlugMap[$registerIdStr] ?? null) !== null) {
						/*
						 * @var array<int|string, string> $regIdSlugMap
						 */

						$property['register'] = $regIdSlugMap[$registerIdStr];
					}
				}
			}

			if (($property['items']['register'] ?? null) !== null) {
				// Ensure items is an array for consistent access.
				if (is_object($property['items']) === true) {
					$property['items'] = (array)$property['items'];
				}

				if (is_string($property['items']['register']) === true) {
					$registerId = $this->getLastNumericSegment(url: $property['items']['register']);
					$registerIdStr = $registerId;
					if (($regIdSlugMap[$registerIdStr] ?? null) !== null) {
						/*
						 * @var array<int|string, string> $regIdSlugMap
						 */

						$property['items']['register'] = $regIdSlugMap[$registerIdStr];
					}
				}
			}
		}//end foreach

		return $schemaArray;
	}//end exportSchema()

	/**
	 * Get the last segment of a URL if it is numeric.
	 *
	 * This method takes a URL string, removes trailing slashes, splits it by '/' and
	 * checks if the last segment is numeric. If it is, returns that numeric value,
	 * otherwise returns the original URL.
	 *
	 * @param string $url The input URL to evaluate.
	 *
	 * @return string The numeric value if found, or the original URL.
	 *
	 * @throws \InvalidArgumentException If the URL is not a string.
	 */
	private function getLastNumericSegment(string $url): string {
		// Remove trailing slashes from the URL.
		$url = rtrim($url, '/');

		// Split the URL by '/' to get individual segments.
		$parts = explode('/', $url);

		// Get the last segment.
		$lastSegment = end($parts);

		// Return numeric segment if found, otherwise return original URL.
		if (is_numeric($lastSegment) === true) {
			return $lastSegment;
		}

		return $url;
	}//end getLastNumericSegment()

}//end class
