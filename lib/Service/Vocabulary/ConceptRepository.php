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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
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
	 * Constructor.
	 *
	 * @param ObjectService $objectService The object read path.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {

	}//end __construct()

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

		try {
			// The register/schema pair goes UNDER `filters` — a top-level pair
			// is read by nothing and the query then runs on whatever the last
			// write left on the shared service (openregister#3408).
			$results = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => VocabularyImportService::REGISTER,
						'schema' => VocabularyImportService::SCHEMA_SCHEME,
						'uri' => $schemeUri,
					],
					'limit' => 1,
				]
			);
		} catch (Throwable $unreadable) {
			return null;
		}

		$entity = ($results[0] ?? null);
		if ($entity instanceof ObjectEntity === false) {
			return null;
		}

		$data = ($entity->getObject() ?? []);
		if (is_array($data) === false) {
			return null;
		}

		$this->schemeCache[$schemeUri] = $data;

		return $data;
	}//end scheme()

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

		$scheme = $this->scheme(schemeUri: $schemeUri);
		if ($scheme === null) {
			return [];
		}

		$schemeId = trim((string)($scheme['id'] ?? ($scheme['uuid'] ?? '')));
		$filters = [
			'register' => VocabularyImportService::REGISTER,
			'schema' => VocabularyImportService::SCHEMA_CONCEPT,
		];
		if ($schemeId !== '') {
			$filters['inScheme'] = $schemeId;
		}

		try {
			$results = $this->objectService->findAll(
				config: [
					'filters' => $filters,
					'limit' => self::MAX_SCHEME_CONCEPTS,
				]
			);
		} catch (Throwable $unreadable) {
			return [];
		}

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
	}//end clearCache()
}//end class
