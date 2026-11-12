<?php

/**
 * Golden test for the projections of the group, user-directory and contacts
 * object-source providers.
 *
 * Pins the uuid, register, schema and object data of every virtual object
 * find() and findAll() return, ahead of a parameter refactor of their
 * private toObjectEntity() mappers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\ContactsObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\GroupObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\UserDirectoryObjectSourceProvider;
use OCP\App\IAppManager;
use OCP\Contacts\IManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden pins for the directory-backed providers' virtual objects.
 *
 * @covers \OCA\OpenRegister\Service\ObjectSource\GroupObjectSourceProvider
 * @covers \OCA\OpenRegister\Service\ObjectSource\UserDirectoryObjectSourceProvider
 * @covers \OCA\OpenRegister\Service\ObjectSource\ContactsObjectSourceProvider
 */
class DirectoryProvidersProjectionGoldenTest extends TestCase {

	/**
	 * The register the objects land in.
	 *
	 * @var Register
	 */
	private Register $register;

	/**
	 * The sourced schema.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Build the register and schema.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->register = new Register();
		$this->register->setId(7);
		$this->schema = new Schema();
		$this->schema->setId(42);
	}//end setUp()

	/**
	 * A user double.
	 *
	 * @param string      $uid   The uid.
	 * @param string      $name  The display name.
	 * @param string|null $email The email address.
	 *
	 * @return IUser
	 */
	private function user(string $uid, string $name, ?string $email): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($name);
		$user->method('getEMailAddress')->willReturn($email);

		return $user;
	}//end user()

	/**
	 * A session whose acting user is an administrator.
	 *
	 * @return array{0: IUserSession, 1: IGroupManager}
	 */
	private function adminSession(): array {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user('root', 'Root', null));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		return [$session, $groups];
	}//end adminSession()

	/**
	 * The pinned shape of virtual objects.
	 *
	 * @param array<int, ObjectEntity|null> $entities The objects.
	 *
	 * @return string JSON of uuid, register, schema and object per entity.
	 */
	private function shape(array $entities): string {
		return (string) json_encode(array_map(
			static fn (?ObjectEntity $entity): ?array => ($entity === null ? null : [
				$entity->getUuid(),
				$entity->getRegister(),
				$entity->getSchema(),
				$entity->getObject(),
			]),
			$entities
		));
	}//end shape()

	/**
	 * Groups by find() and findAll().
	 *
	 * @return void
	 */
	public function testGroupProjection(): void {
		[$session, $groups] = $this->adminSession();
		$staff              = $this->createMock(IGroup::class);
		$staff->method('getGID')->willReturn('staff');
		$staff->method('getDisplayName')->willReturn('Staff');
		$board = $this->createMock(IGroup::class);
		$board->method('getGID')->willReturn('board');
		$board->method('getDisplayName')->willReturn('The board');
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => ($gid === 'staff' ? $staff : null));
		$groups->method('search')->willReturn(['x' => $staff, 'y' => $board]);

		$provider = new GroupObjectSourceProvider(groupManager: $groups, session: new \OCA\OpenRegister\Service\LinkSessionRuntime(userSession: $session, logger: $this->createMock(LoggerInterface::class)));
		$found    = [
			$provider->find(source: new SourcedTable(new RegisterSchema($this->register, $this->schema)), id: 'staff'),
			$provider->find(source: new SourcedTable(new RegisterSchema($this->register, $this->schema)), id: 'none'),
		];
		$all      = $provider->findAll(source: new SourcedTable(new RegisterSchema($this->register, $this->schema)), query: ['limit' => 5]);

		$this->assertSame('[["staff","7","42",{"id":"staff","displayName":"Staff"}],null]', $this->shape($found));
		$this->assertSame(
			'[["staff","7","42",{"id":"staff","displayName":"Staff"}],["board","7","42",{"id":"board","displayName":"The board"}]]',
			$this->shape($all)
		);
	}//end testGroupProjection()

	/**
	 * Users by find() and findAll().
	 *
	 * @return void
	 */
	public function testUserDirectoryProjection(): void {
		[$session, $groups] = $this->adminSession();
		$ann                = $this->user('ann', 'Ann', 'ann@example.org');
		$bob                = $this->user('bob', 'Bob', null);
		$users              = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($uid === 'ann' ? $ann : null));
		$users->method('search')->willReturn(['ann' => $ann, 'bob' => $bob]);

		$provider = new UserDirectoryObjectSourceProvider(
			userManager: $users,
			userSession: $session,
			groupManager: $groups,
			logger: $this->createMock(LoggerInterface::class)
		);
		$found    = [
			$provider->find(source: new SourcedTable(new RegisterSchema($this->register, $this->schema)), id: 'ann'),
			$provider->find(source: new SourcedTable(new RegisterSchema($this->register, $this->schema)), id: 'none'),
		];
		$all      = $provider->findAll(source: new SourcedTable(new RegisterSchema($this->register, $this->schema)));

		$this->assertSame('[["ann","7","42",{"id":"ann","displayName":"Ann","email":"ann@example.org"}],null]', $this->shape($found));
		$this->assertSame(
			'[["ann","7","42",{"id":"ann","displayName":"Ann","email":"ann@example.org"}],["bob","7","42",{"id":"bob","displayName":"Bob","email":""}]]',
			$this->shape($all)
		);
	}//end testUserDirectoryProjection()

	/**
	 * Contacts by find() and findAll(), with paging.
	 *
	 * @return void
	 */
	public function testContactsProjection(): void {
		$cards    = [
			['UID' => 'c1', 'FN' => 'Cee One', 'EMAIL' => ['one@example.org', 'alt@example.org'], 'ORG' => 'Acme'],
			['UID' => 'sys', 'FN' => 'System', 'isLocalSystemBook' => true],
			['UID' => 'c2', 'FN' => 'Cee Two', 'EMAIL' => 'two@example.org'],
			['UID' => 'c3', 'EMAIL' => [42]],
		];
		$contacts = $this->createMock(IManager::class);
		$contacts->method('search')->willReturn($cards);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$apps->method('isInstalled')->willReturn(true);

		$provider = new ContactsObjectSourceProvider(contactsManager: $contacts, app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $apps, container: $this->createMock(\Psr\Container\ContainerInterface::class), logger: $this->createMock(LoggerInterface::class)));
		$found    = [
			$provider->find(source: new SourcedTable(new RegisterSchema($this->register, $this->schema)), id: 'c2'),
			$provider->find(source: new SourcedTable(new RegisterSchema($this->register, $this->schema)), id: 'sys'),
		];
		$all      = $provider->findAll(source: new SourcedTable(new RegisterSchema($this->register, $this->schema)));
		$page     = $provider->findAll(source: new SourcedTable(new RegisterSchema($this->register, $this->schema)), query: ['limit' => 1, 'offset' => 1]);

		$this->assertSame('[["c2","7","42",{"id":"c2","fullName":"Cee Two","email":"two@example.org","org":""}],null]', $this->shape($found));
		$this->assertSame(
			'[["c1","7","42",{"id":"c1","fullName":"Cee One","email":"one@example.org","org":"Acme"}],'
			.'["c2","7","42",{"id":"c2","fullName":"Cee Two","email":"two@example.org","org":""}],'
			.'["c3","7","42",{"id":"c3","fullName":"","email":"","org":""}]]',
			$this->shape($all)
		);
		$this->assertSame('[["c2","7","42",{"id":"c2","fullName":"Cee Two","email":"two@example.org","org":""}]]', $this->shape($page));
	}//end testContactsProjection()
}//end class
