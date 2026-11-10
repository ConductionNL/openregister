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
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicListRequest;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
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
	 * @var SchemaMapperInterface The schema mapper instance.
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Register mapper instance for handling register operations.
	 *
	 * @var RegisterMapperInterface The register mapper instance.
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Object mapper instance for handling object operations.
	 *
	 * @var MagicMapperInterface The object mapper instance.
	 */
	private readonly MagicMapperInterface $objectEntityMapper;

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
	] + ConfigurationComponentSections::CONNECTOR_SECTIONS + [
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
	 * @param SchemaMapperInterface $schemaMapper The schema mapper.
	 * @param RegisterMapperInterface $registerMapper The register mapper.
	 * @param MagicMapperInterface $objectEntityMapper The object entity mapper.
	 * @param ConfigurationMapper $configurationMapper The configuration mapper.
	 * @param MappingMapper $mappingMapper The mapping mapper.
	 * @param LoggerInterface $logger The logger interface.
	 */
	public function __construct(
		SchemaMapperInterface $schemaMapper,
		RegisterMapperInterface $registerMapper,
		MagicMapperInterface $objectEntityMapper,
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

		$run = new ConfigurationExportRun(includeObjects: $includeObjects, openConnectorService: $openConnectorService);
		$run->configuration = $this->configurationToExport(input: $input);
		$run->openApiSpec   = $this->emptyDocument(input: $input);

		$this->addRegisters(run: $run, input: $input);
		$this->addMappings(run: $run);

		// Declare the RBAC groups this configuration depends on, in the OAS-native
		// slot. Without this the export is LOSSY: OasService emits the same scope
		// map for API consumers, but the configuration document never carried it,
		// so exporting a register and re-importing it silently dropped every group
		// declaration and left the importer nothing to provision.
		return $this->attachDeclaredScopes(openApiSpec: $run->openApiSpec);
	}//end exportConfig()

	/**
	 * The export document before any register is added: the OAS version, empty components and the header.
	 *
	 * @param array|Configuration|Register $input The export input.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function emptyDocument(array|Configuration|Register $input): array {
		return [
			'openapi' => '3.0.0',
			'components' => self::EMPTY_COMPONENTS,
		] + $this->exportHeader(input: $input);
	}//end emptyDocument()

	/**
	 * Add the export's registers: the configuration's, or the input itself for a register export.
	 *
	 * @param ConfigurationExportRun       $run   The export.
	 * @param array|Configuration|Register $input The export input.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function addRegisters(ConfigurationExportRun $run, array|Configuration|Register $input): void {
		$registers = [$input];
		if ($run->configuration !== null) {
			$registers = $run->configuration->getRegisters();
		}

		foreach ($registers ?? [] as $register) {
			$this->addRegister(run: $run, register: $register);
		}
	}//end addRegisters()

	/**
	 * Add one register with its schemas, its objects when asked, merged over OpenConnector's export of it.
	 *
	 * @param ConfigurationExportRun $run      The export.
	 * @param mixed                  $register A Register or a register id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function addRegister(ConfigurationExportRun $run, mixed $register): void {
		$register = $this->registerToExport(register: $register);
		$this->registersMap[$register->getId()] = $register;
		$run->openApiSpec = $this->withRegisterAndSchemas(openApiSpec: $run->openApiSpec, register: $register);

		if ($run->includeObjects === true) {
			$this->addRegisterObjects(run: $run, register: $register);
		}

		// Merge the OpenAPI specification over the OpenConnector configuration.
		if ($run->openConnectorService !== null) {
			$run->openApiSpec = array_replace_recursive(
				$run->openConnectorService->exportRegister($register->getId()),
				$run->openApiSpec
			);
		}
	}//end addRegister()

	/**
	 * Add a register's objects to the export.
	 *
	 * @param ConfigurationExportRun $run      The export.
	 * @param Register               $register The register.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function addRegisterObjects(ConfigurationExportRun $run, Register $register): void {
		foreach ($this->exportRegisterObjects(register: $register) as $object) {
			$run->openApiSpec['components']['objects'][] = $object;
		}
	}//end addRegisterObjects()

	/**
	 * Add the configuration's mappings to the export; a register export has none.
	 *
	 * @param ConfigurationExportRun $run The export.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function addMappings(ConfigurationExportRun $run): void {
		if ($run->configuration === null) {
			return;
		}

		foreach ($this->exportMappings(configuration: $run->configuration) as $slug => $mapping) {
			$run->openApiSpec['components']['mappings'][$slug] = $mapping;
		}
	}//end addMappings()

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
			return $this->configurationHeader(configuration: $input);
		}

		if ($input instanceof Register) {
			return [
				'info' => $this->entityInfo(entity: $input),
				'x-openregister' => ['type' => 'register'],
			];
		}

		return $this->arrayHeader(input: $input);
	}//end exportHeader()

	/**
	 * The header of a configuration export: its OAS info and its OpenRegister metadata.
	 *
	 * @param Configuration $configuration The configuration.
	 *
	 * @return array{info: array<string, mixed>, x-openregister: array<string, mixed>}
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function configurationHeader(Configuration $configuration): array {
		return [
			'info' => $this->entityInfo(entity: $configuration),
			'x-openregister' => [
				'type' => $configuration->getType(),
				'app' => $configuration->getApp(),
				'sourceType' => $configuration->getSourceType(),
				'sourceUrl' => $configuration->getSourceUrl(),
				'openregister' => $configuration->getOpenregister(),
				'github' => $this->githubSource(configuration: $configuration),
			],
		];
	}//end configurationHeader()

	/**
	 * The GitHub location a configuration is kept at.
	 *
	 * @param Configuration $configuration The configuration.
	 *
	 * @return array{repo: mixed, branch: mixed, path: mixed}
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function githubSource(Configuration $configuration): array {
		return [
			'repo' => $configuration->getGithubRepo(),
			'branch' => $configuration->getGithubBranch(),
			'path' => $configuration->getGithubPath(),
		];
	}//end githubSource()

	/**
	 * The OAS `info` block of a configuration or register: title, description and version.
	 *
	 * @param Configuration|Register $entity The exported entity.
	 *
	 * @return array{title: mixed, description: mixed, version: mixed}
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function entityInfo(Configuration|Register $entity): array {
		return [
			'title' => $entity->getTitle(),
			'description' => $entity->getDescription(),
			'version' => $entity->getVersion(),
		];
	}//end entityInfo()

	/**
	 * The header of an array export, with defaults for what the array leaves out.
	 *
	 * @param array<string, mixed> $input The export input.
	 *
	 * @return array{info: array<string, mixed>, x-openregister: mixed}
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function arrayHeader(array $input): array {
		return [
			'info' => [
				'title' => $input['title'] ?? 'Default Title',
				'description' => $input['description'] ?? 'Default Description',
				'version' => $input['version'] ?? '1.0.0',
			],
			'x-openregister' => $input['x-openregister'] ?? $this->arrayExtension(input: $input),
		];
	}//end arrayHeader()

	/**
	 * The `x-openregister` block of an array export that declares none.
	 *
	 * @param array<string, mixed> $input The export input.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function arrayExtension(array $input): array {
		return [
			'title' => $input['title'] ?? null,
			'description' => $input['description'] ?? null,
			'type' => $input['type'] ?? null,
			'app' => $input['app'] ?? null,
			'version' => $input['version'] ?? '1.0.0',
		];
	}//end arrayExtension()

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
		$slugs = new ExportSlugMaps(
			schemaSlugs: $this->schemaMapper->getIdToSlugMap(),
			registerSlugs: $this->registerMapper->getIdToSlugMap()
		);

		foreach ($schemas as $schema) {
			$this->schemasMap[$schema->getId()] = $schema;

			$openApiSpec['components']['schemas'][$schema->getSlug()] = $this->exportSchema(
				schema: $schema,
				slugs: $slugs
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
		$listing = new MagicListRequest(table: null, query: ListQuery::unbounded(filters: ['register' => $register->getId()]));
		foreach ($this->objectEntityMapper->findAllListed(request: $listing) as $object) {
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
	 * @param Schema         $schema The schema to export.
	 * @param ExportSlugMaps $slugs  The schema and register slugs by id.
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
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function exportSchema(Schema $schema, ExportSlugMaps $slugs): array {
		// Use jsonSerialize to get the JSON representation of the schema.
		$schemaArray = $schema->jsonSerialize();

		// Unset id, uuid, and organisation if they are present.
		// Organisation is instance-specific and should not be exported.
		unset($schemaArray['id'], $schemaArray['uuid'], $schemaArray['organisation']);

		foreach ($schemaArray['properties'] as $name => $property) {
			$schemaArray['properties'][$name] = $this->exportProperty(property: $property, slugs: $slugs);
		}

		return $schemaArray;
	}//end exportSchema()

	/**
	 * One schema property with its references, and those of its `items`, written as slugs.
	 *
	 * @param mixed          $property The property definition.
	 * @param ExportSlugMaps $slugs    The schema and register slugs by id.
	 *
	 * @return mixed The property with slugs for every known id.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function exportProperty(mixed $property, ExportSlugMaps $slugs): mixed {
		if (is_object($property) === true) {
			$property = (array)$property;
		}

		$property = $this->withReferenceSlugs(node: $property, slugs: $slugs);
		if (is_array($property) === true && isset($property['items']) === true) {
			$property['items'] = $this->withReferenceSlugs(node: $property['items'], slugs: $slugs);
		}

		return $property;
	}//end exportProperty()

	/**
	 * A property or `items` node with its schema and register ids written as slugs.
	 *
	 * Rewrites the `$ref` (an id or a URL ending in one), the
	 * `objectConfiguration` register and schema ids, and the legacy `register`
	 * URL. A reference to an unknown id is left as it is. A node or
	 * `objectConfiguration` decoded as an object is read as an array.
	 *
	 * @param mixed          $node  The property or `items` node.
	 * @param ExportSlugMaps $slugs The schema and register slugs by id.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function withReferenceSlugs(mixed $node, ExportSlugMaps $slugs): mixed {
		if (is_object($node) === true) {
			$node = (array)$node;
		}

		if (is_object($node['objectConfiguration'] ?? null) === true) {
			$node['objectConfiguration'] = (array)$node['objectConfiguration'];
		}

		if (($node['$ref'] ?? null) !== null) {
			$node['$ref'] = $slugs->schemaSlug(schemaId: $this->getLastNumericSegment(url: $node['$ref'])) ?? $node['$ref'];
		}

		if (($node['objectConfiguration']['register'] ?? null) !== null) {
			$node['objectConfiguration']['register'] = $slugs->registerSlugOrValue(registerId: $node['objectConfiguration']['register']);
		}

		if (($node['objectConfiguration']['schema'] ?? null) !== null) {
			$node['objectConfiguration']['schema'] = $slugs->schemaSlugOrValue(schemaId: $node['objectConfiguration']['schema']);
		}

		// Legacy structure: the register as a URL.
		if (is_string($node['register'] ?? null) === true) {
			$node['register'] = $slugs->registerSlug(registerId: $this->getLastNumericSegment(url: $node['register'])) ?? $node['register'];
		}

		return $node;
	}//end withReferenceSlugs()

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
