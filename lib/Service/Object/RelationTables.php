<?php

/**
 * OpenRegister RelationTables
 *
 * The registers, schemas and tables a relation lookup reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use Psr\Log\LoggerInterface;

/**
 * Loads the registers and schemas a relation lookup reads, each pair once, and groups located
 * objects by the magic table they live in.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class RelationTables {

	/**
	 * Register/schema pairs by `registerId_schemaId`; null when either could not be loaded.
	 *
	 * @var array<string, array{register: Register, schema: Schema}|null>
	 */
	private array $pairs = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaCatalogInterface $catalog The register and schema mappers.
	 * @param LoggerInterface        $logger  The logger.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Group located objects by the table they came from.
	 *
	 * @param ObjectEntity[] $objects Objects found by a cross-table lookup.
	 *
	 * @return array<int, array{registerId: int, schemaId: int, uuids: array<int, string>}> One entry per table.
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/linked-entity-types/spec.md#requirement-related-objects-must-be-found-without-scanning-every-schema
	 */
	public function groups(array $objects): array {
		$tables = [];
		foreach ($objects as $object) {
			$key = self::tableKey(object: $object);
			if ($key === null) {
				continue;
			}

			$tables[$key] ??= ['registerId' => (int)$object->getRegister(), 'schemaId' => (int)$object->getSchema(), 'uuids' => []];
			$tables[$key]['uuids'][] = (string)$object->getUuid();
		}

		return array_values($tables);
	}//end groups()

	/**
	 * Load a register and schema, once per handler.
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId   The schema id.
	 *
	 * @return array{register: Register, schema: Schema}|null The pair, or null when either cannot be loaded.
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/linked-entity-types/spec.md#requirement-related-objects-must-be-found-without-scanning-every-schema
	 */
	public function pair(int $registerId, int $schemaId): ?array {
		$key = $registerId.'_'.$schemaId;
		if (array_key_exists($key, $this->pairs) === false) {
			try {
				$this->pairs[$key] = ['register' => $this->catalog->registers()->find($registerId), 'schema' => $this->catalog->schemas()->find($schemaId)];
			} catch (\Exception $e) {
				$this->pairs[$key] = null;
			}
		}

		return $this->pairs[$key];
	}//end pair()

	/**
	 * The pinned register and schema of an outgoing lookup; a load failure is logged at debug level.
	 *
	 * @param RelationLookup $lookup The lookup.
	 *
	 * @return PinnedTable
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function pinnedForUses(RelationLookup $lookup): PinnedTable {
		$pinned = $this->pinned(lookup: $lookup);
		if ($pinned->failure !== null) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'registerId' => $lookup->registerId(), 'schemaId' => $lookup->schemaId()];
			$this->logger->debug(
				message: '[RelationHandler::getUses] Could not load register/schema for magic table lookup',
				context: $context + ['error' => $pinned->failure->getMessage()]
			);
		}

		return $pinned;
	}//end pinnedForUses()

	/**
	 * The pinned register and schema of an incoming lookup; a load failure is logged as a warning.
	 *
	 * @param RelationLookup $lookup The lookup.
	 *
	 * @return PinnedTable
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function pinnedForUsedBy(RelationLookup $lookup): PinnedTable {
		$pinned = $this->pinned(lookup: $lookup);
		if ($pinned->failure !== null) {
			$this->logger->warning(
				message: '[RelationHandler] Failed to load register/schema for getUsedBy magic table support',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $pinned->failure->getMessage()]
			);
		}

		return $pinned;
	}//end pinnedForUsedBy()

	/**
	 * The source object's relation map and schema: the pinned schema, else its own.
	 *
	 * @param ObjectEntity $object The source object.
	 * @param PinnedTable  $pinned The pinned register and schema.
	 *
	 * @return RelationSource
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function sourceOf(ObjectEntity $object, PinnedTable $pinned): RelationSource {
		return new RelationSource(
			relations: ($object->getRelations() ?? []),
			schema: ($pinned->schema ?? $this->schemaOf(schemaId: $object->getSchema()))
		);
	}//end sourceOf()

	/**
	 * The schema an object belongs to, loaded without RBAC.
	 *
	 * Reading a label is not reading the schema's data, and the objects the
	 * labels describe have already been filtered by {@see RelatedReadFilter}.
	 * A label that silently disappears because the reader may not read the
	 * schema row would leave the far side saying "referenced by" with no way
	 * to tell that apart from a schema that declares nothing.
	 *
	 * @param mixed $schemaId The object's schema id.
	 *
	 * @return Schema|null The schema, or null when it cannot be loaded.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function schemaOf(mixed $schemaId): ?Schema {
		if (is_numeric($schemaId) === false) {
			return null;
		}

		try {
			return $this->catalog->schemas()->find((int)$schemaId, _rbac: false, _multitenancy: false);
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[RelationHandler] Could not load a schema to resolve relation labels',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId],
			);

			return null;
		}
	}//end schemaOf()

	/**
	 * Load the register and schema a lookup was pinned to, without RBAC or multitenancy.
	 *
	 * Nothing is loaded unless both ids are given. Loading stops at the first failure,
	 * which is kept on the result for the caller to log.
	 *
	 * @param RelationLookup $lookup The lookup.
	 *
	 * @return PinnedTable
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function pinned(RelationLookup $lookup): PinnedTable {
		$pinned = new PinnedTable();
		if ($lookup->isPinned() === false) {
			return $pinned;
		}

		try {
			$pinned->register = $this->catalog->registers()->find($lookup->registerId(), _rbac: false, _multitenancy: false);
			$pinned->schema = $this->catalog->schemas()->find($lookup->schemaId(), _rbac: false, _multitenancy: false);
		} catch (\Exception $e) {
			$pinned->failure = $e;
		}

		return $pinned;
	}//end pinned()

	/**
	 * The `register_schema` key of the table an object lives in, or null when either id is not numeric.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/linked-entity-types/spec.md#requirement-related-objects-must-be-found-without-scanning-every-schema
	 */
	private static function tableKey(ObjectEntity $object): ?string {
		$registerId = $object->getRegister();
		$schemaId = $object->getSchema();
		if (is_numeric($registerId) === false || is_numeric($schemaId) === false) {
			return null;
		}

		return $registerId.'_'.$schemaId;
	}//end tableKey()
}//end class
