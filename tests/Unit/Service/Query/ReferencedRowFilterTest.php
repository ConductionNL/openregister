<?php

/**
 * `_referenced`: filter objects by a field of the row their own property
 * points at (a beschikking whose subsidieaanvraag belongs to one case).
 *
 * The SQL is asserted as text: this suite reaches no database. What matters is
 * the join direction, that the referenced schema's access predicate is always
 * inside the subquery, and that a bad block is refused rather than dropped.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Query;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Query\RelatedRowExistsClause;
use OCA\OpenRegister\Service\Query\RelatedRowFilter;
use OCA\OpenRegister\Service\Query\RelatedRowFilterParser;
use OCA\OpenRegister\Service\Query\RelatedRowAccess;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ReferencedRowFilterTest extends TestCase {

	private function forwardFilter(): RelatedRowFilter {
		$conditions = [['field' => 'case', 'operator' => 'eq', 'value' => 'case-1']];

		return new RelatedRowFilter('subsidieAanvraag', 'subsidieaanvraag', $conditions, RelatedRowFilter::DIRECTION_FORWARD);
	}

	private function forwardQuery(): array {
		return ['_referenced' => ['subsidieAanvraag' => ['subsidieaanvraag' => ['case' => 'case-1']]]];
	}

	public function testTheForwardKeyParsesWithTheSameGrammarAndRunsForward(): void {
		$filters = (new RelatedRowFilterParser(key: RelatedRowFilterParser::FORWARD_KEY))->parse($this->forwardQuery());

		$this->assertCount(1, $filters);
		$this->assertSame('subsidieAanvraag', $filters[0]->schema);
		$this->assertSame('subsidieaanvraag', $filters[0]->foreignKey);
		$this->assertSame(RelatedRowFilter::DIRECTION_FORWARD, $filters[0]->direction);
		$this->assertSame([['field' => 'case', 'operator' => 'eq', 'value' => 'case-1']], $filters[0]->conditions);

		$inverse = (new RelatedRowFilterParser())->parse(['_related' => ['caseProperty' => ['case' => ['value' => 'x']]]]);
		$this->assertSame(RelatedRowFilter::DIRECTION_INVERSE, $inverse[0]->direction);
	}

	public function testABadForwardBlockIsRefusedNamingTheForwardKey(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('_referenced[subsidieAanvraag]');

		(new RelatedRowFilterParser(key: RelatedRowFilterParser::FORWARD_KEY))->parse(['_referenced' => ['subsidieAanvraag' => []]]);
	}

	public function testAForwardClauseJoinsTheReferencedUuidToTheObjectsProperty(): void {
		$clause = (new RelatedRowExistsClause())->render(
			filter: $this->forwardFilter(),
			engine: RelatedRowExistsClause::ENGINE_POSTGRES,
			table: 'oc_openregister_table_1_7',
			outerAlias: 't',
			innerAlias: 'rel0',
			accessPredicate: 'rel0._owner = :me',
			parameterPrefix: 'rel0',
			storage: RelatedRowExistsClause::STORAGE_COLUMNS
		);

		$this->assertStringContainsString('rel0._uuid = t."subsidieaanvraag"', $clause['sql']);
		$this->assertStringContainsString('rel0."case" = :rel0_c0', $clause['sql']);
		$this->assertStringContainsString('(rel0._owner = :me)', $clause['sql']);
		$this->assertStringContainsString('rel0._deleted IS NULL', $clause['sql']);
		$this->assertSame('case-1', $clause['parameters']['rel0_c0']);
	}

	public function testAForwardClauseOnJsonStorageReadsTheObjectsPropertyFromItsJson(): void {
		$clause = (new RelatedRowExistsClause())->render(
			filter: $this->forwardFilter(),
			engine: RelatedRowExistsClause::ENGINE_MARIADB,
			table: 'oc_openregister_objects',
			outerAlias: 'o',
			innerAlias: 'r0',
			accessPredicate: 'r0.owner = :me',
			parameterPrefix: 'rel0'
		);

		$this->assertStringContainsString("r0.uuid = JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.subsidieaanvraag'))", $clause['sql']);
	}

	public function testACamelCasePropertyReadsItsSnakeCaseColumn(): void {
		$clause = (new RelatedRowExistsClause())->render(
			filter: new RelatedRowFilter('caseProperty', 'case', [['field' => 'propertyDefinition', 'operator' => 'eq', 'value' => 'pd-7']]),
			engine: RelatedRowExistsClause::ENGINE_POSTGRES,
			table: 'oc_openregister_table_1_2',
			outerAlias: 't',
			innerAlias: 'rel0',
			accessPredicate: 'rel0._owner = :me',
			parameterPrefix: 'rel0',
			storage: RelatedRowExistsClause::STORAGE_COLUMNS
		);

		$this->assertStringContainsString('rel0."property_definition" = :rel0_c0', $clause['sql']);
	}

	public function testTheApplierAddsForwardBlocksWithTheReferencedSchemasOwnAccessPredicate(): void {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(['case' => ['type' => 'string']]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->expects($this->once())->method('findBySlug')->with('subsidieAanvraag', 2)->willReturn([$schema]);

		$tableHandler = $this->createMock(MagicTableHandler::class);
		$tableHandler->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_7');
		$tableHandler->method('existsTableForRegisterSchema')->willReturn(true);

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->expects($this->once())->method('buildRbacPredicateForAlias')
			->with($schema, 'rel0', 'read')
			->willReturn('rel0._owner = \'alice\'');

		$sql = [];
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createFunction')->willReturnCallback(static function (string $text) use (&$sql): string {
			$sql[] = $text;
			return $text;
		});
		$qb->expects($this->once())->method('andWhere');

		$register = new Register();
		$register->setId(1);

		$applied = (new RelatedRowQueryApplier(
			schemaMapper: $schemaMapper,
			tableHandler: $tableHandler,
			db: $this->createMock(IDBConnection::class),
			logger: new NullLogger(),
			access: new RelatedRowAccess(rbac: $rbac, logger: new NullLogger())
		))->apply(qb: $qb, query: $this->forwardQuery(), register: $register, organisationScopeFor: static fn (): array => ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []]);

		$this->assertSame(1, $applied);
		$this->assertStringContainsString('rel0._uuid = t."subsidieaanvraag"', $sql[0]);
		$this->assertStringContainsString("rel0._owner = 'alice'", $sql[0]);
	}

	public function testAColumnNameFollowsTheRuleThatNamesTheColumns(): void {
		$sql = fn (string $property): string => (new RelatedRowExistsClause())->render(
			filter: new RelatedRowFilter('caseProperty', 'case', [['field' => $property, 'operator' => 'eq', 'value' => 'x']]),
			engine: RelatedRowExistsClause::ENGINE_POSTGRES,
			table: 'oc_openregister_table_1_2',
			outerAlias: 't',
			innerAlias: 'rel0',
			accessPredicate: 'TRUE',
			parameterPrefix: 'rel0',
			storage: RelatedRowExistsClause::STORAGE_COLUMNS
		)['sql'];

		$this->assertStringContainsString('rel0."due_date" = :rel0_c0', $sql('due-date'));
		$this->assertStringContainsString('rel0."first_name" = :rel0_c0', $sql('first__name'));
		$this->assertStringContainsString('rel0."is_active" = :rel0_c0', $sql('isActive_'));

		// Capped at 64 characters, as MagicSearchHandler::sanitizeColumnName caps it.
		$this->assertStringContainsString('rel0."' . str_repeat('a', 64) . '" = :rel0_c0', $sql(str_repeat('a', 70)));
	}
}
