<?php

/**
 * MigrationHandler::migrateObjects() accepts a source register and schema given by slug.
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
 * The ownership check compares the object's stored register and schema with the ids of the
 * loaded source entities, whatever form the caller named them in.
 *
 * @covers \OCA\OpenRegister\Service\Object\MigrationHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\MigrationRun
 * @uses   \OCA\OpenRegister\Service\Object\MigrationSide
 */
class MigrationHandlerSlugSourceTest extends TestCase {

	/**
	 * Objects updated by the mapper double.
	 *
	 * @var list<ObjectEntity>
	 */
	private array $updated = [];

	/**
	 * A slug source register and schema migrate an object that lives in them.
	 *
	 * @return void
	 */
	public function testSlugSourceMigratesAnObjectThatLivesThere(): void {
		$report = $this->migrate(sourceRegister: 'src-register', sourceSchema: 'src-schema', register: '1', schema: '2');

		$this->assertSame(expected: 1, actual: $report['statistics']['objectsMigrated']);
		$this->assertSame(expected: 0, actual: $report['statistics']['objectsFailed']);
		$this->assertSame(expected: [], actual: $report['errors']);
		$this->assertCount(expectedCount: 1, haystack: $this->updated);
		$this->assertSame(expected: '30', actual: $this->updated[0]->getRegister());
		$this->assertSame(expected: '40', actual: $this->updated[0]->getSchema());
	}//end testSlugSourceMigratesAnObjectThatLivesThere()

	/**
	 * Id sources keep working.
	 *
	 * @return void
	 */
	public function testIdSourceMigratesAnObjectThatLivesThere(): void {
		$report = $this->migrate(sourceRegister: 1, sourceSchema: '2', register: '1', schema: '2');

		$this->assertSame(expected: 1, actual: $report['statistics']['objectsMigrated']);
		$this->assertCount(expectedCount: 1, haystack: $this->updated);
	}//end testIdSourceMigratesAnObjectThatLivesThere()

	/**
	 * A slug source still refuses an object stored in another register or schema.
	 *
	 * @return void
	 */
	public function testSlugSourceRefusesAnObjectThatLivesElsewhere(): void {
		foreach ([['7', '2'], ['1', '8']] as [$register, $schema]) {
			$this->updated = [];
			$report = $this->migrate(sourceRegister: 'src-register', sourceSchema: 'src-schema', register: $register, schema: $schema);

			$this->assertSame(expected: 0, actual: $report['statistics']['objectsMigrated']);
			$this->assertSame(expected: 1, actual: $report['statistics']['objectsFailed']);
			$this->assertStringContainsString(needle: 'does not belong', haystack: $report['errors'][0]);
			$this->assertSame(expected: [], actual: $this->updated);
		}
	}//end testSlugSourceRefusesAnObjectThatLivesElsewhere()

	/**
	 * Migrate one object stored in the given register and schema.
	 *
	 * @param string|int $sourceRegister The source register as requested.
	 * @param string|int $sourceSchema   The source schema as requested.
	 * @param string     $register       The register the object is stored in.
	 * @param string     $schema         The schema the object is stored in.
	 *
	 * @return array<string, mixed> The migration report.
	 */
	private function migrate(string|int $sourceRegister, string|int $sourceSchema, string $register, string $schema): array {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setName('One');
		$object->setRegister($register);
		$object->setSchema($schema);
		$object->setObject(['title' => 'T']);

		$entities = [
			'register' => ['src-register' => 1, '1' => 1, 'target' => 30],
			'schema'   => ['src-schema' => 2, '2' => 2, 'target' => 40],
		];
		$utility = $this->createMock(originalClassName: UtilityHandler::class);
		$utility->method('normalizeEntity')->willReturnCallback(
			static function ($entity, string $type) use ($entities) {
				$loaded = new Register();
				if ($type === 'schema') {
					$loaded = new Schema();
				}

				$loaded->setId($entities[$type][(string) $entity]);
				return $loaded;
			}
		);

		$mapper = $this->createMock(originalClassName: MagicMapper::class);
		$mapper->method('findMultiple')->willReturn([$object]);
		$mapper->method('update')->willReturnCallback(
			function (ObjectEntity $entity): ObjectEntity {
				$this->updated[] = $entity;
				return $entity;
			}
		);

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
			sourceRegister: $sourceRegister,
			sourceSchema: $sourceSchema,
			targetRegister: 'target',
			targetSchema: 'target',
			objectIds: ['u-1'],
			mapping: ['title' => 'title']
		);
	}//end migrate()
}//end class
