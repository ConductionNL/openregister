<?php

/**
 * VocabularyController — public read-only resolution endpoints for the SKOS
 * vocabulary register (skos-concept-registers, SKOS-004).
 *
 * Three endpoints, all `#[PublicPage]` because vocabularies are public
 * reference data (design.md D5) — writes to the `vocabulary` register stay
 * admin-gated via its schema `authorization` block, only reads are opened
 * here:
 *   - GET /api/vocabulary/concept          resolve a concept by exact uri
 *   - GET /api/vocabulary/concept/notation resolve a concept by (scheme, notation)
 *   - GET /api/vocabulary/concepts         list a scheme's concepts, paginated,
 *                                          with a language-agnostic label search
 *                                          across prefLabel/altLabel (design.md D5)
 *
 * Unknown uris/notations/schemes always resolve to a uniform 404 with the
 * standard `{"message": ...}` error shape — never an empty 200 (SKOS-004).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
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
 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\VocabularyImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Public resolution controller for the vocabulary register.
 *
 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-004
 */
class VocabularyController extends Controller {

	/**
	 * Register slug (mirrors {@see VocabularyImportService::REGISTER}).
	 *
	 * @var string
	 */
	private const REGISTER = VocabularyImportService::REGISTER;

	/**
	 * ConceptScheme schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_SCHEME = VocabularyImportService::SCHEMA_SCHEME;

	/**
	 * Concept schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_CONCEPT = VocabularyImportService::SCHEMA_CONCEPT;

	/**
	 * Upper bound on concepts fetched for a scheme's in-memory label-search
	 * pass. Vocabulary schemes are reference data (tens to low thousands of
	 * concepts, e.g. TOOI's 17 informatiecategorieën); this cap keeps the
	 * search endpoint O(1) request shape without needing a dedicated
	 * full-text index over the multilingual label maps.
	 *
	 * @var int
	 */
	private const MAX_SCHEME_CONCEPTS = 2000;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name (injected by NC).
	 * @param IRequest $request Current request.
	 * @param ObjectService $objectService OR object read path (findAll — real API only).
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objectService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/vocabulary/concept?uri=...
	 *
	 * Resolve a single concept by its exact durable source uri.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return JSONResponse The concept object, or a 404 standard error shape.
	 *
	 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function resolveByUri(): JSONResponse {
		$uri = trim((string)$this->request->getParam('uri', ''));
		if ($uri === '') {
			return $this->notFound();
		}

		$draft = $this->findOneBy(schema: self::SCHEMA_CONCEPT, filters: ['uri' => $uri]);
		if ($draft === null) {
			return $this->notFound();
		}

		return new JSONResponse($draft->jsonSerialize());
	}//end resolveByUri()

	/**
	 * GET /api/vocabulary/concept/notation?scheme=...&notation=...
	 *
	 * Resolve a single concept by its owning scheme's uri plus its notation.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return JSONResponse The concept object, or a 404 standard error shape.
	 *
	 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function resolveByNotation(): JSONResponse {
		$scheme = trim((string)$this->request->getParam('scheme', ''));
		$notation = trim((string)$this->request->getParam('notation', ''));
		if ($scheme === '' || $notation === '') {
			return $this->notFound();
		}

		$schemeUuid = $this->resolveSchemeUuid(schemeUriOrUuid: $scheme);
		if ($schemeUuid === null) {
			return $this->notFound();
		}

		$draft = $this->findOneBy(
			schema: self::SCHEMA_CONCEPT,
			filters: [
				'inScheme' => $schemeUuid,
				'notation' => $notation,
			]
		);
		if ($draft === null) {
			return $this->notFound();
		}

		return new JSONResponse($draft->jsonSerialize());
	}//end resolveByNotation()

	/**
	 * GET /api/vocabulary/concepts?scheme=...&q=...&_limit=&_offset=
	 *
	 * List a scheme's concepts, paginated, optionally filtered by a
	 * language-agnostic label search over prefLabel/altLabel.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return JSONResponse The standard paginated objects envelope, or a 404 when the scheme is unknown.
	 *
	 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function listConcepts(): JSONResponse {
		$scheme = trim((string)$this->request->getParam('scheme', ''));
		if ($scheme === '') {
			return new JSONResponse(
				['message' => 'Query parameter "scheme" is required.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$schemeUuid = $this->resolveSchemeUuid(schemeUriOrUuid: $scheme);
		if ($schemeUuid === null) {
			return $this->notFound();
		}

		$query = trim((string)$this->request->getParam('q', ''));
		$limit = max(1, min(200, (int)$this->request->getParam('_limit', 20)));
		$offset = max(0, (int)$this->request->getParam('_offset', 0));

		try {
			$all = $this->objectService->findAll(
				config: [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_CONCEPT,
					'filters' => ['inScheme' => $schemeUuid],
					'limit' => self::MAX_SCHEME_CONCEPTS,
				]
			);
		} catch (Throwable $e) {
			return new JSONResponse(
				['message' => 'Unable to list concepts.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		$matching = [];
		foreach ($all as $entity) {
			$data = ($entity->getObject() ?? []);
			if ($query === '' || $this->labelMatches(data: $data, query: $query) === true) {
				$matching[] = $entity;
			}
		}

		$total = count($matching);
		$page = array_slice($matching, $offset, $limit);

		return new JSONResponse(
			[
				'results' => array_map(static fn ($entity): array => $entity->jsonSerialize(), $page),
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
				'page' => ((int)floor($offset / max(1, $limit)) + 1),
				'pages' => ((int)ceil($total / max(1, $limit))),
			]
		);
	}//end listConcepts()

	/**
	 * Resolve a scheme's uuid from its durable uri (or pass an already-resolved uuid through).
	 *
	 * @param string $schemeUriOrUuid The scheme's source uri (or its OpenRegister uuid).
	 *
	 * @return string|null The scheme's uuid, or null when unresolvable.
	 */
	private function resolveSchemeUuid(string $schemeUriOrUuid): ?string {
		$scheme = $this->findOneBy(schema: self::SCHEMA_SCHEME, filters: ['uri' => $schemeUriOrUuid]);
		if ($scheme !== null) {
			return (string)$scheme->getUuid();
		}

		// Fall back to treating the value as an already-resolved uuid (a
		// leaf caller that stored the uuid rather than the source uri).
		try {
			$byId = $this->objectService->find(
				id: $schemeUriOrUuid,
				register: self::REGISTER,
				schema: self::SCHEMA_SCHEME
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($byId === null) {
			return null;
		}

		return (string)$byId->getUuid();
	}//end resolveSchemeUuid()

	/**
	 * Find a single object of `$schema` matching `$filters`, or null.
	 *
	 * @param string $schema Schema slug within the vocabulary register.
	 * @param array<string,string> $filters Exact-match field filters.
	 *
	 * @return ObjectEntity|null
	 */
	private function findOneBy(string $schema, array $filters): ?ObjectEntity {
		try {
			$results = $this->objectService->findAll(
				config: [
					'register' => self::REGISTER,
					'schema' => $schema,
					'filters' => $filters,
					'limit' => 1,
				]
			);
		} catch (Throwable $e) {
			return null;
		}

		return ($results[0] ?? null);
	}//end findOneBy()

	/**
	 * Whether `$query` case/language-insensitively matches any prefLabel or
	 * altLabel value on `$data`.
	 *
	 * @param array<string,mixed> $data A concept's decoded object data.
	 * @param string $query The search term.
	 *
	 * @return bool
	 */
	private function labelMatches(array $data, string $query): bool {
		$needle = mb_strtolower($query);
		foreach (['prefLabel', 'altLabel'] as $field) {
			$labels = ($data[$field] ?? null);
			if (is_array($labels) === false) {
				continue;
			}

			foreach ($labels as $label) {
				if (is_string($label) === true && str_contains(mb_strtolower($label), $needle) === true) {
					return true;
				}
			}
		}

		return false;
	}//end labelMatches()

	/**
	 * The standard 404 error shape.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['message' => 'Not Found'], Http::STATUS_NOT_FOUND);
	}//end notFound()
}//end class
