<?php

/**
 * Unit tests for UserDirectoryObjectSourceProvider — which accounts a caller may see.
 *
 * A non-admin sees only their own account (and only when a search matches
 * their uid); an admin searches the directory. These tests pin the branches
 * UserDirectoryObjectSourceProviderTest leaves: a failing account lookup or
 * directory search, an admin check that throws (treated as not admin), a
 * non-admin search that does not match, and a signed-out single read.
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\UserDirectoryObjectSourceProvider;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class UserDirectoryVisibilityTest extends TestCase {

	private IUserManager&MockObject $users;

	private IUserSession&MockObject $session;

	private IGroupManager&MockObject $groups;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->users = $this->createMock(IUserManager::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function provider(): UserDirectoryObjectSourceProvider {
		return new UserDirectoryObjectSourceProvider($this->users, $this->session, $this->groups, $this->logger);
	}

	private function user(string $uid, ?string $email = null): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(ucfirst($uid));
		$user->method('getEMailAddress')->willReturn($email);
		return $user;
	}

	public function testAFailingAccountLookupIsLoggedAndFindsNothing(): void {
		$this->users->method('get')->willThrowException(new RuntimeException('ldap down'));
		$this->logger->expects($this->once())->method('warning')->with('[ObjectSource:user-directory-source] could not read user: ldap down');

		$this->assertNull($this->provider()->find(new Register(), new Schema(), 'anna'));
	}

	public function testSignedOutNoAccountIsReadable(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->users->method('get')->willReturn($this->user('anna'));
		$this->groups->expects($this->never())->method('isAdmin');

		$this->assertNull($this->provider()->find(new Register(), new Schema(), 'anna'));
	}

	public function testAThrowingAdminCheckCountsAsNotAdmin(): void {
		$bert = $this->user('bert');
		$this->session->method('getUser')->willReturn($bert);
		$this->groups->method('isAdmin')->willThrowException(new RuntimeException('groups backend'));
		$this->users->method('get')->willReturnMap([['anna', $this->user('anna')], ['bert', $bert]]);
		$this->users->expects($this->never())->method('search');

		$this->assertNull($this->provider()->find(new Register(), new Schema(), 'anna'), 'Another account stays hidden.');
		$self = $this->provider()->find(new Register(), new Schema(), 'bert');
		$this->assertSame(['bert', 'Bert', ''], [$self->getUuid(), $self->getObject()['displayName'], $self->getObject()['email']]);
		$this->assertSame(['bert'], array_map(static fn ($e) => $e->getUuid(), $this->provider()->findAll(new Register(), new Schema(), [])));
	}

	public function testANonAdminSearchOnlyMatchesTheirOwnUid(): void {
		$this->session->method('getUser')->willReturn($this->user('bert'));
		$this->groups->method('isAdmin')->willReturn(false);

		$this->assertSame([], $this->provider()->findAll(new Register(), new Schema(), ['search' => 'anna']));
		$this->assertCount(1, $this->provider()->findAll(new Register(), new Schema(), ['_search' => 'BER']));
	}

	public function testAnAdminSearchesTheDirectoryAndAFailingSearchIsLogged(): void {
		$this->session->method('getUser')->willReturn($this->user('root'));
		$this->groups->method('isAdmin')->with('root')->willReturn(true);
		$this->users->method('search')->willReturnCallback(function (string $search, int $limit, int $offset) {
			if ($search === 'boom') {
				throw new RuntimeException('directory timeout');
			}
			$this->assertSame(['an', 5, 10], [$search, $limit, $offset]);
			return ['anna' => $this->user('anna', 'anna@example.com')];
		});
		$this->logger->expects($this->once())->method('warning')->with('[ObjectSource:user-directory-source] could not list users: directory timeout');

		$rows = $this->provider()->findAll(new Register(), new Schema(), ['filters' => ['search' => 'an'], 'limit' => 5, 'offset' => 10]);
		$this->assertSame('anna@example.com', $rows[0]->getObject()['email']);
		$this->assertSame([], $this->provider()->findAll(new Register(), new Schema(), ['search' => 'boom']));
	}
}
