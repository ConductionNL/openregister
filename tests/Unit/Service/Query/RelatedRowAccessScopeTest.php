<?php

/**
 * The organisation boundary and the reveal-only refusal of the related-row access path.
 *
 * A related-row subquery consults rows of another schema, so the organisation
 * term it adds decides which of those rows can count: every scope mode must
 * render the boundary it names, and a mode that names nothing must admit
 * nothing. A property shown only on request must never be matchable.
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
use OCA\OpenRegister\Db\MagicMapper\AliasedRows;
use OCA\OpenRegister\Db\MagicMapper\OrganisationScopeResolverInterface;
use OCA\OpenRegister\Db\MagicMapper\RelatedRowRbacInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ExplicitRevealQueryException;
use OCA\OpenRegister\Service\Query\RelatedRowAccess;
use OCA\OpenRegister\Service\Query\RelatedRowFilter;
use OCA\OpenRegister\Service\Query\RelatedRowSource;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `RelatedRowAccess::predicate()` per organisation scope mode, and
 * `assertReadable()` on reveal-only and unknown properties.
 *
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses \OCA\OpenRegister\Db\MagicMapper\AliasedRows
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilter
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowSource
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 */
class RelatedRowAccessScopeTest extends TestCase {

	/**
	 * The row predicate the RBAC mock returns.
	 */
	private const ROW_PREDICATE = "r0._owner = 'alice'";

	/**
	 * Parameters the query builder was given, name to value.
	 *
	 * @var array<string, mixed>
	 */
	private array $bound = [];

	/**
	 * What the RBAC mock was asked: [schema, alias, action, table].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $rbacAsked = [];

	/**
	 * The access path under test, with a recording RBAC mock.
	 *
	 * @return RelatedRowAccess
	 */
	private function access(): RelatedRowAccess {
		$rbac = $this->createMock(RelatedRowRbacInterface::class);
		$rbac->method('buildRbacPredicateForAlias')->willReturnCallback(
			function (Schema $schema, AliasedRows $rows): string {
				$this->rbacAsked[] = [$schema, $rows->alias, $rows->action, $rows->tableName];
				return self::ROW_PREDICATE;
			}
		);

		return new RelatedRowAccess(rbac: $rbac, logger: new NullLogger());
	}//end access()

	/**
	 * A query builder that records setParameter.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('setParameter')->willReturnCallback(
			function ($name, $value) use ($qb) {
				$this->bound[$name] = $value;
				return $qb;
			}
		);
		return $qb;
	}//end queryBuilder()

	/**
	 * The predicate for one scope, on alias r0 of the case-property table.
	 *
	 * @param array<string, mixed> $scope The organisation scope.
	 * @param Schema|null          $schema The related schema.
	 *
	 * @return string
	 */
	private function predicateFor(array $scope, ?Schema $schema = null): string {
		$source = new RelatedRowSource(
			schema: ($schema ?? new Schema()),
			tableName: 'openregister_table_1_2',
			alias: 'r0',
			scope: $scope
		);
		return $this->access()->predicate(qb: $this->queryBuilder(), source: $source);
	}//end predicateFor()

	/**
	 * A schema with the given properties.
	 *
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return Schema
	 */
	private static function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		return $schema;
	}//end schema()

	/**
	 * Every organisation: only the row predicate, asked for reads on the related alias.
	 *
	 * @return void
	 */
	public function testAllOrganisationsAddsNoTerm(): void {
		$schema = new Schema();

		self::assertSame(self::ROW_PREDICATE, $this->predicateFor(['mode' => OrganisationScopeResolverInterface::SCOPE_ALL, 'uuids' => []], $schema));
		self::assertSame([[$schema, 'r0', 'read', 'openregister_table_1_2']], $this->rbacAsked);
		self::assertSame([], $this->bound);
	}//end testAllOrganisationsAddsNoTerm()

	/**
	 * Rows without an organisation only.
	 *
	 * @return void
	 */
	public function testNullOnlyAdmitsRowsWithoutAnOrganisation(): void {
		self::assertSame(
			'(' . self::ROW_PREDICATE . ') AND r0._organisation IS NULL',
			$this->predicateFor(['mode' => OrganisationScopeResolverInterface::SCOPE_NULL_ONLY, 'uuids' => ['org-1']])
		);
		self::assertSame([], $this->bound);
	}//end testNullOnlyAdmitsRowsWithoutAnOrganisation()

	/**
	 * The named organisations, bound as parameters per alias and position.
	 *
	 * @return void
	 */
	public function testInBindsEveryNamedOrganisation(): void {
		self::assertSame(
			'(' . self::ROW_PREDICATE . ') AND r0._organisation IN (:r0_org0, :r0_org1)',
			$this->predicateFor(['mode' => OrganisationScopeResolverInterface::SCOPE_IN, 'uuids' => ['org-a', 'org-b']])
		);
		self::assertSame(['r0_org0' => 'org-a', 'r0_org1' => 'org-b'], $this->bound);
	}//end testInBindsEveryNamedOrganisation()

	/**
	 * In-or-null also admits rows without an organisation.
	 *
	 * @return void
	 */
	public function testInOrNullAlsoAdmitsRowsWithoutAnOrganisation(): void {
		self::assertSame(
			'(' . self::ROW_PREDICATE . ') AND (r0._organisation IN (:r0_org0) OR r0._organisation IS NULL)',
			$this->predicateFor(['mode' => OrganisationScopeResolverInterface::SCOPE_IN_OR_NULL, 'uuids' => ['org-a']])
		);
		self::assertSame(['r0_org0' => 'org-a'], $this->bound);
	}//end testInOrNullAlsoAdmitsRowsWithoutAnOrganisation()

	/**
	 * Empty and non-string organisation ids are dropped, and the remaining ones
	 * are numbered from zero.
	 *
	 * @return void
	 */
	public function testBlankAndNonStringIdsAreDropped(): void {
		self::assertSame(
			'(' . self::ROW_PREDICATE . ') AND r0._organisation IN (:r0_org0, :r0_org1)',
			$this->predicateFor(['mode' => OrganisationScopeResolverInterface::SCOPE_IN, 'uuids' => ['', 'org-a', 42, null, 'org-b']])
		);
		self::assertSame(['r0_org0' => 'org-a', 'r0_org1' => 'org-b'], $this->bound);
	}//end testBlankAndNonStringIdsAreDropped()

	/**
	 * No organisation, an unknown or missing mode, or "in" naming none admits nothing.
	 *
	 * @return void
	 */
	public function testAScopeNamingNothingAdmitsNothing(): void {
		$nothing = '(' . self::ROW_PREDICATE . ') AND 1 = 0';
		foreach ([
			'none' => ['mode' => OrganisationScopeResolverInterface::SCOPE_NONE, 'uuids' => ['org-a']],
			'unknown mode' => ['mode' => 'everything-please', 'uuids' => ['org-a']],
			'no mode' => ['uuids' => ['org-a']],
			'in, no ids' => ['mode' => OrganisationScopeResolverInterface::SCOPE_IN, 'uuids' => []],
			'in, only blanks' => ['mode' => OrganisationScopeResolverInterface::SCOPE_IN, 'uuids' => ['', 7]],
			'in-or-null, no ids key' => ['mode' => OrganisationScopeResolverInterface::SCOPE_IN_OR_NULL],
		] as $case => $scope) {
			$this->bound = [];
			self::assertSame($nothing, $this->predicateFor($scope), $case);
			self::assertSame([], $this->bound, $case);
		}
	}//end testAScopeNamingNothingAdmitsNothing()

	/**
	 * A condition on a reveal-only property is refused with its own exception,
	 * before the property read rule is consulted.
	 *
	 * @return void
	 */
	public function testAConditionOnARevealOnlyPropertyIsRefused(): void {
		$schema = self::schema([
			'case' => ['type' => 'string'],
			'bsn' => ['type' => 'string', 'authorization' => ['reveal' => 'explicit']],
		]);
		$filter = new RelatedRowFilter(
			schema: 'person',
			foreignKey: 'case',
			conditions: [['field' => 'bsn', 'operator' => 'eq', 'value' => '123']]
		);

		$this->expectException(ExplicitRevealQueryException::class);
		$this->expectExceptionMessage('\'bsn\' is shown only on request, so a related-row filter cannot match on it.');
		$this->access()->assertReadable(filter: $filter, schema: $schema);
	}//end testAConditionOnARevealOnlyPropertyIsRefused()

	/**
	 * An inverse filter also reads its foreign key, so a reveal-only foreign key is refused too.
	 *
	 * @return void
	 */
	public function testARevealOnlyForeignKeyIsRefusedOnAnInverseFilter(): void {
		$schema = self::schema([
			'kind' => ['type' => 'string'],
			'owner' => ['type' => 'string', 'authorization' => ['reveal' => 'explicit']],
		]);
		$filter = new RelatedRowFilter(
			schema: 'person',
			foreignKey: 'owner',
			conditions: []
		);

		$this->expectException(ExplicitRevealQueryException::class);
		$this->expectExceptionMessage('\'owner\' is shown only on request');
		$this->access()->assertReadable(filter: $filter, schema: $schema);
	}//end testARevealOnlyForeignKeyIsRefusedOnAnInverseFilter()

	/**
	 * On a schema with property-level rules and no container to check them, a
	 * plain property is refused as unreadable rather than matched unchecked.
	 *
	 * @return void
	 */
	public function testAnUncheckablePropertyIsRefused(): void {
		$schema = self::schema([
			'kind' => ['type' => 'string'],
			'owner' => ['type' => 'string', 'authorization' => ['reveal' => 'explicit']],
		]);
		$filter = new RelatedRowFilter(
			schema: 'person',
			foreignKey: 'case',
			conditions: [['field' => 'kind', 'operator' => 'eq', 'value' => 'a']],
			direction: RelatedRowFilter::DIRECTION_FORWARD
		);

		self::assertFalse($this->access()->mayReadProperty(schema: $schema, property: 'kind'));
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('\'kind\' is not a property of schema \'person\' that a related-row filter can match on.');
		$this->access()->assertReadable(filter: $filter, schema: $schema);
	}//end testAnUncheckablePropertyIsRefused()

	/**
	 * A forward filter does not read its foreign key on the related schema; plain
	 * readable properties pass.
	 *
	 * @return void
	 */
	public function testAForwardFilterOnReadablePropertiesPasses(): void {
		$schema = self::schema(['case' => ['type' => 'string'], 'status' => ['type' => 'string']]);
		$filter = new RelatedRowFilter(
			schema: 'aanvraag',
			foreignKey: 'notOnThisSchema',
			conditions: [['field' => 'status', 'operator' => 'eq', 'value' => 'open'], ['field' => 'case', 'operator' => 'eq', 'value' => 'c-1']],
			direction: RelatedRowFilter::DIRECTION_FORWARD
		);

		$this->access()->assertReadable(filter: $filter, schema: $schema);
		self::assertTrue($this->access()->mayReadProperty(schema: $schema, property: 'status'));
	}//end testAForwardFilterOnReadablePropertiesPasses()

	/**
	 * An inverse filter whose foreign key is not a property of the related schema is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownForeignKeyIsRefused(): void {
		$schema = self::schema(['status' => ['type' => 'string']]);
		$filter = new RelatedRowFilter(
			schema: 'aanvraag',
			foreignKey: 'case',
			conditions: [['field' => 'status', 'operator' => 'eq', 'value' => 'open']]
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('\'case\' is not a property of schema \'aanvraag\' that a related-row filter can match on.');
		$this->access()->assertReadable(filter: $filter, schema: $schema);
	}//end testAnUnknownForeignKeyIsRefused()
}//end class
