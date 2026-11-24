<?php

/**
 * InverseRelationsTest: a saved object is added to the relations of what it points at.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SaveObject\InverseRelations;
use OCA\OpenRegister\Service\Object\SaveObject\InverseRelationWrite;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\InverseRelations
 */
class InverseRelationsTest extends TestCase {

	private const SAVED = '11111111-1111-4111-8111-111111111111';

	private const FIRST = '22222222-2222-4222-8222-222222222222';

	private const SECOND = '33333333-3333-4333-8333-333333333333';

	/**
	 * Each referenced object gets only its relations written, without a read or a full update.
	 *
	 * @return void
	 */
	public function testEachReferencedObjectGetsOnlyItsRelationsWrittenWithoutAReadOrAFullUpdate(): void {
		$register = new Register();
		$schema   = new Schema();
		$schema->setProperties(['filedOver' => ['type' => 'array', 'items' => ['type' => 'string', '$ref' => 'case']]]);
		$target = new Schema();

		$saved = new ObjectEntity();
		$saved->setUuid(self::SAVED);
		$saved->setRelations(['filedOver.0' => self::FIRST, 'filedOver.1' => self::SECOND]);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->with('case')->willReturn($target);

		$written = [];
		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->expects($this->never())->method('find');
		$objects->expects($this->never())->method('update');
		$objects->expects($this->exactly(2))->method('appendRelation')->willReturnCallback(
			function (RegisterSchema $table, string $uuid, string $relatedUuid) use (&$written, $register, $target): int {
				$this->assertSame($register, $table->register);
				$this->assertSame($target, $table->schema);
				$written[] = [$uuid, $relatedUuid];
				return 1;
			}
		);

		(new InverseRelations($objects, $schemas, new NullLogger()))->update($saved, new InverseRelationWrite(register: $register, schema: $schema));

		$this->assertSame([[self::FIRST, self::SAVED], [self::SECOND, self::SAVED]], $written);
	}//end testEachReferencedObjectGetsOnlyItsRelationsWrittenWithoutAReadOrAFullUpdate()

	/**
	 * A relation whose property has no $ref writes nothing back.
	 *
	 * @return void
	 */
	public function testARelationWithoutARefWritesNothing(): void {
		$schema = new Schema();
		$schema->setProperties(['filedOver' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']]]);

		$saved = new ObjectEntity();
		$saved->setUuid(self::SAVED);
		$saved->setRelations(['filedOver.0' => self::FIRST]);

		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->expects($this->never())->method('appendRelation');

		(new InverseRelations($objects, $this->createMock(SchemaMapperInterface::class), new NullLogger()))
			->update($saved, new InverseRelationWrite(register: new Register(), schema: $schema));
	}//end testARelationWithoutARefWritesNothing()
}//end class
