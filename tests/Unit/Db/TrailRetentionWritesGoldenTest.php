<?php

/**
 * The retention writes of the audit-trail and search-trail mappers: the query
 * each builds, what it returns, and the error it logs before rethrowing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @coversNothing
 */
final class TrailRetentionWritesGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * Error messages the mapper logged, with their context keys.
	 *
	 * @var array<int, array{string, array<int, string>, mixed}>
	 */
	private array $errors = [];

	/**
	 * Both mappers, with their table and the noun their messages use.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function mappers(): array {
		return [
			'audit trail' => ['audit', 'openregister_audit_trails', 'AuditTrailMapper', 'audit trail'],
			'search trail' => ['search', 'openregister_search_trails', 'SearchTrailMapper', 'search trail'],
		];
	}//end mappers()

	/**
	 * A mapper over a recording connection.
	 *
	 * @param string      $kind     'audit' or 'search'.
	 * @param object|null $platform The database platform the connection reports.
	 * @param bool        $failing  Whether executeStatement() throws.
	 *
	 * @return AuditTrailMapper|SearchTrailMapper
	 */
	private function mapper(string $kind, ?object $platform = null, bool $failing = false): AuditTrailMapper|SearchTrailMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($failing) {
				$qb = $this->recordingBuilder();
				if ($failing === true) {
					$qb->method('executeStatement')->willThrowException(new RuntimeException('disk full'));
				}

				return $qb;
			}
		);
		$db->method('getDatabasePlatform')->willReturn($platform ?? new MySQLPlatform());

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, array_keys($context), ($context['app'] ?? null)];
			}
		);

		if ($kind === 'audit') {
			return new AuditTrailMapper(
				$db,
				new AuditTrailMapperServices(
					$this->createMock(ContainerInterface::class),
					$logger,
					$this->createMock(IUserSession::class),
					$this->createMock(IRequest::class)
				)
			);
		}

		return new SearchTrailMapper($db, $this->createMock(IRequest::class), $this->createMock(IUserSession::class), $logger);
	}//end mapper()

	/**
	 * Clearing every row deletes the whole table and reports that rows went.
	 *
	 * @param string $kind  The mapper.
	 * @param string $table Its table.
	 *
	 * @return void
	 */
	#[DataProvider('mappers')]
	public function testClearAllLogsDeletesTheWholeTable(string $kind, string $table): void {
		$this->assertTrue($this->mapper(kind: $kind)->clearAllLogs());
		$this->assertSame(['#1 delete(' . $table . ', NULL)', '#1 executeStatement()'], $this->queryLog);
	}//end testClearAllLogsDeletesTheWholeTable()

	/**
	 * The expiry stamp is created plus the retention in seconds, spelled for MySQL.
	 *
	 * @param string $kind  The mapper.
	 * @param string $table Its table.
	 *
	 * @return void
	 */
	#[DataProvider('mappers')]
	public function testSetExpiryDateOnMysql(string $kind, string $table): void {
		$this->assertSame(1, $this->mapper(kind: $kind)->setExpiryDate(90061500));
		$this->assertSame(
			[
				'#1 update(' . $table . ', NULL)',
				'#1 set(expires, DATE_ADD(created, INTERVAL 90061 SECOND))',
				'#1 expr.isNull(expires)',
				'#1 where(isNull(expires))',
				'#1 executeStatement()',
			],
			$this->queryLog
		);
	}//end testSetExpiryDateOnMysql()

	/**
	 * On PostgreSQL the interval is spelled the PostgreSQL way.
	 *
	 * @param string $kind The mapper.
	 *
	 * @return void
	 */
	#[DataProvider('mappers')]
	public function testSetExpiryDateOnPostgres(string $kind): void {
		$this->mapper(kind: $kind, platform: new PostgreSQLPlatform())->setExpiryDate(3600000);
		$this->assertContains("#1 set(expires, created + INTERVAL '3600 seconds')", $this->queryLog);
	}//end testSetExpiryDateOnPostgres()

	/**
	 * A failed write is logged as an error naming the mapper, then thrown on.
	 *
	 * @param string $kind   The mapper.
	 * @param string $table  Its table.
	 * @param string $prefix The class name in the message.
	 * @param string $noun   The trail as the message names it.
	 *
	 * @return void
	 */
	#[DataProvider('mappers')]
	public function testAFailedWriteIsLoggedAndRethrown(string $kind, string $table, string $prefix, string $noun): void {
		$calls = [
			'clearLogs' => 'clear expired ' . $noun . ' logs',
			'clearAllLogs' => 'clear all ' . $noun . ' logs',
			'setExpiryDate' => 'set expiry dates for ' . $noun . 's',
		];
		foreach ($calls as $method => $failure) {
			$this->errors = [];
			$mapper = $this->mapper(kind: $kind, failing: true);
			try {
				if ($method === 'setExpiryDate') {
					$mapper->setExpiryDate(1000);
				} else {
					$mapper->$method();
				}

				$this->fail($method . ' did not rethrow');
			} catch (RuntimeException $e) {
				$this->assertSame('disk full', $e->getMessage());
			}

			$this->assertSame(
				[['[' . $prefix . '] Failed to ' . $failure . ': disk full', ['file', 'line', 'app', 'exception'], 'openregister']],
				$this->errors,
				$method
			);
		}//end foreach
	}//end testAFailedWriteIsLoggedAndRethrown()
}//end class
