<?php

/**
 * The task mapper's raw-SQL shapes, per platform.
 *
 * The inbox's watcher predicate does a LIKE over a JSON column. On
 * PostgreSQL `Types::JSON` creates a `json` column and `json LIKE text` is
 * not an operator (`operator does not exist: json ~~ unknown`), so without a
 * cast every non-admin inbox request 500s there while MySQL and SQLite pass.
 * These tests pin the cast per platform, and pin that the candidate EXISTS
 * matches role pools, not only users and groups.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\TaskMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Platform-dependent SQL in TaskMapper.
 *
 * @covers \OCA\OpenRegister\Db\TaskMapper
 */
class TaskMapperTest extends TestCase {

	/**
	 * A mapper over a connection that reports the given platform and quotes
	 * identifiers the way that platform does.
	 *
	 * @param class-string<AbstractPlatform> $platformClass The platform.
	 * @param string $quote The platform's identifier quote character.
	 *
	 * @return TaskMapper The mapper.
	 */
	private function mapperOn(string $platformClass, string $quote): TaskMapper {
		$platform = $this->createMock(originalClassName: $platformClass);
		$platform->method('quoteIdentifier')->willReturnCallback(
			static fn (string $identifier): string => $quote . $identifier . $quote
		);
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);

		return new TaskMapper(db: $db);
	}//end mapperOn()

	/**
	 * RED 2: ON POSTGRESQL THE WATCHERS JSON COLUMN IS CAST TO TEXT before
	 * the LIKE, with double-quoted identifiers and no backtick anywhere.
	 *
	 * @return void
	 */
	public function testWatcherPredicateCastsJsonToTextOnPostgres(): void {
		$sql = $this->mapperOn(platformClass: PostgreSQLPlatform::class, quote: '"')->watchersAsText();

		$this->assertSame(expected: 'CAST("watchers" AS TEXT)', actual: $sql);
		$this->assertStringNotContainsString(needle: '`', haystack: $sql);
	}//end testWatcherPredicateCastsJsonToTextOnPostgres()

	/**
	 * On MySQL/MariaDB the cast is AS CHAR (TEXT is not a CAST target there).
	 *
	 * @return void
	 */
	public function testWatcherPredicateCastsToCharOnMysql(): void {
		$sql = $this->mapperOn(platformClass: MySQLPlatform::class, quote: '`')->watchersAsText();

		$this->assertSame(expected: 'CAST(`watchers` AS CHAR)', actual: $sql);
	}//end testWatcherPredicateCastsToCharOnMysql()

	/**
	 * The pooled-inbox EXISTS matches user, group AND role rows, quoted per
	 * platform, so a role-only pool is visible to the people who may claim
	 * from it — and it carries no backticks on PostgreSQL.
	 *
	 * @return void
	 */
	public function testCandidateMembershipMatchesRolesAndQuotesPerPlatform(): void {
		$sql = $this->mapperOn(platformClass: PostgreSQLPlatform::class, quote: '"')->candidateMembershipSql(
			uidPlaceholder: ':uid',
			groupsPlaceholder: ':groups'
		);

		$this->assertStringContainsString(needle: "\"tc\".\"kind\" = 'user' AND \"tc\".\"ref\" = :uid", haystack: $sql);
		$this->assertStringContainsString(needle: "\"tc\".\"kind\" = 'group' AND \"tc\".\"ref\" IN (:groups)", haystack: $sql);
		$this->assertStringContainsString(needle: "\"tc\".\"kind\" = 'role' AND \"tc\".\"ref\" IN (:groups)", haystack: $sql);
		$this->assertStringContainsString(needle: '"*PREFIX*openregister_task_candidates"', haystack: $sql);
		$this->assertStringNotContainsString(needle: '`', haystack: $sql);
	}//end testCandidateMembershipMatchesRolesAndQuotesPerPlatform()

	/**
	 * With no groups, only the user branch is emitted: no dangling IN ().
	 *
	 * @return void
	 */
	public function testCandidateMembershipWithoutGroupsHasOnlyTheUserBranch(): void {
		$sql = $this->mapperOn(platformClass: MySQLPlatform::class, quote: '`')->candidateMembershipSql(
			uidPlaceholder: ':uid',
			groupsPlaceholder: null
		);

		$this->assertStringContainsString(needle: "= 'user'", haystack: $sql);
		$this->assertStringNotContainsString(needle: "= 'group'", haystack: $sql);
		$this->assertStringNotContainsString(needle: "= 'role'", haystack: $sql);
	}//end testCandidateMembershipWithoutGroupsHasOnlyTheUserBranch()
}//end class
