<?php

/**
 * Tests for the optional expiry and share time of ObjectSharingService::grant().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\InheritedGrantLister;
use OCA\OpenRegister\Service\Rbac\ObjectAuthorizationWriter;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCA\OpenRegister\Service\Rbac\ShareTimeWriter;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * grant() sets the expiry before core creates the share and writes the share time after, and a live caller that
 * passes neither gets neither.
 */
class ObjectSharingServiceGrantDatesTest extends TestCase {

	private IShare&MockObject $share;

	private ShareTimeWriter&MockObject $times;

	private ObjectSharingService $sharing;

	private ObjectEntity $record;

	/**
	 * A record owned by the caller, and a share manager that hands back the share it is given.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pjansen');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn([]);
		$folders = $this->createMock(FolderManagementHandler::class);
		$folders->method('getObjectFolder')->willReturn($this->createMock(Folder::class));

		$this->share = $this->createMock(IShare::class);
		$this->share->method('getId')->willReturn('41');
		$this->share->method('getFullId')->willReturn('ocinternal:41');
		$shares = $this->createMock(IManager::class);
		$shares->method('newShare')->willReturn($this->share);
		$shares->method('createShare')->willReturnArgument(0);
		$this->times = $this->createMock(ShareTimeWriter::class);

		$this->sharing = new ObjectSharingService(
			authorizationWriter: $this->createMock(ObjectAuthorizationWriter::class),
			userSession: $session,
			groupManager: $groups,
			folders: $folders,
			scopeResolver: new ObjectScopeResolver(),
			grantResolver: $this->createMock(ObjectGrantResolver::class),
			shareManager: $shares,
			logger: new NullLogger(),
			inheritedGrants: $this->createMock(InheritedGrantLister::class),
			grantManage: new GrantManageGuard(new ObjectScopeResolver(), $this->createMock(OrganisationSpecialRights::class)),
			shareTimes: $this->times
		);

		$this->record = new ObjectEntity();
		$this->record->setUuid('710d44a4-0000-4000-8000-000000000001');
		$this->record->setOwner('pjansen');
	}//end setUp()

	/**
	 * The expiry is on the share core creates; the share time is written after creation.
	 *
	 * @return void
	 */
	public function testAGrantCarriesItsExpiryAndShareTime(): void {
		$expires  = new DateTime('2026-10-31');
		$sharedAt = new DateTime('2026-10-06 10:00');
		$this->share->expects($this->once())->method('setExpirationDate')->with($expires);
		$this->share->expects($this->once())->method('setShareTime')->with($sharedAt);
		$this->times->expects($this->once())->method('stamp')->with(41, $sharedAt);

		$grant = $this->sharing->grant(object: $this->record, type: 'group', shareWith: 'juridische-zaken', expiresAt: $expires, sharedAt: $sharedAt);

		$this->assertSame(expected: 'ocinternal:41', actual: $grant['id']);
	}//end testAGrantCarriesItsExpiryAndShareTime()

	/**
	 * A live caller passes neither and gets neither.
	 *
	 * @return void
	 */
	public function testALiveGrantSetsNoDates(): void {
		$this->share->expects($this->never())->method('setExpirationDate');
		$this->share->expects($this->never())->method('setShareTime');
		$this->times->expects($this->never())->method('stamp');

		$this->sharing->grant(object: $this->record, type: 'user', shareWith: 'tvisser');
	}//end testALiveGrantSetsNoDates()

	/**
	 * The guard runs before anything is dated: a caller who may not share writes no share time.
	 *
	 * @return void
	 */
	public function testARefusedGrantWritesNoShareTime(): void {
		$this->record->setOwner('somebody-else');
		$this->times->expects($this->never())->method('stamp');

		$this->expectException(NotAuthorizedException::class);
		$this->sharing->grant(object: $this->record, type: 'group', shareWith: 'juridische-zaken', sharedAt: new DateTime('2026-10-06'));
	}//end testARefusedGrantWritesNoShareTime()

	/**
	 * The share time goes on the share row and on the per-recipient rows of a group share.
	 *
	 * @return void
	 */
	public function testTheShareTimeIsWrittenOnTheShareAndItsRecipientRows(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->expects($this->exactly(2))->method('eq')->willReturnCallback(static fn (string $column): string => $column.' = ?');
		$either = $this->createMock(ICompositeExpression::class);
		$expr->expects($this->once())->method('orX')->with('id = ?', 'parent = ?')->willReturn($either);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn (mixed $value): string => (string) $value);
		$qb->expects($this->once())->method('update')->with('share')->willReturnSelf();
		$qb->expects($this->once())->method('set')->with('stime', (string) (new DateTime('2026-10-06 10:00 UTC'))->getTimestamp())->willReturnSelf();
		$qb->expects($this->once())->method('where')->with($either)->willReturnSelf();
		$qb->method('executeStatement')->willReturn(3);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$this->assertSame(expected: 3, actual: (new ShareTimeWriter($db))->stamp(shareId: 41, moment: new DateTime('2026-10-06 10:00 UTC')));
	}//end testTheShareTimeIsWrittenOnTheShareAndItsRecipientRows()
}//end class
