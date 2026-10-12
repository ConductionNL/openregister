<?php

/**
 * An owner's drafts, by id or newest in a destination; anyone else's are not found.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\DraftFinder;
use OCA\OpenRegister\Service\Object\DraftOwner;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Decision 195: only the asserted owner finds its draft.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftFinder
 * @uses \OCA\OpenRegister\Service\Object\DraftOwner
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class DraftFinderTest extends TestCase {

	/**
	 * Stored objects by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * Searches run: query and flags.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $searches = [];

	private ObjectService $objects;

	private DraftFinder $finder;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturnCallback(
			function (string|int $id, ...$rest): ?ObjectEntity {
				if ($id === 'boom') {
					throw new RuntimeException('database gone');
				}

				return ($this->stored[(string)$id] ?? null);
			}
		);
		$this->objects->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searches[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$owner = ($query['@self']['owner'] ?? null);
				$hits = array_values(array_filter($this->stored, static fn (ObjectEntity $entity): bool => $entity->getOwner() === $owner));
				$hits[] = ['not' => 'an entity'];

				return $hits;
			}
		);
		$this->finder = new DraftFinder(objects: $this->objects);
		$this->register = new Register();
		$this->register->setId(10);
		$this->schema = new Schema();
		$this->schema->setId(1);
	}//end setUp()

	/**
	 * Store an object.
	 *
	 * @param array<string, mixed> $data The data.
	 */
	private function store(string $uuid, string $owner, ?string $status, array $data, string $updated): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setStatus($status);
		$entity->setObject($data);
		$entity->setUpdated(new DateTime($updated));
		$this->stored[$uuid] = $entity;

		return $entity;
	}//end store()

	/**
	 * By id: the owner's draft is found; another owner, a non-draft, an unknown id, a failing read or nobody find nothing.
	 */
	public function testOwnFindsOnlyTheOwnersDraft(): void {
		$draft = $this->store(uuid: 'd1', owner: 'portal:subject-1', status: 'draft', data: ['title' => 'A'], updated: '2026-10-10');
		$this->store(uuid: 'sent', owner: 'portal:subject-1', status: 'active', data: [], updated: '2026-10-10');
		$owner = DraftOwner::portalSubject(subjectRef: 'subject-1');

		$this->assertTrue($this->finder->own(register: $this->register, schema: $this->schema, draftId: 'd1', owner: $owner) === $draft);
		$this->assertNull($this->finder->own(register: $this->register, schema: $this->schema, draftId: 'd1', owner: DraftOwner::portalSubject(subjectRef: 'subject-2')));
		$this->assertNull($this->finder->own(register: $this->register, schema: $this->schema, draftId: 'sent', owner: $owner));
		$this->assertNull($this->finder->own(register: $this->register, schema: $this->schema, draftId: 'nope', owner: $owner));
		$this->assertNull($this->finder->own(register: $this->register, schema: $this->schema, draftId: 'boom', owner: $owner));
		$this->assertNull($this->finder->own(register: $this->register, schema: $this->schema, draftId: 'd1', owner: null));
	}//end testOwnFindsOnlyTheOwnersDraft()

	/**
	 * In a destination: the owner's newest matching draft, searched by owner with RBAC off and checked again.
	 */
	public function testNewestPicksTheOwnersNewestMatchingDraft(): void {
		$this->store(uuid: 'old', owner: 'portal:subject-1', status: 'draft', data: ['caseType' => 'ct-1'], updated: '2026-10-01');
		$this->store(uuid: 'new', owner: 'portal:subject-1', status: 'draft', data: ['caseType' => 'ct-1'], updated: '2026-10-09');
		$this->store(uuid: 'other-type', owner: 'portal:subject-1', status: 'draft', data: ['caseType' => 'ct-2'], updated: '2026-10-10');
		$this->store(uuid: 'sent', owner: 'portal:subject-1', status: 'active', data: ['caseType' => 'ct-1'], updated: '2026-10-11');
		$this->store(uuid: 'theirs', owner: 'portal:subject-2', status: 'draft', data: ['caseType' => 'ct-1'], updated: '2026-10-11');
		$owner = DraftOwner::portalSubject(subjectRef: 'subject-1');

		$newest = $this->finder->newest(register: $this->register, schema: $this->schema, owner: $owner, match: ['caseType' => 'ct-1']);

		$this->assertSame('new', $newest?->getUuid());
		$this->assertSame(['register' => '10', 'schema' => '1', 'owner' => 'portal:subject-1'], $this->searches[0]['query']['@self']);
		$this->assertSame(DraftFinder::SEARCH_LIMIT, $this->searches[0]['query']['_limit']);
		$this->assertSame('true', $this->searches[0]['query']['_drafts'], 'A list hides drafts unless asked (decision 201); the finder asks for drafts alone.');
		$this->assertFalse($this->searches[0]['rbac']);
		$this->assertFalse($this->searches[0]['multitenancy']);
		$this->assertSame('other-type', $this->finder->newest(register: $this->register, schema: $this->schema, owner: $owner)?->getUuid());
		$this->assertNull($this->finder->newest(register: $this->register, schema: $this->schema, owner: $owner, match: ['caseType' => 'ct-9']));
		$this->assertNull($this->finder->newest(register: $this->register, schema: $this->schema, owner: DraftOwner::portalSubject(subjectRef: 'subject-3')));
	}//end testNewestPicksTheOwnersNewestMatchingDraft()

	/**
	 * A failing or counting search finds nothing rather than failing the caller.
	 */
	public function testAFailingOrCountingSearchFindsNothing(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjects')->willReturnOnConsecutiveCalls(3, $this->throwException(new RuntimeException('gone')));
		$finder = new DraftFinder(objects: $objects);
		$owner = DraftOwner::portalSubject(subjectRef: 'subject-1');

		$this->assertNull($finder->newest(register: $this->register, schema: $this->schema, owner: $owner));
		$this->assertNull($finder->newest(register: $this->register, schema: $this->schema, owner: $owner));
	}//end testAFailingOrCountingSearchFindsNothing()

	/**
	 * The data without its metadata keys.
	 */
	public function testDataOfLeavesOutTheMetadata(): void {
		$entity = $this->store(uuid: 'd1', owner: 'portal:subject-1', status: 'draft', data: ['title' => 'A', '@self' => ['x' => 1]], updated: '2026-10-10');

		$this->assertSame(['title' => 'A'], DraftFinder::dataOf(object: $entity));
		$this->assertSame(
			['id' => 'd1', 'status' => 'draft', 'data' => ['title' => 'A'], 'updatedAt' => (new DateTime('2026-10-10'))->format(DATE_ATOM)],
			DraftFinder::answer(draft: $entity)
		);
		$this->assertSame('', DraftFinder::answer(draft: new ObjectEntity())['updatedAt']);
	}//end testDataOfLeavesOutTheMetadata()
}//end class
