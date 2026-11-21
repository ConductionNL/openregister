<?php

/**
 * `_snippet=true` through ObjectService::searchObjectsPaginated(): `@self.snippet` only when asked.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\ObjectServiceFactory;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Search\SearchSnippetAttacher
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses \OCA\OpenRegister\Service\Search\SnippetBuilder
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md#requirement-a-result-shows-the-passage-that-matched-only-from-what-the-reader-may-see-req-sdl-002
 */
final class SearchSnippetWiringTest extends TestCase {

	/**
	 * The rows the database search answers with.
	 *
	 * @var array<int, mixed>
	 */
	private array $rows = [];

	/**
	 * Whether reading the schema fails.
	 *
	 * @var bool
	 */
	private bool $schemaFails = false;

	protected function setUp(): void {
		$this->rows = [[
			'@self' => ['id' => 'u-1', 'schema' => 7, 'name' => 'Dakkapel'],
			'bsn' => '999990019 vergunning',
			'toelichting' => 'De aanvraag voor de omgevingsvergunning is afgewezen.',
		]];
	}

	private function service(): ObjectService {
		$queryHandler = $this->createMock(QueryHandler::class);
		$queryHandler->method('searchObjectsPaginatedDatabase')->willReturnCallback(
			fn (): array => ['results' => $this->rows, 'total' => count($this->rows), 'page' => 1]
		);

		$schema = new Schema();
		$schema->setProperties([
			'bsn' => ['type' => 'string', 'writeOnly' => true],
			'toelichting' => ['type' => 'string'],
		]);
		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$schemaMapper->method('find')->willReturnCallback(function () use ($schema): Schema {
			if ($this->schemaFails === true) {
				throw new RuntimeException('schema unreadable');
			}

			return $schema;
		});

		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturn($this->createStub(ReadHistoryService::class));

		$given = ['queryHandler' => $queryHandler, 'schemaMapper' => $schemaMapper, 'container' => $container];
		$args  = [];
		foreach ((new ReflectionMethod(ObjectServiceFactory::class, 'args'))->getParameters() as $parameter) {
			$type = $parameter->getType();
			if (array_key_exists($parameter->getName(), $given) === true) {
				$args[$parameter->getName()] = $given[$parameter->getName()];
			} elseif ($parameter->isOptional() === false && $type instanceof ReflectionNamedType) {
				$args[$parameter->getName()] = $this->createMock($type->getName());
			}
		}

		return new ObjectService(...ObjectServiceFactory::args(...$args));
	}

	public function testSnippetIsOnlyAddedWhenAsked(): void {
		$without = $this->service()->searchObjectsPaginated(query: ['_search' => 'vergunning']);
		$this->assertSame($this->rows, $without['results']);
		$this->assertArrayNotHasKey('snippet', $without['results'][0]['@self']);

		$with = $this->service()->searchObjectsPaginated(query: ['_search' => 'vergunning', '_snippet' => 'true']);
		$this->assertSame(
			['source' => 'property', 'property' => 'toelichting', 'text' => 'De aanvraag voor de omgevings<mark>vergunning</mark> is afgewezen.'],
			$with['results'][0]['@self']['snippet']
		);
	}

	public function testAWriteOnlyPropertyIsNeverQuotedThroughTheService(): void {
		$this->rows[0]['toelichting'] = 'Geen treffer hier.';

		$result = $this->service()->searchObjectsPaginated(query: ['_search' => 'vergunning', '_snippet' => true]);

		$this->assertArrayNotHasKey('snippet', $result['results'][0]['@self']);
	}

	public function testAnUnreadableSchemaGivesNoSnippet(): void {
		$this->schemaFails = true;

		$result = $this->service()->searchObjectsPaginated(query: ['_search' => 'vergunning', '_snippet' => '1']);

		$this->assertArrayNotHasKey('snippet', $result['results'][0]['@self']);
	}

	public function testWithoutASearchTermNothingIsAdded(): void {
		$result = $this->service()->searchObjectsPaginated(query: ['_snippet' => 'true']);

		$this->assertSame($this->rows, $result['results']);
	}

	public function testAnEntityRowIsSerialisedAndQuoted(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('u-2');
		$entity->setSchema('7');
		$entity->setObject(['toelichting' => 'Vergunning verleend.']);
		$this->rows = [$entity];

		$result = $this->service()->searchObjectsPaginated(query: ['_search' => 'vergunning', '_snippet' => 'true']);

		$this->assertSame('<mark>Vergunning</mark> verleend.', $result['results'][0]['@self']['snippet']['text']);
		$this->assertSame('u-2', $result['results'][0]['id']);
	}
}
