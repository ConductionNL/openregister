<?php

/**
 * Unit tests for TaskSubjectLocator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/flow-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Task\TaskSubjectLocator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A task's subject is located once, when the task is written, so the inbox
 * never has to search every magic table for it again.
 *
 * @spec openspec/specs/flow-tasks/spec.md
 */
class TaskSubjectLocatorTest extends TestCase {

	/**
	 * An entity in a magic table.
	 *
	 * @param string $uuid The uuid.
	 * @param string|null $register The register id as the entity carries it.
	 * @param string|null $schema The schema id as the entity carries it.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, ?string $register, ?string $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister($register);
		$entity->setSchema($schema);

		return $entity;
	}//end entity()

	/**
	 * A locator over the given object mapper, with stub register and schema mappers.
	 *
	 * @param MagicMapper $objects The object mapper.
	 *
	 * @return TaskSubjectLocator The locator.
	 */
	private function locator(MagicMapper $objects): TaskSubjectLocator {
		return new TaskSubjectLocator(
			$objects,
			$this->createMock(\OCA\OpenRegister\Db\RegisterMapper::class),
			$this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class),
			new NullLogger()
		);
	}//end locator()

	/**
	 * An unlocated anchor gets its register and schema from one lookup.
	 *
	 * @return void
	 */
	public function testAnUnlocatedAnchorIsLocated(): void {
		$objects = $this->createMock(MagicMapper::class);
		$objects->expects($this->once())->method('findMultipleAcrossAllMagicTables')
			->with(['obj-1'])
			->willReturn([$this->entity(uuid: 'obj-1', register: '7', schema: '42')]);

		$data = ($this->locator($objects))->withLocation(data: ['objectUuid' => 'obj-1', 'title' => 'Beoordelen']);

		$this->assertSame(['objectUuid' => 'obj-1', 'title' => 'Beoordelen', 'registerId' => 7, 'schemaId' => 42], $data);
	}//end testAnUnlocatedAnchorIsLocated()

	/**
	 * A caller that already names the location is trusted and costs nothing.
	 *
	 * @return void
	 */
	public function testAKnownLocationIsNotLookedUpAgain(): void {
		$objects = $this->createMock(MagicMapper::class);
		$objects->expects($this->never())->method('findMultipleAcrossAllMagicTables');

		$data = ['objectUuid' => 'obj-1', 'registerId' => 3, 'schemaId' => 9];

		$this->assertSame($data, ($this->locator($objects))->withLocation(data: $data));
	}//end testAKnownLocationIsNotLookedUpAgain()

	/**
	 * A missing subject, a non-numeric location or a failing store leaves the
	 * task exactly as given.
	 *
	 * @return void
	 */
	public function testWhatCannotBeLocatedIsLeftAsGiven(): void {
		$data = ['objectUuid' => 'obj-1'];

		$missing = $this->createMock(MagicMapper::class);
		$missing->method('findMultipleAcrossAllMagicTables')->willReturn([]);
		$this->assertSame($data, ($this->locator($missing))->withLocation(data: $data));

		$slugged = $this->createMock(MagicMapper::class);
		$slugged->method('findMultipleAcrossAllMagicTables')->willReturn([$this->entity(uuid: 'obj-1', register: 'zaken', schema: 'zaak')]);
		$this->assertSame($data, ($this->locator($slugged))->withLocation(data: $data));

		$failing = $this->createMock(MagicMapper::class);
		$failing->method('findMultipleAcrossAllMagicTables')->willThrowException(new RuntimeException('down'));
		$this->assertSame($data, ($this->locator($failing))->withLocation(data: $data));

		$standalone = $this->createMock(MagicMapper::class);
		$standalone->expects($this->never())->method('findMultipleAcrossAllMagicTables');
		$this->assertSame(['title' => 'Los'], ($this->locator($standalone))->withLocation(data: ['title' => 'Los']));
	}//end testWhatCannotBeLocatedIsLeftAsGiven()

	/**
	 * Many subjects are located with ONE search, keyed by uuid.
	 *
	 * @return void
	 */
	public function testManySubjectsShareOneSearch(): void {
		$objects = $this->createMock(MagicMapper::class);
		$objects->expects($this->once())->method('findMultipleAcrossAllMagicTables')
			->with(['a', 'b'])
			->willReturn([$this->entity(uuid: 'a', register: '1', schema: '2'), $this->entity(uuid: 'b', register: '3', schema: '4')]);

		$this->assertSame(
			['a' => ['registerId' => 1, 'schemaId' => 2], 'b' => ['registerId' => 3, 'schemaId' => 4]],
			($this->locator($objects))->locateMany(uuids: ['a', 'b', 'a', ' '])
		);
	}//end testManySubjectsShareOneSearch()

	/**
	 * A long list is searched in chunks, never in one statement.
	 *
	 * One search for 66 uuids over 1,664 tables passes ~110,000 parameters,
	 * which Postgres refuses, and the search then answers with nothing at all
	 * (measured 2026-10-06). Asserting the chunk sizes, so a version that went
	 * back to one search would fail here instead of silently locating nothing.
	 *
	 * @return void
	 */
	public function testALongListIsSearchedInChunks(): void {
		$uuids = array_map(fn (int $i): string => 'obj-' . $i, range(1, 60));
		$sizes = [];

		$objects = $this->createMock(MagicMapper::class);
		$objects->method('findMultipleAcrossAllMagicTables')->willReturnCallback(
			function (array $chunk) use (&$sizes): array {
				$sizes[] = count($chunk);

				return array_map(fn (string $uuid): ObjectEntity => $this->entity(uuid: $uuid, register: '1', schema: '2'), $chunk);
			}
		);

		$located = ($this->locator($objects))->locateMany(uuids: $uuids);

		$this->assertSame([25, 25, 10], $sizes);
		$this->assertCount(60, $located);
	}//end testALongListIsSearchedInChunks()
}//end class
