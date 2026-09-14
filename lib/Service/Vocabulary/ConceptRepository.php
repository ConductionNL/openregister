<?php

/**
 * OpenRegister ConceptRepository
 *
 * Reads a concept scheme out of the vocabulary register and hands it to the
 * pure walkers as a map keyed by uri.
 *
 * It exists so that {@see ConceptLifecycle} and {@see ConceptHierarchy} never
 * touch the object layer, and so a save that validates six coded properties
 * against the same scheme reads that scheme once. The cache is request-scoped
 * on purpose: a concept added a minute ago must be writable without a schema
 * save (the dependency change's own requirement), and a cache that outlived
 * the request would be the thing that broke it.
 *
 * It reads through `MagicMapper` rather than through `ObjectService`, and that
 * is not a style choice. The write-time guard above this runs inside a save,
 * from an event listener the container builds at dispatch time. Depending on
 * `ObjectService` there would make every coded save construct a second
 * `ObjectService` graph, and `ObjectService` is the service that dispatched
 * the event in the first place. The mappers have no such cycle.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\VocabularyImportService;
use Throwable;

/**
 * Request-scoped reader for concept schemes.
 */
class ConceptRepository {

	/**
	 * Upper bound on concepts read for one scheme.
	 *
	 * Mirrors {@see \OCA\OpenRegister\Controller\VocabularyController}: a
	 * vocabulary scheme is reference data, tens to low thousands of terms.
	 *
	 * @var integer
	 */
	public const MAX_SCHEME_CONCEPTS = 2000;

	/**
	 * Concepts already read this request, keyed by scheme uri.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	private array $conceptCache = [];

	/**
	 * Scheme objects already read this request, keyed by scheme uri.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private array $schemeCache = [];

	/**
	 * Resolved `[registerId, schemeSchemaId, conceptSchemaId]`, or null when
	 * the vocabulary register is not installed on this instance.
	 *
	 * @var array{0:int,1:int,2:int}|null
	 */
	private ?array $resolvedIds = null;

	/**
	 * Whether resolution has already been attempted this request.
	 *
	 * @var boolean
	 */
	private bool $resolutionAttempted = false;

	/**
	 * Constructor.
	 *
	 * @param MagicMapper $objects Reads objects out of a register/schema table.
	 * @param RegisterMapper $registers Resolves the vocabulary register.
	 * @param SchemaMapper $schemas Resolves the conceptScheme and concept schemas.
	 */
	public function __construct(
		private readonly MagicMapper $objects,
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
	) {

	}//end __construct()

	/**
	 * The numeric ids of the vocabulary register and its two schemas.
	 *
	 * Resolved once per request and cached as a failure too: on an instance
	 * without the vocabulary register every coded save would otherwise pay for
	 * three failing mapper lookups.
	 *
	 * @return array{0:int,1:int,2:int}|null The ids, or null when unresolvable.
	 */
	private function ids(): ?array {
		if ($this->resolutionAttempted === true) {
			return $this->resolvedIds;
		}

		$this->resolutionAttempted = true;

		try {
			$register = $this->registers->find(
				id: VocabularyImportService::REGISTER,
				_rbac: false,
				_multitenancy: false
			);
			$scheme = $this->schemas->find(
				id: VocabularyImportService::SCHEMA_SCHEME,
				_rbac: false,
				_multitenancy: false
			);
			$concept = $this->schemas->find(
				id: VocabularyImportService::SCHEMA_CONCEPT,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $missing) {
			return null;
		}

		$this->resolvedIds = [(int)$register->getId(), (int)$scheme->getId(), (int)$concept->getId()];

		return $this->resolvedIds;
	}//end ids()

	/**
	 * Run one read against the vocabulary register.
	 *
	 * @param integer $registerId The vocabulary register's id.
	 * @param integer $schemaId The schema to read from.
	 * @param array<string,mixed> $filters Object-field filters.
	 * @param integer $limit The row cap.
	 * @param array<string,mixed> $metadata Extra `@self` metadata filters.
	 *
	 * @return array<int,ObjectEntity> The matching entities.
	 */
	private function read(int $registerId, int $schemaId, array $filters, int $limit, array $metadata = []): array {
		// Vocabularies are public reference data and this read runs inside a
		// write guard, so it is deliberately not RBAC-scoped: a value the
		// caller may not LIST is still a value the scheme refuses to accept,
		// and hiding it would turn a refusal into a silent acceptance.
		$query = array_merge(
			$filters,
			[
				'@self' => array_merge($metadata, ['register' => $registerId, 'schema' => $schemaId]),
				'_limit' => $limit,
			]
		);

		try {
			$results = $this->objects->searchObjects(
				query: $query,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $unreadable) {
			return [];
		}

		if (is_array($results) === false) {
			return [];
		}

		return $results;
	}//end read()

	/**
	 * The scheme object identified by its durable uri, or null.
	 *
	 * @param string $schemeUri The scheme's uri.
	 *
	 * @return array<string,mixed>|null The scheme's decoded object data.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function scheme(string $schemeUri): ?array {
		if (array_key_exists($schemeUri, $this->schemeCache) === true) {
			return $this->schemeCache[$schemeUri];
		}

		$this->schemeCache[$schemeUri] = null;

		$ids = $this->ids();
		if ($ids === null) {
			return null;
		}

		$results = $this->read(
			registerId: $ids[0],
			schemaId: $ids[1],
			filters: ['uri' => $schemeUri],
			limit: 1
		);

		$entity = ($results[0] ?? null);
		if ($entity instanceof ObjectEntity === false) {
			return null;
		}

		$data = ($entity->getObject() ?? []);
		if (is_array($data) === false) {
			return null;
		}

		$data['@uuid'] = (string)$entity->getUuid();
		$this->schemeCache[$schemeUri] = $data;

		return $data;
	}//end scheme()

	/**
	 * The scheme a uuid or slug reference points at, or null.
	 *
	 * `inScheme` on a concept stores the scheme object's uuid, not its uri, so
	 * a guard that has a concept in hand and needs the scheme's uri has to
	 * come back through the register to get it.
	 *
	 * @param string $reference The scheme object's uuid, id or slug.
	 *
	 * @return array<string,mixed>|null The scheme's decoded object data.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function schemeByReference(string $reference): ?array {
		foreach ($this->schemeCache as $cached) {
			if (is_array($cached) === true && (string)($cached['@uuid'] ?? '') === $reference) {
				return $cached;
			}
		}

		$ids = $this->ids();
		if ($ids === null) {
			return null;
		}

		$results = $this->read(
			registerId: $ids[0],
			schemaId: $ids[1],
			filters: [],
			limit: 1,
			metadata: ['uuid' => $reference]
		);

		$entity = ($results[0] ?? null);
		if ($entity instanceof ObjectEntity === false) {
			return null;
		}

		$data = ($entity->getObject() ?? []);
		if (is_array($data) === false) {
			return null;
		}

		$data['@uuid'] = (string)$entity->getUuid();
		$uri = trim((string)($data['uri'] ?? ''));
		if ($uri !== '') {
			$this->schemeCache[$uri] = $data;
		}

		return $data;
	}//end schemeByReference()

	/**
	 * The shape a scheme declares for its concepts, or an empty array.
	 *
	 * @param string $schemeUri The scheme's uri.
	 *
	 * @return array<string,mixed> The declared concept shape.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function conceptShape(string $schemeUri): array {
		$scheme = $this->scheme(schemeUri: $schemeUri);
		if ($scheme === null) {
			return [];
		}

		$shape = ($scheme[ConceptLifecycle::SCHEME_FIELD_SHAPE] ?? null);
		if (is_array($shape) === false) {
			return [];
		}

		return $shape;
	}//end conceptShape()

	/**
	 * Every concept of a scheme, keyed by its durable uri.
	 *
	 * An unreadable scheme answers with the empty map rather than throwing:
	 * a vocabulary that cannot be read must not become the reason nothing can
	 * be saved. The guards above this treat an empty map as "nothing to
	 * refuse", which fails open on infrastructure and closed on data.
	 *
	 * @param string $schemeUri The scheme's uri.
	 *
	 * @return array<string,array<string,mixed>> The concepts, keyed by uri.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function conceptsOf(string $schemeUri): array {
		if (array_key_exists($schemeUri, $this->conceptCache) === true) {
			return $this->conceptCache[$schemeUri];
		}

		$this->conceptCache[$schemeUri] = [];

		$ids = $this->ids();
		if ($ids === null) {
			return [];
		}

		$scheme = $this->scheme(schemeUri: $schemeUri);
		if ($scheme === null) {
			return [];
		}

		$schemeRef = trim((string)($scheme['@uuid'] ?? ($scheme['id'] ?? ($scheme['uuid'] ?? ''))));
		$filters = [];
		if ($schemeRef !== '') {
			$filters['inScheme'] = $schemeRef;
		}

		$results = $this->read(
			registerId: $ids[0],
			schemaId: $ids[2],
			filters: $filters,
			limit: self::MAX_SCHEME_CONCEPTS
		);

		$byUri = [];
		foreach ($results as $entity) {
			if ($entity instanceof ObjectEntity === false) {
				continue;
			}

			$data = ($entity->getObject() ?? []);
			if (is_array($data) === false) {
				continue;
			}

			$uri = trim((string)($data['uri'] ?? ''));
			if ($uri === '') {
				continue;
			}

			$data['@uuid'] = (string)$entity->getUuid();
			$byUri[$uri] = $data;
		}//end foreach

		$this->conceptCache[$schemeUri] = $byUri;

		return $byUri;
	}//end conceptsOf()

	/**
	 * Resolve a stored value to its concept, in either storage form.
	 *
	 * A property storing notations rather than uris is the second half of the
	 * dependency change's `store` key; resolving both here means no caller has
	 * to branch on it.
	 *
	 * @param string $value The stored value.
	 * @param string $schemeUri The scheme's uri.
	 * @param string $store Either `uri` or `notation`.
	 *
	 * @return array<string,mixed>|null The concept, or null when the scheme does not hold it.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function resolve(string $value, string $schemeUri, string $store = 'uri'): ?array {
		$concepts = $this->conceptsOf(schemeUri: $schemeUri);
		if ($store === 'uri') {
			return ($concepts[$value] ?? null);
		}

		foreach ($concepts as $concept) {
			if ((string)($concept['notation'] ?? '') === $value) {
				return $concept;
			}
		}

		return null;
	}//end resolve()

	/**
	 * Drop everything read this request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function clearCache(): void {
		$this->conceptCache = [];
		$this->schemeCache = [];
		$this->resolvedIds = null;
		$this->resolutionAttempted = false;
	}//end clearCache()
}//end class
