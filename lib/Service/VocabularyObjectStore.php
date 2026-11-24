<?php

/**
 * VocabularyObjectStore — uri-keyed reads and writes on one vocabulary schema.
 *
 * Upserts conceptScheme/concept objects keyed on their durable SKOS source
 * `uri` (never the OpenRegister uuid) and flags concepts a re-import no longer
 * carries as `deprecated` — never deletes them (design.md D3). Used by
 * {@see VocabularyImportService}.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\VocabularyImporterInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;

/**
 * Uri-keyed persistence for the objects of one vocabulary schema.
 */
class VocabularyObjectStore {
	/**
	 * Page size of the deprecation sweep.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService OR object CRUD (findAll/saveObject).
	 * @param string $schema Schema slug (SCHEMA_SCHEME or SCHEMA_CONCEPT) this store reads and writes.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly string $schema,
	) {
	}//end __construct()

	/**
	 * Create the object for a uri only when none exists; an existing one is left exactly as it is.
	 *
	 * Unlike upsertByUri() nothing an administrator edited is overwritten, which is what a seed
	 * that runs on every upgrade needs.
	 *
	 * @param array<string, mixed> $data The object data, with its `uri`.
	 *
	 * @return array{status: string, uuid: string} `created` or `present`, and the object's uuid.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/tasks.md#3-the-dictionary
	 */
	public function createIfMissing(array $data): array {
		$existing = $this->findByUri(uri: (string)$data['uri']);
		if ($existing !== null) {
			return ['status' => 'present', 'uuid' => (string)$existing->getUuid()];
		}

		return ['status' => 'created', 'uuid' => (string)$this->save(data: $data, uuid: null)->getUuid()];
	}//end createIfMissing()

	/**
	 * Create the object for `$data['uri']`, or update it when a compared key changed.
	 *
	 * @param array<string,mixed> $data The computed data, including `uri`.
	 *
	 * @return array{status: string, uuid: string} status is created|updated|unchanged.
	 */
	public function upsertByUri(array $data): array {
		$existing = $this->findByUri(uri: $data['uri']);
		if ($existing === null) {
			return ['status' => 'created', 'uuid' => (string)$this->save(data: $data, uuid: null)->getUuid()];
		}

		return $this->updateExisting(existing: $existing, data: $data);
	}//end upsertByUri()

	/**
	 * Update a stored object when a compared key changed.
	 *
	 * @param ObjectEntity        $existing The stored object with the same uri.
	 * @param array<string,mixed> $data     The computed data, including `uri`.
	 *
	 * @return array{status: string, uuid: string} status is updated|unchanged.
	 */
	private function updateExisting(ObjectEntity $existing, array $data): array {
		$existingData = ($existing->getObject() ?? []);
		$uuid = (string)$existing->getUuid();
		if ($this->dataChanged(existingData: $existingData, newData: $data) === false) {
			return ['status' => 'unchanged', 'uuid' => $uuid];
		}

		// The saveObject call is PUT-semantic (omitted properties are cleared)
		// — carry every existing field forward, only the compared keys are overridden.
		$this->save(data: array_merge($existingData, $data), uuid: $existing->getUuid());
		return ['status' => 'updated', 'uuid' => $uuid];
	}//end updateExisting()

	/**
	 * Save an object of this store's schema.
	 *
	 * @param array<string,mixed> $data The full object data.
	 * @param string|null $uuid The uuid to update, or null to create.
	 *
	 * @return ObjectEntity The saved object.
	 */
	public function save(array $data, ?string $uuid): ObjectEntity {
		return $this->objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: VocabularyImporterInterface::REGISTER, schema: $this->schema),
				uuid: $uuid
			)
		);
	}//end save()

	/**
	 * Mark concepts (on the concept store) of a scheme absent from `$seenUris` as deprecated. Never deletes.
	 *
	 * @param string $schemeUuid The conceptScheme's uuid.
	 * @param array<string> $seenUris URIs present in the just-completed import.
	 *
	 * @return int Count of concepts newly marked deprecated.
	 */
	public function deprecateMissing(string $schemeUuid, array $seenUris): int {
		$count = 0;
		$offset = 0;
		do {
			$results = $this->conceptPage(schemeUuid: $schemeUuid, offset: $offset);
			// Counted once per page, not re-counted by the loop condition.
			$pageSize = count($results);
			$count += $this->deprecateAll(entities: $results, seenUris: $seenUris);
			$offset += self::PAGE_SIZE;
		} while ($pageSize === self::PAGE_SIZE);

		return $count;
	}//end deprecateMissing()

	/**
	 * Flag every concept of one page deprecated unless it is still current.
	 *
	 * @param array<int,ObjectEntity> $entities The stored concepts of one page.
	 * @param array<string>           $seenUris URIs present in the just-completed import.
	 *
	 * @return int Count of concepts newly marked deprecated.
	 */
	private function deprecateAll(array $entities, array $seenUris): int {
		$count = 0;
		foreach ($entities as $entity) {
			$count += $this->deprecate(entity: $entity, seenUris: $seenUris);
		}

		return $count;
	}//end deprecateAll()

	/**
	 * One page of a scheme's concepts.
	 *
	 * @param string $schemeUuid The conceptScheme's uuid.
	 * @param int $offset The page offset.
	 *
	 * @return array<int,ObjectEntity>
	 */
	private function conceptPage(string $schemeUuid, int $offset): array {
		return $this->findInSchema(
			filters: ['inScheme' => $schemeUuid],
			page: ['limit' => self::PAGE_SIZE, 'offset' => $offset]
		);
	}//end conceptPage()

	/**
	 * Find objects of this store's schema, scoped by register + schema under `filters`.
	 *
	 * The pair goes UNDER `filters`. `prepareFindAllConfig()` reads
	 * `$config['filters']['register']` / `['schema']` and no other key, so
	 * a top-level pair scopes NOTHING — the read runs on whatever the last
	 * write left on the shared ObjectService. In the deprecation sweep that was
	 * self-concealing: the `saveObject()` in deprecate() anchors the service
	 * on exactly this pair, so page 1 read an arbitrary scope and every later
	 * page read the right one, by accident. openregister#3408 is what the
	 * same shape costs when no write happens to precede the read. For the uri
	 * lookup an unscoped read answers "does this uri already exist?" from the
	 * wrong table, and the import creates a duplicate rather than updating.
	 *
	 * @param array<string,mixed> $filters Filters after the register + schema pair.
	 * @param array<string,int>   $page    `limit`, and optionally `offset`.
	 *
	 * @return array<int,ObjectEntity>
	 */
	private function findInSchema(array $filters, array $page): array {
		$scope = ['register' => VocabularyImporterInterface::REGISTER, 'schema' => $this->schema];

		return $this->objectService->findAll(config: array_merge(['filters' => array_merge($scope, $filters)], $page));
	}//end findInSchema()

	/**
	 * Flag one concept deprecated unless it is still current.
	 *
	 * @param ObjectEntity $entity The stored concept.
	 * @param array<string> $seenUris URIs present in the just-completed import.
	 *
	 * @return int 1 when the concept was newly deprecated, else 0.
	 */
	private function deprecate(ObjectEntity $entity, array $seenUris): int {
		$data = ($entity->getObject() ?? []);
		if ($this->staysCurrent(data: $data, seenUris: $seenUris) === true) {
			return 0;
		}

		$data['deprecated'] = true;
		$this->save(data: $data, uuid: $entity->getUuid());
		return 1;
	}//end deprecate()

	/**
	 * Whether a stored concept is left alone by the sweep: no uri, re-imported, or already deprecated.
	 *
	 * @param array<string,mixed> $data The stored concept data.
	 * @param array<string> $seenUris URIs present in the just-completed import.
	 *
	 * @return bool
	 */
	private function staysCurrent(array $data, array $seenUris): bool {
		$uri = ($data['uri'] ?? null);
		return $uri === null
			|| in_array($uri, $seenUris, true) === true
			|| ($data['deprecated'] ?? false) === true;
	}//end staysCurrent()

	/**
	 * Find a conceptScheme/concept object by its durable source uri.
	 *
	 * @param string $uri The source uri to look up.
	 *
	 * @return ObjectEntity|null The matching object, or null when absent.
	 */
	private function findByUri(string $uri): ?ObjectEntity {
		// The import's identity lookup — see findInSchema() for why the scope matters.
		$results = $this->findInSchema(filters: ['uri' => $uri], page: ['limit' => 1]);

		return ($results[0] ?? null);
	}//end findByUri()

	/**
	 * Whether any of the compared keys in `$newData` differ from `$existingData`.
	 *
	 * @param array<string,mixed> $existingData The stored object data.
	 * @param array<string,mixed> $newData The newly computed data (subset of keys to compare).
	 *
	 * @return bool True when at least one compared key differs.
	 */
	private function dataChanged(array $existingData, array $newData): bool {
		foreach ($newData as $key => $value) {
			if (($existingData[$key] ?? null) !== $value) {
				return true;
			}
		}

		return false;
	}//end dataChanged()
}//end class
