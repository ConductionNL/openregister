<?php

/**
 * Unit test for ObjectTreeMappers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Relation\ObjectTreeMappers;
use PHPUnit\Framework\TestCase;

/**
 * The bundle hands back exactly the mappers it was given.
 */
class ObjectTreeMappersTest extends TestCase {

	/**
	 * Each mapper is exposed under its own role.
	 *
	 * @return void
	 */
	public function testExposesEachMapperUnderItsRole(): void {
		$objects   = $this->createMock(MagicMapperInterface::class);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$schemas   = $this->createMock(SchemaMapperInterface::class);

		$mappers = new ObjectTreeMappers(objectMapper: $objects, registerMapper: $registers, schemaMapper: $schemas);

		$this->assertSame($objects, $mappers->objectMapper);
		$this->assertSame($registers, $mappers->registerMapper);
		$this->assertSame($schemas, $mappers->schemaMapper);
	}//end testExposesEachMapperUnderItsRole()
}//end class
