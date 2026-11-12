<?php

/**
 * Unit tests for rendering inverse relations whose declaration is incomplete.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\InverseObjectHooks;
use OCA\OpenRegister\Service\Object\InverseRelationCache;
use OCA\OpenRegister\Service\Object\InverseRelationPreload;
use OCA\OpenRegister\Service\Object\InverseRelationRender;
use OCA\OpenRegister\Service\Object\InverseRenderSupport;
use OCA\OpenRegister\Service\Object\InverseSchemaLookups;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Extra inverse fields without a target schema add no query; a property whose own level names no field is left unrendered;
 * multi-field inversions on the same schema still match.
 *
 * @covers \OCA\OpenRegister\Service\Object\InverseRelationRender
 */
class InverseRelationRenderDeclarationsTest extends TestCase {

	/**
	 * The mapper, mocked.
	 *
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface&MockObject $mapper;

	/**
	 * Render the inverse properties of entity `case-1` (schema 9) whose schema declares the given properties.
	 *
	 * @param array<string, mixed> $properties The schema properties.
	 * @param array<string, mixed> $data       The object data.
	 *
	 * @return array<string, mixed> The rendered data.
	 */
	private function render(array $properties, array $data): array {
		$schema = new Schema();
		$schema->setProperties($properties);

		$cache = $this->createMock(InverseRelationCache::class);
		$cache->method('hasCachedInverse')->willReturn(false);

		$support = new InverseRenderSupport(
			$this->mapper,
			new NullLogger(),
			new InverseSchemaLookups(
				static fn ($id): ?Schema => (string)$id === '9' ? $schema : null,
				static fn (string $ref): string => ''
			),
			new InverseObjectHooks(
				$cache,
				static fn (ObjectEntity $o): string => (string)$o->getUuid(),
				static fn () => null,
				static fn (array $objects): array => $objects
			)
		);

		$entity = new ObjectEntity();
		$entity->setUuid('case-1');
		$entity->setSchema('9');
		$entity->setRegister('3');

		return (new InverseRelationRender($support, new InverseRelationPreload($support)))->handleInversedProperties($entity, $data);

	}//end render()

	/**
	 * Build the mapper mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(MagicMapperInterface::class);

	}//end setUp()

	/**
	 * Two inverse fields on the object's own schema: no extra query without a $ref, and both fields match.
	 *
	 * @return void
	 */
	public function testMultipleFieldsWithoutARefUseTheOwnSchemaOnly(): void {
		$byA = new ObjectEntity();
		$byA->setUuid('r-1');
		$byA->setSchema('9');
		$byA->setObject(['a' => 'case-1']);
		$byB = new ObjectEntity();
		$byB->setUuid('r-2');
		$byB->setSchema('9');
		$byB->setObject(['b' => 'case-1']);
		$this->mapper->expects($this->once())->method('findByRelation')->with('case-1')->willReturn([$byA, $byB]);
		$this->mapper->expects($this->never())->method('findByRelationBatchInSchema');

		$data = $this->render(['linked' => ['type' => 'array', 'inversedBy' => ['a', 'b'], 'items' => ['type' => 'object']]], ['title' => 'x']);

		$this->assertSame(['r-1', 'r-2'], $data['linked']);

	}//end testMultipleFieldsWithoutARefUseTheOwnSchemaOnly()

	/**
	 * A non-array property that only declares inversedBy on its items names no field of its own and is not rendered.
	 *
	 * @return void
	 */
	public function testAPropertyWithoutItsOwnFieldIsNotRendered(): void {
		$this->mapper->method('findByRelation')->willReturn([]);

		$data = $this->render(['owner' => ['type' => 'object', 'items' => ['inversedBy' => 'case']]], ['title' => 'x']);

		$this->assertSame(['title' => 'x'], $data);

	}//end testAPropertyWithoutItsOwnFieldIsNotRendered()
}//end class
