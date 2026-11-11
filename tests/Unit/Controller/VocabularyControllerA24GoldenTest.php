<?php

/**
 * Golden outcomes of the public vocabulary resolution endpoints.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\VocabularyController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilder;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionScope;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\DeclarationLookup;
use OCA\OpenRegister\Service\Vocabulary\VocabularyDeclarationResolver;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins language negotiation, the option envelope and the concept list paging.
 */
class VocabularyControllerA24GoldenTest extends TestCase {
	/**
	 * The params of the case.
	 *
	 * @var array
	 */
	private array $params = [];
	/**
	 * The acceptLanguage of the case.
	 *
	 * @var string
	 */
	private string $acceptLanguage = '';
	/**
	 * The objects of the case.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;
	/**
	 * The options of the case.
	 *
	 * @var CodedOptionsBuilder&MockObject
	 */
	private CodedOptionsBuilder&MockObject $options;
	/**
	 * The resolver of the case.
	 *
	 * @var VocabularyDeclarationResolver&MockObject
	 */
	private VocabularyDeclarationResolver&MockObject $resolver;
	/**
	 * The controller of the case.
	 *
	 * @var VocabularyController
	 */
	private VocabularyController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => (['Accept-Language' => $this->acceptLanguage][$name] ?? ''));
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->options = $this->createMock(CodedOptionsBuilder::class);
		$this->resolver = $this->createMock(VocabularyDeclarationResolver::class);
		$this->controller = new VocabularyController($request, new \OCA\OpenRegister\Controller\Support\VocabularyControllerServices($this->objects, $this->options, $this->resolver));
	}

	private function languageFor(array $params, string $header): string {
		$this->params = $params + ['scheme' => 'urn:s'];
		$this->acceptLanguage = $header;
		$this->resolver->method('resolveDeclaration')->willReturn(new CodedPropertyDeclaration(scheme: 'urn:s'));
		$this->options->method('options')->willReturn([]);
		return $this->controller->propertyOptions()->getData()['language'];
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
	 */
	public static function languages(): array {
		return [
			'explicit wins'     => [['language' => ' en '], 'de', 'en'],
			'no header'         => [[], '  ', 'nl'],
			'first of list'     => [[], 'fr-BE;q=0.9, en', 'fr-BE'],
			'empty first entry' => [[], ' ;q=1, en', 'nl'],
			'plain'             => [[], 'de', 'de'],
		];
	}

	/**
	 * @param array<string, mixed> $params   Request params.
	 * @param string               $header   Accept-Language.
	 * @param string               $expected Negotiated tag.
	 *
	 * @return void
	 *
	 * @dataProvider languages
	 */
	public function testTheNegotiatedLanguage(array $params, string $header, string $expected): void {
		$this->assertSame($expected, $this->languageFor(params: $params, header: $header));
	}

	public function testAnOptionsReadByLeftOrRightOnlyIsRefusedOrResolved(): void {
		$this->params = ['schema' => 's'];
		$this->assertSame(
			[400, ['message' => 'Either "schema" and "property", or "scheme", must be given.']],
			[$this->controller->propertyOptions()->getStatus(), $this->controller->propertyOptions()->getData()]
		);

		$this->params = ['scheme' => ' urn:s ', 'context' => ' a '];
		$declaration = new CodedPropertyDeclaration(scheme: 'urn:s');
		$this->resolver->expects($this->once())->method('resolveDeclaration')->with(new DeclarationLookup('', '', 'urn:s'))->willReturn($declaration);
		$this->options->expects($this->once())->method('options')->with($declaration, new CodedOptionScope(language: 'nl', context: 'a'))->willReturn([['v' => 1]]);

		$this->assertSame(
			['property' => 'scheme', 'scheme' => 'urn:s', 'language' => 'nl', 'context' => 'a', 'results' => [['v' => 1]], 'total' => 1],
			$this->controller->propertyOptions()->getData()
		);
	}

	public function testAnUnresolvedDeclarationIs404AndATreeHasItsOwnShape(): void {
		$this->params = ['schema' => 's', 'property' => 'p', 'tree' => 'yes', 'context' => 'c'];
		$declaration = new CodedPropertyDeclaration(scheme: 'urn:s');
		$this->resolver->method('resolveDeclaration')->willReturnOnConsecutiveCalls(null, $declaration);
		$this->options->method('tree')->with($declaration, new CodedOptionScope(language: 'nl'))->willReturn([['t']]);
		$this->options->expects($this->never())->method('options');

		$missing = $this->controller->propertyOptions();
		$this->assertSame([404, ['message' => 'Not Found']], [$missing->getStatus(), $missing->getData()]);
		$this->assertSame(
			['property' => 'p', 'scheme' => 'urn:s', 'language' => 'nl', 'tree' => [['t']]],
			$this->controller->propertyOptions()->getData()
		);
	}

	private function concept(string $uuid, array $labels): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($labels);
		return $entity;
	}

	public function testTheConceptListPagesAndClampsAndFallsBackToTheUuid(): void {
		$this->params = ['scheme' => 'sch-uuid', 'q' => 'BOOM', '_limit' => '500', '_offset' => '-3'];
		$matching = $this->concept('c1', ['prefLabel' => ['nl' => 'Boomkap'], 'altLabel' => 'x']);
		$other = $this->concept('c2', ['prefLabel' => 'flat', 'altLabel' => ['nl' => 7, 'en' => 'tree']]);
		$alt = $this->concept('c3', ['altLabel' => ['nl' => 'kapboom']]);
		$scheme = $this->concept('sch-uuid', []);
		$this->objects->method('findAll')->willReturnCallback(
			static fn (array $config): array => (['conceptScheme' => []][$config['filters']['schema'] ?? ''] ?? [$matching, $other, $alt])
		);
		$this->objects->expects($this->once())->method('find')->willReturn($scheme);

		$data = $this->controller->listConcepts()->getData();

		$this->assertSame(
			['results' => [$matching->jsonSerialize(), $alt->jsonSerialize()], 'total' => 2, 'limit' => 200, 'offset' => 0, 'page' => 1, 'pages' => 1],
			$data
		);
	}

	public function testTheConceptListPageNumbersAndItsFailure(): void {
		$this->params = ['scheme' => 'urn:s', '_limit' => '1', '_offset' => '1'];
		$scheme = $this->concept('sch', []);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config) use ($scheme): array {
				if (($config['filters']['schema'] ?? '') === 'conceptScheme') {
					return [$scheme];
				}
				if (($config['limit'] ?? 0) !== 2000) {
					throw new RuntimeException('unexpected limit');
				}
				if ($this->params['_offset'] === 'boom') {
					throw new RuntimeException('down');
				}
				return [$this->concept('a', []), $this->concept('b', []), $this->concept('c', [])];
			}
		);

		$data = $this->controller->listConcepts()->getData();
		$this->assertSame([1, 1, 2, 3, 3], [$data['limit'], $data['offset'], $data['page'], $data['pages'], $data['total']]);

		$this->params['_offset'] = 'boom';
		$failed = $this->controller->listConcepts();
		$this->assertSame([500, ['message' => 'Unable to list concepts.']], [$failed->getStatus(), $failed->getData()]);
	}

	public function testAnUnresolvableSchemeIsNotFoundEvenWhenTheFallbackThrows(): void {
		$this->params = ['scheme' => 'nope', 'notation' => 'x'];
		$this->objects->method('findAll')->willThrowException(new RuntimeException('x'));
		$this->objects->method('find')->willReturnOnConsecutiveCalls($this->throwException(new RuntimeException('x')), null);

		$this->assertSame(404, $this->controller->resolveByNotation()->getStatus());
		$this->assertSame(404, $this->controller->listConcepts()->getStatus());
	}
}
