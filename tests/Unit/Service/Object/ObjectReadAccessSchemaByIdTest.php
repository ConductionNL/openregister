<?php

/**
 * The schema lookup by id that the graph's per-schema doelbinding check uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\ObjectReadAccess::schemaById
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ObjectReadAccessSchemaByIdTest extends TestCase {

	/**
	 * An id that resolves answers its schema; an empty, unknown or failing one answers null.
	 *
	 * @return void
	 */
	public function testSchemaByIdResolvesOrAnswersNull(): void {
		$schema = new Schema();
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(
			static function (string|int $id) use ($schema): Schema {
				if ((string)$id === '7') {
					return $schema;
				}

				throw new \RuntimeException('gone');
			}
		);
		$access = new ObjectReadAccess($this->createMock(ObjectService::class), $this->createMock(IUserSession::class), $mapper);

		$this->assertSame($schema, $access->schemaById(schemaId: '7'));
		$this->assertSame($schema, $access->schemaById(schemaId: 7));
		$this->assertNull($access->schemaById(schemaId: null));
		$this->assertNull($access->schemaById(schemaId: ''));
		$this->assertNull($access->schemaById(schemaId: '8'));
	}//end testSchemaByIdResolvesOrAnswersNull()
}
