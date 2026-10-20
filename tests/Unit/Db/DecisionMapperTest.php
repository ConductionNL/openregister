<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\DecisionMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Decisions are stored with a uuid and a moment, and found by uuid or subject.
 *
 * @covers \OCA\OpenRegister\Db\DecisionMapper
 * @covers \OCA\OpenRegister\Db\Decision
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
 */
class DecisionMapperTest extends TestCase {

	/**
	 * The columns written by the last insert.
	 *
	 * @var array<string, mixed>
	 */
	private array $written = [];

	/**
	 * The conditions of the last select.
	 *
	 * @var array<int, string>
	 */
	private array $conditions = [];

	/**
	 * A mapper over a query builder that records what it is asked.
	 *
	 * @return DecisionMapper The mapper.
	 */
	private function mapper(): DecisionMapper {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, mixed $value): string {
				$this->conditions[] = $column.'='.(string)$value;
				return $column;
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'insert'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn (mixed $value): mixed => $value);
		$qb->method('setValue')->willReturnCallback(
			function (string $column, mixed $value) use ($qb): IQueryBuilder {
				$this->written[$column] = $value;
				return $qb;
			}
		);
		$qb->method('executeStatement')->willReturn(1);
		$qb->method('getLastInsertId')->willReturn(5);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new class($db) extends DecisionMapper {
			/**
			 * The rows a select answers.
			 *
			 * @var array<int, Decision>
			 */
			public array $rows = [];

			/**
			 * Answer the first row instead of reading a database.
			 *
			 * @param IQueryBuilder $query The query.
			 *
			 * @return Decision The row.
			 */
			protected function findEntity(IQueryBuilder $query): Decision {
				return $this->rows[0];
			}

			/**
			 * Answer every row instead of reading a database.
			 *
			 * @param IQueryBuilder $query The query.
			 *
			 * @return array<int, Decision> The rows.
			 */
			protected function findEntities(IQueryBuilder $query): array {
				return $this->rows;
			}
		};
	}//end mapper()

	/**
	 * An inserted decision gets a uuid and the moment it was taken.
	 *
	 * @return void
	 */
	public function testAnInsertedDecisionGetsAUuidAndAMoment(): void {
		$decision = new Decision();
		$decision->setSubjectType('entity-relation');
		$decision->setSubjectId('7');
		$decision->setVerdict('redact');
		$decision->setDecidedBy('j.devries');

		$stored = $this->mapper()->insert($decision);

		self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$stored->getUuid());
		self::assertNotNull($stored->getDecidedAt());
		self::assertSame(5, $stored->getId());
		self::assertSame('j.devries', $this->written['decided_by'] ?? null);
		self::assertSame('entity-relation', $this->written['subject_type'] ?? null);
		self::assertSame($stored->getUuid(), $stored->jsonSerialize()['uuid']);
	}//end testAnInsertedDecisionGetsAUuidAndAMoment()

	/**
	 * Only decisions are stored.
	 *
	 * @return void
	 */
	public function testOnlyDecisionsAreStored(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->mapper()->insert(new EntityRelation());
	}//end testOnlyDecisionsAreStored()

	/**
	 * Finding filters on the uuid, or on the subject's type and id.
	 *
	 * @return void
	 */
	public function testItFindsByUuidAndBySubject(): void {
		$row = new Decision();
		$mapper = $this->mapper();
		$mapper->rows = [$row];

		self::assertSame($row, $mapper->findByUuid(uuid: 'd-1'));
		self::assertSame([$row], $mapper->findForSubject(subjectType: 'entity-relation', subjectId: '7'));
		self::assertSame(['uuid=d-1', 'subject_type=entity-relation', 'subject_id=7'], $this->conditions);
	}//end testItFindsByUuidAndBySubject()
}//end class
