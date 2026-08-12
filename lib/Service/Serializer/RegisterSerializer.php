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
use OCA\OpenRegister\Db\SchemaMapper;
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
final class RegisterSerializer {

	/**
	 * Schemas already fetched this request, keyed by identifier.
	 *
	 * @var array<string, Schema>
	 */
	private array $schemaCache = [];

	/**
	 * Wire mappers + logger via constructor DI.
	 *
	 * @param SchemaMapper $schemaMapper Schema lookup mapper.
	 * @param LoggerInterface $logger Logger for orphan-ID warnings.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Serialize a single Register entity with optional extensions.
	 *
	 * @param Register $register The Register entity to serialize.
	 * @param array $extend Extension keys to apply (`schemas`, `@self.stats`).
	 * @param array<int,array{total:int}>|null $schemaStats Pre-computed per-schema object counts (id → ['total' => int]).
	 *
	 * @return array Serialized register payload, with extensions applied.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 *   (Requirement: schemas extension SHALL replace schema IDs with full schema objects)
	 */
	public function serialize(Register $register, array $extend = [], ?array $schemaStats = null): array {
		$data = $register->jsonSerialize();
		$wantSchemas = in_array('schemas', $extend, true);
		$wantStats = in_array('@self.stats', $extend, true);

		if ($wantSchemas === true) {
			$data['schemas'] = $this->expandSchemas(ids: ($data['schemas'] ?? []), attachStats: $wantStats, schemaStats: $schemaStats);
		}

		return $data;
	}//end serialize()

	/**
	 * Serialize a collection of Register entities.
	 *
	 * @param Register[] $registers Registers to serialize.
	 * @param array $extend Extension keys to apply.
	 * @param array<int,array<int,array{total:int}>>|null $schemaStatsByRegisterId Pre-computed per-register stats
	 *                                                                             (registerId → schemaId →
	 *                                                                             ['total' => int]).
	 *
	 * @return array<int,array> Serialized payload for each register.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function serializeMany(
		array $registers,
		array $extend = [],
		?array $schemaStatsByRegisterId = null,
	): array {
		// PERF (N+1): expandSchemas() resolves each schema id through
		// findSchemaCached() → SchemaMapper::find(), i.e. ONE query per schema.
		// With `_extend[]=schemas` over every register that is one query per
		// register-schema pair (~1,200 on the dev instance) on a single page
		// load. Warm the cache with one batched lookup up front; the per-id path
		// below then only runs for ids the batch could not resolve.
		$this->warmSchemaCache(registers: $registers, extend: $extend);

		$out = [];
		foreach ($registers as $register) {
			$registerId = (int)$register->getId();
			$stats = null;
			if ($schemaStatsByRegisterId !== null
				&& isset($schemaStatsByRegisterId[$registerId]) === true
			) {
				$stats = $schemaStatsByRegisterId[$registerId];
			}

			$out[] = $this->serialize(register: $register, extend: $extend, schemaStats: $stats);
		}

		return $out;
	}//end serializeMany()

	/**
	 * Expand a `schemas` ID array to schema objects, optionally annotating with stats.
	 *
	 * Orphan IDs (SchemaMapper::find throws DoesNotExistException) are
	 * retained in their original position with their original PHP type
	 * preserved. The serializer logs a warning for each orphan.
	 *
	 * @param array $ids Schema ID array (int|string).
	 * @param bool $attachStats Whether to attach per-schema stats to expanded entries.
	 * @param array<int,array{total:int}>|null $schemaStats Pre-computed per-schema stats (id → ['total'
	 *                                                      => int]).
	 *
	 * @return array Heterogeneous array of schema objects + retained orphan IDs.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Handles three branches per ID (resolved/orphan/stats-on)
	 * which produce the smallest combined surface; splitting them duplicates the loop scaffold.
	 */
	private function expandSchemas(array $ids, bool $attachStats, ?array $schemaStats): array {
		$expanded = [];
		foreach ($ids as $schemaId) {
			try {
				// Match the original controller's call shape: bypass
				// multitenancy when expanding schemas for registers (a
				// register may legitimately reference a schema that is
				// not directly visible in the caller's tenant — the
				// expansion is read-only metadata).
				$schema = $this->findSchemaCached(schemaId: $schemaId);
				$schemaJson = $schema->jsonSerialize();
			} catch (DoesNotExistException $e) {
				// Preserve the orphan ID at its original position +
				// original type so typed JSON clients can still see it
				// even if they have to switch decoder shape on the
				// edge case. Log warning and continue.
				$this->logger->warning(
					message: '[RegisterSerializer] Schema not found for expansion — retaining orphan ID',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'schemaId' => $schemaId,
					]
				);
				$expanded[] = $schemaId;
				continue;
			}//end try

			if ($attachStats === true) {
				$idForLookup = $schemaJson['id'] ?? null;
				$count = 0;
				if ($idForLookup !== null
					&& $schemaStats !== null
					&& isset($schemaStats[$idForLookup]) === true
					&& isset($schemaStats[$idForLookup]['total']) === true
				) {
					$count = (int)$schemaStats[$idForLookup]['total'];
				}

				$schemaJson['stats'] = [
					'objects' => ['total' => $count],
				];
			}

			$expanded[] = $schemaJson;
		}//end foreach

		return $expanded;
	}//end expandSchemas()

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
