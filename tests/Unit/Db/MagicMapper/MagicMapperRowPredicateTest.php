<?php

/**
 * MagicMapper::rbacRowPredicateSql() asks the RBAC handler for the read predicate of the table.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacAccessCall;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The raw-query row predicate is the handler's read predicate for the right table.
 */
class MagicMapperRowPredicateTest extends TestCase {

	/**
	 * A mapper whose RBAC handler answers with the given predicate for the expected question.
	 *
	 * @param Schema      $schema    The schema asked about.
	 * @param string|null $tableName The table the handler must be asked about.
	 * @param string|null $predicate The handler's answer.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function mapper(Schema $schema, ?string $tableName, ?string $predicate): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['getTableNameForRegisterSchema'])
			->getMock();
		$mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_2');

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->expects($this->once())
			->method('buildRbacRowPredicateSql')
			->with($this->callback(fn (RbacAccessCall $call): bool => $call->schema === $schema && $call->action === 'read' && $call->tableName === $tableName && $call->columnPrefix === ''))
			->willReturn($predicate);
		(new ReflectionProperty(MagicMapper::class, 'rbacHandler'))->setValue($mapper, $rbac);

		return $mapper;
	}

	public function testWithARegisterTheReadPredicateOfThatTableIsReturned(): void {
		$schema = new Schema();

		$this->assertSame('t._owner = 1', $this->mapper(schema: $schema, tableName: 'openregister_table_1_2', predicate: 't._owner = 1')->rbacRowPredicateSql(schema: $schema, register: new Register()));
	}

	public function testWithoutARegisterNoTableIsNamed(): void {
		$schema = new Schema();

		$this->assertSame('1 = 0', $this->mapper(schema: $schema, tableName: null, predicate: '1 = 0')->rbacRowPredicateSql(schema: $schema));
	}

	public function testNoRestrictionStaysNull(): void {
		$schema = new Schema();

		$this->assertNull($this->mapper(schema: $schema, tableName: null, predicate: null)->rbacRowPredicateSql(schema: $schema));
	}
}
