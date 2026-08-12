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
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\VocabularyImportService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class VocabularyControllerTest extends TestCase {

	private ObjectService&MockObject $objectService;

	private VocabularyController $controller;

	/**
	 * The current test's simulated query params.
	 *
	 * @var array<string, string>
	 */
	private array $params = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($this->params[$key] ?? $default)
		);

		$this->controller = new VocabularyController(
			appName: 'openregister',
			request: $request,
			objectService: $this->objectService
		);
	}//end setUp()

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

		$concept = $this->entity('uuid-1', ['uri' => 'https://example.org/vocab/x', 'prefLabel' => ['nl' => 'X']]);

		$this->objectService->method('findAll')->willReturn([$concept]);

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
		$concept = $this->entity('uuid-1', ['uri' => 'https://example.org/vocab/c1', 'notation' => 'c_1']);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($scheme, $concept) {
				if ($config['schema'] === VocabularyImportService::SCHEMA_SCHEME) {
					return [$scheme];
				}

				return [$concept];
			}
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
				if ($config['schema'] === VocabularyImportService::SCHEMA_SCHEME) {
					return [$scheme];
				}

				return [$a, $b];
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
				if ($config['schema'] === VocabularyImportService::SCHEMA_SCHEME) {
					return [$scheme];
				}

				return [$a, $b];
			}
		);

		$response = $this->controller->listConcepts();
		$data = $response->getData();

		$this->assertSame(2, $data['total']);
		$this->assertCount(2, $data['results']);
	}//end testListConceptsWithoutQueryReturnsAllConceptsOfScheme()
}//end class
