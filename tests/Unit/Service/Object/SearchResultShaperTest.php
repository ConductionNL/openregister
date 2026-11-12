<?php

/**
 * Unit tests for SearchResultShaper — count, pass through, or render.
 *
 * The shaper decides one of three things for a search result: answer with a
 * count, hand the rows back untouched, or send them through RenderObject.
 * Rendering happens when the query asks for it (_extend, _fields, _filter,
 * _unset) or when any row's schema carries property authorization. Each test
 * flips the input that decides it and asserts which of the three happened.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SearchRenderRequest;
use OCA\OpenRegister\Service\Object\SearchResultShaper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SearchResultShaperTest extends TestCase {

	private RenderObject&MockObject $render;

	private SchemaMapperInterface&MockObject $schemas;

	/**
	 * How often each schema id was looked up.
	 *
	 * @var array<string, int>
	 */
	private array $lookups = [];

	/**
	 * Schema id => its properties; an id missing here is "not found".
	 *
	 * @var array<string, array>
	 */
	private array $schemaProperties = [];

	private function shaper(): SearchResultShaper {
		$this->render  = $this->createMock(RenderObject::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->schemas->method('find')->willReturnCallback(function (string|int $id): Schema {
			$id = (string) $id;
			$this->lookups[$id] = ($this->lookups[$id] ?? 0) + 1;
			if (array_key_exists($id, $this->schemaProperties) === false) {
				throw new DoesNotExistException('no schema ' . $id);
			}
			$schema = new Schema();
			$schema->setProperties($this->schemaProperties[$id]);
			return $schema;
		});
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => $id === SchemaMapperInterface::class ? $this->schemas : null
		);
		return new SearchResultShaper($this->render, $container);
	}

	private function row(string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($schema);
		return $object;
	}

	public static function countFlags(): array {
		return ['boolean true' => [true], 'string true' => ['true']];
	}

	#[DataProvider('countFlags')]
	public function testACountRequestAnswersWithTheNumberOfRows(bool|string $flag): void {
		$shaper = $this->shaper();
		$this->render->expects($this->never())->method('renderEntities');

		$this->assertSame(3, $shaper->shape([$this->row('1'), $this->row('1'), $this->row('2')], new SearchRenderRequest(['_count' => $flag], true, true)));
	}

	public function testAFalseCountFlagDoesNotCount(): void {
		$shaper = $this->shaper();
		$rows = [$this->row('plain')];
		$this->schemaProperties = ['plain' => ['name' => ['type' => 'string']]];

		$this->assertSame($rows, $shaper->shape($rows, new SearchRenderRequest(['_count' => 'false'], true, true)));
	}

	public function testPlainRowsWithoutPropertyAuthorizationPassThroughUntouched(): void {
		$shaper = $this->shaper();
		$this->schemaProperties = ['plain' => ['name' => ['type' => 'string']]];
		$rows = [$this->row('plain'), $this->row('plain')];
		$this->render->expects($this->never())->method('renderEntities');

		$this->assertSame($rows, $shaper->shape($rows, new SearchRenderRequest([], true, true)));
		$this->assertSame(['plain' => 1], $this->lookups, 'A schema is looked up once per result, not once per row.');
	}

	public function testAnEmptyResultPassesThroughWithoutLookingUpSchemas(): void {
		$shaper = $this->shaper();
		$this->render->expects($this->never())->method('renderEntities');

		$this->assertSame([], $shaper->shape([], new SearchRenderRequest([], true, true)));
		$this->assertSame([], $this->lookups);
	}

	public function testAnIntegerResultPassesThroughWithoutLookingUpSchemas(): void {
		$shaper = $this->shaper();

		$this->assertSame(7, $shaper->shape(7, new SearchRenderRequest([], true, true)));
		$this->assertSame([], $this->lookups);
	}

	public function testAMissingSchemaCountsAsNoPropertyAuthorization(): void {
		$shaper = $this->shaper();
		$rows = [$this->row('ghost')];
		$this->render->expects($this->never())->method('renderEntities');

		$this->assertSame($rows, $shaper->shape($rows, new SearchRenderRequest([], true, true)));
		$this->assertSame(['ghost' => 1], $this->lookups);
	}

	public function testARowWhoseSchemaCarriesPropertyAuthorizationIsRendered(): void {
		$shaper = $this->shaper();
		$this->schemaProperties = [
			'plain' => ['name' => ['type' => 'string']],
			'guarded' => ['bsn' => ['type' => 'string', 'authorization' => ['read' => ['group' => 'admin']]]],
		];
		$rows = [$this->row('plain'), $this->row('guarded'), $this->row('never-looked-up')];
		$rendered = [['rendered' => true]];
		$this->render->expects($this->once())->method('renderEntities')
			->with($rows, [], null, null, null, false, true)
			->willReturn($rendered);

		$this->assertSame($rendered, $shaper->shape($rows, new SearchRenderRequest([], false, true)));
		$this->assertArrayNotHasKey('never-looked-up', $this->lookups, 'The scan stops at the first schema that needs rendering.');
	}

	public static function renderingQueries(): array {
		return [
			'_extend' => [['_extend' => ['owner']], ['owner'], null, null, null],
			'_fields' => [['_fields' => ['name']], [], null, ['name'], null],
			'_filter' => [['_filter' => ['name']], [], ['name'], null, null],
			'_unset' => [['_unset' => ['secret']], [], null, null, ['secret']],
		];
	}

	#[DataProvider('renderingQueries')]
	public function testAQueryThatAsksForRenderingIsRenderedWithItsOptions(array $query, array $extend, ?array $filter, ?array $fields, ?array $unset): void {
		$shaper = $this->shaper();
		$rows = [$this->row('plain')];
		$this->render->expects($this->once())->method('renderEntities')
			->with($rows, $extend, $filter, $fields, $unset, true, false)
			->willReturn(['shaped']);

		$this->assertSame(['shaped'], $shaper->shape($rows, new SearchRenderRequest($query, true, false)));
		$this->assertSame([], $this->lookups, 'A rendering query needs no schema scan.');
	}

	public function testEmptyRenderingOptionsDoNotTriggerRendering(): void {
		$shaper = $this->shaper();
		$this->schemaProperties = ['plain' => []];
		$rows = [$this->row('plain')];
		$this->render->expects($this->never())->method('renderEntities');

		$this->assertSame($rows, $shaper->shape($rows, new SearchRenderRequest(['_extend' => [], '_fields' => '', '_filter' => null, '_unset' => []], true, true)));
	}
}
