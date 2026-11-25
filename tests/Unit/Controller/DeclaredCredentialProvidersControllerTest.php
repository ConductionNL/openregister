<?php

/**
 * DeclaredCredentialProvidersController: only a real administrator reads the review list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\DeclaredCredentialProvidersController;
use OCA\OpenRegister\Service\Credential\DeclaredProviderResolver;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-novel-declaration-is-unusable-until-an-administrator-approves-it
 */
class DeclaredCredentialProvidersControllerTest extends TestCase {

	private IUserSession&MockObject $session;

	private IGroupManager&MockObject $groups;

	private DeclaredProviderResolver&MockObject $declared;

	protected function setUp(): void {
		$this->session  = $this->createMock(IUserSession::class);
		$this->groups   = $this->createMock(IGroupManager::class);
		$this->declared = $this->createMock(DeclaredProviderResolver::class);
	}//end setUp()

	private function controller(): DeclaredCredentialProvidersController {
		return new DeclaredCredentialProvidersController(
			request: $this->createMock(IRequest::class),
			groups: $this->groups,
			userSession: $this->session,
			declared: $this->declared
		);
	}//end controller()

	private function signedIn(string $uid, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->with($uid)->willReturn($admin);
	}//end signedIn()

	public function testAnAnonymousCallerGets401AndNothingIsRead(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->declared->expects($this->never())->method('review');

		$response = $this->controller()->index();

		$this->assertSame([401, ['error' => 'Authentication required']], [$response->getStatus(), $response->getData()]);
	}//end testAnAnonymousCallerGets401AndNothingIsRead()

	public function testADelegatedAdminWhoIsNoAdministratorGets403AndNothingIsRead(): void {
		$this->signedIn(uid: 'delegate', admin: false);
		$this->declared->expects($this->never())->method('review');

		$response = $this->controller()->index();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Only administrators may review declared credential providers.'], $response->getData());
	}//end testADelegatedAdminWhoIsNoAdministratorGets403AndNothingIsRead()

	public function testAnAdministratorGetsTheReviewList(): void {
		$this->signedIn(uid: 'admin', admin: true);
		$rows = [['identifier' => 'alpha:codeberg', 'status' => 'pending', 'host' => 'codeberg.org']];
		$this->declared->expects($this->once())->method('review')->willReturn($rows);

		$response = $this->controller()->index();

		$this->assertSame([200, ['results' => $rows]], [$response->getStatus(), $response->getData()]);
	}//end testAnAdministratorGetsTheReviewList()

	public function testTheRouteIsAnAdminSettingAndNotOpenToEveryUser(): void {
		$method = new ReflectionMethod(DeclaredCredentialProvidersController::class, 'index');
		$names  = array_map(static fn ($attribute): string => $attribute->getName(), $method->getAttributes());

		$this->assertSame([AuthorizedAdminSetting::class], $names);
	}//end testTheRouteIsAnAdminSettingAndNotOpenToEveryUser()
}//end class
