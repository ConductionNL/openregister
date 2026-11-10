<?php

/**
 * Unit tests for `GdprEntityMapper::findOneByValueAndType`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests\Unit\Db
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A test subclass that lets us inject the rows `findEntities` would
 * have returned, sidestepping the noisy `IQueryBuilder` mock chain.
 * The query-construction path is exercised by integration tests.
 */
class TestableGdprEntityMapper extends GdprEntityMapper {

	/**
	 * Rows the override returns from `findEntities`.
	 *
	 * @var Entity[]
	 */
	public array $stubbedFindEntities = [];

	/**
	 * Override the protected parent method.
	 *
	 * @param IQueryBuilder $query Query builder (ignored — kept for signature parity).
	 *
	 * @return Entity[]
	 */
	protected function findEntities(IQueryBuilder $query): array {
		return $this->stubbedFindEntities;
	}//end findEntities()
}//end class

/**
 * Verifies the three observable outcomes of `findOneByValueAndType`:
 * exactly-one match returns it, zero matches return null, more-than-one
 * matches log a warning and return the first.
 */
class GdprEntityMapperTest extends TestCase {

	/**
	 * DB connection mock — exposes a stub QueryBuilder chain.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Logger mock used to assert the dedup-invariant warning.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * SUT under test (subclass that lets us stub `findEntities`).
	 *
	 * @var TestableGdprEntityMapper
	 */
	private TestableGdprEntityMapper $mapper;

	/**
	 * Wire a minimum IDBConnection stub (the SUT calls
	 * `$this->db->getQueryBuilder()` and chains a few selects on it).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(originalClassName: IDBConnection::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$composite = $this->createMock(originalClassName: ICompositeExpression::class);

		$expr = $this->createMock(originalClassName: IExpressionBuilder::class);
		$expr->method('andX')->willReturn(value: $composite);
		$expr->method('eq')->willReturn(value: 'eq');

		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('expr')->willReturn(value: $expr);
		$qb->method('createNamedParameter')->willReturn(value: ':p');

		$this->db->method('getQueryBuilder')->willReturn(value: $qb);

		$this->mapper = new TestableGdprEntityMapper(db: $this->db, logger: $this->logger);

	}//end setUp()

	/**
	 * Returns the matching row when exactly one row is found.
	 *
	 * @return void
	 */
	public function testReturnsExistingRow(): void {
		$existing = new GdprEntity();
		$existing->setId(7);
		$existing->setValue('Jan Jansen');
		$existing->setType('PERSON');

		$this->mapper->stubbedFindEntities = [$existing];

		$this->logger->expects($this->never())->method('warning');

		$result = $this->mapper->findOneByValueAndType(value: 'Jan Jansen', type: 'PERSON');

		$this->assertSame(expected: $existing, actual: $result);

	}//end testReturnsExistingRow()

	/**
	 * Returns null when no row matches.
	 *
	 * @return void
	 */
	public function testReturnsNullWhenNoMatch(): void {
		$this->mapper->stubbedFindEntities = [];

		$this->logger->expects($this->never())->method('warning');

		$result = $this->mapper->findOneByValueAndType(value: 'Jan Jansen', type: 'PERSON');

		$this->assertNull(actual: $result);

	}//end testReturnsNullWhenNoMatch()

	/**
	 * Two rows for the same (value, type) → dedup-invariant violation.
	 * Logs a structured warning and returns the first row. ADR-005:
	 * the value MUST NOT appear in the log payload (only the type +
	 * colliding ids).
	 *
	 * @return void
	 */
	public function testTwoRowsLogsAndReturnsFirst(): void {
		$first = new GdprEntity();
		$first->setId(7);
		$first->setValue('Jan Jansen');
		$first->setType('PERSON');

		$second = new GdprEntity();
		$second->setId(8);
		$second->setValue('Jan Jansen');
		$second->setType('PERSON');

		$this->mapper->stubbedFindEntities = [$first, $second];

		$this->logger->expects($this->once())
			->method('warning')
			->willReturnCallback(
				callback: function (string $message, array $context): void {
					$this->assertStringContainsString(needle: 'dedup invariant', haystack: $message);
					// PII rule: the value must NOT appear anywhere in the log.
					foreach ($context as $v) {
						if (is_string($v) === true) {
							$this->assertNotSame(expected: 'Jan Jansen', actual: $v);
						}
					}

					$this->assertSame(expected: 'PERSON', actual: $context['type']);
					$this->assertSame(expected: [7, 8], actual: $context['collidingIds']);
				}
			);

		$result = $this->mapper->findOneByValueAndType(value: 'Jan Jansen', type: 'PERSON');

		$this->assertSame(expected: $first, actual: $result);

	}//end testTwoRowsLogsAndReturnsFirst()

	/**
	 * No logger wired → still safe (warning is silently dropped).
	 *
	 * @return void
	 */
	public function testTwoRowsWithoutLoggerStillSafe(): void {
		$first = new GdprEntity();
		$first->setId(7);

		$second = new GdprEntity();
		$second->setId(8);

		// Re-instantiate without a logger.
		$mapper = new TestableGdprEntityMapper(db: $this->db, logger: null);
		$mapper->stubbedFindEntities = [$first, $second];

		$result = $mapper->findOneByValueAndType(value: 'X', type: 'PERSON');

		$this->assertSame(expected: $first, actual: $result);

	}//end testTwoRowsWithoutLoggerStillSafe()
}//end class
