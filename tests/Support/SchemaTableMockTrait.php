<?php
/**
 * SPDX-FileCopyrightText: 2024 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One table double that is valid on every Nextcloud major this app declares.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Support
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Support;

use PHPUnit\Framework\MockObject\MockObject;

/**
 * A schema-table double typed for the server under test.
 *
 * `ISchemaWrapper` hands out two different things across the declared range:
 * `Doctrine\DBAL\Schema\Table` up to NC 34, and `OCP\DB\Schema\ITable` from
 * NC 35, where the interface also DECLARES that return type. A test that always
 * built a Doctrine double therefore stopped being loadable on 35, not because
 * the app changed but because the double no longer satisfied the signature:
 *
 *   IncompatibleReturnValueException: Method getTable may not return value of
 *   type MockObject, its declared return type is "OCP\DB\Schema\ITable"
 *
 * and, where PHPUnit could not refuse it up front, the same thing at runtime:
 *
 *   TypeError: createTable(): Return value must be of type
 *   OCP\DB\Schema\ITable
 *
 * Picking the class from the server present at runtime keeps ONE test body
 * correct on 32, 33, 34 and 35. The two types agree on every method these tests
 * drive — addColumn, addIndex, addUniqueIndex, setPrimaryKey, dropIndex,
 * hasColumn, hasIndex, getColumn — which is the same overlap that lets the
 * migrations themselves run unmodified on both.
 */
trait SchemaTableMockTrait {
	/**
	 * A table double the server under test will accept.
	 *
	 * @return MockObject The double, typed for this server.
	 */
	private function createTableMock(): MockObject {
		return $this->createMock($this->schemaTableClass());
	}//end createTableMock()

	/**
	 * The table type this server's ISchemaWrapper deals in.
	 *
	 * @return class-string The interface on NC 35+, the Doctrine class below it.
	 */
	private function schemaTableClass(): string {
		if (interface_exists('\OCP\DB\Schema\ITable') === true) {
			return '\OCP\DB\Schema\ITable';
		}

		return '\Doctrine\DBAL\Schema\Table';
	}//end schemaTableClass()
}//end trait
