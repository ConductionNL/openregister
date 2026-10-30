<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Resolving a schema reference, with and without the caller's RBAC and tenancy.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler::resolveSchemaReference
 * @uses \OCA\OpenRegister\Db\Schema
 */
class RelationCascadeHandlerSchemaReferenceTest extends TestCase {

	/**
	 * A schema row with an id and slug.
	 *
	 * @param int    $id   The id.
	 * @param string $slug The slug.
	 *
	 * @return Schema
	 */
	private function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);

		return $schema;
	}//end schema()

	/**
	 * A system-side slug lookup reads the schema list without RBAC and tenancy; the default keeps both.
	 *
	 * @return void
	 */
	public function testASystemSideLookupDropsRbacAndTenancyOnlyWhenAsked(): void {
		$seen = [];
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = [], ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use (&$seen): array {
				$seen[] = [$_rbac, $_multitenancy];

				return [$this->schema(7, 'supplier')];
			}
		);
		$handler = new RelationCascadeHandler(
			$this->createMock(MagicMapper::class),
			$mapper,
			$this->createMock(RegisterMapper::class),
			new NullLogger()
		);

		$this->assertSame('7', $handler->resolveSchemaReference(reference: '#/components/schemas/Supplier'));
		$this->assertSame('7', $handler->resolveSchemaReference(reference: 'supplier', scope: RelationCascadeHandler::SYSTEM_SCOPE));
		$this->assertSame([[true, true], [false, false]], $seen);
	}//end testASystemSideLookupDropsRbacAndTenancyOnlyWhenAsked()

	/**
	 * A system-side numeric lookup passes the same flags to find().
	 *
	 * @return void
	 */
	public function testASystemSideIdLookupDropsRbacAndTenancy(): void {
		$seen = [];
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use (&$seen): Schema {
				$seen[] = [$_rbac, $_multitenancy];

				return $this->schema(12, 'x');
			}
		);
		$handler = new RelationCascadeHandler(
			$this->createMock(MagicMapper::class),
			$mapper,
			$this->createMock(RegisterMapper::class),
			new NullLogger()
		);

		$this->assertSame('12', $handler->resolveSchemaReference(reference: '12', scope: RelationCascadeHandler::SYSTEM_SCOPE));
		$this->assertSame('12', $handler->resolveSchemaReference(reference: '12'));
		$this->assertNull($handler->resolveSchemaReference(reference: ''));
		$this->assertSame([[false, false], [true, true]], $seen);
	}//end testASystemSideIdLookupDropsRbacAndTenancy()
}//end class
