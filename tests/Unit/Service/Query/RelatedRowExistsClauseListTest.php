<?php

/**
 * RelatedRowExistsClause — a forward filter through a list of references.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Query
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Query;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Query\RelatedRowExistsClause;
use OCA\OpenRegister\Service\Query\RelatedRowFilter;
use OCA\OpenRegister\Service\Query\RelatedRowInnerRow;
use OCA\OpenRegister\Service\Query\RelatedRowSqlTarget;
use OCA\OpenRegister\Service\Query\RelatedRowSubquery;
use PHPUnit\Framework\TestCase;

/**
 * A list property joins by JSON containment on each engine and storage; one
 * reference keeps the equality join.
 *
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowExistsClause
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilter
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowInnerRow
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowSqlTarget
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowSubquery
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowField
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowBoundCondition
 */
class RelatedRowExistsClauseListTest extends TestCase {

	private function render(string $engine, string $storage, bool $throughList = true): string {
		$filter = new RelatedRowFilter(
			schema: '12',
			foreignKey: 'betrokkenen',
			conditions: [['field' => 'woonplaats', 'operator' => 'eq', 'value' => 'Zuiddrecht']],
			direction: RelatedRowFilter::DIRECTION_FORWARD,
			throughList: $throughList
		);

		return (new RelatedRowExistsClause())->render(
			filter: $filter,
			subquery: new RelatedRowSubquery(
				target: new RelatedRowSqlTarget(engine: $engine, table: 'oc_person', outerAlias: 't', storage: $storage),
				inner: new RelatedRowInnerRow(alias: 'rel0', accessPredicate: 'rel0.owner = :me', parameterPrefix: 'rel0')
			)
		)['sql'];
	}

	public function testAMagicTableListJoinsByJsonbContainmentOnPostgres(): void {
		$sql = $this->render(engine: RelatedRowExistsClause::ENGINE_POSTGRES, storage: RelatedRowExistsClause::STORAGE_COLUMNS);

		$this->assertStringContainsString('WHERE CAST(t."betrokkenen" AS jsonb) @> jsonb_build_array(rel0._uuid) AND ', $sql);
		$this->assertStringNotContainsString('rel0._uuid = ', $sql);
	}

	public function testAMagicTableListJoinsByJsonContainsOnMariaDb(): void {
		$sql = $this->render(engine: RelatedRowExistsClause::ENGINE_MARIADB, storage: RelatedRowExistsClause::STORAGE_COLUMNS);

		$this->assertStringContainsString('WHERE JSON_CONTAINS(t."betrokkenen", JSON_QUOTE(rel0._uuid)) AND ', $sql);
	}

	public function testAnObjectsTableListReadsTheListFromTheDocumentAsJson(): void {
		$postgres = $this->render(engine: RelatedRowExistsClause::ENGINE_POSTGRES, storage: RelatedRowExistsClause::STORAGE_JSON);
		$mariaDb = $this->render(engine: RelatedRowExistsClause::ENGINE_MARIADB, storage: RelatedRowExistsClause::STORAGE_JSON);

		$this->assertStringContainsString(" AND CAST(t.object -> 'betrokkenen' AS jsonb) @> jsonb_build_array(rel0.uuid) AND ", $postgres);
		$this->assertStringContainsString(" AND JSON_CONTAINS(JSON_EXTRACT(t.object, '$.betrokkenen'), JSON_QUOTE(rel0.uuid)) AND ", $mariaDb);
	}

	public function testTheBlockConditionsAndTheAccessPredicateStayInsideTheSubquery(): void {
		$sql = $this->render(engine: RelatedRowExistsClause::ENGINE_POSTGRES, storage: RelatedRowExistsClause::STORAGE_COLUMNS);

		$this->assertStringStartsWith('EXISTS (SELECT 1 FROM oc_person rel0 WHERE ', $sql);
		$this->assertStringContainsString('(rel0.owner = :me)', $sql);
		$this->assertStringContainsString('rel0."woonplaats" = :rel0_c0', $sql);
		$this->assertStringEndsWith(')', $sql);
	}

	public function testOneReferenceKeepsTheEqualityJoin(): void {
		$sql = $this->render(engine: RelatedRowExistsClause::ENGINE_POSTGRES, storage: RelatedRowExistsClause::STORAGE_COLUMNS, throughList: false);

		$this->assertStringContainsString('WHERE rel0._uuid = t."betrokkenen" AND ', $sql);
		$this->assertStringNotContainsString('jsonb', $sql);
	}

	public function testAnUnknownEngineIsRefusedForAList(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->render(engine: 'sqlite', storage: RelatedRowExistsClause::STORAGE_COLUMNS);
	}
}//end class
