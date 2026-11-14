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
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\CodedOptionsRequest;
use OCA\OpenRegister\Controller\Support\VocabularyControllerServices;
use OCA\OpenRegister\Controller\Support\VocabularyLanguage;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\VocabularyImporterInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionScope;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilderInterface;
use OCA\OpenRegister\Service\Vocabulary\DeclarationLookup;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\VocabularyDeclarationResolverInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Throwable;

/**
 * Public resolution controller for the vocabulary register.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
class VocabularyController extends Controller {

	/**
	 * Register slug (mirrors {@see VocabularyImportService::REGISTER}).
	 *
	 * @var string
	 */
	private const REGISTER = VocabularyImporterInterface::REGISTER;

	/**
	 * ConceptScheme schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_SCHEME = VocabularyImporterInterface::SCHEMA_SCHEME;

	/**
	 * Concept schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_CONCEPT = VocabularyImporterInterface::SCHEMA_CONCEPT;

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
	 * The language the caller asked for.
	 *
	 * @var VocabularyLanguage
	 */
	private readonly VocabularyLanguage $language;

	/**
	 * OR object read path (findAll, real API only).
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Builds a coded property option list or option tree.
	 *
	 * @var CodedOptionsBuilderInterface
	 */
	private readonly CodedOptionsBuilderInterface $options;

	/**
	 * Resolves the coded declaration a read is about.
	 *
	 * @var VocabularyDeclarationResolverInterface
	 */
	private readonly VocabularyDeclarationResolverInterface $declarationResolver;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param VocabularyControllerServices $services The vocabulary controller services.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function __construct(
		IRequest $request,
		VocabularyControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->objectService = $services->objectService;
		$this->options = $services->options;
		$this->declarationResolver = $services->declarationResolver;
		$this->language = new VocabularyLanguage(request: $request);
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
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function propertyOptions(): JSONResponse {
		$schemaRef = trim((string)$this->request->getParam('schema', ''));
		$property = trim((string)$this->request->getParam('property', ''));
		$schemeUri = trim((string)$this->request->getParam('scheme', ''));

		if (($schemaRef === '' || $property === '') && $schemeUri === '') {
			return new JSONResponse(['message' => 'Either "schema" and "property", or "scheme", must be given.'], Http::STATUS_BAD_REQUEST);
		}

		$declaration = $this->declarationResolver->resolveDeclaration(new DeclarationLookup(schemaRef: $schemaRef, property: $property, schemeUri: $schemeUri));
		if ($declaration === null) {
			return $this->notFound();
		}

		return $this->optionsAnswer(declaration: $declaration, property: $this->propertyLabel(property: $property));
	}//end propertyOptions()

	/**
	 * The name a read reports its property under: the property, or `scheme` for a scheme-only read.
	 *
	 * @param string $property The requested property.
	 *
	 * @return string The reported name.
	 */
	private function propertyLabel(string $property): string {
		if ($property === '') {
			return 'scheme';
		}

		return $property;
	}//end propertyLabel()

	/**
	 * The options of a resolved declaration, as a tree when `tree` is asked, else as a list.
	 *
	 * @param CodedPropertyDeclaration $declaration The coded declaration.
	 * @param string $property The name the answer reports.
	 *
	 * @return JSONResponse The tree or list envelope.
	 */
	private function optionsAnswer(CodedPropertyDeclaration $declaration, string $property): JSONResponse {
		$read = new CodedOptionsRequest(declaration: $declaration, property: $property, language: $this->language->tag());
		$asTree = filter_var($this->request->getParam('tree', false), FILTER_VALIDATE_BOOLEAN);

		if ($asTree === true) {
			$tree = $this->options->tree(declaration: $declaration, scope: new CodedOptionScope(language: $read->language));

			return new JSONResponse(array_merge($read->envelope(), ['tree' => $tree]));
		}

		return $this->optionList(read: $read);
	}//end optionsAnswer()

	/**
	 * The flat option list, narrowed by `context` when given.
	 *
	 * @param CodedOptionsRequest $read The declaration, reported name and language.
	 *
	 * @return JSONResponse The list envelope.
	 */
	private function optionList(CodedOptionsRequest $read): JSONResponse {
		$context = trim((string)$this->request->getParam('context', ''));
		if ($context === '') {
			$context = null;
		}

		$options = $this->options->options(declaration: $read->declaration, scope: new CodedOptionScope(language: $read->language, context: $context));

		return new JSONResponse(
			array_merge(
				$read->envelope(),
				['context' => $context, 'results' => $options, 'total' => count($options)]
			)
		);
	}//end optionList()

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
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function resolveByUri(): JSONResponse {
		$uri = trim((string)$this->request->getParam('uri', ''));
		if ($uri === '') {
			return $this->notFound();
		}

		return $this->conceptAnswer(concept: $this->findOneBy(schema: self::SCHEMA_CONCEPT, filters: ['uri' => $uri]));
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
	 * @spec openspec/specs/skos-concept-registers/spec.md
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

		$filters = ['inScheme' => $schemeUuid, 'notation' => $notation];

		return $this->conceptAnswer(concept: $this->findOneBy(schema: self::SCHEMA_CONCEPT, filters: $filters));
	}//end resolveByNotation()

	/**
	 * The concept, or the standard 404 when none was found.
	 *
	 * @param ObjectEntity|null $concept The concept.
	 *
	 * @return JSONResponse The concept object, or the 404.
	 */
	private function conceptAnswer(?ObjectEntity $concept): JSONResponse {
		if ($concept === null) {
			return $this->notFound();
		}

		return new JSONResponse($concept->jsonSerialize());
	}//end conceptAnswer()

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
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function listConcepts(): JSONResponse {
		$scheme = trim((string)$this->request->getParam('scheme', ''));
		if ($scheme === '') {
			return new JSONResponse(['message' => 'Query parameter "scheme" is required.'], Http::STATUS_BAD_REQUEST);
		}

		$schemeUuid = $this->resolveSchemeUuid(schemeUriOrUuid: $scheme);
		if ($schemeUuid === null) {
			return $this->notFound();
		}

		$all = $this->schemeConcepts(schemeUuid: $schemeUuid);
		if ($all === null) {
			return new JSONResponse(['message' => 'Unable to list concepts.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->conceptPage(matching: $this->matchingConcepts(concepts: $all));
	}//end listConcepts()

	/**
	 * Every concept of a scheme, up to the in-memory cap.
	 *
	 * The pair goes UNDER `filters`. `ObjectService::prepareFindAllConfig()`
	 * reads `$config['filters']['register']` and `$config['filters']['schema']`
	 * and no other key, so a top-level pair is read by nothing: the read then
	 * ran on whatever register/schema the last write left on the shared
	 * service. See openregister#3408, where exactly this shape made a repair
	 * step copy two `brokeredcredential` examples into the flow table.
	 *
	 * @param string $schemeUuid The scheme's uuid.
	 *
	 * @return array<int, ObjectEntity>|null The concepts, or null when the read failed.
	 */
	private function schemeConcepts(string $schemeUuid): ?array {
		$filters = ['register' => self::REGISTER, 'schema' => self::SCHEMA_CONCEPT, 'inScheme' => $schemeUuid];

		try {
			return $this->objectService->findAll(config: ['filters' => $filters, 'limit' => self::MAX_SCHEME_CONCEPTS]);
		} catch (Throwable $e) {
			return null;
		}
	}//end schemeConcepts()

	/**
	 * The concepts whose labels match the `q` search, or all of them without one.
	 *
	 * @param array<int, ObjectEntity> $concepts The scheme's concepts.
	 *
	 * @return array<int, ObjectEntity> The matching concepts, in order.
	 */
	private function matchingConcepts(array $concepts): array {
		$query = trim((string)$this->request->getParam('q', ''));

		$matching = [];
		foreach ($concepts as $entity) {
			if ($query === '' || $this->labelMatches(data: ($entity->getObject() ?? []), query: $query) === true) {
				$matching[] = $entity;
			}
		}

		return $matching;
	}//end matchingConcepts()

	/**
	 * One page of concepts in the standard paginated objects envelope.
	 *
	 * @param array<int, ObjectEntity> $matching The matching concepts.
	 *
	 * @return JSONResponse The envelope.
	 */
	private function conceptPage(array $matching): JSONResponse {
		$limit = max(1, min(200, (int)$this->request->getParam('_limit', 20)));
		$offset = max(0, (int)$this->request->getParam('_offset', 0));
		$total = count($matching);

		return new JSONResponse(
			[
				'results' => array_map(static fn ($entity): array => $entity->jsonSerialize(), array_slice($matching, $offset, $limit)),
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
				'page' => ((int)floor($offset / max(1, $limit)) + 1),
				'pages' => ((int)ceil($total / max(1, $limit))),
			]
		);
	}//end conceptPage()

	/**
	 * Resolve a scheme's uuid from its durable uri (or pass an already-resolved uuid through).
	 *
	 * @param string $schemeUriOrUuid The scheme's source uri (or its OpenRegister uuid).
	 *
	 * @return string|null The scheme's uuid, or null when unresolvable.
	 * @spec openspec/specs/object-interactions/spec.md#requirement-only-a-person-opening-an-object-counts-as-recently-opened
	 */
	private function resolveSchemeUuid(string $schemeUriOrUuid): ?string {
		$scheme = $this->findOneBy(schema: self::SCHEMA_SCHEME, filters: ['uri' => $schemeUriOrUuid]);
		if ($scheme !== null) {
			return (string)$scheme->getUuid();
		}

		$byId = $this->schemeById(uuid: $schemeUriOrUuid);
		if ($byId === null) {
			return null;
		}

		return (string)$byId->getUuid();
	}//end resolveSchemeUuid()

	/**
	 * The scheme with this uuid, for a leaf caller that stored the uuid rather than the source uri.
	 *
	 * @param string $uuid The value, treated as an already-resolved uuid.
	 *
	 * @return ObjectEntity|null The scheme, or null.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function schemeById(string $uuid): ?ObjectEntity {
		try {
			return WriteCause::asLookup(fn () => $this->objectService->find(
				id: $uuid,
				options: new ObjectFindOptions(register: self::REGISTER, schema: self::SCHEMA_SCHEME)
			));
		} catch (Throwable $e) {
			return null;
		}
	}//end schemeById()

	/**
	 * Find a single object of `$schema` matching `$filters`, or null.
	 *
	 * The pair goes UNDER `filters` — see schemeConcepts() for why a top-level
	 * pair is inert. `$filters` is spread before the pair so a caller can
	 * never shadow the scope with a field of the same name.
	 *
	 * @param string $schema Schema slug within the vocabulary register.
	 * @param array<string,string> $filters Exact-match field filters.
	 *
	 * @return ObjectEntity|null
	 */
	private function findOneBy(string $schema, array $filters): ?ObjectEntity {
		$scoped = array_merge($filters, ['register' => self::REGISTER, 'schema' => $schema]);

		try {
			$results = $this->objectService->findAll(config: ['filters' => $scoped, 'limit' => 1]);
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
			if ($this->anyLabelContains(labels: ($data[$field] ?? null), needle: $needle) === true) {
				return true;
			}
		}

		return false;
	}//end labelMatches()

	/**
	 * Whether any string label of a multilingual label map contains the needle.
	 *
	 * @param mixed $labels The label map; anything else matches nothing.
	 * @param string $needle The lower-cased search term.
	 *
	 * @return bool
	 */
	private function anyLabelContains(mixed $labels, string $needle): bool {
		if (is_array($labels) === false) {
			return false;
		}

		foreach ($labels as $label) {
			if (is_string($label) === true && str_contains(mb_strtolower($label), $needle) === true) {
				return true;
			}
		}

		return false;
	}//end anyLabelContains()

	/**
	 * The standard 404 error shape.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['message' => 'Not Found'], Http::STATUS_NOT_FOUND);
	}//end notFound()
}//end class
