<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\PersonLinkedEvent;
use OCA\OpenRegister\Event\PersonLinkUpdatedEvent;
use OCA\OpenRegister\Event\PersonUnlinkedEvent;
use OCA\OpenRegister\Service\PersonLinkChange;
use OCA\OpenRegister\Service\PersonLinkRef;
use OCA\OpenRegister\Service\PersonLinkTarget;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\PersonLinkEvents;
use OCA\OpenRegister\Service\PersonLinkService;
use OCA\OpenRegister\Service\PersonLinkTargets;
use OCA\OpenRegister\Service\UserLinkRequest;
use OCA\OpenRegister\Service\UserLinkWriter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\ContactCardRef;
use OCA\OpenRegister\Service\ContactPlacement;

/**
 * People on objects: the service that links a user or a contact in a role.
 *
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md
 */
class PersonLinkServiceTest extends TestCase {

	/**
	 * The link rows.
	 *
	 * @var ContactLinkMapper&MockObject
	 */
	private $links;

	/**
	 * The CardDAV side.
	 *
	 * @var ContactService&MockObject
	 */
	private $contacts;

	/**
	 * The account side.
	 *
	 * @var UserLinkWriter&MockObject
	 */
	private $userLinks;

	/**
	 * The schemas, for the vocabulary.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private $schemas;

	/**
	 * What was dispatched.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * The service under test.
	 *
	 * @var PersonLinkService
	 */
	private PersonLinkService $service;

	/**
	 * Build the service on doubles, recording every event.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->links = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'findByObjectAndContact', 'findByObjectContactAndRole', 'update'])
			->getMock();
		$this->contacts = $this->createMock(ContactService::class);
		$this->userLinks = $this->createMock(UserLinkWriter::class);
		$this->schemas = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->dispatched[] = $event;
			}
		);

		$this->service = new PersonLinkService(
			$this->links,
			new PersonLinkTargets($this->contacts, $this->userLinks),
			$this->schemas,
			new PersonLinkEvents($dispatcher)
		);
	}//end setUp()

	/**
	 * A schema declaring the two roles the tests use.
	 *
	 * @return void
	 */
	private function schemaDeclaresRoles(): void {
		$schema = new Schema();
		$schema->setConfiguration(
			['linkRoles' => [['key' => 'initiator', 'label' => 'Initiator'], ['key' => 'handler', 'label' => 'Handler']]]
		);
		$this->schemas->method('find')->willReturn($schema);
	}//end schemaDeclaresRoles()

	/**
	 * A stored link, as the mapper would hand it back.
	 *
	 * @param string $contactUid The person.
	 * @param string|null $role The role.
	 *
	 * @return ContactLink The link.
	 */
	private function storedLink(string $contactUid = 'user:jan', ?string $role = 'handler'): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid('case-1');
		$link->setContactUid($contactUid);
		$link->setUserId(str_starts_with($contactUid, 'user:') === true ? substr($contactUid, 5) : null);
		$link->setRole($role);

		return $link;
	}//end storedLink()

	/**
	 * A payload naming a user goes to the account writer, and the link is announced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function testAUserPayloadWritesAUserLinkAndAnnouncesIt(): void {
		$this->schemaDeclaresRoles();
		$this->contacts->expects($this->never())->method('linkCard');
		$this->userLinks->expects($this->once())
			->method('write')
			->with(new UserLinkRequest('case-1', 5, 7, 'jan'), 'handler')
			->willReturn($this->storedLink());

		$link = $this->service->linkPerson(target: new PersonLinkTarget(objectUuid: 'case-1', registerId: 5, schemaId: 7), payload: ['userId' => 'jan', 'role' => 'handler']);

		$this->assertSame('user:jan', $link->getContactUid());
		$this->assertCount(1, $this->dispatched);
		$this->assertInstanceOf(PersonLinkedEvent::class, $this->dispatched[0]);
		$this->assertSame($link, $this->dispatched[0]->getLink());
	}//end testAUserPayloadWritesAUserLinkAndAnnouncesIt()

	/**
	 * A payload naming an address-book contact goes to the CardDAV side.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function testAContactPayloadWritesAContactLink(): void {
		$this->schemaDeclaresRoles();
		$this->userLinks->expects($this->never())->method('write');
		$this->contacts->expects($this->once())
			->method('linkCard')
			->with(new ContactCardRef(addressbookId: 1, contactUri: 'jan.vcf'), new ContactPlacement(objectUuid: 'case-1', registerId: 5, schemaId: 7, role: 'initiator'))
			->willReturn($this->storedLink(contactUid: 'jan-uid', role: 'initiator'));

		$link = $this->service->linkPerson(target: new PersonLinkTarget(objectUuid: 'case-1', registerId: 5, schemaId: 7), payload: ['addressbookId' => 1, 'contactUri' => 'jan.vcf', 'role' => 'initiator']);

		$this->assertSame('jan-uid', $link->getContactUid());
		$this->assertFalse($link->isUserLink());
	}//end testAContactPayloadWritesAContactLink()

	/**
	 * A payload naming nobody is a 400, and nothing is written or announced.
	 *
	 * @return void
	 */
	public function testAPayloadNamingNobodyIsRefused(): void {
		$this->schemaDeclaresRoles();

		try {
			$this->service->linkPerson(target: new PersonLinkTarget(objectUuid: 'case-1', registerId: 5, schemaId: 7), payload: ['role' => 'handler']);
			$this->fail('a payload naming no person must be refused');
		} catch (Exception $e) {
			$this->assertSame(400, $e->getCode());
		}

		$this->assertSame([], $this->dispatched);
	}//end testAPayloadNamingNobodyIsRefused()

	/**
	 * A role the schema does not declare is refused, naming the allowed keys.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-schema-declares-the-roles-its-objects-carry
	 */
	public function testARoleOutsideTheVocabularyIsRefused(): void {
		$this->schemaDeclaresRoles();
		$this->userLinks->expects($this->never())->method('write');

		try {
			$this->service->linkPerson(target: new PersonLinkTarget(objectUuid: 'case-1', registerId: 5, schemaId: 7), payload: ['userId' => 'jan', 'role' => 'observer']);
			$this->fail('a role outside the vocabulary must be refused');
		} catch (Exception $e) {
			$this->assertSame(400, $e->getCode());
			$this->assertStringContainsString('initiator', $e->getMessage());
			$this->assertStringContainsString('handler', $e->getMessage());
		}
	}//end testARoleOutsideTheVocabularyIsRefused()

	/**
	 * A schema declaring no roles takes any role.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-schema-declares-the-roles-its-objects-carry
	 */
	public function testASchemaWithoutAVocabularyTakesAnyRole(): void {
		$this->schemas->method('find')->willReturn(new Schema());
		$this->userLinks->expects($this->once())
			->method('write')
			->with(new UserLinkRequest('case-1', 5, 7, 'jan'), 'whatever')
			->willReturn($this->storedLink(role: 'whatever'));

		$this->service->linkPerson(target: new PersonLinkTarget(objectUuid: 'case-1', registerId: 5, schemaId: 7), payload: ['userId' => 'jan', 'role' => 'whatever']);

		$this->assertCount(1, $this->dispatched);
	}//end testASchemaWithoutAVocabularyTakesAnyRole()

	/**
	 * Validity and note are written onto the link the writer returned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function testValidityAndNoteAreStoredOnTheLink(): void {
		$this->schemaDeclaresRoles();
		$stored = $this->storedLink();
		$this->userLinks->method('write')->willReturn($stored);
		$this->links->expects($this->once())
			->method('update')
			->willReturnCallback(
				static function (ContactLink $link): ContactLink {
					return $link;
				}
			);

		$link = $this->service->linkPerson(target: new PersonLinkTarget(objectUuid: 'case-1', registerId: 5, schemaId: 7), payload: [
				'userId' => 'jan',
				'role' => 'handler',
				'validFrom' => '2026-03-01',
				'validUntil' => '2026-03-31',
				'note' => 'Stands in for Piet',
			]);

		$this->assertSame('2026-03-01', $link->getValidFrom()->format('Y-m-d'));
		$this->assertSame('2026-03-31', $link->getValidUntil()->format('Y-m-d'));
		$this->assertSame('Stands in for Piet', $link->getNote());
		$this->assertFalse($link->jsonSerialize()['active']);
	}//end testValidityAndNoteAreStoredOnTheLink()

	/**
	 * An update sets the end date, leaves the role alone and announces the change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
	 */
	public function testAnUpdateSetsTheEndDateAndKeepsTheRole(): void {
		$this->schemaDeclaresRoles();
		$stored = $this->storedLink();
		$this->links->method('findByObjectContactAndRole')->willReturn($stored);
		$this->links->method('update')->willReturnCallback(
			static function (ContactLink $link): ContactLink {
				return $link;
			}
		);

		$link = $this->service->changeLink(change: new PersonLinkChange(ref: new PersonLinkRef(objectUuid: 'case-1', contactUid: 'user:jan', role: 'handler'), schemaId: 7, changes: ['validUntil' => '2026-12-31']));

		$this->assertSame('handler', $link->getRole());
		$this->assertSame('2026-12-31', $link->getValidUntil()->format('Y-m-d'));
		$this->assertCount(1, $this->dispatched);
		$this->assertInstanceOf(PersonLinkUpdatedEvent::class, $this->dispatched[0]);
	}//end testAnUpdateSetsTheEndDateAndKeepsTheRole()

	/**
	 * An update to a role outside the schema's vocabulary is refused before anything is stored or announced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-schema-declares-the-roles-its-objects-carry
	 */
	public function testAnUpdateToARoleOutsideTheVocabularyIsRefused(): void {
		$this->schemaDeclaresRoles();
		$this->links->method('findByObjectContactAndRole')->willReturn($this->storedLink());
		$this->links->expects($this->never())->method('update');
		$this->contacts->expects($this->never())->method('updateRole');

		try {
			$this->service->changeLink(change: new PersonLinkChange(ref: new PersonLinkRef(objectUuid: 'case-1', contactUid: 'user:jan', role: 'handler'), schemaId: 7, changes: ['role' => 'observer']));
			$this->fail('a role outside the vocabulary must be refused');
		} catch (Exception $e) {
			$this->assertSame(400, $e->getCode());
		}

		$this->assertSame([], $this->dispatched);
	}//end testAnUpdateToARoleOutsideTheVocabularyIsRefused()

	/**
	 * A role change on a contact link goes through the CardDAV side, so the vCard follows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
	 */
	public function testARoleChangeOnAContactLinkUpdatesTheVcard(): void {
		$this->schemaDeclaresRoles();
		$contactLink = $this->storedLink(contactUid: 'jan-uid', role: 'initiator');
		$this->links->method('findByObjectAndContact')->willReturn($contactLink);
		$this->contacts->expects($this->once())
			->method('updateRole')
			->willReturn($this->storedLink(contactUid: 'jan-uid', role: 'handler'));

		$link = $this->service->changeLink(change: new PersonLinkChange(ref: new PersonLinkRef(objectUuid: 'case-1', contactUid: 'jan-uid', role: null), schemaId: 7, changes: ['role' => 'handler']));

		$this->assertSame('handler', $link->getRole());
	}//end testARoleChangeOnAContactLinkUpdatesTheVcard()

	/**
	 * Unlink without a role removes every role the person holds on the object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
	 */
	public function testUnlinkWithoutARoleRemovesThemAll(): void {
		$first = $this->storedLink(role: 'handler');
		$first->setId(11);
		$second = $this->storedLink(role: 'initiator');
		$second->setId(12);
		$other = $this->storedLink(contactUid: 'user:piet', role: 'handler');
		$other->setId(13);
		$this->links->method('findByObjectUuid')->willReturn([$first, $second, $other]);

		$removed = [];
		$this->contacts->method('unlinkContact')->willReturnCallback(
			static function (int $linkId) use (&$removed): void {
				$removed[] = $linkId;
			}
		);

		$count = $this->service->removeLinks(ref: new PersonLinkRef(objectUuid: 'case-1', contactUid: 'user:jan', role: null));

		$this->assertSame(2, $count);
		$this->assertSame([11, 12], $removed);
		$this->assertCount(2, $this->dispatched);
		$this->assertInstanceOf(PersonUnlinkedEvent::class, $this->dispatched[0]);
	}//end testUnlinkWithoutARoleRemovesThemAll()

	/**
	 * Unlink with a role removes that one link only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
	 */
	public function testUnlinkWithARoleRemovesOnlyThatLink(): void {
		$link = $this->storedLink(role: 'initiator');
		$link->setId(12);
		$this->links->method('findByObjectContactAndRole')->willReturn($link);
		$this->links->expects($this->never())->method('findByObjectUuid');
		$this->contacts->expects($this->once())->method('unlinkContact')->with(12);

		$this->assertSame(1, $this->service->removeLinks(ref: new PersonLinkRef(objectUuid: 'case-1', contactUid: 'user:jan', role: 'initiator')));
	}//end testUnlinkWithARoleRemovesOnlyThatLink()

	/**
	 * A person with no link on the object is a 404.
	 *
	 * @return void
	 */
	public function testUnlinkingSomeoneWhoIsNotLinkedIs404(): void {
		$this->links->method('findByObjectUuid')->willReturn([]);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);

		$this->service->removeLinks(ref: new PersonLinkRef(objectUuid: 'case-1', contactUid: 'user:ghost', role: null));
	}//end testUnlinkingSomeoneWhoIsNotLinkedIs404()

	/**
	 * The listing groups by role, names the vocabulary and buckets a link without a role.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-schema-declares-the-roles-its-objects-carry
	 */
	public function testTheListingGroupsByRoleAndCarriesTheVocabulary(): void {
		$this->schemaDeclaresRoles();
		$this->contacts->method('getContactsForObject')->willReturn(
			[
				'results' => [
					['contactUid' => 'user:jan', 'role' => 'handler'],
					['contactUid' => 'jan-uid', 'role' => 'initiator'],
					['contactUid' => 'piet-uid', 'role' => null],
				],
				'total' => 3,
			]
		);

		$listing = $this->service->listForObject(objectUuid: 'case-1', schemaId: 7);

		$this->assertSame(3, $listing['total']);
		$this->assertSame(['handler', 'initiator', 'other'], array_keys($listing['byRole']));
		$this->assertCount(1, $listing['byRole']['other']);
		$this->assertSame(['initiator', 'handler'], array_column($listing['roles'], 'key'));
	}//end testTheListingGroupsByRoleAndCarriesTheVocabulary()

	/**
	 * An object whose schema cannot be read still lists, with no vocabulary.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaMeansNoVocabulary(): void {
		$this->schemas->method('find')->willThrowException(new Exception('gone'));
		$this->contacts->method('getContactsForObject')->willReturn(['results' => [], 'total' => 0]);

		$listing = $this->service->listForObject(objectUuid: 'case-1', schemaId: 7);

		$this->assertSame([], $listing['roles']);
		$this->assertSame([], $listing['byRole']);
	}//end testAnUnreadableSchemaMeansNoVocabulary()
}//end class
