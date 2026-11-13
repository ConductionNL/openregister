<?php

/**
 * Unit tests for preloading an inverse relation that names no inverse field.
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
use OCA\OpenRegister\Service\Object\InverseRenderSupport;
use OCA\OpenRegister\Service\Object\InverseSchemaLookups;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An items-level `inversedBy: []` wins over the property's own and names no field: nothing is queried,
 * and every entity still gets an empty cache entry so the render does not fall back to the slow lookup.
 *
 * @covers \OCA\OpenRegister\Service\Object\InverseRelationPreload
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Object\InverseObjectHooks
 * @uses \OCA\OpenRegister\Service\Object\InverseRenderSupport
 * @uses \OCA\OpenRegister\Service\Object\InverseSchemaLookups
 */
class InverseRelationPreloadEmptyFieldsTest extends TestCase {

	/**
	 * No field means no query, and empty cache entries for both entities.
	 *
	 * @return void
	 */
	public function testNoInverseFieldMeansNoQuery(): void {
		$source = new Schema();
		$source->setProperties(['notes' => ['type' => 'array', 'inversedBy' => 'case', 'items' => ['$ref' => 'note', 'inversedBy' => []]]]);
		$target = new Schema();

		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->expects($this->never())->method('findByRelationBatchInSchema');

		$cache = $this->createMock(InverseRelationCache::class);
		$cache->expects($this->once())
			->method('initializeInverseCacheEntries')
			->with(['c-1', 'c-2'], 'notes');
		$cache->expects($this->once())
			->method('indexReferencingObjects')
			->with([], $this->anything());

		$support = new InverseRenderSupport(
			$mapper,
			new NullLogger(),
			new InverseSchemaLookups(
				static fn ($id): ?Schema => $id === '9' ? $source : ($id === '5' ? $target : null),
				static fn (string $ref): string => $ref === 'note' ? '5' : ''
			),
			new InverseObjectHooks(
				$cache,
				static fn () => null,
				static fn () => null,
				static fn (array $objects): array => $objects
			)
		);

		$entities = [];
		foreach (['c-1', 'c-2'] as $uuid) {
			$entity = new ObjectEntity();
			$entity->setUuid($uuid);
			$entity->setSchema('9');
			$entity->setRegister('3');
			$entities[] = $entity;
		}

		(new InverseRelationPreload($support))->preloadInverseRelationships($entities, ['notes']);

	}//end testNoInverseFieldMeansNoQuery()
}//end class
