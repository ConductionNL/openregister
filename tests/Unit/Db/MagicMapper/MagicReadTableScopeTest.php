<?php

/**
 * MagicReadTableScopeTest: the organisation scope a magic read table names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicReadTable
 * @uses   \OCA\OpenRegister\Db\MagicMapper\OrganisationScopeTable
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicReadTableScopeTest extends TestCase {

	public function testTheScopeTableCarriesTheRegisterAndTheSchemasId(): void {
		$schema = new Schema();
		$schema->setId(12);

		$scope = (new MagicReadTable($schema, 3, 'openregister_table_3_12'))->organisationScopeTable();

		$this->assertSame(3, $scope->registerId);
		$this->assertSame(12, $scope->schemaId);
		$this->assertTrue($scope->isNamed());
	}//end testTheScopeTableCarriesTheRegisterAndTheSchemasId()

	public function testWithoutARegisterTheScopeIsNamedByTheSchemaAlone(): void {
		$schema = new Schema();
		$schema->setId(12);

		$scope = (new MagicReadTable($schema))->organisationScopeTable();

		$this->assertNull($scope->registerId);
		$this->assertSame(12, $scope->schemaId);
	}//end testWithoutARegisterTheScopeIsNamedByTheSchemaAlone()
}//end class
