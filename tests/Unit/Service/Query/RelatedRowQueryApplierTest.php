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
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `RelatedRowQueryApplier`.
 *
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 */
class RelatedRowQueryApplierTest extends TestCase {

	/**
	 * Build an applier whose schema lookup returns the given rows.
	 *
	 * @param array<int, Schema> $found What findBySlug returns.
	 *
	 * @return RelatedRowQueryApplier The applier.
	 */
	private function applierFinding(array $found): RelatedRowQueryApplier {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findBySlug')->willReturn($found);

		$tableHandler = $this->createMock(MagicTableHandler::class);
		$tableHandler->method('getTableNameForRegisterSchema')->willReturn('oc_openregister_table_1_2');

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('buildRbacPredicateForAlias')->willReturn('rel0._owner = \'alice\'');

		return new RelatedRowQueryApplier(
			schemaMapper: $schemaMapper,
			tableHandler: $tableHandler,
			rbacHandler: $rbac,
			db: $this->createMock(IDBConnection::class),
			logger: new NullLogger()
		);
	}//end applierFinding()

	/**
	 * A schema.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(2);

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
			register: $this->register()
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
			register: $this->register()
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
			register: $this->register()
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
			register: $this->register()
		);

		$this->assertSame(1, $applied);
	}//end testAResolvableBlockNarrowsTheQuery()
}//end class
