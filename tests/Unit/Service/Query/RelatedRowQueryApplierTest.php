<?php

/**
 * The caller that turns a `_related` block into SQL on a real query.
 *
 * 🔴 THE POINT OF THIS CLASS IS THAT IT REFUSES. A `_related` block that is
 * dropped answers the UNFILTERED set to a deliberately narrow question, and the
 * response looks identical to a correctly filtered one. The parser already
 * throws on a malformed block; these are the two refusals only a live lookup
 * can make.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Query
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Query;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Query\PropertyReadGuard;
use OCA\OpenRegister\Service\Query\RelatedRowAccess;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `RelatedRowQueryApplier`.
 *
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowExistsClause
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilter
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilterParser
 */
class RelatedRowQueryApplierTest extends TestCase {

	/**
	 * Build an applier whose schema lookup returns the given rows.
	 *
	 * @param array<int, Schema> $found       What findBySlug returns.
	 * @param bool               $tableExists Whether the related schema's table exists.
	 * @param PropertyReadGuard|null $guard   The property read guard, when the test needs to decide it.
	 *
	 * @return RelatedRowQueryApplier The applier.
	 */
	private function applierFinding(array $found, bool $tableExists=true, ?PropertyReadGuard $guard=null): RelatedRowQueryApplier {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findBySlug')->willReturn($found);

		$tableHandler = $this->createMock(MagicTableHandler::class);
		$tableHandler->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_2');
		$tableHandler->method('existsTableForRegisterSchema')->willReturn($tableExists);

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('buildRbacPredicateForAlias')->willReturn('rel0._owner = \'alice\'');

		return new RelatedRowQueryApplier(
			schemaMapper: $schemaMapper,
			tableHandler: $tableHandler,
			db: $this->createMock(IDBConnection::class),
			logger: new NullLogger(),
			access: new RelatedRowAccess(rbac: $rbac, logger: new NullLogger(), guard: $guard)
		);
	}//end applierFinding()

	/**
	 * An organisation scope that admits every organisation (admin bypass, system context).
	 *
	 * @return callable The scope resolver.
	 */
	private function allOrganisations(): callable {
		return static fn (): array => ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []];
	}//end allOrganisations()

	/**
	 * A schema.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(2);
		$schema->setProperties(['case' => ['type' => 'string'], 'value' => ['type' => 'number']]);

		return $schema;
	}//end schema()

	/**
	 * A register.
	 *
	 * @return Register The register.
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(1);

		return $register;
	}//end register()

	/**
	 * A query carrying one related block.
	 *
	 * @return array<string, mixed> The query.
	 */
	private function relatedQuery(): array {
		return [
			'_related' => [
				'caseProperty' => [
					'case' => ['value' => ['gte' => '100']],
				],
			],
		];
	}//end relatedQuery()

	/**
	 * 🔑 NO `_related` KEY MEANS NO WORK AND NO CHANGE.
	 *
	 * Every existing call site goes through here, so the quiet path has to stay
	 * quiet: nothing looked up, nothing added to the query.
	 *
	 * @return void
	 */
	public function testAQueryWithoutRelatedBlocksIsUntouched(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->never())->method('andWhere');

		$applied = $this->applierFinding([])->apply(
			qb: $qb,
			query: ['_limit' => 10],
			register: $this->register(),
			organisationScopeFor: $this->allOrganisations()
		);

		$this->assertSame(0, $applied);
	}//end testAQueryWithoutRelatedBlocksIsUntouched()

	/**
	 * 🔴 A SCHEMA NOBODY CAN NAME ENDS THE QUERY.
	 *
	 * Skipping the block would answer every case in the register, presented as
	 * the answer to a narrow question, with nothing in the response to say the
	 * filter was never applied.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaIsRefusedNotSkipped(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->never())->method('andWhere');

		$this->expectException(InvalidArgumentException::class);

		$this->applierFinding([])->apply(
			qb: $qb,
			query: $this->relatedQuery(),
			register: $this->register(),
			organisationScopeFor: $this->allOrganisations()
		);
	}//end testAnUnresolvableSchemaIsRefusedNotSkipped()

	/**
	 * Two schemas answering one slug is ambiguous, so it is refused.
	 *
	 * Picking the first would silently filter against whichever happened to be
	 * created first, and be right often enough to go unnoticed.
	 *
	 * @return void
	 */
	public function testAnAmbiguousSchemaNameIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->applierFinding([$this->schema(), $this->schema()])->apply(
			qb: $this->createMock(IQueryBuilder::class),
			query: $this->relatedQuery(),
			register: $this->register(),
			organisationScopeFor: $this->allOrganisations()
		);
	}//end testAnAmbiguousSchemaNameIsRefused()

	/**
	 * A resolvable block narrows the query and binds its parameters.
	 *
	 * The control for the two refusals above: without it, "0 clauses applied"
	 * could equally mean the applier never works.
	 *
	 * @return void
	 */
	public function testAResolvableBlockNarrowsTheQuery(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->once())->method('andWhere');
		$qb->expects($this->atLeastOnce())->method('setParameter');
		$qb->method('createFunction')->willReturnArgument(0);

		$applied = $this->applierFinding([$this->schema()])->apply(
			qb: $qb,
			query: $this->relatedQuery(),
			register: $this->register(),
			organisationScopeFor: $this->allOrganisations()
		);

		$this->assertSame(1, $applied);
	}//end testAResolvableBlockNarrowsTheQuery()

	/**
	 * 🔴 A REGISTER NAMED IN THE QUERY IS ENOUGH, BECAUSE THE FACET PATH PASSES
	 * NO REGISTER ID AT ALL.
	 *
	 * `MagicFacetHandler` calls `buildFilteredQuery()` without one. The first
	 * wiring read only the explicit argument, so a facet request carrying
	 * `_related` was refused even when the query itself named the register, and
	 * the alternative failure is worse than the refusal: a facet count that
	 * ignores a filter the list honours describes every case in the register
	 * beside a narrowed list. Nothing looks broken; the numbers answer a
	 * different question.
	 *
	 * @return void
	 */
	public function testTheRegisterMayComeFromTheQueryItself(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->expects($this->once())->method('andWhere');

		$query = $this->relatedQuery();
		$query['register'] = '1';

		$applied = $this->applierFinding([$this->schema()])->apply(
			qb: $qb,
			query: $query,
			register: $this->register(),
			organisationScopeFor: $this->allOrganisations()
		);

		$this->assertSame(1, $applied);
	}//end testTheRegisterMayComeFromTheQueryItself()

	/**
	 * 🔴 A REFERENCED SCHEMA WITHOUT A TABLE MATCHES NOTHING, IT DOES NOT FAIL.
	 *
	 * A schema that has never stored an object has no magic table. Rendering
	 * the subquery against it answered HTTP 500 (`relation does not exist`).
	 * No row can exist there, so the block narrows to the empty set.
	 *
	 * @return void
	 */
	public function testAReferencedSchemaWithoutATableMatchesNothing(): void {
		$conditions = [];
		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->expects($this->never())->method('setParameter');
		$qb->method('andWhere')->willReturnCallback(
			function ($condition) use (&$conditions, $qb) {
				$conditions[] = $condition;
				return $qb;
			}
		);

		$applied = $this->applierFinding(found: [$this->schema()], tableExists: false)->apply(
			qb: $qb,
			query: [
				'_referenced' => [
					'subsidieAanvraag' => [
						'subsidieaanvraag' => ['case' => '9eab0293-7ce5-4bcf-b7aa-0ab79a1eab4f'],
					],
				],
			],
			register: $this->register(),
			organisationScopeFor: $this->allOrganisations()
		);

		$this->assertSame(expected: 1, actual: $applied);
		$this->assertSame(expected: ['1 = 0'], actual: $conditions);
	}//end testAReferencedSchemaWithoutATableMatchesNothing()

	/**
	 * 🔴 A CONDITION ON A PROPERTY THE CALLER MAY NOT READ IS REFUSED.
	 *
	 * The related schema's row predicate says which rows may be consulted, not
	 * which of their fields: `gt`/`lt` on a withheld `salary` is a binary search
	 * for its value. The query ends, and nothing is added to it.
	 *
	 * @return void
	 */
	public function testAConditionOnAnUnreadablePropertyIsRefused(): void {
		$guard = $this->createMock(PropertyReadGuard::class);
		$guard->expects($this->atLeastOnce())->method('mayRead')->willReturn(false);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->never())->method('andWhere');

		$this->expectException(InvalidArgumentException::class);

		$this->applierFinding(found: [$this->schema()], guard: $guard)->apply(
			qb: $qb,
			query: $this->relatedQuery(),
			register: $this->register(),
			organisationScopeFor: $this->allOrganisations()
		);
	}//end testAConditionOnAnUnreadablePropertyIsRefused()

	/**
	 * The control: the same block with a readable property is applied.
	 *
	 * @return void
	 */
	public function testAConditionOnAReadablePropertyIsApplied(): void {
		$guard = $this->createMock(PropertyReadGuard::class);
		$guard->method('mayRead')->willReturn(true);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->expects($this->once())->method('andWhere');

		$applied = $this->applierFinding(found: [$this->schema()], guard: $guard)->apply(
			qb: $qb,
			query: $this->relatedQuery(),
			register: $this->register(),
			organisationScopeFor: $this->allOrganisations()
		);

		$this->assertSame(1, $applied);
	}//end testAConditionOnAReadablePropertyIsApplied()
	/**
	 * 🔴 THE RELATED ROWS ARE CONFINED TO THE CALLER'S ORGANISATION.
	 *
	 * The row predicate carries no `_organisation` term, so on a schema with no
	 * authorization block a foreign organisation's row satisfied a related-row
	 * condition. The subquery now carries the boundary the list applies.
	 *
	 * @return void
	 */
	public function testTheRelatedRowsAreConfinedToTheCallersOrganisation(): void {
		$sql        = [];
		$parameters = [];
		$qb         = $this->createMock(IQueryBuilder::class);
		$qb->method('createFunction')->willReturnCallback(static function (string $text) use (&$sql): string {
			$sql[] = $text;
			return $text;
		});
		$qb->method('setParameter')->willReturnCallback(function (string $name, mixed $value) use (&$parameters, $qb) {
			$parameters[$name] = $value;
			return $qb;
		});

		$this->applierFinding([$this->schema()])->apply(
			qb: $qb,
			query: $this->relatedQuery(),
			register: $this->register(),
			organisationScopeFor: static fn (): array => ['mode' => MagicOrganizationHandler::SCOPE_IN_OR_NULL, 'uuids' => ['org-a', 'org-b']]
		);

		$this->assertStringContainsString("(rel0._owner = 'alice') AND (rel0._organisation IN (:rel0_org0, :rel0_org1) OR rel0._organisation IS NULL)", $sql[0]);
		$this->assertSame('org-a', $parameters['rel0_org0']);
		$this->assertSame('org-b', $parameters['rel0_org1']);
	}//end testTheRelatedRowsAreConfinedToTheCallersOrganisation()

	/**
	 * No organisation, or a scope the renderer does not know, matches nothing; null-only matches the org-less rows.
	 *
	 * @return void
	 */
	public function testAScopeThatAdmitsNothingMatchesNothing(): void {
		$cases = [
			'none'    => [['mode' => MagicOrganizationHandler::SCOPE_NONE, 'uuids' => []], 'AND 1 = 0'],
			'unknown' => [['mode' => 'surprise', 'uuids' => ['org-a']], 'AND 1 = 0'],
			'empty'   => [['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => []], 'AND 1 = 0'],
			'null'    => [['mode' => MagicOrganizationHandler::SCOPE_NULL_ONLY, 'uuids' => []], 'AND rel0._organisation IS NULL'],
		];

		foreach ($cases as $label => [$scope, $expected]) {
			$sql = [];
			$qb  = $this->createMock(IQueryBuilder::class);
			$qb->method('createFunction')->willReturnCallback(static function (string $text) use (&$sql): string {
				$sql[] = $text;
				return $text;
			});

			$this->applierFinding([$this->schema()])->apply(
				qb: $qb,
				query: $this->relatedQuery(),
				register: $this->register(),
				organisationScopeFor: static fn (): array => $scope
			);

			$this->assertStringContainsString($expected, $sql[0], $label);
		}
	}//end testAScopeThatAdmitsNothingMatchesNothing()
	/**
	 * 🔴 A METADATA COLUMN OR AN UNKNOWN NAME IS NOT A PROPERTY OF THE RELATED SCHEMA.
	 *
	 * Without the lookup, `_owner`, `_authorization` and `_status` of readable
	 * rows could be compared with `gt`/`lt` (sharing metadata by binary search),
	 * and an unknown name reached the database as an unknown column.
	 *
	 * @return void
	 */
	public function testAFieldThatIsNotAPropertyOfTheRelatedSchemaIsRefused(): void {
		foreach (['_owner', '_authorization', '_status', 'noSuchProperty'] as $field) {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->expects($this->never())->method('andWhere');

			try {
				$this->applierFinding([$this->schema()])->apply(
					qb: $qb,
					query: ['_related' => ['caseProperty' => ['case' => [$field => ['gt' => 'a']]]]],
					register: $this->register(),
					organisationScopeFor: $this->allOrganisations()
				);
				$this->fail('Expected a refusal for ' . $field);
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString("'" . $field . "'", $e->getMessage());
			}
		}
	}//end testAFieldThatIsNotAPropertyOfTheRelatedSchemaIsRefused()

	/**
	 * The foreign key the join compares is a property of the related schema too, for `_related`.
	 *
	 * @return void
	 */
	public function testAForeignKeyThatIsNotAPropertyIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("'_organisation'");

		$this->applierFinding([$this->schema()])->apply(
			qb: $this->createMock(IQueryBuilder::class),
			query: ['_related' => ['caseProperty' => ['_organisation' => ['value' => 'x']]]],
			register: $this->register(),
			organisationScopeFor: $this->allOrganisations()
		);
	}//end testAForeignKeyThatIsNotAPropertyIsRefused()
}//end class
