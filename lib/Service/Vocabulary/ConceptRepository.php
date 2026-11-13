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
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectSearchInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\VocabularyImportService;
use Throwable;

/**
 * Request-scoped reader for concept schemes.
 */
class ConceptRepository implements ConceptRepositoryInterface {

	/**
	 * A uuid, any version, any case.
	 *
	 * @var string
	 */
	private const UUID_SHAPE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

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
	 * @param SchemaCatalogInterface     $catalog Resolves the vocabulary register and its conceptScheme and concept schemas.
	 * @param MagicObjectSearchInterface $objects Reads objects out of a register/schema table.
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly MagicObjectSearchInterface $objects,
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
			$ids = $this->lookUpIds();
		} catch (Throwable $missing) {
			return null;
		}

		$this->resolvedIds = $ids;

		return $this->resolvedIds;
	}//end ids()

	/**
	 * Look up the vocabulary register, scheme schema and concept schema ids.
	 *
	 * @return array{0:int,1:int,2:int} The ids.
	 *
	 * @throws Throwable When one of them does not exist.
	 */
	private function lookUpIds(): array {
		return [
			$this->registerIdOf(slug: VocabularyImportService::REGISTER),
			$this->schemaIdOf(slug: VocabularyImportService::SCHEMA_SCHEME),
			$this->schemaIdOf(slug: VocabularyImportService::SCHEMA_CONCEPT),
		];
	}//end lookUpIds()

	/**
	 * The id of a register, read without RBAC or multitenancy (vocabularies are public reference data).
	 *
	 * @param string $slug The register's slug.
	 *
	 * @return int The id.
	 *
	 * @throws Throwable When the register does not exist.
	 */
	private function registerIdOf(string $slug): int {
		return (int)$this->catalog->registers()->find(id: $slug, _rbac: false, _multitenancy: false)->getId();
	}//end registerIdOf()

	/**
	 * The id of a schema, read without RBAC or multitenancy (vocabularies are public reference data).
	 *
	 * @param string $slug The schema's slug.
	 *
	 * @return int The id.
	 *
	 * @throws Throwable When the schema does not exist.
	 */
	private function schemaIdOf(string $slug): int {
		return (int)$this->catalog->schemas()->find(id: $slug, _rbac: false, _multitenancy: false)->getId();
	}//end schemaIdOf()

	/**
	 * The `@self` scope of a read against the vocabulary register, whose ids the caller has resolved.
	 *
	 * @param integer             $schemaId The schema to read from.
	 * @param array<string,mixed> $metadata Extra `@self` metadata filters.
	 *
	 * @return array<string,mixed> The metadata filters, scoped to the register and schema.
	 */
	private function scope(int $schemaId, array $metadata): array {
		return array_merge($metadata, ['register' => (int)($this->ids()[0] ?? 0), 'schema' => $schemaId]);
	}//end scope()

	/**
	 * Run one search query against the object layer.
	 *
	 * @param array<string,mixed> $query The search query: object-field filters, the `@self` scope and the row cap.
	 *
	 * @return array<int,ObjectEntity> The matching entities.
	 */
	private function search(array $query): array {
		// Vocabularies are public reference data and this read runs inside a
		// write guard, so it is deliberately not RBAC-scoped: a value the
		// caller may not LIST is still a value the scheme refuses to accept,
		// and hiding it would turn a refusal into a silent acceptance.
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
	}//end search()

	/**
	 * The scheme a property's binding names, or null: by uri, then slug, then uuid.
	 *
	 * The two spellings of the binding name a scheme differently: the
	 * `x-openregister-concepts` annotation by its durable uri, the `conceptScheme`
	 * modifier by its slug, as its published description says. Both reach every
	 * reader (the value check, the options and the filter expansion) through
	 * this one method, so resolving here keeps the three from disagreeing about
	 * which scheme a property is bound to. The uri is tried first, so a slug that
	 * happens to equal another scheme's uri cannot take its place.
	 *
	 * @param string $schemeUri The scheme's uri, slug or uuid.
	 *
	 * @return array<string,mixed>|null The scheme's decoded object data.
	 *
	 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
	 */
	public function scheme(string $schemeUri): ?array {
		if (array_key_exists($schemeUri, $this->schemeCache) === true) {
			return $this->schemeCache[$schemeUri];
		}

		$this->schemeCache[$schemeUri] = null;

		$data = $this->firstScheme(filters: ['uri' => $schemeUri], metadata: []);
		if ($data === null) {
			$data = $this->schemeBySlugOrUuid(reference: $schemeUri);
		}

		if ($data === null) {
			return null;
		}

		$this->schemeCache[$schemeUri] = $data;

		return $data;
	}//end scheme()

	/**
	 * The scheme whose slug, else whose uuid, is the reference, or null.
	 *
	 * A uuid lookup is only tried on a uuid-shaped reference: the metadata
	 * column is typed, and a slug passed to it is a query error rather than a
	 * miss.
	 *
	 * @param string $reference The scheme's slug or uuid.
	 *
	 * @return array<string,mixed>|null The scheme's decoded object data.
	 *
	 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
	 */
	private function schemeBySlugOrUuid(string $reference): ?array {
		$reference = trim($reference);
		if ($reference === '') {
			return null;
		}

		$data = $this->firstScheme(filters: [], metadata: ['slug' => $reference]);
		if ($data !== null || preg_match(self::UUID_SHAPE, $reference) !== 1) {
			return $data;
		}

		return $this->firstScheme(filters: [], metadata: ['uuid' => $reference]);
	}//end schemeBySlugOrUuid()

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
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function schemeByReference(string $reference): ?array {
		$cached = $this->cachedSchemeWithUuid(reference: $reference);
		if ($cached !== null) {
			return $cached;
		}

		$data = $this->firstScheme(filters: [], metadata: ['uuid' => $reference]);
		if ($data === null) {
			return null;
		}

		$uri = trim((string)($data['uri'] ?? ''));
		if ($uri !== '') {
			$this->schemeCache[$uri] = $data;
		}

		return $data;
	}//end schemeByReference()

	/**
	 * A scheme already read this request whose uuid is the reference, or null.
	 *
	 * @param string $reference The scheme's uuid.
	 *
	 * @return array<string, mixed>|null The cached scheme.
	 */
	private function cachedSchemeWithUuid(string $reference): ?array {
		foreach ($this->schemeCache as $cached) {
			if (is_array($cached) === true && (string)($cached['@uuid'] ?? '') === $reference) {
				return $cached;
			}
		}

		return null;
	}//end cachedSchemeWithUuid()

	/**
	 * The first scheme object matching the filters, as decoded data carrying its `@uuid`; null when none.
	 *
	 * @param array<string,mixed> $filters  Property filters.
	 * @param array<string,mixed> $metadata `@self` filters besides register and schema.
	 *
	 * @return array<string,mixed>|null The scheme's decoded object data.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	private function firstScheme(array $filters, array $metadata): ?array {
		$ids = $this->ids();
		if ($ids === null) {
			return null;
		}

		$results = $this->search(
			query: array_merge($filters, ['@self' => $this->scope(schemaId: $ids[1], metadata: $metadata), '_limit' => 1])
		);

		return self::entityData(entity: ($results[0] ?? null));
	}//end firstScheme()

	/**
	 * The shape a scheme declares for its concepts, or an empty array.
	 *
	 * @param string $schemeUri The scheme's uri.
	 *
	 * @return array<string,mixed> The declared concept shape.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
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
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function conceptsOf(string $schemeUri): array {
		if (array_key_exists($schemeUri, $this->conceptCache) === true) {
			return $this->conceptCache[$schemeUri];
		}

		$this->conceptCache[$schemeUri] = [];
		$this->conceptCache[$schemeUri] = $this->readConcepts(schemeUri: $schemeUri);

		return $this->conceptCache[$schemeUri];
	}//end conceptsOf()

	/**
	 * Read a scheme's concepts from the vocabulary register.
	 *
	 * @param string $schemeUri The scheme's URI.
	 *
	 * @return array<string, array<string, mixed>> Concepts keyed by URI; empty when the
	 *                                             register or the scheme is not there.
	 */
	private function readConcepts(string $schemeUri): array {
		$ids = $this->ids();
		if ($ids === null) {
			return [];
		}

		$scheme = $this->scheme(schemeUri: $schemeUri);
		if ($scheme === null) {
			return [];
		}

		return self::keyedByUri(results: $this->search(query: $this->conceptQuery(scheme: $scheme, conceptSchemaId: $ids[2])));
	}//end readConcepts()

	/**
	 * The query for a scheme's concepts: in the scheme, scoped to the concept schema, capped.
	 *
	 * @param array<string, mixed> $scheme          The scheme object.
	 * @param int                  $conceptSchemaId The concept schema's id.
	 *
	 * @return array<string, mixed> The query.
	 */
	private function conceptQuery(array $scheme, int $conceptSchemaId): array {
		$schemeRef = trim((string)($scheme['@uuid'] ?? ($scheme['id'] ?? ($scheme['uuid'] ?? ''))));
		$filters = [];
		if ($schemeRef !== '') {
			$filters['inScheme'] = $schemeRef;
		}

		return array_merge($filters, ['@self' => $this->scope(schemaId: $conceptSchemaId, metadata: []), '_limit' => self::MAX_SCHEME_CONCEPTS]);
	}//end conceptQuery()

	/**
	 * The concepts among the search results, keyed by their trimmed URI.
	 *
	 * @param array<int, mixed> $results The search results.
	 *
	 * @return array<string, array<string, mixed>> The concepts.
	 */
	private static function keyedByUri(array $results): array {
		$byUri = [];
		foreach ($results as $entity) {
			$data = self::conceptData(entity: $entity);
			if ($data !== null) {
				$byUri[trim((string)$data['uri'])] = $data;
			}
		}

		return $byUri;
	}//end keyedByUri()

	/**
	 * A result's concept data with its uuid added, or null when it is not a concept with a URI.
	 *
	 * @param mixed $entity One search result.
	 *
	 * @return array<string, mixed>|null The concept data.
	 */
	private static function conceptData(mixed $entity): ?array {
		$data = self::entityData(entity: $entity);
		if ($data === null || trim((string)($data['uri'] ?? '')) === '') {
			return null;
		}

		return $data;
	}//end conceptData()

	/**
	 * A result's object data with its uuid added, or null when it is not an object with array data.
	 *
	 * @param mixed $entity One search result.
	 *
	 * @return array<string, mixed>|null The data.
	 */
	private static function entityData(mixed $entity): ?array {
		if ($entity instanceof ObjectEntity === false) {
			return null;
		}

		$data = ($entity->getObject() ?? []);
		if (is_array($data) === false) {
			return null;
		}

		$data['@uuid'] = (string)$entity->getUuid();
		return $data;
	}//end entityData()

	/**
	 * Resolve a stored value to its concept, in either storage form.
	 *
	 * A property storing notations rather than uris is the second half of the
	 * dependency change's `store` key; resolving both here means no caller has
	 * to branch on it.
	 *
	 * @param string                   $value       The stored value.
	 * @param CodedPropertyDeclaration $declaration The declaration naming the scheme and the storage form (`uri` or `notation`).
	 *
	 * @return array<string,mixed>|null The concept, or null when the scheme does not hold it.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function resolve(string $value, CodedPropertyDeclaration $declaration): ?array {
		$concepts = $this->conceptsOf(schemeUri: $declaration->scheme);
		if ($declaration->store === 'uri') {
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
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function clearCache(): void {
		$this->conceptCache = [];
		$this->schemeCache = [];
		$this->resolvedIds = null;
		$this->resolutionAttempted = false;
	}//end clearCache()
}//end class
