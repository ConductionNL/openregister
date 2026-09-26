<?php

/**
 * OpenRegister - the organisation boundary on the string-built query paths.
 *
 * The UNION search arms and the UNION facet arms build their WHERE clause as
 * text rather than through the QueryBuilder, so they cannot reuse
 * `applyOrganizationFilter()`. For a long time that meant they simply did not
 * carry the boundary at all: the RBAC half was ported across, the organisation
 * half was not, and a cross-table read returned rows from other organisations
 * while every other path denied them.
 *
 * These tests pin the rendering of each decision the organisation handler can
 * take, including the two that mean "no rows" and the one that means "every
 * row", so that a renderer which always emitted something, or never did, cannot
 * pass.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md#requirement-the-private-principal-is-honoured-identically-on-every-enforcement-path
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
 */
final class MagicSearchHandlerUnionTenancyTest extends TestCase {

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
	 * The ordinary case: a member of one organisation is confined to it.
	 *
	 * @return void
	 */
	public function testTheUnionArmIsConfinedToTheCallersOrganisation(): void {
		$sql = $this->sqlFor(
			$this->handler(['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a']])
		);

		$this->assertStringContainsString("_organisation IN ('org-a')", $sql);
	}//end testTheUnionArmIsConfinedToTheCallersOrganisation()

	/**
	 * Several organisations, and the shared master data holders folded in with
	 * them, all reach the SQL.
	 *
	 * @return void
	 */
	public function testEveryReadableOrganisationReachesTheSql(): void {
		$sql = $this->sqlFor(
			$this->handler(
				['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a', 'org-parent', 'holder']]
			)
		);

		$this->assertStringContainsString("_organisation IN ('org-a', 'org-parent', 'holder')", $sql);
	}//end testEveryReadableOrganisationReachesTheSql()

	/**
	 * An admin also sees the rows that belong to no organisation.
	 *
	 * ⚠️ This is the exact defect the aggregation API shipped: `IN` never
	 * matches NULL, so rendering only the `IN` half made every org-less row
	 * invisible while the list path returned it. The disjunct is the test.
	 *
	 * @return void
	 */
	public function testTheOrgLessRowsGetTheirOwnDisjunct(): void {
		$sql = $this->sqlFor(
			$this->handler(['mode' => MagicOrganizationHandler::SCOPE_IN_OR_NULL, 'uuids' => ['org-a']])
		);

		$this->assertStringContainsString(
			"(_organisation IN ('org-a') OR _organisation IS NULL)",
			$sql
		);
	}//end testTheOrgLessRowsGetTheirOwnDisjunct()

	/**
	 * An admin with no active organisation sees only the org-less rows.
	 *
	 * @return void
	 */
	public function testNullOnlyRendersTheNullPredicate(): void {
		$sql = $this->sqlFor(
			$this->handler(['mode' => MagicOrganizationHandler::SCOPE_NULL_ONLY, 'uuids' => []])
		);

		$this->assertStringContainsString('_organisation IS NULL', $sql);
		$this->assertStringNotContainsString('IN (', $sql);
	}//end testNullOnlyRendersTheNullPredicate()

	/**
	 * The least privileged principal that should be refused: an authenticated
	 * user with no active organisation at all.
	 *
	 * @return void
	 */
	public function testAUserWithNoOrganisationIsRefused(): void {
		$sql = $this->sqlFor(
			$this->handler(['mode' => MagicOrganizationHandler::SCOPE_NONE, 'uuids' => []])
		);

		$this->assertStringContainsString('1 = 0', $sql);
	}//end testAUserWithNoOrganisationIsRefused()

	/**
	 * "In these organisations", with no organisation named, is the empty set.
	 *
	 * Without this the `IN ()` would be either a syntax error or, worse on some
	 * platforms, a clause that matches nothing silently while the reader
	 * believes a boundary was applied.
	 *
	 * @return void
	 */
	public function testAScopedDecisionWithNoUuidsIsRefused(): void {
		$sql = $this->sqlFor(
			$this->handler(['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => []])
		);

		$this->assertStringContainsString('1 = 0', $sql);
		$this->assertStringNotContainsString('IN ()', $sql);
	}//end testAScopedDecisionWithNoUuidsIsRefused()

	/**
	 * A decision this renderer cannot read denies everything.
	 *
	 * The aggregation renderer answers an unknown mode by refusing and falling
	 * back to the PHP path. A UNION arm has nothing to fall back to, so the only
	 * safe answer here is no rows — never "no condition", which is how a missing
	 * boundary reads in SQL.
	 *
	 * @return void
	 */
	public function testAnUnreadableDecisionFailsClosed(): void {
		$sql = $this->sqlFor($this->handler(['mode' => 'a-mode-from-a-later-version', 'uuids' => ['org-a']]));

		$this->assertStringContainsString('1 = 0', $sql);
		$this->assertStringNotContainsString("'org-a'", $sql);
	}//end testAnUnreadableDecisionFailsClosed()

	/**
	 * CONTROL: a caller who may see everything gets no organisation condition.
	 *
	 * Without this, a renderer that emitted `1 = 0` for every decision would
	 * pass half the tests above, and one that emitted an `IN` for every decision
	 * would pass the other half.
	 *
	 * @return void
	 */
	public function testAnUnboundedScopeEmitsNoOrganisationCondition(): void {
		$sql = $this->sqlFor(
			$this->handler(['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []])
		);

		$this->assertStringNotContainsString('_organisation', $sql);
		$this->assertStringNotContainsString('1 = 0', $sql);
	}//end testAnUnboundedScopeEmitsNoOrganisationCondition()

	/**
	 * A per-object grant does not widen the tenant edge.
	 *
	 * A grant holder keeps the boundary even on a schema whose conditional rules
	 * would otherwise skip it (design D3c). This is the whole reason the union
	 * path mattered: the grant predicate was already there, so a grant was the
	 * one way a row from another organisation could be reached.
	 *
	 * @return void
	 */
	public function testAGrantHolderKeepsTheBoundary(): void {
		$sql = $this->sqlFor(
			$this->handler(
				['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a']],
				conditionalBypass: true,
				holdsGrants: true
			)
		);

		$this->assertStringContainsString("_organisation IN ('org-a')", $sql);
	}//end testAGrantHolderKeepsTheBoundary()

	/**
	 * CONTROL for the test above: without a grant, a conditional rule still
	 * bypasses tenancy here exactly as it does on the QueryBuilder path.
	 *
	 * This asserts PARITY, not a policy: the point of the change is that both
	 * paths take one decision, so a new rule invented only for the union arms
	 * would be its own kind of divergence.
	 *
	 * @return void
	 */
	public function testAConditionalRuleBypassesTenancyJustAsItDoesOnTheQueryBuilderPath(): void {
		$sql = $this->sqlFor(
			$this->handler(
				['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a']],
				conditionalBypass: true,
				holdsGrants: false
			)
		);

		$this->assertStringNotContainsString('_organisation', $sql);
	}//end testAConditionalRuleBypassesTenancyJustAsItDoesOnTheQueryBuilderPath()

	/**
	 * An explicit `_multitenancy=false` is honoured, including as a string.
	 *
	 * Query-string parameters arrive as text, and `"false"` is a non-empty
	 * string: read by identity it would have meant true, and read by truthiness
	 * it would have meant true as well.
	 *
	 * @param mixed $value A spelling of false that arrives over the wire.
	 *
	 * @return void
	 *
	 * @dataProvider falseSpellings
	 */
	public function testAnExplicitOptOutDropsTheBoundary(mixed $value): void {
		$sql = $this->sqlFor(
			$this->handler(['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a']]),
			['_multitenancy' => $value]
		);

		$this->assertStringNotContainsString('_organisation', $sql);
	}//end testAnExplicitOptOutDropsTheBoundary()

	/**
	 * The spellings of false a query string actually produces.
	 *
	 * @return array<string, array{0: mixed}> The cases.
	 */
	public static function falseSpellings(): array {
		return [
			'the string' => ['false'],
			'the boolean' => [false],
			'the digit' => ['0'],
		];
	}//end falseSpellings()

	/**
	 * A flag that means neither leaves the boundary on.
	 *
	 * The only thing this flag can do is turn access control OFF, so an
	 * unreadable request is not permission to skip it.
	 *
	 * @return void
	 */
	public function testAnUnreadableFlagKeepsTheBoundary(): void {
		$sql = $this->sqlFor(
			$this->handler(['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a']]),
			['_multitenancy' => ['not', 'a', 'flag']]
		);

		$this->assertStringContainsString("_organisation IN ('org-a')", $sql);
	}//end testAnUnreadableFlagKeepsTheBoundary()
}//end class
