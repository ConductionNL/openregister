<?php

/**
 * Only the owner's draft is discarded; a sent object or someone else's draft stays.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-discarded-by-its-owner
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\DraftDiscard;
use OCA\OpenRegister\Service\Object\DraftOwner;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Decision 195: a draft is discarded by its asserted owner only.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftDiscard
 * @uses \OCA\OpenRegister\Service\Object\DraftFinder
 * @uses \OCA\OpenRegister\Service\Object\DraftOwner
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class DraftDiscardTest extends TestCase {

	/**
	 * Stored objects by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * Deletes run: uuid and flags.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $deletes = [];

	private DraftDiscard $discard;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		$this->register = new Register();
		$this->register->setId(10);
		$this->schema = new Schema();
		$this->schema->setId(1);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			fn (string|int $id, ...$rest): ?ObjectEntity => ($this->stored[(string)$id] ?? null)
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_retentionSweep = false, $currentUser = null, bool $permanent = false): bool {
				if ($uuid === 'stuck') {
					throw new RuntimeException('database gone');
				}

				$this->deletes[] = ['uuid' => $uuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'permanent' => $permanent, 'register' => $register, 'schema' => $schema];
				unset($this->stored[$uuid]);

				return true;
			}
		);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			function (string|int $id): Register {
				if ((string)$id !== 'portal') {
					throw new RuntimeException('no register');
				}

				return $this->register;
			}
		);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id): Schema {
				if ((string)$id !== 'case') {
					throw new RuntimeException('no schema');
				}

				return $this->schema;
			}
		);

		$this->discard = new DraftDiscard(objects: $objects, registers: $registers, schemas: $schemas);
	}//end setUp()

	/**
	 * Store an object.
	 */
	private function store(string $uuid, string $owner, ?string $status): void {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setStatus($status);
		$entity->setObject(['title' => 'A']);
		$this->stored[$uuid] = $entity;
	}//end store()

	/**
	 * The owner's draft is removed permanently, with RBAC off because the owner check ran first.
	 */
	public function testDiscardsTheOwnersDraft(): void {
		$this->store(uuid: 'd1', owner: 'portal:subject-1', status: 'draft');

		$discarded = $this->discard->discard(
			destination: ['register' => 'portal', 'schema' => 'case'],
			draftId: 'd1',
			owner: DraftOwner::portalSubject(subjectRef: 'subject-1')
		);

		$this->assertTrue($discarded);
		$this->assertCount(1, $this->deletes);
		$this->assertSame('d1', $this->deletes[0]['uuid']);
		$this->assertFalse($this->deletes[0]['rbac']);
		$this->assertFalse($this->deletes[0]['multitenancy']);
		$this->assertTrue($this->deletes[0]['permanent']);
		$this->assertSame($this->register, $this->deletes[0]['register']);
		$this->assertSame($this->schema, $this->deletes[0]['schema']);
	}//end testDiscardsTheOwnersDraft()

	/**
	 * Entities passed as the destination are used as they are.
	 */
	public function testAcceptsEntitiesAsTheDestination(): void {
		$this->store(uuid: 'd1', owner: 'portal:subject-1', status: 'draft');

		$this->assertTrue(
			$this->discard->discard(
				destination: ['register' => $this->register, 'schema' => $this->schema],
				draftId: 'd1',
				owner: DraftOwner::portalSubject(subjectRef: 'subject-1')
			)
		);
	}//end testAcceptsEntitiesAsTheDestination()

	/**
	 * Someone else's draft, a sent object, an unknown id, an empty id, an unknown destination
	 * and a failing delete remove nothing and answer false.
	 */
	public function testRefusesEverythingThatIsNotTheOwnersDraft(): void {
		$this->store(uuid: 'theirs', owner: 'portal:subject-2', status: 'draft');
		$this->store(uuid: 'sent', owner: 'portal:subject-1', status: 'active');
		$this->store(uuid: 'stuck', owner: 'portal:subject-1', status: 'draft');
		$owner = DraftOwner::portalSubject(subjectRef: 'subject-1');
		$destination = ['register' => 'portal', 'schema' => 'case'];

		$this->assertFalse($this->discard->discard(destination: $destination, draftId: 'theirs', owner: $owner));
		$this->assertFalse($this->discard->discard(destination: $destination, draftId: 'sent', owner: $owner));
		$this->assertFalse($this->discard->discard(destination: $destination, draftId: 'nope', owner: $owner));
		$this->assertFalse($this->discard->discard(destination: $destination, draftId: '', owner: $owner));
		$this->assertFalse($this->discard->discard(destination: ['register' => 'gone', 'schema' => 'case'], draftId: 'sent', owner: $owner));
		$this->assertFalse($this->discard->discard(destination: ['register' => 'portal', 'schema' => 'gone'], draftId: 'sent', owner: $owner));
		$this->assertFalse($this->discard->discard(destination: $destination, draftId: 'stuck', owner: $owner));
		$this->assertSame([], $this->deletes);
		$this->assertArrayHasKey('theirs', $this->stored);
		$this->assertArrayHasKey('sent', $this->stored);
	}//end testRefusesEverythingThatIsNotTheOwnersDraft()
}//end class
