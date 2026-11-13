<?php

/**
 * Unit tests for the fail-closed branches of the organisation projection.
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
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\OrganisationActingUser;
use OCA\OpenRegister\Service\ObjectSource\OrganisationObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Locks that every collaborator failure on the organisation projection
 * answers "nothing" or "refused", never the record.
 *
 * @covers \OCA\OpenRegister\Service\ObjectSource\OrganisationObjectSourceProvider
 * @uses   \OCA\OpenRegister\Db\Organisation
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\RegisterSchema
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\ObjectSource\OrganisationActingUser
 * @uses   \OCA\OpenRegister\Service\ObjectSource\SourcedObjectBinding
 * @uses   \OCA\OpenRegister\Service\ObjectSource\SourcedTable
 */
final class OrganisationObjectSourceFailClosedTest extends TestCase {

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
	 * The group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private $groupManager;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Wire fresh doubles for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->organisationMapper  = $this->createMock(OrganisationMapper::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->userSession         = $this->createMock(IUserSession::class);
		$this->groupManager        = $this->createMock(IGroupManager::class);
		$this->logger              = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the provider over the current doubles.
	 *
	 * @return OrganisationObjectSourceProvider The provider under test.
	 */
	private function provider(): OrganisationObjectSourceProvider {
		return new OrganisationObjectSourceProvider(
			organisationMapper: $this->organisationMapper,
			actingUser: new OrganisationActingUser(userSession: $this->userSession, groupManager: $this->groupManager),
			logger: $this->logger,
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
	 * The bound register and schema.
	 *
	 * @return SourcedTable
	 */
	private function table(): SourcedTable {
		return new SourcedTable(new RegisterSchema(new Register(), new Schema()));
	}//end table()

	/**
	 * Build an organisation.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisation(string $uuid = 'org-uuid'): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setName('Gemeente Utrecht');
		$organisation->setUsers(['alice']);

		return $organisation;
	}//end organisation()

	/**
	 * A read the mapper cannot answer finds nothing and is logged at debug level.
	 *
	 * @return void
	 */
	public function testAFindTheMapperCannotAnswerIsNull(): void {
		$this->actingUser();
		$this->organisationMapper->method('findByUuidFollowingMerge')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('debug')
			->with('[ObjectSource:organisation-source] could not read organisation: db down');

		$this->assertNull($this->provider()->find(source: $this->table(), id: 'org-uuid'));
	}//end testAFindTheMapperCannotAnswerIsNull()

	/**
	 * When the admin check itself fails, the read is denied rather than granted.
	 *
	 * @return void
	 */
	public function testAFindWhoseAdminCheckFailsIsDenied(): void {
		$this->actingUser();
		$this->organisationMapper->method('findByUuidFollowingMerge')->willReturn($this->organisation());
		$this->groupManager->method('isAdmin')->willThrowException(new RuntimeException('ldap down'));

		$this->assertNull($this->provider()->find(source: $this->table(), id: 'org-uuid'));
	}//end testAFindWhoseAdminCheckFailsIsDenied()

	/**
	 * A list the mapper cannot answer is empty, and the failure is logged.
	 *
	 * @return void
	 */
	public function testAListTheMapperCannotAnswerIsEmpty(): void {
		$this->actingUser();
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->organisationMapper->method('findByUserId')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('[ObjectSource:organisation-source] could not list organisations: db down');

		$this->assertSame([], $this->provider()->findAll(source: $this->table()));
	}//end testAListTheMapperCannotAnswerIsEmpty()

	/**
	 * An update of an organisation the mapper cannot load is refused as absent.
	 *
	 * @return void
	 */
	public function testAnUpdateOfAnUnloadableOrganisationIsRefusedAsAbsent(): void {
		$this->actingUser();
		$cause = new RuntimeException('not found');
		$this->organisationMapper->method('findByUuid')->willThrowException($cause);
		$this->organisationMapper->expects($this->never())->method('save');

		try {
			$this->provider()->update(source: $this->table(), id: 'gone-uuid', data: ['name' => 'Renamed']);
		} catch (RuntimeException $e) {
			$this->assertSame('Organisation "gone-uuid" does not exist.', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
			return;
		}

		$this->fail('Expected RuntimeException was not thrown.');
	}//end testAnUpdateOfAnUnloadableOrganisationIsRefusedAsAbsent()

	/**
	 * When the write-authority check fails, the update is refused, not allowed.
	 *
	 * @return void
	 */
	public function testAnUpdateWhoseAuthorityCheckFailsIsRefused(): void {
		$this->actingUser();
		$this->organisationMapper->method('findByUuid')->willReturn($this->organisation());
		$this->organisationService->method('isOrganisationAdmin')->willThrowException(new RuntimeException('rbac down'));
		$this->organisationMapper->expects($this->never())->method('save');
		$this->logger->expects($this->once())
			->method('warning')
			->with('[ObjectSource:organisation-source] could not check write authority: rbac down');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Organisation "org-uuid" does not exist.');

		$this->provider()->update(source: $this->table(), id: 'org-uuid', data: ['name' => 'Renamed']);
	}//end testAnUpdateWhoseAuthorityCheckFailsIsRefused()
}//end class
