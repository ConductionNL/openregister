<?php

/**
 * SchemaFollowerMapper: follow, unfollow, list and count.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use OCA\OpenRegister\Db\SchemaFollower;
use OCA\OpenRegister\Db\SchemaFollowerMapper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\SchemaFollowerMapper
 * @covers \OCA\OpenRegister\Db\SchemaFollower
 */
final class SchemaFollowerMapperTest extends TestCase {
	use RecordsQueries;

	public function testFollowingTwiceStoresOneRow(): void {
		$mapper = new SchemaFollowerMapper($this->recordingDb());
		$this->results[] = [];
		$this->results[] = [['id' => 1]];

		$this->assertTrue($mapper->follow(5, 'jan'));
		$this->assertFalse($mapper->follow(5, 'jan'));

		$this->assertCount(3, $this->queries, 'Two lookups and one insert.');
		$this->assertSame(
			'select(id) from(openregister_schema_followers) where(eq(schema_id, :p0)) andWhere(eq(uid, :p1)) setMaxResults(1) executeQuery()',
			$this->sql(0)
		);
		$this->assertStringStartsWith('insert(openregister_schema_followers)', $this->sql(1));
	}//end testFollowingTwiceStoresOneRow()

	public function testUnfollowSaysWhetherARowWent(): void {
		$this->affected = 1;

		$this->assertTrue((new SchemaFollowerMapper($this->recordingDb()))->unfollow(5, 'jan'));
		$this->assertSame('delete(openregister_schema_followers) where(eq(schema_id, :p0)) andWhere(eq(uid, :p1)) executeStatement()', $this->sql());
	}//end testUnfollowSaysWhetherARowWent()

	public function testFollowersAreListedOldestFirstAndCounted(): void {
		$mapper = new SchemaFollowerMapper($this->recordingDb());
		$this->results[] = [['uid' => 'jan'], ['uid' => 'piet']];
		$this->results[] = [['count' => 2]];

		$this->assertSame(['jan', 'piet'], $mapper->findUids(5));
		$this->assertSame(2, $mapper->countFollowers(5));
		$this->assertStringContainsString('orderBy(id, ASC) setMaxResults(1000)', $this->sql(0));
	}//end testFollowersAreListedOldestFirstAndCounted()

	public function testTheEntityTypesItsFields(): void {
		$this->assertSame(['id' => 'integer', 'schemaId' => 'integer', 'uid' => 'string', 'created' => 'datetime'], (new SchemaFollower())->getFieldTypes());
	}//end testTheEntityTypesItsFields()
}//end class
