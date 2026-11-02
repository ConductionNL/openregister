<?php

/**
 * MigrationHandler slug re-check (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Review finding (Robert, 30-10): a migration carries the slug into another
 * register/schema, so it is re-checked against the target's holders first.
 */
class MigrationHandlerSlugTest extends TestCase {

	public function testAMigratedObjectGetsTheSlugDecidedForTheTarget(): void {
		$sourceRegister = new Register();
		$sourceRegister->setId(1);
		$sourceSchema = new Schema();
		$sourceSchema->setId(10);
		$targetRegister = new Register();
		$targetRegister->setId(2);
		$targetSchema = new Schema();
		$targetSchema->setId(20);

		$object = new ObjectEntity();
		$object->setId(5);
		$object->setUuid('11111111-1111-1111-1111-111111111111');
		$object->setRegister('1');
		$object->setSchema('10');
		$object->setSlug('hello-world');
		$object->setObject(['title' => 'Hello World']);

		$utility = $this->createMock(UtilityHandler::class);
		$utility->method('normalizeEntity')->willReturnCallback(
			fn ($entity, string $type) => match ([$type, (int)$entity]) {
				['register', 1] => $sourceRegister,
				['schema', 10] => $sourceSchema,
				['register', 2] => $targetRegister,
				['schema', 20] => $targetSchema,
			}
		);
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findMultiple')->willReturn([$object]);
		$written = 'unset';
		$mapper->method('update')->willReturnCallback(
			function (ObjectEntity $entity) use (&$written): ObjectEntity {
				$written = $entity->getSlug();
				return $entity;
			}
		);
		$manip = $this->createMock(DataManipulationHandler::class);
		$manip->method('mapObjectProperties')->willReturn(['title' => 'Hello World']);
		$slugs = $this->createMock(ObjectSlugHandler::class);
		$slugs->expects($this->once())->method('slugForMove')
			->with($object, $targetRegister, $targetSchema)
			->willReturn('hello-world-20261030120000');

		$handler = new MigrationHandler(
			$mapper,
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SaveObject::class),
			$utility,
			$manip,
			new NullLogger(),
			$slugs
		);

		$report = $handler->migrateObjects(1, 10, 2, 20, ['11111111-1111-1111-1111-111111111111'], ['title' => 'title']);

		$this->assertSame(1, $report['statistics']['objectsMigrated'], json_encode($report));
		$this->assertSame('hello-world-20261030120000', $written);
	}
}//end class
