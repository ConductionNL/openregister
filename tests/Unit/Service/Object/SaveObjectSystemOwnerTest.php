<?php

/**
 * OpenRegister - SaveObject system-owner attribution test
 *
 * Pins the openregister#1617 contract on `SaveObject::applyOwnerAttribution()`:
 *   - Logged-in writes set `_owner` to the user UID (legacy behaviour).
 *   - Session-less writes attribute to the configured system identifier.
 *   - An explicit pre-existing owner is never overwritten.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;

/**
 * Unit tests for {@see SaveObject::applyOwnerAttribution()}.
 *
 * The helper is exercised via reflection because the production call sites
 * (prepareObjectForCreation) carry many heavyweight dependencies that are not
 * relevant to this single contract.
 */
class SaveObjectSystemOwnerTest extends TestCase {

	/**
	 * IUserSession mock - controls whether a "session user" is active.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * OrganisationService mock - returns the configured system identifier.
	 *
	 * @var OrganisationService&MockObject
	 */
	private OrganisationService $organisationService;

	/**
	 * System under test.
	 *
	 * @var SaveObject
	 */
	private SaveObject $handler;

	/**
	 * Wire mocks + build a SaveObject instance with mostly-mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->organisationService = $this->createMock(OrganisationService::class);

		$this->handler = new SaveObject(
			$this->createMock(MagicMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(MetadataHydrationHandler::class),
			$this->createMock(FilePropertyHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler::class),
			$this->userSession,
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->organisationService,
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
	}//end setUp()

	/**
	 * Invoke the private applyOwnerAttribution() helper on the entity.
	 *
	 * @param ObjectEntity $entity Entity to attribute.
	 *
	 * @return void
	 */
	private function invokeApply(ObjectEntity $entity): void {
		$method = new ReflectionMethod(SaveObject::class, 'applyOwnerAttribution');
		$method->setAccessible(true);
		$method->invoke($this->handler, $entity);
	}//end invokeApply()

	/**
	 * Logged-in writes attribute the user UID (regression - legacy behaviour).
	 *
	 * @return void
	 */
	public function testLoggedInWriteSetsUserUid(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		// OrganisationService MUST NOT be touched when a user is present.
		$this->organisationService
			->expects($this->never())
			->method('getSystemUserId');

		$entity = new ObjectEntity();
		$this->invokeApply($entity);

		$this->assertSame('alice', $entity->getOwner());
	}//end testLoggedInWriteSetsUserUid()

	/**
	 * Session-less write with empty owner falls back to the system identifier.
	 *
	 * @return void
	 */
	public function testSessionlessWriteFallsBackToSystemIdentifier(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->organisationService
			->expects($this->once())
			->method('getSystemUserId')
			->willReturn('__system__');

		$entity = new ObjectEntity();
		$this->invokeApply($entity);

		$this->assertSame('__system__', $entity->getOwner());
	}//end testSessionlessWriteFallsBackToSystemIdentifier()

	/**
	 * Session-less write honours an operator's configured override.
	 *
	 * @return void
	 */
	public function testSessionlessWriteHonoursConfiguredOverride(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->organisationService
			->method('getSystemUserId')
			->willReturn('cron-bot');

		$entity = new ObjectEntity();
		$this->invokeApply($entity);

		$this->assertSame('cron-bot', $entity->getOwner());
	}//end testSessionlessWriteHonoursConfiguredOverride()

	/**
	 * A pre-existing non-empty owner is preserved on session-less writes
	 * (e.g. an explicit `@self.owner` set by the caller).
	 *
	 * @return void
	 */
	public function testSessionlessWritePreservesExplicitOwner(): void {
		$this->userSession->method('getUser')->willReturn(null);

		// OrganisationService MUST NOT be touched - owner is already set.
		$this->organisationService
			->expects($this->never())
			->method('getSystemUserId');

		$entity = new ObjectEntity();
		$entity->setOwner('explicit-owner');
		$this->invokeApply($entity);

		$this->assertSame('explicit-owner', $entity->getOwner());
	}//end testSessionlessWritePreservesExplicitOwner()

	/**
	 * Empty-string owner triggers the system-context fallback
	 * (defends against callers passing `''` explicitly).
	 *
	 * @return void
	 */
	public function testSessionlessWriteOverwritesEmptyStringOwner(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->organisationService
			->method('getSystemUserId')
			->willReturn('__system__');

		$entity = new ObjectEntity();
		$entity->setOwner('');
		$this->invokeApply($entity);

		$this->assertSame('__system__', $entity->getOwner());
	}//end testSessionlessWriteOverwritesEmptyStringOwner()
}//end class
