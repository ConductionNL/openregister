<?php

/**
 * OpenRegister RegisterSerializer
 *
 * Entity-level serializer for Register entities with `_extend` support.
 * Lives in the `lib/Service/Serializer/` namespace, the first inhabitant
 * of a folder that will host future entity serializers
 * (`SchemaSerializer`, `ObjectSerializer`).
 *
 * The serializer owns the schema-expansion + per-schema stats logic
 * that used to live inline in `RegistersController::index()`, so HTTP
 * consumers and DI consumers receive identical post-processed data.
 * `Register::jsonSerialize()` stays ID-only by contract; expansion is
 * an opt-in serializer step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Serializer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Serializer;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Serialize Register entities with optional `_extend` post-processing.
 *
 * Supported `_extend` keys:
 * - `schemas` — replace schema IDs with hydrated schema objects.
 * - `@self.stats` — attach per-schema `stats.objects.total` counts
 *   (only meaningful alongside `schemas`).
 *
 * Unknown keys are silently ignored. Orphan schema IDs (schema not
 * found in the DB) are retained in their original array position; the
 * serializer logs a warning and does not throw.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final class RegisterSerializer implements RegisterSerializerInterface {

	/**
	 * Schemas already fetched this request, keyed by identifier.
	 *
	 * @var array<string, Schema>
	 */
	private array $schemaCache = [];

	/**
	 * Wire mappers + logger via constructor DI.
	 *
	 * @param SchemaMapperInterface $schemaMapper Schema lookup mapper.
	 * @param LoggerInterface $logger Logger for orphan-ID warnings.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Serialize a single Register entity with the expansion it asks for.
	 *
	 * @param Register          $register  The Register entity to serialize.
	 * @param RegisterExpansion $expansion Whether schemas expand, and the counts they carry.
	 *
	 * @return array Serialized register payload, with extensions applied.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 *   (Requirement: schemas extension SHALL replace schema IDs with full schema objects)
	 */
	public function serializeExpanded(Register $register, RegisterExpansion $expansion): array {
		$data = $register->jsonSerialize();
		if ($expansion->schemas === true) {
			$data['schemas'] = $this->expandSchemas(ids: ($data['schemas'] ?? []), counts: $expansion->counts);
		}

		return $data;
	}//end serializeExpanded()

	/**
	 * Serialize a collection of Register entities.
	 *
	 * @param Register[]            $registers Registers to serialize.
	 * @param RegisterListExtension $extension The extension keys and the per-register schema counts.
	 *
	 * @return array<int,array> Serialized payload for each register.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function serializePage(array $registers, RegisterListExtension $extension=new RegisterListExtension()): array {
		// PERF (N+1): expandSchemas() resolves each schema id through
		// findSchemaCached() → SchemaMapper::find(), i.e. ONE query per schema.
		// With `_extend[]=schemas` over every register that is one query per
		// register-schema pair (~1,200 on the dev instance) on a single page
		// load. Warm the cache with one batched lookup up front; the per-id path
		// below then only runs for ids the batch could not resolve.
		$this->warmSchemaCache(registers: $registers, extend: $extension->extend);

		$out = [];
		foreach ($registers as $register) {
			$stats     = ($extension->schemaStatsByRegisterId[(int)$register->getId()] ?? null);
			$expansion = RegisterExpansion::fromExtend(extend: $extension->extend, schemaStats: $stats);
			$out[]     = $this->serializeExpanded(register: $register, expansion: $expansion);
		}

		return $out;
	}//end serializePage()

	/**
	 * Expand a `schemas` ID array to schema objects, optionally annotating with stats.
	 *
	 * Orphan IDs (SchemaMapper::find throws DoesNotExistException) are
	 * retained in their original position with their original PHP type
	 * preserved. The serializer logs a warning for each orphan.
	 *
	 * @param array                   $ids    Schema ID array (int|string).
	 * @param SchemaObjectCounts|null $counts The counts to attach to expanded entries, or null to attach none.
	 *
	 * @return array Heterogeneous array of schema objects + retained orphan IDs.
	 */
	private function expandSchemas(array $ids, ?SchemaObjectCounts $counts): array {
		$expanded = [];
		foreach ($ids as $schemaId) {
			$expanded[] = $this->expandOne(schemaId: $schemaId, counts: $counts);
		}

		return $expanded;
	}//end expandSchemas()

	/**
	 * Expand one schema id to its schema object, or retain it when it is an orphan.
	 *
	 * @param int|string              $schemaId The schema id.
	 * @param SchemaObjectCounts|null $counts   The counts to attach, or null to attach none.
	 *
	 * @return array|int|string The schema object, or the orphan id with its original type.
	 */
	private function expandOne(int|string $schemaId, ?SchemaObjectCounts $counts): array|int|string {
		try {
			// Match the original controller's call shape: bypass
			// multitenancy when expanding schemas for registers (a
			// register may legitimately reference a schema that is
			// not directly visible in the caller's tenant — the
			// expansion is read-only metadata).
			$schemaJson = $this->findSchemaCached(schemaId: $schemaId)->jsonSerialize();
		} catch (DoesNotExistException $e) {
			return $this->retainOrphan(schemaId: $schemaId);
		}

		if ($counts !== null) {
			$schemaJson['stats'] = [
				'objects' => ['total' => $counts->totalFor(schemaId: ($schemaJson['id'] ?? null))],
			];
		}

		return $schemaJson;
	}//end expandOne()

	/**
	 * Log an orphan schema id and hand it back unchanged.
	 *
	 * The orphan ID stays at its original position with its original type so
	 * typed JSON clients can still see it, even if they have to switch decoder
	 * shape on the edge case.
	 *
	 * @param int|string $schemaId The schema id no schema matches.
	 *
	 * @return int|string The same id.
	 */
	private function retainOrphan(int|string $schemaId): int|string {
		$this->logger->warning(
			message: '[RegisterSerializer] Schema not found for expansion — retaining orphan ID',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schemaId' => $schemaId,
			]
		);

		return $schemaId;
	}//end retainOrphan()

	/**
	 * Find a Schema, reusing the entity within this request.
	 *
	 * Registers share schemas, and a list response expands the schemas of EVERY register
	 * — so the same schema was re-fetched once per register that references it, and
	 * SchemaMapper::find() resolves an identifier with `WHERE uuid = ? OR slug = ? OR
	 * id = ?`, which no index covers. On a dev instance with 76 registers / 1,231 schemas
	 * this was the single hottest query in the whole request.
	 *
	 * Schemas do not change mid-request, so hold on to them. A miss still throws
	 * DoesNotExistException, keeping the orphan-ID retention contract in expandSchemas()
	 * exactly as it was.
	 *
	 * @param int|string $schemaId The schema identifier.
	 *
	 * @return Schema The schema entity.
	 *
	 * @throws DoesNotExistException When no schema matches the identifier.
	 *
	 * @spec exclude request-scoped identity cache; no behaviour change
	 */
	private function findSchemaCached(int|string $schemaId): Schema {
		$key = (string)$schemaId;

		if (isset($this->schemaCache[$key]) === false) {
			$this->schemaCache[$key] = $this->schemaMapper->find(id: $schemaId, _multitenancy: false);
		}

		return $this->schemaCache[$key];
	}//end findSchemaCached()

	/**
	 * Resolve every register's schemas in ONE query and seed the request cache.
	 *
	 * Only NUMERIC ids are batched: findMultipleOptimized() matches on `id`,
	 * whereas find() also resolves a uuid or a slug. Anything non-numeric (and
	 * anything the batch does not return — e.g. an orphan id) is deliberately
	 * left out of the cache so findSchemaCached() still falls back to find(),
	 * preserving both the uuid/slug lookup and the DoesNotExistException that
	 * expandSchemas() relies on to retain orphan ids.
	 *
	 * Matches find(_multitenancy: false) semantics: findMultipleOptimized()
	 * applies no organisation filter, and find()'s `_rbac` branch is currently a
	 * no-op, so this is a pure query-count optimisation.
	 *
	 * @param Register[] $registers The registers about to be serialized.
	 * @param array<string> $extend Extension keys; schemas are only resolved when requested.
	 *
	 * @return void
	 *
	 * @spec exclude batched warm-up of the request-scoped cache; no behaviour change
	 */
	private function warmSchemaCache(array $registers, array $extend): void {
		if (in_array('schemas', $extend, true) === false) {
			return;
		}

		$ids = [];
		foreach ($registers as $register) {
			foreach (($register->getSchemas() ?? []) as $schemaId) {
				if (is_numeric($schemaId) === false) {
					continue;
				}

				if (isset($this->schemaCache[(string)$schemaId]) === true) {
					continue;
				}

				$ids[(int)$schemaId] = true;
			}
		}

		if (empty($ids) === true) {
			return;
		}

		foreach ($this->schemaMapper->findMultipleOptimized(ids: array_keys($ids)) as $id => $schema) {
			$this->schemaCache[(string)$id] = $schema;
		}
	}//end warmSchemaCache()
}//end class
