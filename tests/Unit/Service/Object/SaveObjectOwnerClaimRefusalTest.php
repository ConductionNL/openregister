<?php

/**
 * OpenRegister - a save cannot claim an owner
 *
 * Ownership is derived from the authenticated actor. A write that names a
 * different owner is REFUSED, because a caller that can set the owner can grant
 * itself edit rights on somebody else's record: the owner is admitted
 * unconditionally by every enforcement path.
 *
 * THESE TESTS GO THROUGH THE REAL CALLERS, not through the guard. `setSelfMetadata()`
 * has exactly two callers — `prepareObjectForCreation()` and
 * `prepareObjectForUpdate()` — and they are what the create and the update paths
 * run. A test that invoked the refusal helper directly would pass just as well
 * with the call site deleted, which is the failure this project hits most.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
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
 * The owner cannot be moved through a save.
 */
class SaveObjectOwnerClaimRefusalTest extends TestCase {

	/**
	 * The session, which is where an owner comes from.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * System under test.
	 *
	 * @var SaveObject
	 */
	private SaveObject $handler;

	/**
	 * Build a SaveObject with mocked collaborators.
	 *
	 * None of them is reached by these tests: the refusal is the first statement
	 * of the first call both preparers make, so a refused write touches nothing.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);

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
	}//end setUp()

	/**
	 * Sign a user in for the duration of one test.
	 *
	 * @param string|null $uid The acting user, or null for a background context.
	 *
	 * @return void
	 */
	private function signIn(?string $uid): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Run the real update preparation, which is what a PUT runs.
	 *
	 * @param ObjectEntity $entity The stored record.
	 * @param array $selfData The caller's `@self` block.
	 *
	 * @return void
	 */
	private function prepareUpdate(ObjectEntity $entity, array $selfData): void {
		$method = new ReflectionMethod(SaveObject::class, 'prepareObjectForUpdate');
		$method->setAccessible(true);
		$method->invoke($this->handler, $entity, new Schema(), [], $selfData, null, null);
	}//end prepareUpdate()

	/**
	 * Run the real creation preparation, which is what a POST runs.
	 *
	 * @param ObjectEntity $entity The new record.
	 * @param array $selfData The caller's `@self` block.
	 *
	 * @return void
	 */
	private function prepareCreate(ObjectEntity $entity, array $selfData): void {
		$method = new ReflectionMethod(SaveObject::class, 'prepareObjectForCreation');
		$method->setAccessible(true);
		$method->invoke($this->handler, $entity, new Schema(), [], $selfData, true, null, false);
	}//end prepareCreate()

	/**
	 * An update that names somebody else as owner is refused.
	 *
	 * @return void
	 */
	public function testUpdateNamingAnotherOwnerIsRefused(): void {
		$this->signIn('bob');

		$entity = new ObjectEntity();
		$entity->setOwner('alice');

		$this->expectException(NotAuthorizedException::class);
		$this->prepareUpdate($entity, ['owner' => 'carol']);
	}//end testUpdateNamingAnotherOwnerIsRefused()

	/**
	 * A create that names somebody else as owner is refused.
	 *
	 * This is the vector: on a create there is no stored owner to compare
	 * against, so an accepted value would be the owner the record is born with.
	 *
	 * @return void
	 */
	public function testCreateNamingAnotherOwnerIsRefused(): void {
		$this->signIn('bob');

		$this->expectException(NotAuthorizedException::class);
		$this->prepareCreate(new ObjectEntity(), ['owner' => 'alice']);
	}//end testCreateNamingAnotherOwnerIsRefused()

	/**
	 * The refusal names the way a record does change hands.
	 *
	 * A refusal that does not say what to do instead is read as a bug in the
	 * caller, and the caller then goes looking for a field that will work.
	 *
	 * @return void
	 */
	public function testRefusalNamesTheOwnershipEndpoint(): void {
		$this->signIn('bob');

		$entity = new ObjectEntity();
		$entity->setOwner('alice');

		$this->expectExceptionMessageMatches('/ownership endpoint/');
		$this->prepareUpdate($entity, ['owner' => 'carol']);
	}//end testRefusalNamesTheOwnershipEndpoint()

	/**
	 * Echoing back the stored owner is not a claim.
	 *
	 * Every read emits `@self.owner`, so the ordinary GET-edit-PUT round trip
	 * sends it straight back. Refusing that would break eleven consuming apps
	 * for asking for no change at all — so the refusal must NOT fire here, and
	 * the preparation runs on past it.
	 *
	 * @return void
	 */
	public function testEchoingTheStoredOwnerIsNotRefused(): void {
		$this->signIn('bob');

		$entity = new ObjectEntity();
		$entity->setOwner('alice');

		$refused = false;
		try {
			$this->prepareUpdate($entity, ['owner' => 'alice']);
		} catch (NotAuthorizedException $e) {
			$refused = true;
		} catch (\Throwable $e) {
			// Any other failure comes from the collaborators this test mocks
			// away, which is past the refusal and therefore proof it did not
			// fire.
			$refused = false;
		}

		$this->assertFalse($refused, 'An echoed owner must not be refused');
	}//end testEchoingTheStoredOwnerIsNotRefused()

	/**
	 * Naming yourself as owner is not a claim either.
	 *
	 * @return void
	 */
	public function testNamingYourselfIsNotRefused(): void {
		$this->signIn('alice');

		$entity = new ObjectEntity();
		$entity->setOwner('alice');

		$refused = false;
		try {
			$this->prepareUpdate($entity, ['owner' => 'alice']);
		} catch (NotAuthorizedException $e) {
			$refused = true;
		} catch (\Throwable $e) {
			$refused = false;
		}

		$this->assertFalse($refused, 'The acting user naming themselves must not be refused');
	}//end testNamingYourselfIsNotRefused()

	/**
	 * A background context is not refused.
	 *
	 * An import, a migration or a job has no acting user to derive an owner
	 * from, and the owner it carries is the one being restored. That path is
	 * unauthenticated by construction and is not reachable from a request.
	 *
	 * @return void
	 */
	public function testABackgroundContextIsNotRefused(): void {
		$this->signIn(null);

		$refused = false;
		try {
			$this->prepareCreate(new ObjectEntity(), ['owner' => 'alice']);
		} catch (NotAuthorizedException $e) {
			$refused = true;
		} catch (\Throwable $e) {
			$refused = false;
		}

		$this->assertFalse($refused, 'A session-less write must keep restoring owners');
	}//end testABackgroundContextIsNotRefused()

	/**
	 * An expanded owner object is an echo, not a claim.
	 *
	 * A read can render the owner as a user object, and sending that back is the
	 * same round trip as the string case.
	 *
	 * @return void
	 */
	public function testAnExpandedOwnerIsNotRefused(): void {
		$this->signIn('bob');

		$entity = new ObjectEntity();
		$entity->setOwner('alice');

		$refused = false;
		try {
			$this->prepareUpdate($entity, ['owner' => ['id' => 'alice', 'displayName' => 'Alice']]);
		} catch (NotAuthorizedException $e) {
			$refused = true;
		} catch (\Throwable $e) {
			$refused = false;
		}

		$this->assertFalse($refused, 'An expanded owner must not be refused');
	}//end testAnExpandedOwnerIsNotRefused()

}//end class
