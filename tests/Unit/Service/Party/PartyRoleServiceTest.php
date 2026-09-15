<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyLinkWriter;
use OCA\OpenRegister\Service\Party\PartyRoleService;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Service\PersonLinkEvents;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A party on an object, in a role, and which one the object is filed against.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */
class PartyRoleServiceTest extends TestCase {

	/**
	 * The link rows.
	 *
	 * @var ContactLinkMapper&MockObject
	 */
	private $links;

	/**
	 * Writes one party link.
	 *
	 * @var PartyLinkWriter&MockObject
	 */
	private $writer;

	/**
	 * The party records.
	 *
	 * @var PartyService&MockObject
	 */
	private $parties;

	/**
	 * The schemas.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private $schemas;

	/**
	 * The audit trail.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private $audit;

	/**
	 * The service under test.
	 *
	 * @var PartyRoleService
	 */
	private PartyRoleService $service;

	/**
	 * What was dispatched.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * The rows this test's link table holds.
	 *
	 * @var array<int, ContactLink>
	 */
	private array $rows = [];

	/**
	 * Build the service on doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->links = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findPartiesForObject', 'findPrimaryParty', 'update', 'delete'])
			->getMock();
		$this->writer = $this->createMock(PartyLinkWriter::class);
		$this->parties = $this->createMock(PartyService::class);
		$this->schemas = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$this->audit = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['createAuditTrailEntry'])
			->getMock();

		$this->links->method('findPartiesForObject')->willReturnCallback(fn (): array => $this->rows);
		$this->links->method('update')->willReturnArgument(0);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->dispatched[] = $event;
			}
		);

		$this->service = new PartyRoleService(
			$this->links,
			$this->writer,
			$this->parties,
			$this->schemas,
			$this->audit,
			new PersonLinkEvents($dispatcher)
		);
	}//end setUp()

	/**
	 * A schema accepting one kind, with the roles that kind may hold.
	 *
	 * @param array<int, array<string, mixed>> $kinds The accepted kinds.
	 *
	 * @return void
	 */
	private function schemaAccepts(array $kinds): void {
		$schema = new Schema();
		$schema->setConfiguration(['partyKinds' => $kinds]);
		$this->schemas->method('find')->willReturn($schema);
	}//end schemaAccepts()

	/**
	 * A party of a kind the object layer will hand back.
	 *
	 * @param string $uuid The uuid.
	 * @param string $kind The kind.
	 *
	 * @return void
	 */
	private function partyOfKind(string $uuid, string $kind): void {
		$party = new ObjectEntity();
		$party->setUuid($uuid);
		$this->parties->method('find')->willReturn($party);
		$this->parties->method('definitionFor')->willReturn(new PartyDefinition(kind: $kind));
	}//end partyOfKind()

	/**
	 * A stored party link.
	 *
	 * @param string $partyUuid The party.
	 * @param string|null $role The role.
	 * @param bool $primary Whether it is the primary party.
	 *
	 * @return ContactLink The link.
	 */
	private function storedLink(string $partyUuid, ?string $role, bool $primary = false): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid('case-1');
		$link->setContactUid(ContactLink::partyUid(partyUuid: $partyUuid));
		$link->setPartyUuid($partyUuid);
		$link->setPartyKind('person');
		$link->setRole($role);
		$link->setPrimaryParty($primary);
		$link->setDisplayName('Party ' . $partyUuid);

		return $link;
	}//end storedLink()

	/**
	 * Two parties hold the same role on one case, and reading the object
	 * returns both, each with its own period.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testTwoGemachtigdenOnOneCase(): void {
		$first = $this->storedLink(partyUuid: 'party-a', role: 'gemachtigde');
		$first->setValidFrom(new \DateTime('2026-01-01'));
		$second = $this->storedLink(partyUuid: 'party-b', role: 'gemachtigde');
		$second->setValidFrom(new \DateTime('2026-06-01'));
		$this->rows = [$first, $second];

		$listing = $this->service->listForObject(objectUuid: 'case-1', schemaId: null);

		$this->assertSame(2, $listing['total']);
		$this->assertCount(2, $listing['byRole']['gemachtigde']);
		$this->assertSame('2026-01-01', $listing['byRole']['gemachtigde'][0]['validFrom']);
		$this->assertSame('2026-06-01', $listing['byRole']['gemachtigde'][1]['validFrom']);
		$this->assertNull($listing['primary']);
	}//end testTwoGemachtigdenOnOneCase()

	/**
	 * A party of a kind the schema does not accept is refused, and the
	 * message names the kind.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testAnUndeclaredPartyKindIsRefusedNamingTheKind(): void {
		$this->schemaAccepts(kinds: [['key' => 'organisation', 'label' => 'Organisatie']]);
		$this->partyOfKind(uuid: 'party-a', kind: 'person');
		$this->writer->expects($this->never())->method('write');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Party kind "person" is not one of: organisation');

		$this->service->addParty(
			objectUuid: 'case-1',
			registerId: 1,
			schemaId: 7,
			payload: ['partyUuid' => 'party-a', 'role' => 'aanvrager']
		);
	}//end testAnUndeclaredPartyKindIsRefusedNamingTheKind()

	/**
	 * A kind naming its roles holds only those, and the refusal names them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testARoleOutsideTheKindsRolesIsRefused(): void {
		$this->schemaAccepts(
			kinds: [['key' => 'person', 'label' => 'Persoon', 'roles' => ['aanvrager', 'gemachtigde']]]
		);
		$this->partyOfKind(uuid: 'party-a', kind: 'person');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('holds only the roles: aanvrager, gemachtigde');

		$this->service->addParty(
			objectUuid: 'case-1',
			registerId: 1,
			schemaId: 7,
			payload: ['partyUuid' => 'party-a', 'role' => 'toeschouwer']
		);
	}//end testARoleOutsideTheKindsRolesIsRefused()

	/**
	 * A schema that declares no party kinds accepts every kind, which is what
	 * keeps a register written before the party model behaving as it did.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testASchemaDeclaringNoKindsAcceptsAnyParty(): void {
		$this->schemaAccepts(kinds: []);
		$this->partyOfKind(uuid: 'party-a', kind: 'anything-at-all');
		$this->writer->expects($this->once())
			->method('write')
			->willReturn($this->storedLink(partyUuid: 'party-a', role: 'aanvrager'));

		$link = $this->service->addParty(
			objectUuid: 'case-1',
			registerId: 1,
			schemaId: 7,
			payload: ['partyUuid' => 'party-a', 'role' => 'aanvrager']
		);

		$this->assertSame('party-a', $link->getPartyUuid());
		$this->assertCount(1, $this->dispatched);
	}//end testASchemaDeclaringNoKindsAcceptsAnyParty()

	/**
	 * Replacing the party a case is filed against writes exactly one audit
	 * entry, naming the party that went, the party that came and the role.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testReplacingThePrimaryPartyIsRecorded(): void {
		$this->schemaAccepts(kinds: []);
		$this->partyOfKind(uuid: 'party-b', kind: 'person');

		$previous = $this->storedLink(partyUuid: 'party-a', role: 'aanvrager', primary: true);
		$this->rows = [$previous];
		$this->links->method('findPrimaryParty')->willReturn($previous);
		$this->writer->method('write')->willReturn($this->storedLink(partyUuid: 'party-b', role: 'aanvrager'));

		$recorded = [];
		$this->audit->expects($this->once())
			->method('createAuditTrailEntry')
			->willReturnCallback(
				function (ObjectEntity $object, string $action, array $context) use (&$recorded): AuditTrail {
					$recorded = ['action' => $action, 'context' => $context];

					return new AuditTrail();
				}
			);

		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$object->setRegister('1');
		$object->setSchema('7');

		$link = $this->service->replacePrimaryParty(object: $object, partyUuid: 'party-b', role: 'aanvrager');

		$this->assertTrue($link->getPrimaryParty());
		$this->assertFalse($previous->getPrimaryParty());
		$this->assertSame(PartyRoleService::PRIMARY_REPLACED_ACTION, $recorded['action']);
		$this->assertSame('party-a', $recorded['context']['from']);
		$this->assertSame('party-b', $recorded['context']['to']);
		$this->assertSame('aanvrager', $recorded['context']['role']);
	}//end testReplacingThePrimaryPartyIsRecorded()

	/**
	 * Taking a party off an object removes only the role named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testOneRoleGoesAndTheOtherStays(): void {
		$kept = $this->storedLink(partyUuid: 'party-a', role: 'aanvrager');
		$this->rows = [$kept, $this->storedLink(partyUuid: 'party-a', role: 'gemachtigde')];

		$deleted = [];
		$this->links->method('delete')->willReturnCallback(
			function (ContactLink $link) use (&$deleted): ContactLink {
				$deleted[] = $link->getRole();

				return $link;
			}
		);

		$removed = $this->service->removeParty(objectUuid: 'case-1', partyUuid: 'party-a', role: 'gemachtigde');

		$this->assertSame(1, $removed);
		$this->assertSame(['gemachtigde'], $deleted);
	}//end testOneRoleGoesAndTheOtherStays()

	/**
	 * A party that holds no such role is a 404, and nothing is removed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function testRemovingAPartyThatIsNotThereIs404(): void {
		$this->rows = [];
		$this->links->expects($this->never())->method('delete');

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);

		$this->service->removeParty(objectUuid: 'case-1', partyUuid: 'party-a');
	}//end testRemovingAPartyThatIsNotThereIs404()
}//end class
