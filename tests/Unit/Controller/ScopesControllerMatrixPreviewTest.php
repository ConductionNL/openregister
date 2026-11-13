<?php

/**
 * ScopesController::matrixPreview(): the administrator's preview of a
 * schema's department matrix for one user.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ScopesController;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\ScopesControllerServices;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * 403 for anyone but an administrator, before anything is looked up; 404 for
 * an unknown user or schema; otherwise the handler's preview for that user.
 */
class ScopesControllerMatrixPreviewTest extends TestCase {

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;

	/**
	 * @var PermissionHandlerInterface&MockObject
	 */
	private PermissionHandlerInterface&MockObject $permissions;

	/**
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * The controller under test.
	 *
	 * @var ScopesController
	 */
	private ScopesController $controller;

	/**
	 * Build the controller; `bob` exists, schema `case` exists.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === 'bob' ? $this->user(uid: 'bob') : null);
		$this->controller = new ScopesController(
			$this->createMock(IRequest::class),
			new ControllerSession($this->session, $this->groups, new NullLogger()),
			new ScopesControllerServices($this->createMock(RegisterMapperInterface::class), $this->schemas),
			$this->permissions,
			$this->users
		);
	}//end setUp()

	/**
	 * A user.
	 *
	 * @param string $uid The id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * Sign a user in, an administrator or not.
	 *
	 * @param bool $admin Whether the caller is an administrator.
	 *
	 * @return void
	 */
	private function signIn(bool $admin): void {
		$this->session->method('getUser')->willReturn($this->user(uid: 'ann'));
		$this->groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'ann' && $admin === true);
	}//end signIn()

	/**
	 * A caller who is not an administrator, or nobody, is refused before any lookup.
	 *
	 * @return void
	 */
	public function testOnlyAnAdministratorMayPreview(): void {
		$this->schemas->expects($this->never())->method('find');
		$this->permissions->expects($this->never())->method('previewDepartmentMatrix');

		$anonymous = $this->controller->matrixPreview('case', 'bob');
		$this->signIn(admin: false);
		$member = $this->controller->matrixPreview('case', 'bob');

		$this->assertSame(403, $anonymous->getStatus());
		$this->assertSame(403, $member->getStatus());
		$this->assertSame(['error' => 'Only an administrator can preview a rights matrix'], $member->getData());
	}//end testOnlyAnAdministratorMayPreview()

	/**
	 * An unknown or missing user, and an unknown schema, are 404 and preview nothing.
	 *
	 * @return void
	 */
	public function testUnknownUsersAndSchemasAreNotFound(): void {
		$this->signIn(admin: true);
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('no schema'));
		$this->permissions->expects($this->never())->method('previewDepartmentMatrix');

		$this->assertSame(['error' => 'User not found'], $this->controller->matrixPreview('case', 'nobody')->getData());
		$this->assertSame(404, $this->controller->matrixPreview('case', '')->getStatus());
		$schemaMissing = $this->controller->matrixPreview('nope', 'bob');
		$this->assertSame(404, $schemaMissing->getStatus());
		$this->assertSame(['error' => 'Schema not found'], $schemaMissing->getData());
	}//end testUnknownUsersAndSchemasAreNotFound()

	/**
	 * An administrator gets the handler's preview for the user named, with the schema and user.
	 *
	 * @return void
	 */
	public function testAnAdministratorGetsThePreviewForTheNamedUser(): void {
		$this->signIn(admin: true);
		$schema = new Schema();
		$schema->setId(5);
		$this->schemas->method('find')->with('case')->willReturn($schema);
		$scopes = ['read' => [['group' => 'handlers', 'match' => ['department' => ['$in' => ['VTH']]]]]];
		$this->permissions->expects($this->once())->method('previewDepartmentMatrix')->willReturnCallback(
			function (Schema $previewed, IUser $user) use ($schema, $scopes): array {
				$this->assertSame($schema, $previewed);
				$this->assertSame('bob', $user->getUID());
				return ['values' => ['VTH'], 'scopes' => $scopes];
			}
		);

		$response = $this->controller->matrixPreview('case', ' bob ');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['schema' => 5, 'user' => 'bob', 'values' => ['VTH'], 'scopes' => $scopes], $response->getData());
	}//end testAnAdministratorGetsThePreviewForTheNamedUser()
}//end class
