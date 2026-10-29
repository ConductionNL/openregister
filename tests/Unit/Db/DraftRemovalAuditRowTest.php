<?php

/**
 * The audit-trail row of a removed draft, as the real table stores it.
 *
 * A mocked mapper accepts any row. This test writes through the real
 * AuditTrailMapper into the table the app's own migrations create, and reads
 * the stored row back: it is there, it says who, when, where and which
 * object, and no column holds a value the draft held (decision 226).
 *
 * It needs a Nextcloud server's classes for the migrated table, like the
 * other AuditTrail tests in this directory, so it runs in CI and not in a
 * bare clone.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-removing-a-draft-must-leave-one-audit-trail-entry-without-its-content
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\DraftRemovalRecord;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Object\DraftRemovalRecord
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\AuditHashService
 * @uses \OCA\OpenRegister\Db\AuditFlowAttribution
 * @uses \OCA\OpenRegister\Service\Audit\PurposeAttribution
 * @uses \OCA\OpenRegister\Service\Audit\TokenAttribution
 * @uses \OCA\OpenRegister\Db\StoredEntity
 */
class DraftRemovalAuditRowTest extends TestCase {

	private MigratedSqliteDatabase $database;

	private DraftRemovalRecord $record;

	protected function setUp(): void {
		$this->database = new MigratedSqliteDatabase($this, ['openregister_audit_trails']);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent-anna');
		$user->method('getDisplayName')->willReturn('Anna de Vries');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->record = new DraftRemovalRecord(
			auditTrail: new AuditTrailMapper(
				db: $this->database->idbConnection(),
				container: $this->createMock(ContainerInterface::class),
				userSession: $session,
				request: $this->createMock(IRequest::class),
				logger: new NullLogger()
			),
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * A draft holding typed values, in register 7 and schema 99.
	 *
	 * @return array{0: ObjectEntity, 1: Register, 2: Schema}
	 */
	private function draft(): array {
		$draft = new ObjectEntity();
		$draft->setId(41);
		$draft->setUuid('3f0d6c1e-0000-4000-8000-000000000001');
		$draft->setRegister('7');
		$draft->setSchema('99');
		$draft->setOwner('agent-anna');
		$draft->setStatus('draft');
		$draft->setObject(['subject' => 'Complaint about the neighbour at number 14', 'email' => 'resident@example.org']);

		$register = new Register();
		$register->setId(7);
		$register->setSlug('service');
		$schema = new Schema();
		$schema->setId(99);
		$schema->setSlug('ticket');

		return [$draft, $register, $schema];
	}//end draft()

	/**
	 * Every stored audit row, every column.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(): array {
		return $this->database->connection()->fetchAllAssociative('SELECT * FROM openregister_audit_trails ORDER BY id');
	}//end rows()

	/**
	 * A user's removal: one row, with the facts, and nothing the draft held in any column.
	 */
	public function testTheStoredRowSaysWhoWhenWhereAndNothingTheDraftHeld(): void {
		[$draft, $register, $schema] = $this->draft();

		$this->assertTrue($this->record->write(draft: $draft, register: $register, schema: $schema));

		$rows = $this->rows();
		$this->assertCount(1, $rows);
		$row = $rows[0];
		$this->assertSame('draft.removed', $row['action']);
		$this->assertSame('agent-anna', $row['user']);
		$this->assertSame('Anna de Vries', $row['user_name']);
		$this->assertSame('3f0d6c1e-0000-4000-8000-000000000001', $row['object_uuid']);
		$this->assertSame(7, (int)$row['register']);
		$this->assertSame(99, (int)$row['schema']);
		$this->assertNotEmpty($row['created']);
		$this->assertSame(
			['status' => 'draft', 'permanent' => true, 'job' => null, 'register' => 'service', 'schema' => 'ticket', 'owner' => 'agent-anna'],
			json_decode((string)$row['changed'], true)
		);

		// No column of the row holds a value or a property name of the draft.
		$stored = (string)json_encode($row);
		foreach (['Complaint about the neighbour', 'resident@example.org', 'subject', 'email'] as $typed) {
			$this->assertStringNotContainsString($typed, $stored);
		}
	}//end testTheStoredRowSaysWhoWhenWhereAndNothingTheDraftHeld()

	/**
	 * A job's removal: the row names the job, not the session.
	 */
	public function testTheStoredRowNamesTheJob(): void {
		[$draft, $register, $schema] = $this->draft();

		DraftRemovalRecord::asJob(job: 'DraftExpiryJob', operation: fn (): bool => $this->record->write(draft: $draft, register: $register, schema: $schema));

		$rows = $this->rows();
		$this->assertCount(1, $rows);
		$this->assertSame('job:DraftExpiryJob', $rows[0]['user']);
		$this->assertSame('DraftExpiryJob', $rows[0]['user_name']);
		$this->assertSame('DraftExpiryJob', json_decode((string)$rows[0]['changed'], true)['job']);
	}//end testTheStoredRowNamesTheJob()
}//end class
