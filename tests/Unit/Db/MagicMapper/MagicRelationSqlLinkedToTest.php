<?php

/**
 * MagicRelationSqlLinkedToTest: the reverse-relation lookups by one JSON column, and the MySQL reference-column match.
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

use OCA\OpenRegister\Db\MagicMapper\MagicRelationSql;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRelationSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 */
class MagicRelationSqlLinkedToTest extends TestCase {

	public function testOnMySqlALinkIsFoundWithJsonSearchAndANullDeleteMarker(): void {
		$sql = (new MagicRelationSql(false, ['', []]))->linkedTo('oc_openregister_table_1_2', 'owner');

		$this->assertSame(
			"SELECT * FROM oc_openregister_table_1_2 WHERE _deleted IS NULL AND owner IS NOT NULL AND JSON_SEARCH(owner, 'one', ?) IS NOT NULL LIMIT 100",
			$sql
		);
	}//end testOnMySqlALinkIsFoundWithJsonSearchAndANullDeleteMarker()

	public function testOnPostgresALinkIsFoundByJsonbContainmentAndAJsonNullDeleteMarkerCountsAsLive(): void {
		$sql = (new MagicRelationSql(true, ['', []]))->linkedTo('oc_openregister_table_1_2', 'owner');

		$this->assertSame(
			"SELECT * FROM oc_openregister_table_1_2 WHERE (_deleted IS NULL OR _deleted::text = 'null') AND owner IS NOT NULL"
			. ' AND owner::jsonb @> to_jsonb(?::text) LIMIT 100',
			$sql
		);
	}//end testOnPostgresALinkIsFoundByJsonbContainmentAndAJsonNullDeleteMarkerCountsAsLive()

	public function testOnMySqlAReferenceColumnIsMatchedExactlyOrAsTextContainingTheUuid(): void {
		[$where, $params] = (new MagicRelationSql(false, ['', []]))->batchWhere(['u1'], ['ownerId']);

		$this->assertSame(
			"_deleted IS NULL AND (JSON_SEARCH(_relations, 'one', ?) IS NOT NULL OR (`owner_id` = ? OR CAST(`owner_id` AS CHAR) LIKE ?))",
			$where
		);
		$this->assertSame(['u1', 'u1', '%u1%'], $params);
	}//end testOnMySqlAReferenceColumnIsMatchedExactlyOrAsTextContainingTheUuid()

	public function testOnPostgresAReferenceColumnIsReadAsTextForTheContainsMatch(): void {
		[$where, $params] = (new MagicRelationSql(true, ['', []]))->batchWhere(['u1', 'u2'], ['ownerId']);

		$this->assertStringEndsWith(
			'OR ("owner_id" = ? OR "owner_id"::text LIKE ?) OR ("owner_id" = ? OR "owner_id"::text LIKE ?))',
			$where
		);
		$this->assertSame(['u1', 'u1', 'u2', 'u2', 'u1', '%u1%', 'u2', '%u2%'], $params);
	}//end testOnPostgresAReferenceColumnIsReadAsTextForTheContainsMatch()
}//end class
