<?php

/**
 * Unit tests for writing through the organisation projection.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\OrganisationObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\WritableObjectSourceProvider;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Locks the write path, and above all what it refuses.
 *
 * The projection exists to retire the leaf-app `organization` schemas, and it
 * could not while it was read-only: the apps that declared those schemas CREATE
 * organisations. The write therefore had to exist. Everything below is about it
 * going through the organisation lifecycle rather than around it, and about the
 * three refusals — no name, not the administrator, and delete — that are the
 * reason a write here is not simply a second, thinner path to a tenant.
 */
class OrganisationObjectSourceWriteTest extends TestCase {

	/**
	 * The organisation mapper double.
	 *
	 * @var OrganisationMapper&MockObject
	 */
	private $organisationMapper;

	/**
	 * The organisation lifecycle double.
	 *
	 * @var OrganisationService&MockObject
	 */
	private $organisationService;

	/**
	 * The acting user's session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * Wire fresh doubles for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->userSession = $this->createMock(IUserSession::class);

	}//end setUp()

	/**
	 * Build the provider over the current doubles.
	 *
	 * @return OrganisationObjectSourceProvider The provider under test.
	 */
	private function provider(): OrganisationObjectSourceProvider {
		return new OrganisationObjectSourceProvider(
			organisationMapper: $this->organisationMapper,
			userSession: $this->userSession,
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
			organisationService: $this->organisationService
		);

	}//end provider()

	/**
	 * Say a user is logged in.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function actingUser(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end actingUser()

	/**
	 * Build an organisation.
	 *
	 * @param string      $uuid       The uuid.
	 * @param string      $name       The name.
	 * @param string|null $mergedInto The uuid it was merged into, if any.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisation(string $uuid, string $name, ?string $mergedInto = null): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setName($name);
		$organisation->setMergedInto($mergedInto);

		return $organisation;

	}//end organisation()

	/**
	 * The dispatch in SaveObject/DeleteObject delegates only to a provider that
	 * implements this interface, so declaring it is load-bearing rather than
	 * documentation.
	 *
	 * @return void
	 */
	public function testTheProviderDeclaresItselfWritable(): void {
		$this->assertInstanceOf(WritableObjectSourceProvider::class, $this->provider());

	}//end testTheProviderDeclaresItselfWritable()

	/**
	 * A create goes through `createOrganisation()`, not through the mapper.
	 *
	 * This is the whole safety argument: the lifecycle assigns the slug, the
	 * owner, the admin users and the admin-group RBAC grant. A row written
	 * straight through the mapper would be a tenant nobody administers, which is
	 * not visibly different until someone tries to manage it.
	 *
	 * @return void
	 */
	public function testACreateGoesThroughTheLifecycle(): void {
		$this->actingUser();

		$created = $this->organisation(uuid: 'new-uuid', name: 'Gemeente Utrecht');

		$this->organisationService->expects($this->once())
			->method('createOrganisation')
			->with('Gemeente Utrecht', 'The one in the middle')
			->willReturn($created);

		// No further identity fields, so nothing to persist on top.
		$this->organisationMapper->expects($this->never())->method('save');

		$entity = $this->provider()->insert(
			register: new Register(),
			schema: new Schema(),
			data: ['name' => 'Gemeente Utrecht', 'description' => 'The one in the middle']
		);

		$this->assertSame('new-uuid', $entity->getUuid());
		$this->assertSame('Gemeente Utrecht', $entity->getObject()['name']);

	}//end testACreateGoesThroughTheLifecycle()

	/**
	 * The identity fields `createOrganisation()` does not take are applied after
	 * it, in one save.
	 *
	 * @return void
	 */
	public function testACreateAppliesTheRemainingIdentityFields(): void {
		$this->actingUser();

		$created = $this->organisation(uuid: 'new-uuid', name: 'Gemeente Utrecht');
		$this->organisationService->method('createOrganisation')->willReturn($created);

		$this->organisationMapper->expects($this->once())
			->method('save')
			->willReturnCallback(
				function (Organisation $organisation): Organisation {
					// The write reached the entity before it was persisted, which
					// is the thing a "save was called" assertion alone misses.
					$this->assertSame('00000001002220647000', $organisation->getOin());
					$this->assertSame('002220647', $organisation->getRsin());

					return $organisation;
				}
			);

		$entity = $this->provider()->insert(
			register: new Register(),
			schema: new Schema(),
			data: [
				'name' => 'Gemeente Utrecht',
				'oin' => '00000001002220647000',
				'rsin' => '002220647',
			]
		);

		$this->assertSame('00000001002220647000', $entity->getObject()['oin']);

	}//end testACreateAppliesTheRemainingIdentityFields()

	/**
	 * A create with no name is refused rather than defaulted. The slug is derived
	 * from the name, so an empty one produces a tenant whose slug the next create
	 * then collides with.
	 *
	 * @return void
	 */
	public function testACreateWithoutANameIsRefused(): void {
		$this->actingUser();

		$this->organisationService->expects($this->never())->method('createOrganisation');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('needs a name');

		$this->provider()->insert(
			register: new Register(),
			schema: new Schema(),
			data: ['name' => '   ']
		);

	}//end testACreateWithoutANameIsRefused()

	/**
	 * A create with no acting user is refused. `createOrganisation()` assigns the
	 * acting user as owner, so without one it would produce an unowned tenant.
	 *
	 * @return void
	 */
	public function testACreateWithoutAnActingUserIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->organisationService->expects($this->never())->method('createOrganisation');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('acting user');

		$this->provider()->insert(
			register: new Register(),
			schema: new Schema(),
			data: ['name' => 'Gemeente Utrecht']
		);

	}//end testACreateWithoutAnActingUserIsRefused()

	/**
	 * An administrator's update writes the projected fields.
	 *
	 * @return void
	 */
	public function testAnAdministratorCanUpdateTheIdentityFacet(): void {
		$this->actingUser();

		$existing = $this->organisation(uuid: 'org-uuid', name: 'Old name');
		$this->organisationMapper->method('findByUuid')->willReturn($existing);
		$this->organisationService->method('isOrganisationAdmin')->willReturn(true);
		$this->organisationMapper->expects($this->once())
			->method('save')
			->willReturnArgument(0);

		$entity = $this->provider()->update(
			register: new Register(),
			schema: new Schema(),
			id: 'org-uuid',
			data: ['name' => 'New name']
		);

		$this->assertSame('New name', $entity->getObject()['name']);

	}//end testAnAdministratorCanUpdateTheIdentityFacet()

	/**
	 * A member who does not administer the organisation cannot write it, and is
	 * told what an outsider is told, so the projection stays unusable as an
	 * enumeration oracle.
	 *
	 * @return void
	 */
	public function testAMemberWhoIsNotTheAdministratorCannotWrite(): void {
		$this->actingUser();

		$this->organisationMapper->method('findByUuid')
			->willReturn($this->organisation(uuid: 'org-uuid', name: 'Gemeente Utrecht'));
		$this->organisationService->method('isOrganisationAdmin')->willReturn(false);
		$this->organisationMapper->expects($this->never())->method('save');

		$this->expectException(RuntimeException::class);
		// The same wording an absent organisation gets. Asserting on it is what
		// keeps a later "helpful" error message from leaking existence.
		$this->expectExceptionMessage('does not exist');

		$this->provider()->update(
			register: new Register(),
			schema: new Schema(),
			id: 'org-uuid',
			data: ['name' => 'New name']
		);

	}//end testAMemberWhoIsNotTheAdministratorCannotWrite()

	/**
	 * A write on a merged-away organisation is refused rather than applied to the
	 * survivor.
	 *
	 * The READ side deliberately follows the merge chain, so a reference stored
	 * before a merge keeps resolving. Doing the same on a write would edit a
	 * record the caller never addressed, and nothing in the response would say so.
	 *
	 * @return void
	 */
	public function testAWriteOnAMergedAwayOrganisationIsRefused(): void {
		$this->actingUser();

		$this->organisationMapper->method('findByUuid')->willReturn(
			$this->organisation(uuid: 'old-uuid', name: 'Gemeente Oud', mergedInto: 'new-uuid')
		);
		$this->organisationService->method('isOrganisationAdmin')->willReturn(true);
		$this->organisationMapper->expects($this->never())->method('save');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('merged away');

		$this->provider()->update(
			register: new Register(),
			schema: new Schema(),
			id: 'old-uuid',
			data: ['name' => 'New name']
		);

	}//end testAWriteOnAMergedAwayOrganisationIsRefused()

	/**
	 * Tenancy administration is not projected, and so is not writable either.
	 * The read side draws that boundary; the write side must draw the same one.
	 *
	 * An unprojected key is IGNORED rather than rejected, because the store
	 * already discards it on the way in: rejecting would fail a request over a
	 * field the caller cannot see in the first place.
	 *
	 * @return void
	 */
	public function testAnUnprojectedPropertyIsIgnoredRatherThanWritten(): void {
		$this->actingUser();

		$existing = $this->organisation(uuid: 'org-uuid', name: 'Gemeente Utrecht');
		$existing->setStorageQuota(5);

		$this->organisationMapper->method('findByUuid')->willReturn($existing);
		$this->organisationService->method('isOrganisationAdmin')->willReturn(true);
		$this->organisationMapper->method('save')->willReturnArgument(0);

		$entity = $this->provider()->update(
			register: new Register(),
			schema: new Schema(),
			id: 'org-uuid',
			data: ['summary' => 'A municipality', 'storageQuota' => 999]
		);

		$this->assertSame(5, $existing->getStorageQuota(), 'the quota is not writable through the projection');
		$this->assertSame('A municipality', $entity->getObject()['summary']);
		$this->assertArrayNotHasKey('storageQuota', $entity->getObject());

	}//end testAnUnprojectedPropertyIsIgnoredRatherThanWritten()

	/**
	 * An update carrying nothing projectable does not touch the database.
	 *
	 * @return void
	 */
	public function testAnUpdateWithNothingProjectableDoesNotSave(): void {
		$this->actingUser();

		$this->organisationMapper->method('findByUuid')
			->willReturn($this->organisation(uuid: 'org-uuid', name: 'Gemeente Utrecht'));
		$this->organisationService->method('isOrganisationAdmin')->willReturn(true);
		$this->organisationMapper->expects($this->never())->method('save');

		$this->provider()->update(
			register: new Register(),
			schema: new Schema(),
			id: 'org-uuid',
			data: ['storageQuota' => 999]
		);

	}//end testAnUpdateWithNothingProjectableDoesNotSave()

	/**
	 * Delete refuses, always.
	 *
	 * An organisation is the tenant boundary: every object, register and schema
	 * is scoped to one. A caller deleting what it thinks is a reference record
	 * would orphan all of it.
	 *
	 * @return void
	 */
	public function testDeleteIsRefusedAndPointsAtMerging(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Merge it instead');

		$this->provider()->remove(
			register: new Register(),
			schema: new Schema(),
			id: 'org-uuid'
		);

	}//end testDeleteIsRefusedAndPointsAtMerging()

}//end class
