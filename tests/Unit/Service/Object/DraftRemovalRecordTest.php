<?php

/**
 * A removed draft leaves one audit-trail entry, and the entry holds nothing the draft held.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-removing-a-draft-must-leave-one-audit-trail-entry-without-its-content
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\DraftRemovalRecord;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Decision 226: who, when, where, which object, that it was a draft. Never its content.
 *
 * The table-level half (the row as it is stored) is DraftRemovalAuditRowTest.
 * The archival gate's control (an active object is still refused, so the
 * delete handler is never reached and nothing is recorded) is
 * ObjectServiceDraftDeleteTest.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftRemovalRecord
 * @covers \OCA\OpenRegister\Service\Object\DeleteObject
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\AuditTrail
 */
class DraftRemovalRecordTest extends TestCase {

	/**
	 * Values a draft holds that must never reach the audit trail.
	 *
	 * @var array<string, string>
	 */
	private const TYPED = [
		'subject' => 'Complaint about the neighbour at number 14',
		'description' => 'Half a sentence the agent never sent',
		'email' => 'resident@example.org',
	];

	/**
	 * Every call to the audit trail's entry point.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $entries = [];

	/**
	 * Errors logged.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $errors = [];

	/**
	 * An audit trail that records what it is asked to write.
	 *
	 * @param bool $fails Whether writing throws.
	 */
	private function auditTrail(bool $fails = false): AuditTrailMapper {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = [], ?string $actorId = null, ?string $actorName = null) use ($fails): AuditTrail {
				if ($fails === true) {
					throw new RuntimeException('audit table locked');
				}

				$this->entries[] = ['object' => $object, 'action' => $action, 'context' => $context, 'actorId' => $actorId, 'actorName' => $actorName];

				return new AuditTrail();
			}
		);
		// The change-style writers would serialise the object, content and all. A removed draft must never go through them.
		$mapper->expects($this->never())->method('createAuditTrail');
		$mapper->expects($this->never())->method('buildAuditTrail');
		$mapper->expects($this->never())->method('insertAuditTrails');

		return $mapper;
	}//end auditTrail()

	/**
	 * A logger that keeps its errors.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		return $logger;
	}//end logger()

	/**
	 * A stored object holding typed values.
	 */
	private function stored(string $status): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setId(41);
		$entity->setUuid('3f0d6c1e-0000-4000-8000-000000000001');
		$entity->setRegister('7');
		$entity->setSchema('99');
		$entity->setOwner('agent-anna');
		$entity->setStatus($status);
		$entity->setObject(self::TYPED);

		return $entity;
	}//end stored()

	/**
	 * The register and the schema the object lives in.
	 *
	 * @return array{0: Register, 1: Schema}
	 */
	private function scope(): array {
		$register = new Register();
		$register->setId(7);
		$register->setSlug('service');
		$schema = new Schema();
		$schema->setId(99);
		$schema->setSlug('ticket');
		$schema->setConfiguration(['x-openregister-archival' => ['retention' => ['default' => 'P2Y']]]);

		return [$register, $schema];
	}//end scope()

	/**
	 * The real delete handler over mocked storage.
	 */
	private function handler(AuditTrailMapper $auditTrail): DeleteObject {
		return new DeleteObject(
			$this->createMock(MagicMapper::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(IUserSession::class),
			$auditTrail,
			$this->createMock(SettingsService::class),
			$this->logger(),
			$this->createMock(ReferentialIntegrityService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(OrganisationMapper::class)
		);
	}//end handler()

	/**
	 * Assert that nothing the draft held is anywhere in what was written.
	 */
	private function assertHoldsNoTypedValue(mixed $written): void {
		$json = (string)json_encode($written);
		foreach (self::TYPED as $name => $value) {
			$this->assertStringNotContainsString($value, $json, 'The entry holds the draft\'s value of "' . $name . '".');
			$this->assertStringNotContainsString('"' . $name . '"', $json, 'The entry names the draft\'s property "' . $name . '".');
		}
	}//end assertHoldsNoTypedValue()

	/**
	 * Spec scenario: a user's removal writes one entry with the facts, through the audit trail's own entry point.
	 */
	public function testARemovedDraftLeavesOneEntryWithoutItsContent(): void {
		[$register, $schema] = $this->scope();
		$draft = $this->stored(status: 'draft');

		$this->assertTrue($this->handler(auditTrail: $this->auditTrail())->delete(object: $draft, permanent: true, register: $register, schema: $schema));

		$this->assertCount(1, $this->entries);
		$entry = $this->entries[0];
		$this->assertSame('draft.removed', $entry['action']);
		// The row's own columns come from the object: its id, its uuid, its register and schema.
		$this->assertSame($draft, $entry['object']);
		// No explicit actor: the audit trail takes the signed-in user, as for every other entry.
		$this->assertNull($entry['actorId']);
		$this->assertNull($entry['actorName']);
		$this->assertSame(
			['status' => 'draft', 'permanent' => true, 'job' => null, 'register' => 'service', 'schema' => 'ticket', 'owner' => 'agent-anna'],
			$entry['context']
		);
		$this->assertHoldsNoTypedValue(written: $entry['context']);
		$this->assertSame([], $this->errors);
	}//end testARemovedDraftLeavesOneEntryWithoutItsContent()

	/**
	 * Control: a permanent delete of an object that is not a draft writes no draft entry.
	 */
	public function testAPermanentDeleteOfARecordWritesNoDraftEntry(): void {
		[$register, $schema] = $this->scope();

		$this->assertTrue($this->handler(auditTrail: $this->auditTrail())->delete(object: $this->stored(status: 'active'), permanent: true, register: $register, schema: $schema));

		$this->assertSame([], $this->entries);
	}//end testAPermanentDeleteOfARecordWritesNoDraftEntry()

	/**
	 * Spec scenario: a removal by a job names the job as the actor, and only for the length of that removal.
	 */
	public function testARemovalByAJobNamesTheJob(): void {
		[$register, $schema] = $this->scope();
		$record = new DraftRemovalRecord(auditTrail: $this->auditTrail(), logger: $this->logger());
		$draft = $this->stored(status: 'draft');

		$written = DraftRemovalRecord::asJob(job: 'DraftExpiryJob', operation: fn (): bool => $record->write(draft: $draft, register: $register, schema: $schema));
		$this->assertTrue($written);
		$this->assertSame('job:DraftExpiryJob', $this->entries[0]['actorId']);
		$this->assertSame('DraftExpiryJob', $this->entries[0]['actorName']);
		$this->assertSame('DraftExpiryJob', $this->entries[0]['context']['job']);
		$this->assertHoldsNoTypedValue(written: $this->entries[0]['context']);

		// Afterwards nobody is a job any more.
		$record->write(draft: $draft, register: $register, schema: $schema);
		$this->assertNull($this->entries[1]['actorId']);
		$this->assertNull($this->entries[1]['context']['job']);

		// Also when the removal throws.
		try {
			DraftRemovalRecord::asJob(job: 'DraftExpiryJob', operation: static fn () => throw new RuntimeException('refused'));
		} catch (RuntimeException) {
			// Expected.
		}

		$record->write(draft: $draft, register: null, schema: null);
		$this->assertNull($this->entries[2]['actorId']);
		$this->assertSame(['register' => null, 'schema' => null], array_intersect_key($this->entries[2]['context'], ['register' => 0, 'schema' => 0]));
	}//end testARemovalByAJobNamesTheJob()

	/**
	 * An entry that cannot be written is logged as an error with the same facts, and still without the content.
	 */
	public function testAnEntryThatCannotBeWrittenIsLoggedWithoutTheContent(): void {
		[$register, $schema] = $this->scope();
		$record = new DraftRemovalRecord(auditTrail: $this->auditTrail(fails: true), logger: $this->logger());

		$this->assertFalse($record->write(draft: $this->stored(status: 'draft'), register: $register, schema: $schema));

		$this->assertCount(1, $this->errors);
		$this->assertStringContainsString('audit-trail entry could not be written', $this->errors[0][0]);
		$this->assertSame('3f0d6c1e-0000-4000-8000-000000000001', $this->errors[0][1]['uuid']);
		$this->assertSame('ticket', $this->errors[0][1]['schema']);
		$this->assertHoldsNoTypedValue(written: $this->errors[0][1]);
	}//end testAnEntryThatCannotBeWrittenIsLoggedWithoutTheContent()
}//end class
