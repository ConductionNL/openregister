<?php

/**
 * MigrationHandler::migrateObjects() reports an object as not found only when neither its uuid nor its id was returned.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A requested id matches a found object by uuid or by id, whether it arrives as a string or an int.
 *
 * @covers \OCA\OpenRegister\Service\Object\MigrationHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\MigrationRun
 * @uses   \OCA\OpenRegister\Service\Object\MigrationSide
 */
class MigrationHandlerNotFoundIdTest extends TestCase {

	/**
	 * An object requested by uuid and by its id as a string is migrated once and not reported missing.
	 *
	 * @return void
	 */
	public function testStringIdOfAFoundObjectIsNotReportedMissing(): void {
		$report = $this->migrate(objectIds: ['u-1', '12']);

		$this->assertSame(expected: 1, actual: $report['statistics']['objectsMigrated']);
		$this->assertSame(expected: 0, actual: $report['statistics']['objectsFailed']);
		$this->assertSame(expected: [], actual: $report['errors']);
		$this->assertSame(expected: [], actual: $report['warnings']);
		$this->assertCount(expectedCount: 1, haystack: $report['details']);
	}//end testStringIdOfAFoundObjectIsNotReportedMissing()

	/**
	 * An int id of a found object is not reported missing either.
	 *
	 * @return void
	 */
	public function testIntIdOfAFoundObjectIsNotReportedMissing(): void {
		$report = $this->migrate(objectIds: ['u-1', 12]);

		$this->assertSame(expected: 0, actual: $report['statistics']['objectsFailed']);
		$this->assertCount(expectedCount: 1, haystack: $report['details']);
	}//end testIntIdOfAFoundObjectIsNotReportedMissing()

	/**
	 * Ids that match nothing found are still reported missing, each once.
	 *
	 * @return void
	 */
	public function testIdsThatMatchNothingAreReportedMissing(): void {
		$report = $this->migrate(objectIds: ['u-1', '13', 'u-2', '1']);

		$this->assertSame(expected: 1, actual: $report['statistics']['objectsMigrated']);
		$this->assertSame(expected: 3, actual: $report['statistics']['objectsFailed']);
		$this->assertSame(
			expected: [
				'Failed to migrate object 13: Object not found',
				'Failed to migrate object u-2: Object not found',
				'Failed to migrate object 1: Object not found',
			],
			actual: $report['errors']
		);
	}//end testIdsThatMatchNothingAreReportedMissing()

	/**
	 * Migrate the requested ids, of which the mapper finds one object (uuid u-1, id 12).
	 *
	 * @param array<int, string|int> $objectIds The ids as requested.
	 *
	 * @return array<string, mixed> The migration report.
	 */
	private function migrate(array $objectIds): array {
		$object = new ObjectEntity();
		$object->setId(12);
		$object->setUuid('u-1');
		$object->setName('One');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['title' => 'T']);

		$ids = ['register' => ['1' => 1, 'target' => 30], 'schema' => ['2' => 2, 'target' => 40]];
		$utility = $this->createMock(originalClassName: UtilityHandler::class);
		$utility->method('normalizeEntity')->willReturnCallback(
			static function ($entity, string $type) use ($ids) {
				$loaded = new Register();
				if ($type === 'schema') {
					$loaded = new Schema();
				}

				$loaded->setId($ids[$type][(string) $entity]);
				return $loaded;
			}
		);

		$mapper = $this->createMock(originalClassName: MagicMapper::class);
		$mapper->method('findMultiple')->willReturn([$object]);
		$mapper->method('update')->willReturnArgument(0);

		$dataManip = $this->createMock(originalClassName: DataManipulationHandler::class);
		$dataManip->method('mapObjectProperties')->willReturn(['title' => 'T']);

		$handler = new MigrationHandler(
			objectMapper: $mapper,
			schemaMapper: $this->createMock(originalClassName: SchemaMapper::class),
			registerMapper: $this->createMock(originalClassName: RegisterMapper::class),
			saveHandler: $this->createMock(originalClassName: SaveObject::class),
			utilityHandler: $utility,
			dataManipHandler: $dataManip,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		return $handler->migrateObjects(
			sourceRegister: '1',
			sourceSchema: '2',
			targetRegister: 'target',
			targetSchema: 'target',
			objectIds: $objectIds,
			mapping: ['title' => 'title']
		);
	}//end migrate()
}//end class
