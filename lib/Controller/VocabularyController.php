<?php

/**
 * VocabularyController — public read-only resolution endpoints for the SKOS
 * vocabulary register (skos-concept-registers, SKOS-004).
 *
 * Four endpoints, all `#[PublicPage]` because vocabularies are public
 * reference data (design.md D5). Writes to the `vocabulary` register stay
 * admin-gated via its schema `authorization` block, only reads are opened
 * here:
 *   - GET /api/vocabulary/concept          resolve a concept by exact uri
 *   - GET /api/vocabulary/concept/notation resolve a concept by (scheme, notation)
 *   - GET /api/vocabulary/concepts         list a scheme's concepts, paginated,
 *                                          with a language-agnostic label search
 *                                          across prefLabel/altLabel (design.md D5)
 *   - GET /api/vocabulary/options          the options one coded property offers,
 *                                          flat or as a tree, with every value
 *                                          outside its validity window absent
 *                                          (code-list-lifecycle-and-hierarchy)
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
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilder;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\VocabularyImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
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
	 * @param ObjectService $objectService OR object read path (findAll, real API only).
	 * @param SchemaMapper $schemaMapper Reads the schema a coded property is declared on.
	 * @param CodedOptionsBuilder $options Builds a coded property's option list or option tree.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly SchemaMapper $schemaMapper,
		private readonly CodedOptionsBuilder $options,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/vocabulary/options?schema=...&property=...&context=...&tree=1
	 *
	 * The options a coded property offers, as a flat list or as a tree.
	 *
	 * A value outside its validity window is absent from this answer and
	 * still resolves through the three routes above, which is what lets a
	 * dossier from 2019 read correctly beside a picker that no longer offers
	 * the value it holds (REQ-CLH-001).
	 *
	 * `context` narrows the option subset for a property bound to another
	 * property's value or to a declared context key, so one `categorie` field
	 * serves many case types (REQ-CLH-002). `tree=1` returns the hierarchy
	 * rather than the flat list.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return JSONResponse The options, or a 404 when the schema or property is unknown.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function propertyOptions(): JSONResponse {
		$schemaRef = trim((string)$this->request->getParam('schema', ''));
		$property = trim((string)$this->request->getParam('property', ''));
		$schemeUri = trim((string)$this->request->getParam('scheme', ''));

		if (($schemaRef === '' || $property === '') && $schemeUri === '') {
			return new JSONResponse(
				['message' => 'Either "schema" and "property", or "scheme", must be given.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$declaration = null;
		if ($schemaRef !== '' && $property !== '') {
			try {
				$schema = $this->schemaMapper->find(id: $schemaRef);
			} catch (Throwable $missing) {
				return $this->notFound();
			}

			$properties = ($schema->getProperties() ?? []);
			$declaration = CodedPropertyDeclaration::fromProperty(property: ($properties[$property] ?? null));
		}

		if ($declaration === null && $schemeUri !== '') {
			// The unsaved-declaration path. The schema editor has to show the
			// hierarchy of a scheme the property is not yet bound to, because
			// the person choosing the branch is choosing it FROM that tree.
			// Reading a declaration off the query is how they see it before
			// the save rather than after.
			$declaration = CodedPropertyDeclaration::fromProperty(
				property: [
					CodedPropertyDeclaration::ANNOTATION => $this->declarationFromQuery(scheme: $schemeUri),
				]
			);
		}

		if ($declaration === null) {
			return $this->notFound();
		}

		if ($property === '') {
			$property = 'scheme';
		}

		$language = $this->negotiatedLanguage();
		$asTree = filter_var($this->request->getParam('tree', false), FILTER_VALIDATE_BOOLEAN);

		if ($asTree === true) {
			$tree = $this->options->tree(declaration: $declaration, language: $language);

			return new JSONResponse(
				[
					'property' => $property,
					'scheme' => $declaration->scheme,
					'language' => $language,
					'tree' => $tree,
				]
			);
		}

		$context = trim((string)$this->request->getParam('context', ''));
		if ($context === '') {
			$context = null;
		}

		$options = $this->options->options(
			declaration: $declaration,
			language: $language,
			context: $context
		);

		return new JSONResponse(
			[
				'property' => $property,
				'scheme' => $declaration->scheme,
				'language' => $language,
				'context' => $context,
				'results' => $options,
				'total' => count($options),
			]
		);
	}//end propertyOptions()

	/**
	 * Build a declaration from the query, for a property that is not saved yet.
	 *
	 * @param string $scheme The scheme's uri.
	 *
	 * @return array<string,mixed> The declaration.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	private function declarationFromQuery(string $scheme): array {
		$declaration = ['scheme' => $scheme];

		$branch = trim((string)$this->request->getParam('branch', ''));
		if ($branch !== '') {
			$declaration['branch'] = $branch;
		}

		$store = trim((string)$this->request->getParam('store', ''));
		if ($store !== '') {
			$declaration['store'] = $store;
		}

		$maxDepth = $this->request->getParam('maxDepth', null);
		if (is_numeric($maxDepth) === true) {
			$declaration['maxDepth'] = (int)$maxDepth;
		}

		$declaration['leafOnly'] = filter_var(
			$this->request->getParam('leafOnly', false),
			FILTER_VALIDATE_BOOLEAN
		);
		$declaration['allowDeprecated'] = filter_var(
			$this->request->getParam('allowDeprecated', false),
			FILTER_VALIDATE_BOOLEAN
		);

		return $declaration;
	}//end declarationFromQuery()

	/**
	 * The language the caller asked for, defaulting to Dutch.
	 *
	 * Dutch is the default rather than English because `prefLabel.nl` is the
	 * one label the concept schema requires, so it is the only tag guaranteed
	 * to resolve to something a person can read.
	 *
	 * @return string The BCP-47 tag.
	 */
	private function negotiatedLanguage(): string {
		$explicit = trim((string)$this->request->getParam('language', ''));
		if ($explicit !== '') {
			return $explicit;
		}

		$header = trim((string)$this->request->getHeader('Accept-Language'));
		if ($header === '') {
			return 'nl';
		}

		$first = trim((string)(explode(',', $header)[0] ?? ''));
		$first = trim((string)(explode(';', $first)[0] ?? ''));

		if ($first === '') {
			return 'nl';
		}

		return $first;
	}//end negotiatedLanguage()

	/**
	 * GET /api/vocabulary/concept?uri=...
	 *
	 * Resolve a single concept by its exact durable source uri.
	 *
	 * Published vocabulary — resolution is the point of it, so the rate limits
	 * on this method are runaway ceilings rather than gates.
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
	#[AnonRateLimit(limit: 120, period: 60)]
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
	#[AnonRateLimit(limit: 120, period: 60)]
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
	#[AnonRateLimit(limit: 120, period: 60)]
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
			// The pair goes UNDER `filters`. `ObjectService::prepareFindAllConfig()`
			// reads `$config['filters']['register']` and
			// `$config['filters']['schema']` and no other key, so a top-level pair
			// is read by nothing: the read then ran on whatever register/schema
			// the last write left on the shared service. See openregister#3408,
			// where exactly this shape made a repair step copy two
			// `brokeredcredential` examples into the flow table.
			$all = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::SCHEMA_CONCEPT,
						'inScheme' => $schemeUuid,
					],
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
			// The pair goes UNDER `filters` — see listConcepts() for why a
			// top-level pair is inert. `$filters` is spread after the pair so a
			// caller can never shadow the scope with a field of the same name.
			$results = $this->objectService->findAll(
				config: [
					'filters' => array_merge(
						$filters,
						[
							'register' => self::REGISTER,
							'schema' => $schema,
						]
					),
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
