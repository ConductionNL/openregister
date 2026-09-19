<?php

/**
 * OpenRegister - the archive and freeze write guard.
 *
 * Pins the refusal an archived or frozen object answers a write with, at the
 * place the refusal actually lives: `SaveObject::saveObject()`. Asserting only
 * that `ObjectStateWriteException::archived()` builds a nice sentence would
 * prove the sentence and nothing about whether anything ever says it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Twig\Loader\ArrayLoader;

/**
 * The write guard for the archived and frozen states.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @covers \OCA\OpenRegister\Exception\ObjectStateWriteException
 */
class SaveObjectArchiveGuardTest extends TestCase {

	private const UUID = 'obj-99999999-8888-7777-6666-555555555555';

	/**
	 * The mapper the guard reads the existing object from.
	 *
	 * @var MagicMapper|null
	 */
	private ?MagicMapper $mapper = null;

	/**
	 * Build a SaveObject whose `find()` answers with this object.
	 *
	 * @param ObjectEntity $existing The stored object the guard will inspect.
	 *
	 * @return SaveObject The handler under test.
	 */
	private function handler(ObjectEntity $existing): SaveObject {
		$this->mapper = $this->createMock(originalClassName: MagicMapper::class);
		$this->mapper->method('find')->willReturn($existing);

		return new SaveObject(
			$this->mapper,
			$this->createMock(MagicMapper::class),
			$this->createMock(MetadataHydrationHandler::class),
			$this->createMock(FilePropertyHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler::class),
			$this->createMock(IUserSession::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(PropertyRbacHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\TranslationProjectionService::class),
			$this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\OpenRegister\Service\TmloService::class),
			$this->createMock(\OCA\OpenRegister\Service\File\FolderManagementHandler::class),
			new ArrayLoader()
		);
	}//end handler()

	/**
	 * A session belonging to a named user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUserSession The session.
	 */
	private function sessionFor(string $uid): IUserSession {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end sessionFor()

	/**
	 * A stored object with a title.
	 *
	 * @return ObjectEntity The object.
	 */
	private function storedObject(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::UUID);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['title' => 'Bouwvergunning']);

		return $object;
	}//end storedObject()

	/**
	 * A schema and register pair the save path can resolve.
	 *
	 * @return array{0: Schema, 1: Register} The pair.
	 */
	private function context(): array {
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('zaak');
		$schema->setTitle('Zaak');

		$register = new Register();
		$register->setId(1);
		$register->setSlug('zaken');

		return [$schema, $register];
	}//end context()

	/**
	 * An edit to an archived object is refused, and the refusal names the
	 * archive, the archiver and the date.
	 *
	 * @return void
	 */
	public function testWriteToAnArchivedObjectIsRefusedAndSaysWhy(): void {
		$object = $this->storedObject();
		$object->archive(userSession: $this->sessionFor(uid: 'anna'), reason: 'afgehandeld');

		[$schema, $register] = $this->context();

		$this->expectException(ObjectStateWriteException::class);
		$this->expectExceptionMessageMatches('/archived by anna/');

		try {
			$this->handler(existing: $object)->saveObject(
				data: ['title' => 'gewijzigd'],
				schema: $schema,
				register: $register,
				uuid: self::UUID
			);
		} finally {
			// The object is unchanged: the guard fires before anything is
			// written, which is what "the object is unchanged" has to mean.
			$this->assertSame('Bouwvergunning', $object->getObject()['title']);
		}
	}//end testWriteToAnArchivedObjectIsRefusedAndSaysWhy()

	/**
	 * The refusal carries the archiver and the time as data, not only inside
	 * the sentence, so a caller can render its own message.
	 *
	 * @return void
	 */
	public function testTheRefusalCarriesTheArchiverAndTheTime(): void {
		$object = $this->storedObject();
		$object->archive(userSession: $this->sessionFor(uid: 'anna'), reason: 'afgehandeld');

		$refusal = ObjectStateWriteException::archived($object);

		$this->assertSame('archived', $refusal->getState());
		$this->assertSame('anna', $refusal->getActor());
		$this->assertNotNull($refusal->getAt());
		$this->assertSame(409, ObjectStateWriteException::HTTP_STATUS);
	}//end testTheRefusalCarriesTheArchiverAndTheTime()

	/**
	 * An edit to a frozen object is refused too, and the refusal names the
	 * lifecycle state that froze it.
	 *
	 * A freeze declared by a state happens without anybody clicking anything,
	 * so a refusal that does not name the state reads as the system being
	 * broken rather than as the phase having closed.
	 *
	 * @return void
	 */
	public function testWriteToAFrozenObjectIsRefusedAndNamesTheState(): void {
		$object = $this->storedObject();
		$object->freeze(
			userSession: $this->sessionFor(uid: 'bert'),
			reason: 'fase afgesloten',
			state: 'in_bezwaar'
		);

		[$schema, $register] = $this->context();

		$this->expectException(ObjectStateWriteException::class);
		$this->expectExceptionMessageMatches('/frozen by bert.*in_bezwaar/');

		$this->handler(existing: $object)->saveObject(
			data: ['title' => 'gewijzigd'],
			schema: $schema,
			register: $register,
			uuid: self::UUID
		);
	}//end testWriteToAFrozenObjectIsRefusedAndNamesTheState()

	/**
	 * An object in neither state is not refused by this guard.
	 *
	 * The control. Without it, a guard that threw on every write would pass
	 * both tests above and be indistinguishable from one that works.
	 *
	 * @return void
	 */
	public function testAnOpenObjectIsNotRefusedByThisGuard(): void {
		$object = $this->storedObject();
		[$schema, $register] = $this->context();

		$refused = false;
		try {
			$this->handler(existing: $object)->saveObject(
				data: ['title' => 'gewijzigd'],
				schema: $schema,
				register: $register,
				uuid: self::UUID
			);
		} catch (ObjectStateWriteException $e) {
			$refused = true;
		} catch (\Throwable $e) {
			// The save path runs on far more collaborators than this test
			// wires, so it is expected to fail somewhere further down. What
			// matters here is only that it did not fail on the state guard.
			$refused = false;
		}

		$this->assertFalse($refused);
	}//end testAnOpenObjectIsNotRefusedByThisGuard()
}//end class
