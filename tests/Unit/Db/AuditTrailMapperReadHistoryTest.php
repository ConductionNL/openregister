<?php

/**
 * The read history query, run as SQL against the migrated audit trail table.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Distinct objects, newest read first, one reader only, reads only.
 *
 * @coversDefaultClass \OCA\OpenRegister\Db\AuditTrailMapper
 */
class AuditTrailMapperReadHistoryTest extends TestCase {

	private const CASE_A = '11111111-1111-4111-8111-111111111111';

	private const CASE_B = '22222222-2222-4222-8222-222222222222';

	private const CASE_C = '33333333-3333-4333-8333-333333333333';

	private MigratedSqliteDatabase $database;

	private AuditTrailMapper $mapper;

	private int $id = 0;

	/**
	 * Build the table and the mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->database = new MigratedSqliteDatabase($this, ['openregister_audit_trails']);
		$this->mapper = new AuditTrailMapper(
			db: $this->database->idbConnection(),
			container: $this->createMock(ContainerInterface::class),
			userSession: $this->createMock(IUserSession::class),
			request: $this->createMock(IRequest::class),
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * One audit row.
	 *
	 * @param string $user    The actor.
	 * @param string $uuid    The object.
	 * @param string $action  The action.
	 * @param string $created The moment, UTC.
	 *
	 * @return void
	 */
	private function row(string $user, string $uuid, string $action, string $created): void {
		$this->id++;
		$this->database->insert(
			'openregister_audit_trails',
			[
				'id' => $this->id,
				'uuid' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $this->id),
				'object' => 7,
				'object_uuid' => $uuid,
				'action' => $action,
				'user' => $user,
				'created' => $created,
				'changed' => '{}',
			]
		);
	}//end row()

	/**
	 * Repeated reads collapse to one object carrying its LATEST read.
	 *
	 * @return void
	 */
	public function testRepeatedReadsCollapseToTheLatest(): void {
		$this->row(user: 'alice', uuid: self::CASE_A, action: 'read', created: '2026-10-09 08:00:00');
		$this->row(user: 'alice', uuid: self::CASE_B, action: 'read', created: '2026-10-09 09:00:00');
		$this->row(user: 'alice', uuid: self::CASE_A, action: 'read', created: '2026-10-09 10:00:00');
		$this->row(user: 'alice', uuid: self::CASE_A, action: 'read', created: '2026-10-09 10:00:05');

		$this->assertSame(
			[
				self::CASE_A => '2026-10-09T10:00:05+00:00',
				self::CASE_B => '2026-10-09T09:00:00+00:00',
			],
			$this->mapper->findLatestReadsByUser(userId: 'alice')
		);
	}//end testRepeatedReadsCollapseToTheLatest()

	/**
	 * Another reader's reads, writes and tombstoned rows never count.
	 *
	 * @return void
	 */
	public function testOnlyThisReadersReadsCount(): void {
		$this->row(user: 'alice', uuid: self::CASE_A, action: 'read', created: '2026-10-09 08:00:00');
		$this->row(user: 'bob', uuid: self::CASE_B, action: 'read', created: '2026-10-09 09:00:00');
		$this->row(user: 'alice', uuid: self::CASE_C, action: 'update', created: '2026-10-09 09:30:00');
		// A retention purge blanks `user`, so a tombstone belongs to nobody.
		$this->row(user: '', uuid: self::CASE_C, action: 'read', created: '2026-10-09 09:45:00');

		$this->assertSame(
			[self::CASE_A => '2026-10-09T08:00:00+00:00'],
			$this->mapper->findLatestReadsByUser(userId: 'alice')
		);
	}//end testOnlyThisReadersReadsCount()

	/**
	 * The cap keeps the newest objects.
	 *
	 * @return void
	 */
	public function testTheLimitKeepsTheNewest(): void {
		$this->row(user: 'alice', uuid: self::CASE_A, action: 'read', created: '2026-10-09 08:00:00');
		$this->row(user: 'alice', uuid: self::CASE_B, action: 'read', created: '2026-10-09 09:00:00');
		$this->row(user: 'alice', uuid: self::CASE_C, action: 'read', created: '2026-10-09 10:00:00');

		$this->assertSame(
			[self::CASE_C, self::CASE_B],
			array_keys($this->mapper->findLatestReadsByUser(userId: 'alice', limit: 2))
		);
	}//end testTheLimitKeepsTheNewest()
}//end class
