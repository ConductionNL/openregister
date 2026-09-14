<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Controller\VocabularyController}
 * (skos-concept-registers, SKOS-004).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-004
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\VocabularyController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilder;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\VocabularyImportService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class VocabularyControllerTest extends TestCase {

	private ObjectService&MockObject $objectService;

	private SchemaMapper&MockObject $schemaMapper;

	private CodedOptionsBuilder&MockObject $options;

	private VocabularyController $controller;

	/**
	 * The current test's simulated query params.
	 *
	 * @var array<string, string>
	 */
	private array $params = [];

	/**
	 * Every config array handed to ObjectService::findAll(), in call order.
	 *
	 * Recorded so the tests can assert what was ASKED, not only what came back.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $findAllCalls = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->findAllCalls = [];
		$this->objectService = $this->createMock(ObjectService::class);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($this->params[$key] ?? $default)
		);

		$this->schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$this->options = $this->createMock(originalClassName: CodedOptionsBuilder::class);

		$this->controller = new VocabularyController(
			appName: 'openregister',
			request: $request,
			objectService: $this->objectService,
			schemaMapper: $this->schemaMapper,
			options: $this->options
		);
	}//end setUp()

	/**
	 * Record a findAll() config and answer it from where the scope really lives.
	 *
	 * A FAKE THAT ANSWERS ANY QUESTION CANNOT REPORT A WRONG ONE. This refuses a
	 * read whose scope is not under `filters`, because that is the only place
	 * `ObjectService::prepareFindAllConfig()` looks — a pair anywhere else scopes
	 * nothing and the read runs on leftover shared state (openregister#3408).
	 *
	 * @param array<string, mixed> $config The findAll() config under test.
	 * @param ObjectEntity $scheme The conceptScheme row to answer scheme reads with.
	 * @param array<int, ObjectEntity> $concepts The concept rows to answer concept reads with.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function recordAndRoute(array $config, ObjectEntity $scheme, array $concepts): array {
		$this->findAllCalls[] = $config;

		$register = ($config['filters']['register'] ?? null);
		$schema = ($config['filters']['schema'] ?? null);
		if ($register === null || $schema === null) {
			throw new \RuntimeException(
				'findAll() was called without filters.register / filters.schema; ObjectService reads the '
				.'scope from nowhere else, so this read would have run on whatever context the last write '
				.'left behind.'
			);
		}

		if ($schema === VocabularyImportService::SCHEMA_SCHEME) {
			return [$scheme];
		}

		return $concepts;
	}//end recordAndRoute()

	/**
	 * @param string $uuid The object uuid.
	 * @param array<string, mixed> $object The object payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($object);

		return $entity;
	}//end entity()

	// -------------------------------------------------------------------
	// resolveByUri
	// -------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testResolveByUriReturnsConceptWhenFound(): void {
		$this->params = ['uri' => 'https://example.org/vocab/x'];

		$draft = $this->entity('uuid-1', ['uri' => 'https://example.org/vocab/x', 'prefLabel' => ['nl' => 'X']]);

		$this->objectService->method('findAll')->willReturn([$draft]);

		$response = $this->controller->resolveByUri();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('https://example.org/vocab/x', $response->getData()['uri']);
	}//end testResolveByUriReturnsConceptWhenFound()

	/**
	 * @return void
	 */
	public function testResolveByUriReturns404OnUnknownUri(): void {
		$this->params = ['uri' => 'https://example.org/vocab/unknown'];

		$this->objectService->method('findAll')->willReturn([]);

		$response = $this->controller->resolveByUri();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayHasKey('message', $response->getData());
	}//end testResolveByUriReturns404OnUnknownUri()

	/**
	 * @return void
	 */
	public function testResolveByUriReturns404WhenUriParamMissing(): void {
		$this->params = [];

		$this->objectService->expects($this->never())->method('findAll');

		$response = $this->controller->resolveByUri();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testResolveByUriReturns404WhenUriParamMissing()

	// -------------------------------------------------------------------
	// resolveByNotation
	// -------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testResolveByNotationReturnsConceptWhenFound(): void {
		$this->params = ['scheme' => 'https://example.org/vocab/scheme', 'notation' => 'c_1'];

		$scheme = $this->entity('scheme-uuid', ['uri' => 'https://example.org/vocab/scheme']);
		$draft = $this->entity('uuid-1', ['uri' => 'https://example.org/vocab/c1', 'notation' => 'c_1']);

		$this->objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => $this->recordAndRoute(config: $config, scheme: $scheme, concepts: [$draft])
		);

		$response = $this->controller->resolveByNotation();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('c_1', $response->getData()['notation']);
	}//end testResolveByNotationReturnsConceptWhenFound()

	/**
	 * @return void
	 */
	public function testResolveByNotationReturns404OnUnknownScheme(): void {
		$this->params = ['scheme' => 'https://example.org/vocab/unknown-scheme', 'notation' => 'c_1'];

		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('find')->willReturn(null);

		$response = $this->controller->resolveByNotation();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testResolveByNotationReturns404OnUnknownScheme()

	/**
	 * @return void
	 */
	public function testResolveByNotationReturns404WhenNotationParamMissing(): void {
		$this->params = ['scheme' => 'https://example.org/vocab/scheme'];

		$this->objectService->expects($this->never())->method('findAll');

		$response = $this->controller->resolveByNotation();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testResolveByNotationReturns404WhenNotationParamMissing()

	// -------------------------------------------------------------------
	// listConcepts
	// -------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testListConceptsReturns400WhenSchemeMissing(): void {
		$this->params = [];

		$response = $this->controller->listConcepts();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testListConceptsReturns400WhenSchemeMissing()

	/**
	 * @return void
	 */
	public function testListConceptsReturns404OnUnknownScheme(): void {
		$this->params = ['scheme' => 'https://example.org/vocab/unknown-scheme'];

		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('find')->willReturn(null);

		$response = $this->controller->listConcepts();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testListConceptsReturns404OnUnknownScheme()

	/**
	 * A label query matches exactly the concept whose Dutch prefLabel
	 * contains it, across the scheme's concepts, in the standard paginated
	 * envelope (SKOS-004 scenario 2).
	 *
	 * @return void
	 */
	public function testListConceptsLabelSearchMatchesOnlyTheMatchingConcept(): void {
		$this->params = ['scheme' => 'https://example.org/vocab/scheme', 'q' => 'Wonin'];

		$scheme = $this->entity('scheme-uuid', ['uri' => 'https://example.org/vocab/scheme']);
		$a = $this->entity('a', ['uri' => 'https://example.org/vocab/a', 'inScheme' => 'scheme-uuid', 'prefLabel' => ['nl' => 'Woningbouw']]);
		$b = $this->entity('b', ['uri' => 'https://example.org/vocab/b', 'inScheme' => 'scheme-uuid', 'prefLabel' => ['nl' => 'Belastingen']]);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($scheme, $a, $b) {
				// Answer from where ObjectService actually reads the scope. This
				// used to read `$config['schema']`, a key `prepareFindAllConfig()`
				// never looks at, so the fake replied correctly to a question the
				// real service is never asked — see openregister#3408.
				return ($this->recordAndRoute(config: $config, scheme: $scheme, concepts: [$a, $b]));
			}
		);

		$response = $this->controller->listConcepts();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $data['total']);
		$this->assertCount(1, $data['results']);
		$this->assertSame('https://example.org/vocab/a', $data['results'][0]['uri']);
	}//end testListConceptsLabelSearchMatchesOnlyTheMatchingConcept()

	/**
	 * Without a label query, every concept of the scheme is returned, paginated.
	 *
	 * @return void
	 */
	public function testListConceptsWithoutQueryReturnsAllConceptsOfScheme(): void {
		$this->params = ['scheme' => 'https://example.org/vocab/scheme'];

		$scheme = $this->entity('scheme-uuid', ['uri' => 'https://example.org/vocab/scheme']);
		$a = $this->entity('a', ['uri' => 'https://example.org/vocab/a', 'inScheme' => 'scheme-uuid', 'prefLabel' => ['nl' => 'A']]);
		$b = $this->entity('b', ['uri' => 'https://example.org/vocab/b', 'inScheme' => 'scheme-uuid', 'prefLabel' => ['nl' => 'B']]);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($scheme, $a, $b) {
				// Answer from where ObjectService actually reads the scope. This
				// used to read `$config['schema']`, a key `prepareFindAllConfig()`
				// never looks at, so the fake replied correctly to a question the
				// real service is never asked — see openregister#3408.
				return ($this->recordAndRoute(config: $config, scheme: $scheme, concepts: [$a, $b]));
			}
		);

		$response = $this->controller->listConcepts();
		$data = $response->getData();

		$this->assertSame(2, $data['total']);
		$this->assertCount(2, $data['results']);
	}//end testListConceptsWithoutQueryReturnsAllConceptsOfScheme()

	/**
	 * EVERY read must name its scope where ObjectService actually looks.
	 *
	 * `ObjectService::prepareFindAllConfig()` reads
	 * `$config['filters']['register']` and `$config['filters']['schema']`, and no
	 * other key. A pair at the TOP level of the config is read by nothing:
	 * `setRegister()` / `setSchema()` are never called, and `findAll()` passes
	 * the handler `$this->currentRegister` / `$this->currentSchema` — leftover
	 * state from whatever ran last on the same shared service instance.
	 *
	 * This controller used the top-level shape at both of its read sites. It was
	 * the third of the outliers openregister#3408 found and reported rather than
	 * changed, because it was not in that defect's path and needed its own test.
	 * This is that test.
	 *
	 * It asserts the ARGUMENTS, not the response: the fake used to answer from
	 * `$config['schema']`, so it replied correctly to a question the real service
	 * is never asked, and every assertion about the returned concepts passed with
	 * the scope wired to nothing.
	 *
	 * `listConcepts()` is a `#[PublicPage]`, so the unscoped read was reachable
	 * without a session — the widest possible audience for a wrong answer.
	 *
	 * @return void
	 */
	public function testEveryReadNamesItsScopeWhereObjectServiceReadsIt(): void {
		$this->params = ['scheme' => 'https://example.org/vocab/scheme'];

		$scheme = $this->entity('scheme-uuid', ['uri' => 'https://example.org/vocab/scheme']);
		$a = $this->entity('a', ['uri' => 'https://example.org/vocab/a', 'inScheme' => 'scheme-uuid', 'prefLabel' => ['nl' => 'A']]);

		$this->objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => $this->recordAndRoute(config: $config, scheme: $scheme, concepts: [$a])
		);

		$response = $this->controller->listConcepts();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		// listConcepts() resolves the scheme and then lists its concepts, so a
		// recording of fewer than two calls means the endpoint did not run and
		// the loop below would prove nothing.
		$this->assertCount(
			2,
			$this->findAllCalls,
			'Expected a scheme lookup followed by a concept list.'
		);

		foreach ($this->findAllCalls as $index => $config) {
			$this->assertArrayNotHasKey(
				'register',
				$config,
				"findAll() call #$index puts `register` at the top level, where nothing reads it."
			);
			$this->assertArrayNotHasKey(
				'schema',
				$config,
				"findAll() call #$index puts `schema` at the top level, where nothing reads it."
			);
			$this->assertSame(
				VocabularyImportService::REGISTER,
				($config['filters']['register'] ?? null),
				"findAll() call #$index does not scope its register under `filters`."
			);
		}

		$this->assertSame(
			VocabularyImportService::SCHEMA_SCHEME,
			($this->findAllCalls[0]['filters']['schema'] ?? null),
			'The scheme lookup must be scoped to the conceptScheme schema.'
		);
		$this->assertSame(
			VocabularyImportService::SCHEMA_CONCEPT,
			($this->findAllCalls[1]['filters']['schema'] ?? null),
			'The concept list must be scoped to the concept schema.'
		);
	}//end testEveryReadNamesItsScopeWhereObjectServiceReadsIt()

	/**
	 * The uri lookup is scoped too, and still carries its field filter.
	 *
	 * `findOneBy()` is the second read site and merges the caller's exact-match
	 * filters into the same array the scope now lives in. Both have to survive
	 * that merge: dropping the scope reinstates the defect, and dropping the
	 * field filter turns "find the concept with this uri" into "find any
	 * concept", which would answer 200 with the wrong row.
	 *
	 * @return void
	 */
	public function testTheUriLookupIsScopedAndKeepsItsFieldFilter(): void {
		$this->params = ['uri' => 'https://example.org/vocab/a'];

		$scheme = $this->entity('scheme-uuid', ['uri' => 'https://example.org/vocab/scheme']);
		$a = $this->entity('a', ['uri' => 'https://example.org/vocab/a']);

		$this->objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => $this->recordAndRoute(config: $config, scheme: $scheme, concepts: [$a])
		);

		$response = $this->controller->resolveByUri();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$this->assertCount(1, $this->findAllCalls, 'The uri lookup is a single scoped read.');
		$filters = ($this->findAllCalls[0]['filters'] ?? []);

		$this->assertSame(VocabularyImportService::REGISTER, ($filters['register'] ?? null));
		$this->assertSame(VocabularyImportService::SCHEMA_CONCEPT, ($filters['schema'] ?? null));
		$this->assertSame(
			'https://example.org/vocab/a',
			($filters['uri'] ?? null),
			'Nesting the scope must not displace the field filter the lookup exists for.'
		);
	}//end testTheUriLookupIsScopedAndKeepsItsFieldFilter()

	/**
	 * A read naming neither a schema property nor a scheme is a 400, not an
	 * empty 200.
	 *
	 * A picker that asks wrongly must be told it asked wrongly. An empty list
	 * is a legitimate answer to a well-formed question (a scheme whose values
	 * have all been retired returns exactly that), so answering the malformed
	 * question with the same empty list makes the two indistinguishable at the
	 * one place a caller could notice the bug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function testTheOptionsEndpointRefusesARequestThatNamesNeitherAPropertyNorAScheme(): void {
		$this->params = [];

		$response = $this->controller->propertyOptions();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testTheOptionsEndpointRefusesARequestThatNamesNeitherAPropertyNorAScheme()

	/**
	 * An unreadable schema is a 404 carrying the standard error shape.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function testTheOptionsEndpointAnswers404ForASchemaItCannotRead(): void {
		$this->params = [
			'schema' => '404',
			'property' => 'categorie',
		];

		$this->schemaMapper->method('find')->willThrowException(
			exception: new RuntimeException('no such schema')
		);

		$response = $this->controller->propertyOptions();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayHasKey('message', (array)$response->getData());
	}//end testTheOptionsEndpointAnswers404ForASchemaItCannotRead()

	/**
	 * The flat answer carries the property, the scheme, the language and the
	 * options, and the language it reports is the one it read with.
	 *
	 * The language pair is the assertion that matters. A response naming `nl`
	 * while the builder was asked for `en` would look right in every screenshot
	 * and be wrong in every locale but the default.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function testTheOptionsEndpointReturnsTheListInTheLanguageItAskedFor(): void {
		$this->params = [
			'schema' => '7',
			'property' => 'categorie',
			'language' => 'en',
		];

		$this->schemaMapper->method('find')->willReturn(
			value: $this->schemaWithCodedProperty(property: 'categorie')
		);

		$asked = [];
		$this->options->method('options')->willReturnCallback(
			function (CodedPropertyDeclaration $declaration, string $language = 'nl', ?string $context = null) use (&$asked): array {
				$asked = [
					'scheme' => $declaration->scheme,
					'language' => $language,
					'context' => $context,
				];

				return [['value' => 'urn:test:kap', 'label' => 'Felling permit']];
			}
		);

		$response = $this->controller->propertyOptions();
		$data = (array)$response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('categorie', ($data['property'] ?? null));
		$this->assertSame('urn:test:vergunningen', ($data['scheme'] ?? null));
		$this->assertSame('en', ($data['language'] ?? null));
		$this->assertSame(1, ($data['total'] ?? null));
		$this->assertSame('en', ($asked['language'] ?? null), 'The reported language must be the one the options were built in.');
	}//end testTheOptionsEndpointReturnsTheListInTheLanguageItAskedFor()

	/**
	 * `tree=1` answers with a `tree` key and no flat `results` key, so a caller
	 * cannot read one shape while the other was sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function testTheOptionsEndpointReturnsTheTreeWhenTheTreeIsAsked(): void {
		$this->params = [
			'schema' => '7',
			'property' => 'categorie',
			'tree' => '1',
		];

		$this->schemaMapper->method('find')->willReturn(
			value: $this->schemaWithCodedProperty(property: 'categorie')
		);
		$this->options->method('tree')->willReturn(
			value: [['value' => 'urn:test:vergunning', 'children' => []]]
		);

		$response = $this->controller->propertyOptions();
		$data = (array)$response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('tree', $data);
		$this->assertArrayNotHasKey('results', $data, 'The tree answer must not also carry the flat list.');
	}//end testTheOptionsEndpointReturnsTheTreeWhenTheTreeIsAsked()

	/**
	 * A schema carrying one coded property, for the options tests.
	 *
	 * @param string $property The property name to declare the code list on.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWithCodedProperty(string $property): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				$property => [
					'type' => 'string',
					CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'urn:test:vergunningen'],
				],
			]
		);

		return $schema;
	}//end schemaWithCodedProperty()

}//end class
