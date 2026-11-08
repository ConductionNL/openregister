<?php

/**
 * Golden outcomes of RegisterDescriptorController's administrator check, pinned before a refactor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RegisterDescriptorController;
use OCA\OpenRegister\Service\RegisterDescriptorServiceInterface;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Tests\Unit\Controller\Support\RegisterDescriptorControllerFactory;

/**
 * Pins the 401 / 403 refusals and the allowed outcome of every action.
 */
class RegisterDescriptorControllerA24GoldenTest extends TestCase {

	/**
	 * descriptors.
	 *
	 * @var RegisterDescriptorServiceInterface&MockObject
	 */
	private RegisterDescriptorServiceInterface&MockObject $descriptors;

	/**
	 * groupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->descriptors = $this->createMock(RegisterDescriptorServiceInterface::class);
		$this->descriptors->method('inventory')->willReturn([["state" => "absent"], ["state" => "current"]]);
		$this->descriptors->method('reimport')->willReturnCallback(fn (
			string $appId,
			string $slug,
			string $via
		): array => ["outcome" => $slug, "via" => $via]);
	}//end setUp()

	/**
	 * Build the controller for a caller.
	 *
	 * @param string|null $uid   The caller, or null for none.
	 * @param bool        $admin Whether they are an administrator.
	 *
	 * @return mixed The controller.
	 */
	private function controller(?string $uid, bool $admin = false): mixed {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturnCallback(fn (string $checked): bool => ($checked === $uid && $admin));
		return RegisterDescriptorControllerFactory::create(
			"openregister",
			$this->createMock(IRequest::class),
			$this->descriptors,
			$session,
			$this->groupManager
		);
	}//end controller()

	/**
	 * Refusals.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$cases = [
			"index" => fn ($c) => $c->index(),
			"import" => fn ($c) => $c->import("app", "reg"),
		];
		foreach ($cases as $name => $call) {
			$r = $call($this->controller(null));
			$this->assertSame([401, ['error' => 'Authentication required']], [$r->getStatus(), $r->getData()], $name);
		}

		foreach ($cases as $name => $call) {
			$r = $call($this->controller('alice', false));
			$this->assertSame([403, ['error' => 'Forbidden: register descriptors are admin-only']], [$r->getStatus(), $r->getData()], $name);
		}
	}

	/**
	 * Allowed.
	 *
	 * @return void
	 */
	public function testAllowed(): void {
		$c = $this->controller("root", true);
		$r = $c->index();
		$this->assertSame(
			[200, ["results" => [["state" => "absent"], ["state" => "current"]], "total" => 2, "absent" => 1, "behind" => 0]],
			[$r->getStatus(), $r->getData()]
		);
		$r = $c->import("app", "reg");
		$this->assertSame([200, ["outcome" => "reg", "via" => "admin settings: Register descriptors"]], [$r->getStatus(), $r->getData()]);
		$r = $c->import("app", "failed");
		$this->assertSame(422, $r->getStatus());
	}
}//end class
