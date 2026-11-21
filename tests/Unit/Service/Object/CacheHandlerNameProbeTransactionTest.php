<?php
/**
 * The magic-table name probe inside an open transaction.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CacheHandlerRuntime;
use OCA\OpenRegister\Service\Object\CacheHandlerStores;
use OCA\OpenRegister\Service\Object\CacheNameScopeSources;
use OCA\OpenRegister\Service\Object\NameTableQuery;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * A missing name column must not abort the caller's transaction.
 *
 * On PostgreSQL a failed statement aborts the open transaction, so the probe
 * of `naam` on a table that has only `name` broke every later query of a
 * configuration import. The probe now runs in a savepoint of its own.
 *
 * @spec openspec/specs/schema-driven-read-coercion/spec.md
 */
class CacheHandlerNameProbeTransactionTest extends TestCase {

	/**
	 * What the connection double was asked, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Inside a transaction a failed probe is rolled back to its savepoint and the next column answers.
	 *
	 * @return void
	 */
	public function testAFailedProbeInsideATransactionRollsBackOnlyItsSavepoint(): void {
		$names = $this->probe(inTransaction: true);

		$this->assertSame('Named', $names['a']['name']);
		$this->assertSame(
			['begin', 'prepare _name', 'commit', 'begin', 'prepare naam', 'rollBack', 'begin', 'prepare name', 'commit'],
			$this->calls
		);
	}//end testAFailedProbeInsideATransactionRollsBackOnlyItsSavepoint()

	/**
	 * Outside a transaction the probe opens none.
	 *
	 * @return void
	 */
	public function testOutsideATransactionNoSavepointIsOpened(): void {
		$names = $this->probe(inTransaction: false);

		$this->assertSame('Named', $names['a']['name']);
		$this->assertSame(['prepare _name', 'prepare naam', 'prepare name'], $this->calls);
	}//end testOutsideATransactionNoSavepointIsOpened()

	/**
	 * Run the probe on a table whose only name column is `name`.
	 *
	 * @param bool $inTransaction Whether the connection reports an open transaction.
	 *
	 * @return array<string, array<string, mixed>> The names found.
	 */
	private function probe(bool $inTransaction): array {
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn($inTransaction);
		foreach (['beginTransaction' => 'begin', 'commit' => 'commit', 'rollBack' => 'rollBack'] as $method => $label) {
			$db->method($method)->willReturnCallback(function () use ($label): bool {
				$this->calls[] = $label;
				return true;
			});
		}

		$db->method('prepare')->willReturnCallback(function (string $sql): IPreparedStatement {
			preg_match('/_organisation, (\w+) as/', $sql, $match);
			$this->calls[] = 'prepare ' . $match[1];
			if ($match[1] === 'naam') {
				throw new RuntimeException('column "naam" does not exist');
			}

			$rows = [];
			if ($match[1] === 'name') {
				$rows = [['_uuid' => 'a', 'name_value' => 'Named', '_organisation' => null]];
			}

			$rows[] = false;
			$statement = $this->createMock(IPreparedStatement::class);
			$statement->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
			return $statement;
		});

		$handler = new CacheHandler(
			stores: new CacheHandlerStores(organisationMapper: $this->createMock(OrganisationMapperInterface::class), db: $db),
			runtime: new CacheHandlerRuntime(logger: $this->createMock(LoggerInterface::class)),
			nameScope: new CacheNameScopeSources()
		);
		$method = (new ReflectionClass($handler))->getMethod('queryTableForNames');
		$method->setAccessible(true);

		return $method->invoke($handler, new NameTableQuery(tableName: 'tbl', uuids: ['a'], source: '1:5'));
	}//end probe()
}//end class
