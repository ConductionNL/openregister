<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Configuration\AppConfigurationRecord;
use OCA\OpenRegister\Service\Configuration\AppImport;
use OCA\OpenRegister\Service\Configuration\ImportedConfigurationUpdate;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Only entities of the right kind reach a Configuration's lists.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\AppConfigurationRecord
 * @uses \OCA\OpenRegister\Service\Configuration\AppImport
 * @uses \OCA\OpenRegister\Service\Configuration\ImportedConfigurationUpdate
 * @uses \OCA\OpenRegister\Db\Configuration
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class AppConfigurationRecordTest extends TestCase {

	/**
	 * An entity with an id.
	 *
	 * @param object $entity The entity.
	 * @param int    $id     The id.
	 *
	 * @return object
	 */
	private function withId(object $entity, int $id): object {
		(new ReflectionProperty($entity, 'id'))->setValue($entity, $id);

		return $entity;
	}//end withId()

	/**
	 * An entity of another kind in a result list is left out, and a known id is not repeated.
	 *
	 * @return void
	 */
	public function testOnlyNewEntitiesOfTheRightKindAreAdded(): void {
		$configuration = new Configuration();
		$configuration->setRegisters([1]);
		$configuration->setSchemas([]);
		$configuration->setObjects([]);
		$register = $this->withId(entity: new Register(), id: 1);
		$schema   = $this->withId(entity: new Schema(), id: 2);
		$object   = $this->withId(entity: new ObjectEntity(), id: 3);
		$result   = ['registers' => [$register, $schema], 'schemas' => [$schema, $register], 'objects' => [$object, $schema]];

		$record = new AppConfigurationRecord(
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			logger: $this->createMock(LoggerInterface::class)
		);
		$record->track(
			import: new AppImport(appId: 'app', data: [], version: '1.0.0', force: false),
			update: new ImportedConfigurationUpdate(configuration: $configuration, result: $result)
		);

		$this->assertSame([1], $configuration->getRegisters());
		$this->assertSame([2], $configuration->getSchemas());
		$this->assertSame([3], $configuration->getObjects());
	}//end testOnlyNewEntitiesOfTheRightKindAreAdded()
}//end class
