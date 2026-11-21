<?php

/**
 * Unit tests for GroupObjectSourceProvider — which groups a caller may see.
 *
 * GroupObjectSourceProviderTest covers the admin listing and the plain member
 * read. These tests pin the rest of the visibility rule: a non-admin's search
 * runs over their own groups only, matching id or display name
 * case-insensitively; a lookup or membership check that throws hides the
 * group; and without a signed-in user no single group is readable.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\GroupObjectSourceProvider;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class GroupObjectSourceVisibilityTest extends TestCase {

	private IGroupManager&MockObject $groups;

	private IUserSession&MockObject $session;

	private LoggerInterface&MockObject $logger;

	private IUser $user;

	protected function setUp(): void {
		$this->groups = $this->createMock(IGroupManager::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bert');
		$this->user = $user;
	}

	private function provider(): GroupObjectSourceProvider {
		return new GroupObjectSourceProvider(groupManager: $this->groups, session: new \OCA\OpenRegister\Service\LinkSessionRuntime(userSession: $this->session, logger: $this->logger));
	}

	private function group(string $gid, string $name): IGroup&MockObject {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('getDisplayName')->willReturn($name);
		return $group;
	}

	private function ids(array $entities): array {
		return array_map(static fn ($e) => $e->getUuid(), $entities);
	}

	public function testANonAdminSearchMatchesOwnGroupsByIdOrNameCaseInsensitively(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->groups->method('isAdmin')->with('bert')->willReturn(false);
		$this->groups->expects($this->never())->method('search');
		$this->groups->method('getUserGroups')->with($this->user)->willReturn([
			'kcc' => $this->group('kcc', 'Klantcontactcentrum'),
			'ict' => $this->group('ict', 'Informatievoorziening'),
			'fin' => $this->group('fin', 'Financiën'),
		]);

		$this->assertSame(['kcc'], $this->ids($this->provider()->findAll(new SourcedTable(new RegisterSchema(new Register(), new Schema())), ['filters' => ['search' => 'KLANT']])));
		$this->assertSame(['ict'], $this->ids($this->provider()->findAll(new SourcedTable(new RegisterSchema(new Register(), new Schema())), ['_search' => 'IC'])));
		$this->assertSame(['kcc', 'ict', 'fin'], $this->ids($this->provider()->findAll(new SourcedTable(new RegisterSchema(new Register(), new Schema())), [])));
	}

	public function testAFailingGroupLookupIsLoggedAndFindsNothing(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->groups->method('get')->willThrowException(new RuntimeException('ldap down'));
		$this->logger->expects($this->once())->method('warning')->with('[ObjectSource:group-source] could not read group: ldap down');

		$this->assertNull($this->provider()->find(new SourcedTable(new RegisterSchema(new Register(), new Schema())), 'kcc'));
	}

	public function testWithoutAUserNoSingleGroupIsReadable(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->groups->method('get')->with('kcc')->willReturn($this->group('kcc', 'KCC'));
		$this->groups->expects($this->never())->method('isAdmin');

		$this->assertNull($this->provider()->find(new SourcedTable(new RegisterSchema(new Register(), new Schema())), 'kcc'));
	}

	public function testAFailingMembershipCheckHidesTheGroup(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$group = $this->group('kcc', 'KCC');
		$group->method('inGroup')->willThrowException(new RuntimeException('backend error'));
		$this->groups->method('get')->willReturn($group);
		$this->groups->method('isAdmin')->willReturn(false);

		$this->assertNull($this->provider()->find(new SourcedTable(new RegisterSchema(new Register(), new Schema())), 'kcc'));
	}

	public function testAMemberReadsTheirGroupAndANonMemberDoesNot(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$mine = $this->group('kcc', 'KCC');
		$mine->method('inGroup')->with($this->user)->willReturn(true);
		$theirs = $this->group('fin', 'FIN');
		$theirs->method('inGroup')->willReturn(false);
		$this->groups->method('get')->willReturnMap([['kcc', $mine], ['fin', $theirs]]);
		$this->groups->method('isAdmin')->willReturn(false);

		$found = $this->provider()->find(new SourcedTable(new RegisterSchema(new Register(), new Schema())), 'kcc');
		$this->assertSame('kcc', $found->getUuid());
		$this->assertSame('KCC', $found->getObject()['displayName']);
		$this->assertNull($this->provider()->find(new SourcedTable(new RegisterSchema(new Register(), new Schema())), 'fin'));
	}
	public function testAnAdminSearchesAllGroupsWithTheQuerysPaging(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->groups->method('isAdmin')->with('bert')->willReturn(true);
		$this->groups->expects($this->never())->method('getUserGroups');
		$this->groups->expects($this->once())->method('search')->with('zaak', 10, 20)
			->willReturn(['z1' => $this->group('z1', 'Zaak 1'), 'z2' => $this->group('z2', 'Zaak 2')]);

		$provider = $this->provider();
		$this->assertSame(['z1', 'z2'], $this->ids($provider->findAll(new SourcedTable(new RegisterSchema(new Register(), new Schema())), ['search' => 'zaak', 'limit' => 10, 'offset' => 20])));
		$this->assertSame('group-source', $provider->getId());
		$this->assertTrue($provider->isEnabled());
	}

	public function testAFailingListingIsLoggedAndListsNothing(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->groups->method('isAdmin')->willThrowException(new RuntimeException('db'));
		$this->logger->expects($this->once())->method('warning')->with('[ObjectSource:group-source] could not list groups: db');

		$this->assertSame(0, $this->provider()->count(new SourcedTable(new RegisterSchema(new Register(), new Schema()))));
	}

	public function testWithoutAUserNothingIsListed(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->groups->expects($this->never())->method('search');
		$this->groups->expects($this->never())->method('getUserGroups');

		$this->assertSame([], $this->provider()->findAll(new SourcedTable(new RegisterSchema(new Register(), new Schema())), ['search' => 'x']));
	}
}
