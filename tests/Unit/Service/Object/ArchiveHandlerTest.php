<?php

/**
 * The archive and freeze verbs: who may use them, what they write, and what
 * they refuse.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ArchiveNotOfferedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\ArchiveHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\ArchiveHandler
 */
final class ArchiveHandlerTest extends TestCase {

	private const OBJ = 'obj-11111111-2222-3333-4444-555555555555';

	private MagicMapper $magic;

	private AuditTrailMapper $audit;

	private PermissionHandler $permissions;

	private IUserSession $session;

	/**
	 * Wire a handler over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->magic = $this->createMock(originalClassName: MagicMapper::class);
		$this->audit = $this->createMock(originalClassName: AuditTrailMapper::class);
		$this->permissions = $this->createMock(originalClassName: PermissionHandler::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('anna');
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->session->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return ArchiveHandler The handler.
	 */
	private function handler(): ArchiveHandler {
		return new ArchiveHandler(
			$this->magic,
			$this->audit,
			$this->permissions,
			$this->session,
			$this->createMock(LoggerInterface::class)
		);
	}//end handler()

	/**
	 * A plain open object.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJ);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setOwner('owner-uid');
		$object->setObject(['title' => 'Bouwvergunning']);

		return $object;
	}//end object()

	/**
	 * Make the lookup resolve to this object under a schema that does or does
	 * not offer archiving.
	 *
	 * @param ObjectEntity $object The object to resolve to.
	 * @param bool $archivingEnabled Whether the schema declares the annotation.
	 *
	 * @return void
	 */
	private function resolvesTo(ObjectEntity $object, bool $archivingEnabled = true): void {
		// A REAL Schema, not a double. `isArchivingEnabled()` reads the stored
		// configuration, and `setConfiguration()` drops any key outside
		// ANNOTATION_VOCABULARY in silence — so a mocked schema would answer
		// true for an annotation the real entity cannot even keep. This is the
		// one assertion that the vocabulary entry works, and a double cannot
		// make it.
		$schema = new Schema();
		$schema->setTitle('Zaak');
		if ($archivingEnabled === true) {
			$schema->setConfiguration([Schema::ARCHIVE_ANNOTATION => ['enabled' => true]]);
		}

		$this->magic->method('findAcrossAllSources')->willReturn(
			[
				'object' => $object,
				'register' => $this->createMock(originalClassName: Register::class),
				'schema' => $schema,
			]
		);
	}//end resolvesTo()

	/**
	 * Make the persist step hand back whatever it was given.
	 *
	 * @return void
	 */
	private function persistsVerbatim(): void {
		$this->magic->method('updateObjectEntity')->willReturnCallback(
			static fn (ObjectEntity $entity): ObjectEntity => $entity
		);
	}//end persistsVerbatim()

	/**
	 * Archiving names the archiver, the time and the reason, and leaves the
	 * object's own data exactly as it was.
	 *
	 * @return void
	 */
	public function testArchiveWritesTheMarkerAndLeavesTheDataAlone(): void {
		$object = $this->object();
		$this->resolvesTo(object: $object);
		$this->persistsVerbatim();

		$result = $this->handler()->archive(identifier: self::OBJ, reason: 'afgehandeld');

		$this->assertSame('anna', $result['archived']['by']);
		$this->assertSame('afgehandeld', $result['archived']['reason']);
		$this->assertNotEmpty($result['archived']['at']);
		$this->assertTrue($object->isArchived());

		// The data is what the audit trail and the versions are about, so
		// archiving must not touch it. A marker written into the object's own
		// properties would produce a new version of the data on every archive.
		// `getObject()` injects the uuid as `id`, so compare the business keys
		// rather than the whole bag.
		$data = $object->getObject();
		unset($data['id']);
		$this->assertSame(['title' => 'Bouwvergunning'], $data);
	}//end testArchiveWritesTheMarkerAndLeavesTheDataAlone()

	/**
	 * Archiving writes one audit entry, named `archive`.
	 *
	 * @return void
	 */
	public function testArchiveWritesAnAuditEntry(): void {
		$this->resolvesTo(object: $this->object());
		$this->persistsVerbatim();

		$this->audit->expects($this->once())
			->method('createAuditTrail')
			->with(
				$this->anything(),
				$this->anything(),
				'archive'
			);

		$this->handler()->archive(identifier: self::OBJ);
	}//end testArchiveWritesAnAuditEntry()

	/**
	 * Restoring clears the marker and writes a second audit entry.
	 *
	 * @return void
	 */
	public function testUnarchiveClearsTheMarkerAndRecordsIt(): void {
		$object = $this->object();
		$object->archive(userSession: $this->session, reason: 'afgehandeld');
		$this->resolvesTo(object: $object);
		$this->persistsVerbatim();

		$this->audit->expects($this->once())
			->method('createAuditTrail')
			->with($this->anything(), $this->anything(), 'unarchive');

		$result = $this->handler()->unarchive(identifier: self::OBJ);

		$this->assertNull($result['archived']);
		$this->assertFalse($object->isArchived());
	}//end testUnarchiveClearsTheMarkerAndRecordsIt()

	/**
	 * Archiving an already-archived object does not rewrite who archived it.
	 *
	 * The marker exists to say who filed the record and when. A second archive
	 * that overwrote it would destroy that, and would do so silently.
	 *
	 * @return void
	 */
	public function testArchivingTwiceKeepsTheOriginalArchiver(): void {
		$object = $this->object();

		$first = $this->createMock(originalClassName: IUser::class);
		$first->method('getUID')->willReturn('bert');
		$firstSession = $this->createMock(originalClassName: IUserSession::class);
		$firstSession->method('getUser')->willReturn($first);
		$object->archive(userSession: $firstSession, reason: 'eerste');

		$this->resolvesTo(object: $object);
		$this->audit->expects($this->never())->method('createAuditTrail');

		$result = $this->handler()->archive(identifier: self::OBJ, reason: 'tweede');

		$this->assertSame('bert', $result['archived']['by']);
		$this->assertSame('eerste', $result['archived']['reason']);
	}//end testArchivingTwiceKeepsTheOriginalArchiver()

	/**
	 * A schema that does not declare `x-openregister-archive` refuses, and
	 * nothing is written.
	 *
	 * @return void
	 */
	public function testSchemaWithoutTheAnnotationRefuses(): void {
		$object = $this->object();
		$this->resolvesTo(object: $object, archivingEnabled: false);
		$this->magic->expects($this->never())->method('updateObjectEntity');

		$this->expectException(ArchiveNotOfferedException::class);

		try {
			$this->handler()->archive(identifier: self::OBJ);
		} finally {
			$this->assertFalse($object->isArchived());
		}
	}//end testSchemaWithoutTheAnnotationRefuses()

	/**
	 * The refusal is a 422, so a caller is told to declare the annotation
	 * rather than sent looking for a missing record.
	 *
	 * @return void
	 */
	public function testTheAnnotationRefusalCarriesFourTwentyTwo(): void {
		$this->assertSame(422, ArchiveNotOfferedException::HTTP_STATUS);
	}//end testTheAnnotationRefusalCarriesFourTwentyTwo()

	/**
	 * A caller who may read but not update cannot archive, and nothing is
	 * written.
	 *
	 * @return void
	 */
	public function testCallerWithoutUpdateCannotArchive(): void {
		$object = $this->object();
		$this->resolvesTo(object: $object);

		$this->permissions->method('checkPermission')
			->willThrowException(new NotAuthorizedException(message: 'nope'));
		$this->magic->expects($this->never())->method('updateObjectEntity');

		$this->expectException(NotAuthorizedException::class);

		try {
			$this->handler()->archive(identifier: self::OBJ);
		} finally {
			$this->assertFalse($object->isArchived());
		}
	}//end testCallerWithoutUpdateCannotArchive()

	/**
	 * The gate asks for `update`, not `delete`.
	 *
	 * Archiving is not a step towards deletion, and a gate that asked for
	 * `delete` would mean the people who finish a case are exactly the people
	 * who cannot file it (ADR-010). Asserting the action NAME because that is
	 * the thing a later refactor can silently change.
	 *
	 * @return void
	 */
	public function testTheGateAsksForUpdate(): void {
		$this->resolvesTo(object: $this->object());
		$this->persistsVerbatim();

		$this->permissions->expects($this->once())
			->method('checkPermission')
			->with(
				$this->anything(),
				'update',
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything()
			);

		$this->handler()->archive(identifier: self::OBJ);
	}//end testTheGateAsksForUpdate()

	/**
	 * Freezing writes its own marker, and a lifecycle state that declared the
	 * freeze is named on it.
	 *
	 * @return void
	 */
	public function testFreezeWritesItsOwnMarkerAndNamesTheState(): void {
		$object = $this->object();
		$this->resolvesTo(object: $object);
		$this->persistsVerbatim();

		$result = $this->handler()->freeze(identifier: self::OBJ, reason: 'bezwaar', state: 'in_bezwaar');

		$this->assertSame('anna', $result['frozen']['by']);
		$this->assertSame('in_bezwaar', $result['frozen']['state']);
		$this->assertTrue($object->isFrozen());

		// Frozen is not archived. The whole reason they are two markers is that
		// a frozen object stays in the working views and an archived one does
		// not; a freeze that also set the archive marker would hide a zaak in
		// bezwaar from the list it has to stay on.
		$this->assertFalse($object->isArchived());
	}//end testFreezeWritesItsOwnMarkerAndNamesTheState()

	/**
	 * Unfreezing clears the freeze and records it.
	 *
	 * @return void
	 */
	public function testUnfreezeClearsTheMarker(): void {
		$object = $this->object();
		$object->freeze(userSession: $this->session, reason: 'bezwaar');
		$this->resolvesTo(object: $object);
		$this->persistsVerbatim();

		$this->audit->expects($this->once())
			->method('createAuditTrail')
			->with($this->anything(), $this->anything(), 'unfreeze');

		$result = $this->handler()->unfreeze(identifier: self::OBJ);

		$this->assertNull($result['frozen']);
		$this->assertFalse($object->isFrozen());
	}//end testUnfreezeClearsTheMarker()
}//end class
