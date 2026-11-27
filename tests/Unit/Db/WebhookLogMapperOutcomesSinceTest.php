<?php

/**
 * The webhook delivery outcomes since an instant, aggregated in one query.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\WebhookLogMapper::outcomesSince
 */
final class WebhookLogMapperOutcomesSinceTest extends TestCase {

	/**
	 * The query builder calls, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The query sums both outcomes from the instant on; the sums read back as integers.
	 *
	 * @return void
	 */
	public function testSumsOutcomesFromTheInstant(): void {
		$since  = new DateTime('2026-11-15 11:45:00');
		$mapper = new WebhookLogMapper($this->connection(platform: new MySQLPlatform(), row: ['successful' => '3', 'failed' => '2']));

		$this->assertSame(['successful' => 3, 'failed' => 2], $mapper->outcomesSince($since));
		$this->assertSame(
			[
				'select(SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful)',
				'addSelect(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed)',
				'from(openregister_webhook_logs)',
				'param(2026-11-15 11:45:00, '.IQueryBuilder::PARAM_DATETIME_MUTABLE.')',
				'where(gte(created, :p))',
				'executeQuery()',
			],
			$this->calls
		);
	}//end testSumsOutcomesFromTheInstant()

	/**
	 * PostgreSQL compares the boolean with TRUE / FALSE.
	 *
	 * @return void
	 */
	public function testPostgresUsesBooleanLiterals(): void {
		(new WebhookLogMapper($this->connection(platform: new PostgreSQLPlatform(), row: false)))->outcomesSince(new DateTime());

		$this->assertSame('select(SUM(CASE WHEN success = TRUE THEN 1 ELSE 0 END) as successful)', $this->calls[0]);
		$this->assertSame('addSelect(SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END) as failed)', $this->calls[1]);
	}//end testPostgresUsesBooleanLiterals()

	/**
	 * No row, or null sums, read as zero.
	 *
	 * @return void
	 */
	public function testMissingSumsAreZero(): void {
		$this->assertSame(
			['successful' => 0, 'failed' => 0],
			(new WebhookLogMapper($this->connection(platform: new MySQLPlatform(), row: false)))->outcomesSince(new DateTime())
		);
		$this->assertSame(
			['successful' => 0, 'failed' => 0],
			(new WebhookLogMapper($this->connection(platform: new MySQLPlatform(), row: ['successful' => null, 'failed' => null])))->outcomesSince(new DateTime())
		);
	}//end testMissingSumsAreZero()

	/**
	 * A connection whose query builder records its calls and answers one row.
	 *
	 * @param AbstractPlatform $platform The platform.
	 * @param array|false      $row      The row the query answers.
	 *
	 * @return IDBConnection
	 */
	private function connection(AbstractPlatform $platform, array|false $row): IDBConnection {
		$inner = $this->createMock(IDBConnection::class);
		$inner->method('getDatabasePlatform')->willReturn($platform);

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row);
		$result->expects($this->once())->method('closeCursor');

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('gte')->willReturnCallback(static fn (string $column, mixed $value): string => 'gte('.$column.', '.$value.')');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getConnection')->willReturn($inner);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createFunction')->willReturnCallback(
			function (string $call): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn($call);
				return $function;
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type): string {
				$this->calls[] = 'param('.$value->format('Y-m-d H:i:s').', '.$type.')';
				return ':p';
			}
		);
		foreach (['select', 'addSelect', 'from', 'where'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (mixed $arg) use ($qb, $method): IQueryBuilder {
					$this->calls[] = $method.'('.(string) $arg.')';
					return $qb;
				}
			);
		}

		$qb->method('executeQuery')->willReturnCallback(
			function () use ($result): IResult {
				$this->calls[] = 'executeQuery()';
				return $result;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end connection()
}//end class
