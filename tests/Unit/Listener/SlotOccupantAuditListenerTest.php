<?php

/**
 * Replacing the occupant of a slot is on the trail (REQ-GSR-001, task 1.3).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\PersonLinkedEvent;
use OCA\OpenRegister\Event\PersonLinkUpdatedEvent;
use OCA\OpenRegister\Event\PersonUnlinkedEvent;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Listener\SlotOccupantAuditListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Listener\SlotOccupantAuditListener
 * @uses   \OCA\OpenRegister\Service\Rbac\PartyRoleSlotResolver
 */
class SlotOccupantAuditListenerTest extends TestCase {

	/**
	 * The object every case is about.
	 *
	 * @var string
	 */
	private const OBJECT = '5107a000-0000-4000-8000-0000000000aa';

	/**
	 * The audit events the trail was asked to record.
	 *
	 * @var array<int, ObjectAuditEvent>
	 */
	private array $recorded = [];

	/**
	 * A user's link to the object in a role.
	 *
	 * @param string $userId The user.
	 * @param string $role   The role.
	 *
	 * @return ContactLink The link.
	 */
	private static function userLink(string $userId, string $role = 'behandelaar'): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid(self::OBJECT);
		$link->setRegisterId(4);
		$link->setSchemaId(12);
		$link->setContactUid(ContactLink::USER_UID_PREFIX . $userId);
		$link->setUserId($userId);
		$link->setRole($role);

		return $link;
	}//end userLink()

	/**
	 * The case with its own block.
	 *
	 * @param array|null $authorization The object's own block.
	 *
	 * @return ObjectEntity The case.
	 */
	private static function caseWith(?array $authorization): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(77);
		$object->setUuid(self::OBJECT);
		$object->setRegister('4');
		$object->setSchema('12');
		$object->setAuthorization($authorization);

		return $object;
	}//end caseWith()

	/**
	 * A listener over an object layer answering with this object (or throwing), recording into $recorded.
	 *
	 * @param ObjectEntity|null    $object The object the layer returns; null makes it throw.
	 * @param LoggerInterface|null $logger The logger.
	 * @param bool                 $failAudit Whether the trail throws.
	 *
	 * @return SlotOccupantAuditListener The listener.
	 */
	private function listener(?ObjectEntity $object, ?LoggerInterface $logger = null, bool $failAudit = false): SlotOccupantAuditListener {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		if ($object === null) {
			$objects->method('findSilent')->willThrowException(new RuntimeException('gone'));
		} else {
			$objects->method('findSilent')->with(self::OBJECT, null, false, false)->willReturn($object);
		}

		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('recordObjectEvent')->willReturnCallback(
			function (ObjectAuditEvent $event) use ($failAudit): AuditTrail {
				if ($failAudit === true) {
					throw new RuntimeException('trail unavailable');
				}

				$this->recorded[] = $event;
				return new AuditTrail();
			}
		);

		return new SlotOccupantAuditListener(objects: $objects, audit: $audit, logger: ($logger ?? new NullLogger()));
	}//end listener()

	/**
	 * Setting up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->recorded = [];
	}//end setUp()

	/**
	 * Replacing the occupant writes one entry for the one who went and one for the one who came.
	 *
	 * @return void
	 */
	public function testReplacingTheOccupantIsAudited(): void {
		$case = self::caseWith(['update' => [['partyRole' => 'behandelaar']]]);
		$listener = $this->listener(object: $case);

		$listener->handle(new PersonUnlinkedEvent(link: self::userLink(userId: 'ana')));
		$listener->handle(new PersonLinkedEvent(link: self::userLink(userId: 'bob')));

		$this->assertCount(2, $this->recorded);
		[$went, $came] = $this->recorded;

		$this->assertSame(SlotOccupantAuditListener::ACTION, $went->action);
		$this->assertSame($case, $went->object);
		$this->assertSame('unlinked', $went->context['change']);
		$this->assertSame('ana', $went->context['user']);
		$this->assertSame('behandelaar', $went->context['role']);
		$this->assertSame(['update' => ['behandelaar']], $went->context['slots']);
		$this->assertTrue($went->context['objectRead']);

		$this->assertSame('linked', $came->context['change']);
		$this->assertSame('bob', $came->context['user']);

		// The grant itself was not touched by any of it.
		$this->assertSame(['update' => [['partyRole' => 'behandelaar']]], $case->getAuthorization());
	}//end testReplacingTheOccupantIsAudited()

	/**
	 * A changed role or period is an occupant change too.
	 *
	 * @return void
	 */
	public function testAChangedLinkIsAudited(): void {
		$listener = $this->listener(object: self::caseWith(['update' => [['partyRole' => 'behandelaar']]]));
		$link = self::userLink(userId: 'ana', role: 'adviseur');
		$link->setValidUntil(new \DateTime('2001-01-31'));

		$listener->handle(new PersonLinkUpdatedEvent(link: $link));

		$this->assertCount(1, $this->recorded);
		$this->assertSame('updated', $this->recorded[0]->context['change']);
		$this->assertSame('adviseur', $this->recorded[0]->context['role']);
		$this->assertSame('2001-01-31', $this->recorded[0]->context['validUntil']);
	}//end testAChangedLinkIsAudited()

	/**
	 * On an object that names no slot, a role change moves no access and writes nothing.
	 *
	 * @return void
	 */
	public function testNoSlotOnTheObjectWritesNothing(): void {
		$listener = $this->listener(object: self::caseWith(['update' => ['behandelaars']]));
		$listener->handle(new PersonLinkedEvent(link: self::userLink(userId: 'ana')));

		$listener = $this->listener(object: self::caseWith(null));
		$listener->handle(new PersonLinkedEvent(link: self::userLink(userId: 'ana')));

		$this->assertSame([], $this->recorded);
	}//end testNoSlotOnTheObjectWritesNothing()

	/**
	 * A party without an account, or a contact, never holds slot access, so nothing is written.
	 *
	 * @return void
	 */
	public function testAPartyOrContactLinkWritesNothing(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->never())->method('findSilent');
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->expects($this->never())->method('recordObjectEvent');
		$listener = new SlotOccupantAuditListener(objects: $objects, audit: $audit, logger: new NullLogger());

		$party = new ContactLink();
		$party->setObjectUuid(self::OBJECT);
		$party->setPartyUuid('p-1');
		$party->setRole('behandelaar');
		$listener->handle(new PersonLinkedEvent(link: $party));

		$contact = new ContactLink();
		$contact->setObjectUuid(self::OBJECT);
		$contact->setContactUid('vcard-1');
		$contact->setRole('behandelaar');
		$listener->handle(new PersonUnlinkedEvent(link: $contact));

		$listener->handle(new Event());
	}//end testAPartyOrContactLinkWritesNothing()

	/**
	 * An object that cannot be read is still recorded, against the link's object.
	 *
	 * @return void
	 */
	public function testAnUnreadableObjectIsStillRecorded(): void {
		$this->listener(object: null)->handle(new PersonLinkedEvent(link: self::userLink(userId: 'ana')));

		$this->assertCount(1, $this->recorded);
		$entry = $this->recorded[0];
		$this->assertSame(self::OBJECT, $entry->object->getUuid());
		$this->assertSame('4', $entry->object->getRegister());
		$this->assertSame('12', $entry->object->getSchema());
		$this->assertFalse($entry->context['objectRead']);
		$this->assertSame([], $entry->context['slots']);
	}//end testAnUnreadableObjectIsStillRecorded()

	/**
	 * A trail that fails is logged and never undoes or breaks the link change.
	 *
	 * @return void
	 */
	public function testAFailingTrailIsLoggedNotThrown(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$this->listener(object: self::caseWith(['update' => [['partyRole' => 'behandelaar']]]), logger: $logger, failAudit: true)
			->handle(new PersonLinkedEvent(link: self::userLink(userId: 'ana')));

		$this->assertSame([], $this->recorded);
	}//end testAFailingTrailIsLoggedNotThrown()
}//end class
