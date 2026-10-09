<?php

/**
 * OpenRegister - `_ids` on the string-built UNION arms.
 *
 * The recent lens reaches every search path as `_ids`. The UNION arms build
 * their WHERE clause as text and did not apply `_ids` at all, so a
 * cross-table `_recent=true` search returned every row.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/recently-opened-means-opened/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses \OCA\OpenRegister\Db\Schema
 */
final class MagicSearchHandlerIdsSqlTest extends TestCase {

	/**
	 * Build a handler whose organisation and RBAC decisions are dictated.
	 *
	 * Both doubles use `onlyMethods` semantics by construction — they are
	 * `createMock()` of the real classes, so a method the real class does not
	 * declare cannot be stubbed and a renamed collaborator method fails here
	 * rather than silently passing.
	 *
	 * @param array<string, mixed> $scope             The organisation decision to render.
	 * @param bool                 $conditionalBypass Whether RBAC rules would bypass tenancy.
	 * @param bool                 $holdsGrants       Whether the caller holds per-object grants.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(
		array $scope,
		bool $conditionalBypass = false,
		bool $holdsGrants = false,
	): MagicSearchHandler {
		$connection = $this->createMock(originalClassName: IDBConnection::class);
		// Quoting is the database's job; here it only has to be visible, so the
		// assertions can read the uuid that reached the SQL rather than the one
		// the test handed in.
		$connection->method('quote')->willReturnCallback(
			static fn ($value): string => "'" . (string)$value . "'"
		);

		$queryBuilder = $this->createMock(originalClassName: IQueryBuilder::class);
		$queryBuilder->method('getConnection')->willReturn($connection);

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
		$db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());

		$rbac = $this->createMock(originalClassName: MagicRbacHandler::class);
		$rbac->method('buildRbacConditionsSql')->willReturn(['bypass' => true, 'conditions' => []]);
		$rbac->method('hasConditionalRulesBypassingMultitenancy')->willReturn($conditionalBypass);
		$rbac->method('currentCallerHoldsObjectGrants')->willReturn($holdsGrants);

		$organisation = $this->createMock(originalClassName: MagicOrganizationHandler::class);
		$organisation->method('resolveOrganizationScope')->willReturn($scope);
		$organisation->method('isAdminOverrideEnabled')->willReturn(false);

		return new MagicSearchHandler(
			$db,
			$this->createMock(originalClassName: LoggerInterface::class),
			$rbac,
			$organisation,
			$this->createMock(originalClassName: SchemaTypeConverter::class),
			$this->createMock(originalClassName: DateTimeNormalizer::class),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);
	}//end handler()

	/**
	 * The conditions a query produces, joined for substring assertions.
	 *
	 * @param MagicSearchHandler   $handler The handler under test.
	 * @param array<string, mixed> $query   The query.
	 *
	 * @return string The conditions, joined with AND as the arm would join them.
	 */
	private function sqlFor(MagicSearchHandler $handler, array $query = []): string {
		return implode(
			' AND ',
			$handler->buildWhereConditionsSql(query: $query, schema: new Schema())
		);
	}//end sqlFor()

	/**
	 * `_ids` narrows the arm to those uuids or slugs, quoted.
	 *
	 * @return void
	 */
	public function testIdsNarrowTheUnionArm(): void {
		$sql = $this->sqlFor(
			$this->handler(['mode' => MagicOrganizationHandler::SCOPE_ALL]),
			['_ids' => ['uuid-a', "o'brien"]]
		);

		$this->assertStringContainsString("(_uuid IN ('uuid-a', 'o'brien') OR _slug IN ('uuid-a', 'o'brien'))", $sql);
	}//end testIdsNarrowTheUnionArm()

	/**
	 * No `_ids`, no restriction: the arm is not narrowed by accident.
	 *
	 * @return void
	 */
	public function testNoIdsAddNoRestriction(): void {
		$handler = $this->handler(['mode' => MagicOrganizationHandler::SCOPE_ALL]);

		$this->assertStringNotContainsString('_uuid IN', $this->sqlFor($handler, []));
		$this->assertStringNotContainsString('_uuid IN', $this->sqlFor($handler, ['_ids' => []]));
	}//end testNoIdsAddNoRestriction()
}//end class
