<?php

/**
 * A magic table gets one composite index over its schema's identity columns.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/modelling-composite-identity/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIdentityIndex;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableIdentityIndex
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun
 * @uses   \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator
 */
class MagicMapperIdentityIndexTest extends TestCase {

	/**
	 * Statements the connection ran.
	 *
	 * @var array<int, string>
	 */
	private array $statements = [];

	/**
	 * The connection behind the indexer.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * The logger behind the indexer.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * An indexer whose statements throw when they contain one of the given fragments.
	 *
	 * @param AbstractPlatform   $platform The platform.
	 * @param array<int, string> $failing  Statement fragments that make executeStatement throw.
	 *
	 * @return MagicTableIndexer
	 */
	private function indexer(AbstractPlatform $platform, array $failing = []): MagicTableIndexer {
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getDatabasePlatform')->willReturn($platform);
		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($failing): int {
				$this->statements[] = $sql;
				foreach ($failing as $fragment) {
					if (str_contains($sql, $fragment) === true) {
						throw new RuntimeException('cannot create ' . $fragment);
					}
				}

				return 0;
			}
		);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(0);
		$prepared = $this->createMock(IPreparedStatement::class);
		$prepared->method('execute')->willReturn($result);
		$this->db->method('prepare')->willReturn($prepared);

		$this->logger = $this->createMock(LoggerInterface::class);
		return new MagicTableIndexer($this->db, $this->logger, new MagicPgTrgmProbe($this->db, $this->logger));
	}//end indexer()

	/**
	 * A schema with a municipality code and a case number, optionally their identity.
	 *
	 * @param bool                 $identity   Whether `zaaksleutel` is the identity.
	 * @param array<int, string>   $properties The identity properties.
	 *
	 * @return Schema
	 */
	private function schema(bool $identity, array $properties = ['gemeentecode', 'zaaknummer']): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(
			[
				'gemeentecode' => ['type' => 'string'],
				'zaaknummer' => ['type' => 'string'],
				'titel' => ['type' => 'string'],
			]
		);
		$schema->method('getConfiguration')->willReturn(
			[
				'uniqueConstraints' => [
					['name' => 'zaaksleutel', 'properties' => $properties, 'action' => 'refuse', 'identity' => $identity],
				],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * The statements that create an identity index.
	 *
	 * @return array<int, string>
	 */
	private function identityStatements(): array {
		return array_values(array_filter($this->statements, static fn (string $sql): bool => str_contains($sql, '_identity_') === true));
	}//end identityStatements()

	/**
	 * On PostgreSQL the identity columns get one index, in declared order.
	 *
	 * @return void
	 */
	public function testPostgresIndexesTheIdentityColumnsInDeclaredOrder(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');

		$this->indexer(new PostgreSQLPlatform())->create($run, $this->schema(identity: true, properties: ['zaaknummer', 'gemeentecode']));

		$name = 't_identity_' . substr(md5('zaaknummer,gemeentecode'), 0, 8) . '_idx';
		$this->assertSame(["CREATE INDEX IF NOT EXISTS {$name} ON oc_t (\"zaaknummer\", \"gemeentecode\")"], $this->identityStatements());
		$this->assertSame($name, $run->identityIndex);
	}//end testPostgresIndexesTheIdentityColumnsInDeclaredOrder()

	/**
	 * On MariaDB the same index is made with backtick quoting.
	 *
	 * @return void
	 */
	public function testMariaDbIndexesTheIdentityColumns(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');

		$this->indexer(new MySQLPlatform())->create($run, $this->schema(identity: true));

		$name = 't_identity_' . substr(md5('gemeentecode,zaaknummer'), 0, 8) . '_idx';
		$this->assertSame(["CREATE INDEX IF NOT EXISTS {$name} ON oc_t (`gemeentecode`, `zaaknummer`)"], $this->identityStatements());
	}//end testMariaDbIndexesTheIdentityColumns()

	/**
	 * A uniqueness constraint that is not the identity gets no identity index.
	 *
	 * @return void
	 */
	public function testNoIdentityNoIndex(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');

		$this->indexer(new PostgreSQLPlatform())->create($run, $this->schema(identity: false));

		$this->assertSame([], $this->identityStatements());
		$this->assertNull($run->identityIndex);
	}//end testNoIdentityNoIndex()

	/**
	 * An identity over a property the schema has no column for gets no index.
	 *
	 * @return void
	 */
	public function testAnIdentityOverAMissingColumnGetsNoIndex(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');

		$this->indexer(new PostgreSQLPlatform())->create($run, $this->schema(identity: true, properties: ['gemeentecode', 'onbekend']));

		$this->assertSame([], $this->identityStatements());
	}//end testAnIdentityOverAMissingColumnGetsNoIndex()

	/**
	 * A failing identity index is logged and not reported.
	 *
	 * @return void
	 */
	public function testAFailingIdentityIndexIsLoggedAndNotReported(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');
		$indexer = $this->indexer(new MySQLPlatform(), ['_identity_']);
		$this->logger->expects($this->once())->method('warning')->with('[MagicMapper] Could not create the identity index');

		$indexer->create($run, $this->schema(identity: true));

		$this->assertNull($run->identityIndex);
	}//end testAFailingIdentityIndexIsLoggedAndNotReported()

	/**
	 * A sync drops the identity index of an identity over other columns, or of one no longer declared, and keeps the current one.
	 *
	 * @return void
	 */
	public function testASyncDropsAStaleIdentityIndexAndKeepsTheCurrentOne(): void {
		$current = 't_identity_' . substr(md5('gemeentecode,zaaknummer'), 0, 8) . '_idx';
		$stale = 't_identity_' . substr(md5('zaaknummer'), 0, 8) . '_idx';
		$existing = [$current, $stale, 'other_identity_0123abcd_idx', 't_uuid_idx'];

		$dropped = $this->indexer(new PostgreSQLPlatform())->dropUnasked(new MagicTableIndexRun('t', 'oc_t'), $this->schema(identity: true), $existing);

		$this->assertSame([$stale], $dropped);

		$this->statements = [];
		$dropped = $this->indexer(new PostgreSQLPlatform())->dropUnasked(new MagicTableIndexRun('t', 'oc_t'), $this->schema(identity: false), $existing);

		$this->assertSame([$current, $stale], $dropped);
		$this->assertSame(["DROP INDEX IF EXISTS {$current}", "DROP INDEX IF EXISTS {$stale}"], $this->statements);
	}//end testASyncDropsAStaleIdentityIndexAndKeepsTheCurrentOne()

	/**
	 * The name carries a hash of the columns, so other columns get another name.
	 *
	 * @return void
	 */
	public function testTheNameFollowsTheColumns(): void {
		$index = new MagicTableIdentityIndex($this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class));

		$this->assertNotSame($index->name('t', ['a', 'b']), $index->name('t', ['b', 'a']));
		$this->assertMatchesRegularExpression('/^t_identity_[0-9a-f]{8}_idx$/', $index->name('t', ['a', 'b']));
	}//end testTheNameFollowsTheColumns()
}//end class
