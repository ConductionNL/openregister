<?php

/**
 * Characterisation of what AuditTrailMapper takes from the services it is built with.
 *
 * Pins, through one builder, that the session user and the request's address land on
 * the written row, that a malformed bulk entry is refused before anything is written,
 * and (mutation check) that a different session user is visibly a different row. A
 * change to how the mapper is wired cannot then change who an audit row is
 * attributed to, or let a refused write through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @coversNothing
 */
final class AuditTrailMapperWiringTest extends TestCase {

	/**
	 * The migrated audit table.
	 *
	 * @var MigratedSqliteDatabase
	 */
	private MigratedSqliteDatabase $database;

	protected function setUp(): void {
		$this->database = new MigratedSqliteDatabase($this, ['openregister_audit_trails']);
	}//end setUp()

	/**
	 * A session user is the row's user; without one the row records no user.
	 *
	 * @return void
	 */
	public function testTheRowIsAttributedToTheSessionUser(): void {
		$this->assertSame(['alice', 'Alice A.'], $this->writtenBy(uid: 'alice'));
		$this->assertSame(['bob', 'Bob B.'], $this->writtenBy(uid: 'bob'));
		$this->assertNotSame($this->writtenBy(uid: 'alice'), $this->writtenBy(uid: 'bob'));
	}//end testTheRowIsAttributedToTheSessionUser()

	/**
	 * A bulk entry that is neither a row nor an entry array is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testAMalformedBulkEntryIsRefusedBeforeAnyWrite(): void {
		$mapper = $this->mapper(uid: 'alice');
		try {
			$mapper->insertAuditTrails(['not an entry']);
			$this->fail('a malformed entry was accepted');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('insertAuditTrails() expects AuditTrail entities or old/new/action entry arrays.', $e->getMessage());
		}

		$unnamed = new AuditTrail();
		try {
			$mapper->insertAuditTrails([$unnamed]);
			$this->fail('a row without a uuid was accepted');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('insertAuditTrails() requires every pre-built row to carry a uuid.', $e->getMessage());
		}

		$this->assertSame([], $this->database->connection()->fetchAllAssociative('SELECT id FROM openregister_audit_trails'));
	}//end testAMalformedBulkEntryIsRefusedBeforeAnyWrite()

	/**
	 * Write one entry as a user and read back who it is attributed to.
	 *
	 * @param string $uid The session user.
	 *
	 * @return array{0: mixed, 1: mixed}
	 */
	private function writtenBy(string $uid): array {
		$entry = $this->mapper(uid: $uid)->createAuditTrailEntry($this->object(), 'wiring.check');
		$rows = $this->database->connection()->fetchAllAssociative(
			'SELECT user, user_name FROM openregister_audit_trails WHERE id = ?',
			[$entry->getId()]
		);

		return [$rows[0]['user'], $rows[0]['user_name']];
	}//end writtenBy()

	/**
	 * The audited object.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(7);
		$object->setUuid('u-7');
		$object->setRegister('1');
		$object->setSchema('2');
		return $object;
	}//end object()

	/**
	 * The one place this test builds the mapper.
	 *
	 * @param string $uid The session user.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapper(string $uid): AuditTrailMapper {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(ucfirst($uid) . ' ' . strtoupper($uid[0]) . '.');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('10.1.2.3');

		return new AuditTrailMapper(
			$this->database->idbConnection(),
			$session,
			$request,
			new AuditTrailMapperServices($this->createMock(ContainerInterface::class), new NullLogger())
		);
	}//end mapper()
}//end class
