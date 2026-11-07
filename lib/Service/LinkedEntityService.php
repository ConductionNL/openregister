<?php

/**
 * LinkedEntityService
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-magic-tables
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-reverse-lookup-across-tables
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-remove-link-entities-and-mappers
 * @spec openspec/specs/linked-entity-types/spec.md
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Object\PermissionEnforcementInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing linked Nextcloud entities on OpenRegister objects and entities.
 *
 * Handles ad-hoc linking (from sidebars), unlinking, and reverse lookups across
 * all magic tables and entity tables.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Service integrates multiple mappers for cross-table lookups
 */
class LinkedEntityService implements LinkedEntityServiceInterface {
	/**
	 * Maximum number of magic tables to scan for reverse lookups (circuit breaker).
	 */
	private const MAX_TABLES_TO_SCAN = 50;

	/**
	 * Magic mapper for object operations.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $magicMapper;

	/**
	 * Schema mapper.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Register mapper.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Organisation mapper.
	 *
	 * @var OrganisationMapperInterface
	 */
	private readonly OrganisationMapperInterface $organisationMapper;

	/**
	 * Integration registry, the authoritative type-id source.
	 *
	 * @var IntegrationRegistry
	 */
	private readonly IntegrationRegistry $integrationRegistry;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * RBAC handler for write-permission checks (SEC-CTRL-4).
	 *
	 * @var PermissionEnforcementInterface
	 */
	private readonly PermissionEnforcementInterface $permissionHandler;

	/**
	 * Deep-link registry service for cross-app deep links.
	 *
	 * @var DeepLinkRegistryService
	 */
	private readonly DeepLinkRegistryService $deepLinkRegistry;

	/**
	 * Constructor for LinkedEntityService.
	 *
	 * Per `cleanup-linked-entity-type-map`, the legacy linked-type to
	 * column-name map constant was removed; validation flows through
	 * `IntegrationRegistry::isValidIntegrationId()` plus a small
	 * legacy-id allow-list (see `validateType()`). The column name for
	 * a known linked-type is the type id itself — historically the
	 * removed map was a verbatim identity for every entry.
	 *
	 * @param LinkedEntityMappers      $mappers      The object, schema, register and organisation mappers
	 * @param LinkedEntityIntegrations $integrations The integration and deep-link registries and the permission handler
	 * @param LoggerInterface          $logger       Logger
	 */
	public function __construct(
		LinkedEntityMappers $mappers,
		LinkedEntityIntegrations $integrations,
		LoggerInterface $logger,
	) {
		$this->magicMapper = $mappers->magicMapper;
		$this->schemaMapper = $mappers->schemaMapper;
		$this->registerMapper = $mappers->registerMapper;
		$this->organisationMapper = $mappers->organisationMapper;
		$this->integrationRegistry = $integrations->integrationRegistry;
		$this->logger = $logger;
		$this->permissionHandler = $integrations->permissionHandler;
		$this->deepLinkRegistry = $integrations->deepLinkRegistry;
	}//end __construct()

	/**
	 * Assert the current user may write (update) the given object before mutating
	 * its linked-entity columns.
	 *
	 * SEC-CTRL-4: addLink()/removeLink() previously only ran the read check inside
	 * MagicMapper::find(), then called update() with no write-permission gate — so a
	 * read-only user could mutate link columns. This resolves the object's schema and
	 * runs the canonical `update` RBAC check, throwing NotAuthorizedException (403) on
	 * denial.
	 *
	 * @param ObjectEntity $object The object about to be updated.
	 *
	 * @throws Exception When the schema cannot be resolved or the user lacks write permission.
	 *
	 * @return void
	 */
	private function assertCanWriteObject(ObjectEntity $object): void {
		$schemaId = $object->getSchema();
		if ($schemaId === null) {
			throw new Exception('Cannot resolve schema for linked-entity write permission check.');
		}

		$schema = $this->schemaMapper->find($schemaId);

		$this->permissionHandler->checkPermission(
			schema: $schema,
			action: 'update',
			objectOwner: $object->getOwner(),
			object: $object
		);
	}//end assertCanWriteObject()

	/**
	 * Add a linked entity ID to an object's metadata column.
	 *
	 * @param string $objectUuid The object UUID
	 * @param string $type The linked entity type (e.g., 'mail', 'contacts')
	 * @param string $entityId The entity ID to add
	 *
	 * @throws Exception If the type is invalid or the object is not found
	 *
	 * @return array The updated linked IDs array
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function addLink(string $objectUuid, string $type, string $entityId): array {
		$this->validateType(type: $type);
		$columnName = $type;

		$object = $this->magicMapper->find($objectUuid);
		$getter = 'get' . ucfirst($columnName);
		$setter = 'set' . ucfirst($columnName);
		$existingIds = $object->$getter() ?? [];

		// Idempotent: don't add if already present.
		if (in_array($entityId, $existingIds, true) === false) {
			// SEC-CTRL-4: enforce write (update) permission before mutating the object.
			$this->assertCanWriteObject(object: $object);
			$existingIds[] = $entityId;
			$object->$setter($existingIds);
			$this->magicMapper->update($object);
		}

		return $existingIds;
	}//end addLink()

	/**
	 * Remove a linked entity ID from an object's metadata column.
	 *
	 * @param string $objectUuid The object UUID
	 * @param string $type The linked entity type
	 * @param string $entityId The entity ID to remove
	 *
	 * @throws Exception If the type is invalid or the object is not found
	 *
	 * @return array The updated linked IDs array
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-remove-link-entities-and-mappers
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function removeLink(string $objectUuid, string $type, string $entityId): array {
		$this->validateType(type: $type);
		$columnName = $type;

		$object = $this->magicMapper->find($objectUuid);
		$getter = 'get' . ucfirst($columnName);
		$setter = 'set' . ucfirst($columnName);
		$existingIds = $object->$getter() ?? [];

		$existingIds = array_values(
			array_filter(
				$existingIds,
				function ($id) use ($entityId) {
					return $id !== $entityId;
				}
			)
		);

		// SEC-CTRL-4: enforce write (update) permission before mutating the object.
		$this->assertCanWriteObject(object: $object);
		$object->$setter($existingIds);
		$this->magicMapper->update($object);

		return $existingIds;
	}//end removeLink()

	/**
	 * Add a linked entity ID to a register's metadata column.
	 *
	 * @param string $registerUuid The register UUID
	 * @param string $type The linked entity type
	 * @param string $entityId The entity ID to add
	 *
	 * @throws Exception If the type is invalid
	 *
	 * @return array The updated linked IDs array
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
	 */
	public function addLinkToRegister(string $registerUuid, string $type, string $entityId): array {
		$this->validateType(type: $type);
		$columnName = $type;

		$registers = $this->registerMapper->findAll(filters: ['uuid' => $registerUuid]);
		if (empty($registers) === true) {
			throw new Exception("Register not found: $registerUuid");
		}

		$register = $registers[0];
		$getter = 'get' . ucfirst($columnName);
		$setter = 'set' . ucfirst($columnName);
		$existingIds = $register->$getter() ?? [];

		if (in_array($entityId, $existingIds, true) === false) {
			$existingIds[] = $entityId;
			$register->$setter($existingIds);
			$this->registerMapper->update($register);
		}

		return $existingIds;
	}//end addLinkToRegister()

	/**
	 * Add a linked entity ID to a schema's metadata column.
	 *
	 * @param string $schemaUuid The schema UUID
	 * @param string $type The linked entity type
	 * @param string $entityId The entity ID to add
	 *
	 * @throws Exception If the type is invalid
	 *
	 * @return array The updated linked IDs array
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
	 */
	public function addLinkToSchema(string $schemaUuid, string $type, string $entityId): array {
		$this->validateType(type: $type);
		$columnName = $type;

		$schemas = $this->schemaMapper->findAll(filters: ['uuid' => $schemaUuid]);
		if (empty($schemas) === true) {
			throw new Exception("Schema not found: $schemaUuid");
		}

		$schema = $schemas[0];
		$getter = 'get' . ucfirst($columnName);
		$setter = 'set' . ucfirst($columnName);
		$existingIds = $schema->$getter() ?? [];

		if (in_array($entityId, $existingIds, true) === false) {
			$existingIds[] = $entityId;
			$schema->$setter($existingIds);
			$this->schemaMapper->update($schema);
		}

		return $existingIds;
	}//end addLinkToSchema()

	/**
	 * Reverse lookup: find all objects and entities linked to a specific entity.
	 *
	 * Scans magic tables (for schemas with the corresponding linkedType) and
	 * entity tables (registers, schemas, organisations) for the given entity ID.
	 *
	 * @param string $type The linked entity type (e.g., 'mail')
	 * @param string $entityId The entity ID to search for
	 *
	 * @throws Exception If the type is invalid
	 *
	 * @return array Array of result objects with entityType, uuid, name, etc.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-reverse-lookup-across-tables
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function reverseLookup(string $type, string $entityId): array {
		$this->validateType(type: $type);
		$lookup = new LinkedEntityLookup(type: $type, entityId: $entityId);

		return array_merge(
			$this->scanMagicTables(lookup: $lookup),
			$this->scanEntityTable(entityType: 'register', lookup: $lookup),
			$this->scanEntityTable(entityType: 'schema', lookup: $lookup),
			$this->scanEntityTable(entityType: 'organisation', lookup: $lookup)
		);
	}//end reverseLookup()

	/**
	 * Scan magic tables for objects linked to the given entity.
	 *
	 * @param LinkedEntityLookup $lookup The type and entity id looked for
	 *
	 * @return array Array of matching results
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-magic-tables
	 */
	private function scanMagicTables(LinkedEntityLookup $lookup): array {
		$results = [];

		// Find schemas that declare this linkedType.
		// WARNING: RBAC and multitenancy are intentionally disabled here, so schema metadata (names,
		// slugs, linkedType declarations) and matched object UUIDs/names are returned cross-tenant to
		// any authenticated user calling GET /api/linked/{type}/{entityId}. There is currently NO
		// per-row access check before results are returned. This is intentional for the mail-sidebar
		// use-case where cross-tenant linking is required, but constitutes a cross-tenant data exposure
		// for multi-tenant SaaS deployments. A per-row access check should be added before this
		// endpoint is used in strict-isolation deployments. See TODO #1273.
		$allSchemas = $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
		$scanned = 0;

		foreach ($allSchemas as $schema) {
			if ($scanned >= self::MAX_TABLES_TO_SCAN) {
				$this->logger->warning(
					'[LinkedEntityService] Circuit breaker: max tables reached',
					['maxTables' => self::MAX_TABLES_TO_SCAN, 'type' => $lookup->type]
				);
				break;
			}

			if (in_array($lookup->type, $schema->getLinkedTypes(), true) === false) {
				continue;
			}

			// Query this schema's magic table for the entity ID in the column.
			try {
				$objects = $this->magicMapper->findByLinkedEntity($schema, $lookup->magicColumn(), $lookup->entityId);
				foreach ($objects as $object) {
					$results[] = $this->objectResult(schema: $schema, object: $object);
				}
			} catch (Exception $e) {
				$this->logger->warning(
					'[LinkedEntityService] Error scanning magic table',
					['schema' => $schema->getId(), 'error' => $e->getMessage()]
				);
			}//end try

			$scanned++;
		}//end foreach

		return $results;
	}//end scanMagicTables()

	/**
	 * A reverse-lookup result row for a linked object, with the owning app's detail
	 * route when one is registered in the deep-link registry (null falls the frontend
	 * back to OpenRegister's own page).
	 *
	 * @param Schema       $schema The object's schema
	 * @param ObjectEntity $object The linked object
	 *
	 * @return array The result row
	 */
	private function objectResult(Schema $schema, ObjectEntity $object): array {
		$registerId = (int)$object->getRegister();
		$schemaId = (int)$schema->getId();
		$deepLink = $this->deepLinkRegistry->resolveUrl(
			registerId: $registerId,
			schemaId: $schemaId,
			objectData: [
				'uuid' => $object->getUuid(),
				'register' => $registerId,
				'schema' => $schemaId,
			]
		);

		return [
			'entityType' => 'object',
			'uuid' => $object->getUuid(),
			'name' => $object->getName(),
			'schema' => $schema->getTitle(),
			'schemaId' => $schemaId,
			'schemaIcon' => $schema->getIcon(),
			'register' => $registerId,
			'url' => $deepLink,
		];
	}//end objectResult()

	/**
	 * Scan one entity table (registers, schemas or organisations) for entities linked
	 * to the given entity. A table that cannot be read is logged and yields nothing.
	 *
	 * @param string             $entityType `register`, `schema` or `organisation`
	 * @param LinkedEntityLookup $lookup     The type and entity id looked for
	 *
	 * @return array Array of matching results
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
	 */
	private function scanEntityTable(string $entityType, LinkedEntityLookup $lookup): array {
		$results = [];
		try {
			$entities = match ($entityType) {
				'register' => $this->registerMapper->findAll(),
				'schema' => $this->schemaMapper->findAll(),
				default => $this->organisationMapper->findAll(),
			};
			foreach ($entities as $entity) {
				if ($lookup->isLinkedFrom(entity: $entity) === false) {
					continue;
				}

				$results[] = [
					'entityType' => $entityType,
					'uuid' => $entity->getUuid(),
					'name' => $this->entityName(entityType: $entityType, entity: $entity),
				];
			}
		} catch (Exception $e) {
			$this->logger->warning(
				'[LinkedEntityService] Error scanning ' . $entityType . 's',
				['error' => $e->getMessage()]
			);
		}

		return $results;
	}//end scanEntityTable()

	/**
	 * The display name of a register or schema (its title) or an organisation (its name).
	 *
	 * @param string $entityType `register`, `schema` or `organisation`.
	 * @param object $entity     The entity.
	 *
	 * @return string|null
	 */
	private function entityName(string $entityType, object $entity): ?string {
		if ($entityType === 'organisation') {
			return $entity->getName();
		}

		return $entity->getTitle();
	}//end entityName()

	/**
	 * Validate that the given type is a valid linked entity type.
	 *
	 * Per `cleanup-linked-entity-type-map`, validation flows through:
	 *
	 *   1. `IntegrationRegistry::isValidIntegrationId($type)` — the
	 *      authoritative source.
	 *   2. A small legacy-id allow-list (`legacyLinkedTypeIds()`) so
	 *      pre-cleanup callers passing ids like `mail` (now `email` in
	 *      the registry) or `todos` (now `tasks`) continue to work
	 *      until consumers migrate.
	 *
	 * @param string $type The type to validate
	 *
	 * @throws Exception If the type is invalid
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
	 * @spec openspec/specs/cleanup-linked-entity-type-map/spec.md "Registry-Driven Behaviour Unchanged"
	 */
	private function validateType(string $type): void {
		if ($this->integrationRegistry->isValidIntegrationId($type) === true) {
			return;
		}

		$legacy = self::legacyLinkedTypeIds();
		if (in_array($type, $legacy, true) === true) {
			return;
		}

		$registered = $this->integrationRegistry->listIds();
		$combined = array_unique(array_merge($legacy, $registered));
		sort($combined);
		throw new Exception(
			"Invalid linked entity type '$type'. Valid types: " . implode(', ', $combined)
		);
	}//end validateType()

	/**
	 * Legacy linked-type id allow-list — internal implementation detail.
	 *
	 * Mirrors `Schema::legacyLinkedTypeIds()` (the cleanup also
	 * removed `Schema`'s public linked-types constant). Kept private
	 * and method-form so the values aren't exposed as a public symbol;
	 * new linked-types MUST be added by registering an
	 * `IntegrationProvider`, not by extending this list.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/cleanup-linked-entity-type-map/spec.md "Constants Removed"
	 */
	private static function legacyLinkedTypeIds(): array {
		return [
			'files',
			'mail',
			'contacts',
			'notes',
			'todos',
			'calendar',
			'talk',
			'deck',
		];
	}//end legacyLinkedTypeIds()
}//end class
