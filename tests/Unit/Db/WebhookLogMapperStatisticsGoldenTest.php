<?php

/**
 * Characterisation of WebhookLogMapper::getStatistics(): the count expressions per database
 * platform, the optional webhook filter, and the totals it reads back.
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
 * Golden statistics query and totals of WebhookLogMapper.
 *
 * @covers \OCA\OpenRegister\Db\WebhookLogMapper
 */
final class WebhookLogMapperStatisticsGoldenTest extends TestCase {

	/**
	 * The query builder calls, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The platforms, the webhook asked for, and the calls each sends.
	 *
	 * @return array<string, array{0: class-string<AbstractPlatform>, 1: int, 2: list<string>}>
	 */
	public static function cases(): array {
		$mysql = [
			'select(COUNT(*) as total)',
			'addSelect(SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful)',
			'addSelect(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed)',
			'from(openregister_webhook_logs)',
		];
		$postgres = [
			'select(COUNT(*) as total)',
			'addSelect(SUM(CASE WHEN success = TRUE THEN 1 ELSE 0 END) as successful)',
			'addSelect(SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END) as failed)',
			'from(openregister_webhook_logs)',
		];
		return [
			'mysql, every webhook' => [MySQLPlatform::class, 0, [...$mysql, 'executeQuery()']],
			'mysql, one webhook' => [MySQLPlatform::class, 12, [...$mysql, 'param(12, 1)', 'where(eq(webhook, :p))', 'executeQuery()']],
			'postgres, every webhook' => [PostgreSQLPlatform::class, -1, [...$postgres, 'executeQuery()']],
			'postgres, one webhook' => [PostgreSQLPlatform::class, 3, [...$postgres, 'param(3, 1)', 'where(eq(webhook, :p))', 'executeQuery()']],
		];
	}//end cases()

	/**
	 * The query fits the platform and the webhook; the totals are read back as integers.
	 *
	 * @param class-string<AbstractPlatform> $platform  The database platform.
	 * @param int                            $webhookId The webhook, or 0 or less for all.
	 * @param list<string>                   $expected  The query builder calls.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testStatisticsQuery(string $platform, int $webhookId, array $expected): void {
		$mapper = new WebhookLogMapper($this->connection(platform: new $platform(), row: ['total' => '7', 'successful' => '5', 'failed' => 2]));

		$this->assertSame(['total' => 7, 'successful' => 5, 'failed' => 2], $mapper->getStatistics($webhookId));
		$this->assertSame($expected, $this->calls);
	}//end testStatisticsQuery()

	/**
	 * No row, or missing sums, read as zero.
	 *
	 * @return void
	 */
	public function testMissingTotalsAreZero(): void {
		$this->assertSame(
			['total' => 0, 'successful' => 0, 'failed' => 0],
			(new WebhookLogMapper($this->connection(platform: new MySQLPlatform(), row: false)))->getStatistics(0)
		);
		$this->assertSame(
			['total' => 4, 'successful' => 0, 'failed' => 0],
			(new WebhookLogMapper($this->connection(platform: new MySQLPlatform(), row: ['total' => 4, 'successful' => null])))->getStatistics(0)
		);
	}//end testMissingTotalsAreZero()

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

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $column, mixed $value): string => 'eq(' . $column . ', ' . $value . ')');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getConnection')->willReturn($inner);
		$qb->method('expr')->willReturn($expr);
		$qb->method('getTableName')->willReturnCallback(static fn (string $table): string => $table);
		$qb->method('createFunction')->willReturnCallback(
			function (string $call): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn($call);
				return $function;
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type): string {
				$this->calls[] = 'param(' . $value . ', ' . $type . ')';
				return ':p';
			}
		);
		foreach (['select', 'addSelect', 'from', 'where'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (mixed $arg) use ($qb, $method): IQueryBuilder {
					$this->calls[] = $method . '(' . (string) $arg . ')';
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
