<?php

/**
 * Golden outcomes of PrincipalReachController, pinned before a refactor.
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

use OCA\OpenRegister\Controller\PrincipalReachController;
use OCA\OpenRegister\Service\Rbac\PrincipalReachService;
use OCA\OpenRegister\Service\Rbac\ReachRevocationService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins the administrator-only posture, the 400 refusal and the allowed reads/revocations.
 */
class PrincipalReachControllerA24GoldenTest extends TestCase {

	/**
	 * reach.
	 *
	 * @var PrincipalReachService&MockObject
	 */
	private PrincipalReachService&MockObject $reach;

	/**
	 * revocation.
	 *
	 * @var ReachRevocationService&MockObject
	 */
	private ReachRevocationService&MockObject $revocation;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reach = $this->createMock(PrincipalReachService::class);
		$this->revocation = $this->createMock(ReachRevocationService::class);
	}//end setUp()

	/**
	 * Build the controller over these params.
	 *
	 * @param array<string, mixed> $params Params.
	 *
	 * @return PrincipalReachController
	 */
	private function controller(array $params = []): PrincipalReachController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		return new PrincipalReachController('openregister', $request, $this->reach, $this->revocation);
	}//end controller()

	/**
	 * Both actions stay administrator only.
	 *
	 * @return void
	 */
	public function testBothActionsStayAdministratorOnly(): void {
		foreach (['show', 'revoke'] as $name) {
			$method = new ReflectionMethod(PrincipalReachController::class, $name);
			$this->assertSame([], $method->getAttributes(NoAdminRequired::class), $name);
			$this->assertSame([], $method->getAttributes(PublicPage::class), $name);
			$this->assertStringNotContainsString('@NoAdminRequired', (string)$method->getDocComment(), $name);
			$this->assertStringNotContainsString('@PublicPage', (string)$method->getDocComment(), $name);
		}
	}

	/**
	 * Blank principal is refused.
	 *
	 * @return void
	 */
	public function testBlankPrincipalIsRefused(): void {
		$this->reach->expects($this->never())->method('listFor');
		$this->revocation->expects($this->never())->method('revokeAll');
		foreach (['show', 'revoke'] as $name) {
			$r = $this->controller()->$name('  ');
			$this->assertSame([400, ['error' => 'principal is required']], [$r->getStatus(), $r->getData()], $name);
		}
	}

	/**
	 * Allowed.
	 *
	 * @return void
	 */
	public function testAllowed(): void {
		$this->reach->method('listFor')->with('alice')->willReturn(['grants' => []]);
		$this->revocation->method('revokeAll')->willReturnCallback(fn (string $principal, string $reason = ''): array => [$principal, $reason]);
		$r = $this->controller()->show(' alice ');
		$this->assertSame([200, ['grants' => []]], [$r->getStatus(), $r->getData()]);
		$r = $this->controller(['reason' => ' left '])->revoke(' alice ');
		$this->assertSame([200, ['alice', 'left']], [$r->getStatus(), $r->getData()]);
		$this->assertSame(['alice', ''], $this->controller()->revoke('alice')->getData());
	}
}//end class
