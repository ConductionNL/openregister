<?php

/**
 * Which SQL dialect the related-row clause is rendered in, and what it refuses.
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
use OCA\OpenRegister\Service\Query\RelatedRowExistsClause;
use OCA\OpenRegister\Service\Query\RelatedRowFilter;
use OCA\OpenRegister\Service\Query\RelatedRowInnerRow;
use OCA\OpenRegister\Service\Query\RelatedRowSqlTarget;
use OCA\OpenRegister\Service\Query\RelatedRowSubquery;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * `engineFor()` falls back to MariaDB syntax when the platform cannot be read;
 * an unknown storage or engine is refused rather than rendered.
 *
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowExistsClause
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilter
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowInnerRow
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowSqlTarget
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowSubquery
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowField
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowBoundCondition
 */
class RelatedRowExistsClauseEngineTest extends TestCase {

	/**
	 * Render one equality condition for the given engine and storage.
	 *
	 * @param string $engine  The engine.
	 * @param string $storage The storage shape.
	 *
	 * @return array{sql: string, parameters: array<string, mixed>} The clause.
	 */
	private function render(string $engine, string $storage): array {
		return (new RelatedRowExistsClause())->render(
			filter: new RelatedRowFilter('caseProperty', 'case', [['field' => 'value', 'operator' => 'eq', 'value' => 'x']]),
			subquery: new RelatedRowSubquery(
				target: new RelatedRowSqlTarget(engine: $engine, table: 'oc_openregister_table_1_2', outerAlias: 'o', storage: $storage),
				inner: new RelatedRowInnerRow(alias: 'r0', accessPredicate: 'r0._owner = :me', parameterPrefix: 'rel0')
			)
		);
	}//end render()

	/**
	 * A platform that cannot be read is logged and answered with MariaDB syntax.
	 *
	 * @return void
	 */
	public function testAnUnreadablePlatformFallsBackToMariaDb(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willThrowException(new RuntimeException('no connection'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'[RelatedRowExistsClause] Could not read the database platform; defaulting to MariaDB syntax',
			self::callback(static fn (array $context): bool => $context['exception'] === 'no connection')
		);

		self::assertSame(RelatedRowExistsClause::ENGINE_MARIADB, (new RelatedRowExistsClause())->engineFor(db: $db, logger: $logger));
	}//end testAnUnreadablePlatformFallsBackToMariaDb()

	/**
	 * An unknown storage shape is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownStorageIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('No related-row SQL for storage \'csv\'.');
		$this->render(engine: RelatedRowExistsClause::ENGINE_MARIADB, storage: 'csv');
	}//end testAnUnknownStorageIsRefused()

	/**
	 * An unknown engine is refused for JSON storage, where the dialect matters.
	 *
	 * @return void
	 */
	public function testAnUnknownEngineIsRefusedForJsonStorage(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('No related-row SQL for engine \'oracle\'.');
		$this->render(engine: 'oracle', storage: RelatedRowExistsClause::STORAGE_JSON);
	}//end testAnUnknownEngineIsRefusedForJsonStorage()

	/**
	 * Column storage quotes the property as a column of the related alias and
	 * binds the value under the inner row's prefix.
	 *
	 * @return void
	 */
	public function testColumnStorageComparesTheQuotedColumn(): void {
		$clause = $this->render(engine: RelatedRowExistsClause::ENGINE_MARIADB, storage: RelatedRowExistsClause::STORAGE_COLUMNS);

		self::assertStringStartsWith('EXISTS (SELECT 1 FROM oc_openregister_table_1_2 r0 WHERE ', $clause['sql']);
		self::assertStringContainsString('(r0._owner = :me)', $clause['sql']);
		self::assertStringContainsString(' = :rel0_c0', $clause['sql']);
		self::assertSame('x', $clause['parameters']['rel0_c0']);
	}//end testColumnStorageComparesTheQuotedColumn()
}//end class
