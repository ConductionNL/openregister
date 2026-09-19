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
use OCA\OpenRegister\Listener\PartyMergeListener;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Two party records that turn out to be one person: the roles both held
 * carry over, and a reversal puts both back.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/mdm-merge/spec.md
 */
class PartyMergeListenerTest extends TestCase {

	/**
	 * The link rows.
	 *
	 * @var ContactLinkMapper&MockObject
	 */
	private $links;

	/**
	 * The party records.
	 *
	 * @var PartyService&MockObject
	 */
	private $parties;

	/**
	 * The object layer.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objects;

	/**
	 * The listener under test.
	 *
	 * @var PartyMergeListener
	 */
	private PartyMergeListener $listener;

	/**
	 * The links the loser holds.
	 *
	 * @var array<int, ContactLink>
	 */
	private array $loserLinks = [];

	/**
	 * What the survivor already holds on an object and role, by "uuid|role".
	 *
	 * @var array<string, ContactLink>
	 */
	private array $survivorHolds = [];

	/**
	 * Rows written back.
	 *
	 * @var array<int, ContactLink>
	 */
	private array $updated = [];

	/**
	 * Rows inserted.
	 *
	 * @var array<int, ContactLink>
	 */
	private array $inserted = [];

	/**
	 * Rows deleted.
	 *
	 * @var array<int, ContactLink>
	 */
	private array $deleted = [];

	/**
	 * Build the listener on doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->links = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByPartyUuid', 'findByObjectContactAndRole', 'findByOperationMemo', 'update', 'insert', 'delete'])
			->getMock();
		$this->parties = $this->createMock(PartyService::class);
		$this->objects = $this->createMock(ObjectService::class);

		$this->links->method('findByPartyUuid')->willReturnCallback(fn (): array => $this->loserLinks);
		$this->links->method('findByObjectContactAndRole')->willReturnCallback(
			fn (string $objectUuid, string $contactUid, ?string $role): ?ContactLink
				=> ($this->survivorHolds[$objectUuid . '|' . (string)$role] ?? null)
		);
		$this->links->method('update')->willReturnCallback(
			function (ContactLink $link): ContactLink {
				$this->updated[] = $link;

				return $link;
			}
		);
		$this->links->method('insert')->willReturnCallback(
			function (ContactLink $link): ContactLink {
				$this->inserted[] = $link;

				return $link;
			}
		);
		$this->links->method('delete')->willReturnCallback(
			function (ContactLink $link): ContactLink {
				$this->deleted[] = $link;

				return $link;
			}
		);

		$survivor = new ObjectEntity();
		$survivor->setUuid('party-a');
		$survivor->setRegister('1');
		$survivor->setSchema('7');
		$survivor->setObject([]);
		$this->parties->method('find')->willReturn($survivor);
		$this->parties->method('definitionFor')->willReturn(new PartyDefinition());
		$this->parties->method('addresses')->willReturn([]);

		$this->listener = new PartyMergeListener(
			$this->links,
			$this->parties,
			$this->objects,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A link of the merged-away party on an object.
	 *
	 * @param string $objectUuid The object.
	 * @param string|null $role The role.
	 *
	 * @return ContactLink The link.
	 */
	private function loserLink(string $objectUuid, ?string $role): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid($objectUuid);
		$link->setContactUid(ContactLink::partyUid(partyUuid: 'party-b'));
		$link->setPartyUuid('party-b');
		$link->setRole($role);

		return $link;
	}//end loserLink()

	/**
	 * Party A holds a role on two objects and party B on one; after the merge
	 * A holds all three.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
	 */
	public function testEveryRoleTheMergedPartyHeldCarriesOver(): void {
		$this->loserLinks = [$this->loserLink(objectUuid: 'case-3', role: 'aanvrager')];

		$this->listener->handle(
			new ObjectsMergedEvent('party-a', ['party-b'], 'merge-1')
		);

		$this->assertCount(1, $this->updated);
		$this->assertSame('party-a', $this->updated[0]->getPartyUuid());
		$this->assertSame('party:party-a', $this->updated[0]->getContactUid());
		$this->assertSame([], $this->deleted);

		$memo = json_decode((string)$this->updated[0]->getMetadata(), true);
		$this->assertSame('merge-1', $memo[PartyMergeListener::MEMO_KEY]['operation']);
		$this->assertSame('party-b', $memo[PartyMergeListener::MEMO_KEY]['from']);
		$this->assertSame('moved', $memo[PartyMergeListener::MEMO_KEY]['mode']);
	}//end testEveryRoleTheMergedPartyHeldCarriesOver()

	/**
	 * When the survivor already holds that role on that object — which is the
	 * duplicate the merge exists to remove — the losing row is absorbed, not
	 * re-pointed onto a key that already exists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
	 */
	public function testADuplicateRoleIsAbsorbedRatherThanCollided(): void {
		$held = new ContactLink();
		$held->setObjectUuid('case-1');
		$held->setContactUid(ContactLink::partyUid(partyUuid: 'party-a'));
		$held->setPartyUuid('party-a');
		$held->setRole('aanvrager');
		$this->survivorHolds['case-1|aanvrager'] = $held;

		$loser = $this->loserLink(objectUuid: 'case-1', role: 'aanvrager');
		$loser->setNote('van de tweede registratie');
		$this->loserLinks = [$loser];

		$this->listener->handle(
			new ObjectsMergedEvent('party-a', ['party-b'], 'merge-1')
		);

		$this->assertSame([$loser], $this->deleted);
		$memo = json_decode((string)$held->getMetadata(), true)[PartyMergeListener::MEMO_KEY];
		$this->assertSame('absorbed', $memo['mode']);
		$this->assertSame('van de tweede registratie', $memo['absorbed']['note']);
	}//end testADuplicateRoleIsAbsorbedRatherThanCollided()

	/**
	 * Reversing the merge puts the moved role back on the party that held it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
	 */
	public function testAReversalPutsAMovedRoleBack(): void {
		$moved = new ContactLink();
		$moved->setObjectUuid('case-3');
		$moved->setContactUid(ContactLink::partyUid(partyUuid: 'party-a'));
		$moved->setPartyUuid('party-a');
		$moved->setRole('aanvrager');
		$moved->setMetadata(
			(string)json_encode(
				[PartyMergeListener::MEMO_KEY => ['operation' => 'merge-1', 'from' => 'party-b', 'mode' => 'moved']]
			)
		);
		$this->links->method('findByOperationMemo')->willReturn([$moved]);

		$this->listener->handle(
			new ObjectsMergedEvent('party-a', ['party-b'], 'merge-1', true)
		);

		$this->assertSame('party-b', $moved->getPartyUuid());
		$this->assertSame('party:party-b', $moved->getContactUid());
		$this->assertNull($moved->getMetadata());
	}//end testAReversalPutsAMovedRoleBack()

	/**
	 * Reversing the merge re-creates the duplicate row it absorbed, so both
	 * parties exist again holding the roles they held before.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
	 */
	public function testAReversalRestoresAnAbsorbedRow(): void {
		$held = new ContactLink();
		$held->setObjectUuid('case-1');
		$held->setContactUid(ContactLink::partyUid(partyUuid: 'party-a'));
		$held->setPartyUuid('party-a');
		$held->setRole('aanvrager');
		$held->setMetadata(
			(string)json_encode(
				[
					PartyMergeListener::MEMO_KEY => [
						'operation' => 'merge-1',
						'from' => 'party-b',
						'mode' => 'absorbed',
						'absorbed' => [
							'objectUuid' => 'case-1',
							'registerId' => 1,
							'schemaId' => 7,
							'role' => 'aanvrager',
							'displayName' => 'Jan Jansen',
							'partyKind' => 'person',
							'primaryParty' => false,
							'validFrom' => '2026-02-01',
							'note' => 'van de tweede registratie',
							'linkedBy' => 'admin',
						],
					],
				]
			)
		);
		$this->links->method('findByOperationMemo')->willReturn([$held]);

		$this->listener->handle(
			new ObjectsMergedEvent('party-a', ['party-b'], 'merge-1', true)
		);

		$this->assertCount(1, $this->inserted);
		$this->assertSame('party-b', $this->inserted[0]->getPartyUuid());
		$this->assertSame('aanvrager', $this->inserted[0]->getRole());
		$this->assertSame('van de tweede registratie', $this->inserted[0]->getNote());
		$this->assertSame('2026-02-01', $this->inserted[0]->getValidFrom()?->format('Y-m-d'));
		$this->assertNull($held->getMetadata());
	}//end testAReversalRestoresAnAbsorbedRow()

	/**
	 * A memo naming another merge is left alone: the LIKE narrows, the memo
	 * decides, and a row whose metadata merely contains the id as text is
	 * never acted on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
	 */
	public function testAnotherMergesMemoIsLeftAlone(): void {
		$other = new ContactLink();
		$other->setObjectUuid('case-9');
		$other->setPartyUuid('party-a');
		$other->setContactUid(ContactLink::partyUid(partyUuid: 'party-a'));
		$other->setMetadata(
			(string)json_encode(
				[PartyMergeListener::MEMO_KEY => ['operation' => 'merge-2', 'from' => 'party-z', 'mode' => 'moved']]
			)
		);
		$this->links->method('findByOperationMemo')->willReturn([$other]);

		$this->listener->handle(
			new ObjectsMergedEvent('party-a', ['party-b'], 'merge-1', true)
		);

		$this->assertSame('party-a', $other->getPartyUuid());
		$this->assertSame([], $this->updated);
	}//end testAnotherMergesMemoIsLeftAlone()
}//end class
