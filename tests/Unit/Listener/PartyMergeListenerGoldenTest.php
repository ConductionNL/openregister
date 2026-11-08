<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Listener\PartyMergeListener;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyMergeRules;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden outcomes of a party merge: which personal-data rows are moved, kept,
 * deleted or re-created, and which are left alone. Pinned on the code before
 * the A29 refactor so the refactor cannot change them.
 *
 * @spec openspec/specs/mdm-merge/spec.md
 */
class PartyMergeListenerGoldenTest extends TestCase {

	/**
	 * The link rows.
	 *
	 * @var ContactLinkMapper&MockObject
	 */
	private $links;

	/**
	 * The party records.
	 *
	 * @var PartyServiceInterface&MockObject
	 */
	private $parties;

	/**
	 * The object layer.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Every write on the link rows, in order, as "verb:objectUuid:partyUuid".
	 *
	 * @var array<int, string>
	 */
	private array $writes = [];

	/**
	 * Rows inserted.
	 *
	 * @var array<int, ContactLink>
	 */
	private array $inserted = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->links = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByPartyUuid', 'findByObjectContactAndRole', 'findByOperationMemo', 'update', 'insert', 'delete'])
			->getMock();
		$this->parties = $this->createMock(PartyServiceInterface::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		foreach (['update', 'insert', 'delete'] as $verb) {
			$this->links->method($verb)->willReturnCallback(
				function (ContactLink $link) use ($verb): ContactLink {
					$this->writes[] = $verb . ':' . (string)$link->getObjectUuid() . ':' . (string)$link->getPartyUuid();
					if ($verb === 'insert') {
						$this->inserted[] = $link;
					}

					return $link;
				}
			);
		}
	}//end setUp()

	/**
	 * The listener on the doubles.
	 *
	 * @return PartyMergeListener The listener.
	 */
	private function listener(): PartyMergeListener {
		return new PartyMergeListener($this->links, $this->parties, $this->objects, $this->logger);
	}//end listener()

	/**
	 * A party record.
	 *
	 * @param string $uuid The party uuid.
	 * @param array<string, mixed> $data The party data.
	 *
	 * @return ObjectEntity The party.
	 */
	private function party(string $uuid, array $data = []): ObjectEntity {
		$party = new ObjectEntity();
		$party->setUuid($uuid);
		$party->setRegister('1');
		$party->setSchema('7');
		$party->setObject($data);

		return $party;
	}//end party()

	/**
	 * A link of a party on an object.
	 *
	 * @param string $objectUuid The object.
	 * @param string $partyUuid The party.
	 * @param string|null $role The role.
	 *
	 * @return ContactLink The link.
	 */
	private function link(string $objectUuid, string $partyUuid, ?string $role): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid($objectUuid);
		$link->setContactUid(ContactLink::partyUid(partyUuid: $partyUuid));
		$link->setPartyUuid($partyUuid);
		$link->setRole($role);

		return $link;
	}//end link()

	/**
	 * Wire the two parties and the survivor's definition.
	 *
	 * @param PartyDefinition|null $definition The survivor's definition.
	 * @param array<string, array<int, array<string, string>>> $addresses Addresses per party uuid.
	 *
	 * @return void
	 */
	private function wireParties(?PartyDefinition $definition, array $addresses = []): void {
		$survivor = $this->party(uuid: 'party-a', data: ['naam' => 'A', 'addresses' => ($addresses['party-a'] ?? [])]);
		$loser = $this->party(uuid: 'party-b');
		$this->parties->method('find')->willReturnCallback(
			fn (string $partyUuid): ?ObjectEntity => ['party-a' => $survivor, 'party-b' => $loser][$partyUuid] ?? null
		);
		$this->parties->method('definitionFor')->willReturn($definition);
		$this->parties->method('addresses')->willReturnCallback(
			fn (ObjectEntity $party): array => ($addresses[(string)$party->getUuid()] ?? [])
		);
	}//end wireParties()

	/**
	 * A moved link and an absorbed duplicate: the moved row is re-pointed, the
	 * duplicate is recorded on the kept row and deleted, in that order, with
	 * the full snapshot a reversal needs.
	 *
	 * @return void
	 */
	public function testMoveAndAbsorbWriteExactlyTheseRows(): void {
		$this->wireParties(
			definition: new PartyDefinition(
				kind: 'person',
				merge: new PartyMergeRules(survivingProperties: [], unionAddresses: false)
			)
		);
		$held = $this->link(objectUuid: 'case-1', partyUuid: 'party-a', role: 'aanvrager');
		$held->setMetadata((string)json_encode(['keep' => 'me']));
		$duplicate = $this->link(objectUuid: 'case-1', partyUuid: 'party-b', role: 'aanvrager');
		$duplicate->setRegisterId(1);
		$duplicate->setSchemaId(7);
		$duplicate->setDisplayName('Jan');
		$duplicate->setEmail('jan@example.org');
		$duplicate->setPartyKind('person');
		$duplicate->setPrimaryParty(true);
		$duplicate->setValidFrom(new \DateTime('2026-02-01'));
		$duplicate->setNote('tweede');
		$duplicate->setLinkedBy('admin');
		$moved = $this->link(objectUuid: 'case-2', partyUuid: 'party-b', role: 'belanghebbende');
		$this->links->method('findByPartyUuid')->willReturn([$duplicate, $moved]);
		$this->links->method('findByObjectContactAndRole')->willReturnCallback(
			fn (string $objectUuid): ?ContactLink => ['case-1' => $held][$objectUuid] ?? null
		);
		$this->objects->expects($this->never())->method('saveObject');

		$this->listener()->handle(new ObjectsMergedEvent('party-a', ['party-b'], 'merge-1'));

		$this->assertSame(
			['update:case-1:party-a', 'delete:case-1:party-b', 'update:case-2:party-a'],
			$this->writes
		);
		$this->assertSame(
			[
				'keep' => 'me',
				PartyMergeListener::MEMO_KEY => [
					'operation' => 'merge-1',
					'from' => 'party-b',
					'mode' => 'absorbed',
					'absorbed' => [
						'objectUuid' => 'case-1',
						'registerId' => 1,
						'schemaId' => 7,
						'role' => 'aanvrager',
						'displayName' => 'Jan',
						'email' => 'jan@example.org',
						'partyKind' => 'person',
						'primaryParty' => true,
						'validFrom' => '2026-02-01',
						'validUntil' => null,
						'note' => 'tweede',
						'linkedBy' => 'admin',
					],
				],
			],
			json_decode((string)$held->getMetadata(), true)
		);
		$this->assertSame('party:party-a', $moved->getContactUid());
		$this->assertSame('person', $moved->getPartyKind());
		$this->assertSame(
			[PartyMergeListener::MEMO_KEY => ['operation' => 'merge-1', 'from' => 'party-b', 'mode' => 'moved']],
			json_decode((string)$moved->getMetadata(), true)
		);
	}//end testMoveAndAbsorbWriteExactlyTheseRows()

	/**
	 * No survivor, or a survivor that is not a party: nothing is written.
	 *
	 * @return void
	 */
	public function testNoPartyDefinitionWritesNothing(): void {
		$this->wireParties(definition: null);
		$this->links->expects($this->never())->method('findByPartyUuid');

		$this->listener()->handle(new ObjectsMergedEvent('party-a', ['party-b'], 'merge-1'));
		$this->listener()->handle(new ObjectsMergedEvent('party-x', ['party-b'], 'merge-1'));

		$this->assertSame([], $this->writes);
	}//end testNoPartyDefinitionWritesNothing()

	/**
	 * The address union keeps every survivor address and adds only the loser's
	 * addresses it does not already hold, compared case-insensitively.
	 *
	 * @return void
	 */
	public function testAddressUnionAddsOnlyNewAddresses(): void {
		$this->wireParties(
			definition: new PartyDefinition(),
			addresses: [
				'party-a' => [['kind' => 'email', 'value' => 'A@example.org']],
				'party-b' => [
					['kind' => 'email', 'value' => 'a@EXAMPLE.org'],
					['kind' => 'email', 'value' => 'b@example.org'],
					['kind' => 'email', 'value' => 'B@example.org'],
				],
			]
		);
		$this->links->method('findByPartyUuid')->willReturn([]);
		$this->objects->expects($this->once())->method('saveObject')->with(
			[
				'naam' => 'A',
				'addresses' => [
					['kind' => 'email', 'value' => 'A@example.org'],
					['kind' => 'email', 'value' => 'b@example.org'],
				],
				'id' => 'party-a',
			],
			$this->anything(),
			'1',
			'7',
			'party-a'
		)->willReturn($this->party(uuid: 'party-a'));

		$this->listener()->handle(new ObjectsMergedEvent('party-a', ['party-b'], 'merge-1'));
	}//end testAddressUnionAddsOnlyNewAddresses()

	/**
	 * Nothing new to add: the survivor is not saved.
	 *
	 * @return void
	 */
	public function testAddressUnionWithNothingNewDoesNotSave(): void {
		$this->wireParties(
			definition: new PartyDefinition(),
			addresses: [
				'party-a' => [['kind' => 'email', 'value' => 'a@example.org']],
				'party-b' => [['kind' => 'email', 'value' => 'A@example.org']],
			]
		);
		$this->links->method('findByPartyUuid')->willReturn([]);
		$this->objects->expects($this->never())->method('saveObject');

		$this->listener()->handle(new ObjectsMergedEvent('party-a', ['party-b'], 'merge-1'));
	}//end testAddressUnionWithNothingNewDoesNotSave()

	/**
	 * A reversal re-points moved rows, re-creates absorbed rows, strips only the
	 * memo, and leaves rows of another merge and memo-less rows alone.
	 *
	 * @return void
	 */
	public function testReversalRestoresOnlyThisMergesRows(): void {
		$moved = $this->link(objectUuid: 'case-2', partyUuid: 'party-a', role: 'r');
		$moved->setMetadata(
			(string)json_encode(['x' => 1, PartyMergeListener::MEMO_KEY => ['operation' => 'merge-1', 'from' => 'party-b']])
		);
		$absorbedInto = $this->link(objectUuid: 'case-1', partyUuid: 'party-a', role: 'r');
		$absorbedInto->setMetadata(
			(string)json_encode(
				[
					PartyMergeListener::MEMO_KEY => [
						'operation' => 'merge-1',
						'from' => 'party-b',
						'mode' => 'absorbed',
						'absorbed' => ['objectUuid' => 'case-1', 'registerId' => '3', 'schemaId' => 'x', 'role' => ' r ', 'email' => '', 'primaryParty' => true, 'validUntil' => 'not a date'],
					],
				]
			)
		);
		$noFrom = $this->link(objectUuid: 'case-3', partyUuid: 'party-a', role: 'r');
		$noFrom->setMetadata((string)json_encode([PartyMergeListener::MEMO_KEY => ['operation' => 'merge-1']]));
		$other = $this->link(objectUuid: 'case-4', partyUuid: 'party-a', role: 'r');
		$other->setMetadata((string)json_encode([PartyMergeListener::MEMO_KEY => ['operation' => 'merge-2', 'from' => 'party-z']]));
		$broken = $this->link(objectUuid: 'case-5', partyUuid: 'party-a', role: 'r');
		$broken->setMetadata('{not json');
		$this->links->method('findByOperationMemo')->willReturn([$moved, $absorbedInto, $noFrom, $other, $broken]);

		$this->listener()->handle(new ObjectsMergedEvent('party-a', ['party-b'], 'merge-1', true));

		$this->assertSame(['update:case-2:party-b', 'insert:case-1:party-b', 'update:case-1:party-a'], $this->writes);
		$this->assertSame('{"x":1}', $moved->getMetadata());
		$this->assertSame('party:party-b', $moved->getContactUid());
		$this->assertNull($absorbedInto->getMetadata());
	}//end testReversalRestoresOnlyThisMergesRows()

	/**
	 * The re-created row carries the snapshot, normalised.
	 *
	 * @return void
	 */
	public function testRestoredRowIsNormalised(): void {
		$into = $this->link(objectUuid: 'case-1', partyUuid: 'party-a', role: 'r');
		$into->setMetadata(
			(string)json_encode(
				[
					PartyMergeListener::MEMO_KEY => [
						'operation' => 'merge-1',
						'from' => 'party-b',
						'mode' => 'absorbed',
						'absorbed' => ['objectUuid' => 'case-1', 'registerId' => '3', 'schemaId' => 'x', 'role' => ' r ', 'email' => '', 'primaryParty' => true, 'validUntil' => 'not a date', 'validFrom' => '2026-03-04'],
					],
				]
			)
		);
		$this->links->method('findByOperationMemo')->willReturn([$into]);
		$this->listener()->handle(new ObjectsMergedEvent('party-a', ['party-b'], 'merge-1', true));

		$this->assertCount(1, $this->inserted);
		$row = $this->inserted[0];
		$this->assertSame(
			['case-1', 'party:party-b', 'party-b', null, 3, null, 'r', null, null, null, true, '', '2026-03-04', null],
			[
				$row->getObjectUuid(), $row->getContactUid(), $row->getPartyUuid(), $row->getPartyKind(),
				$row->getRegisterId(), $row->getSchemaId(), $row->getRole(), $row->getDisplayName(),
				$row->getEmail(), $row->getNote(), $row->getPrimaryParty(), $row->getLinkedBy(),
				$row->getValidFrom()?->format('Y-m-d'), $row->getValidUntil(),
			]
		);
		$this->assertNotNull($row->getLinkedAt());
	}//end testRestoredRowIsNormalised()

	/**
	 * A failure is logged once and never thrown at the merge.
	 *
	 * @return void
	 */
	public function testFailureIsLoggedNotThrown(): void {
		$this->parties->method('find')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error')
			->with('[PartyMergeListener] party vocabulary failed for merge "merge-1": boom');

		$this->listener()->handle(new ObjectsMergedEvent('party-a', ['party-b'], 'merge-1'));
	}//end testFailureIsLoggedNotThrown()
}//end class
