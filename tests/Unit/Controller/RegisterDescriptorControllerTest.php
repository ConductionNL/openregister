<?php

/**
 * RegisterDescriptorControllerTest.
 *
 * Both endpoints rewrite or expose instance-wide state, so the assertions that
 * matter are the refusals. A controller whose tests only exercise the happy
 * path proves that an administrator can use it, which was never in doubt.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RegisterDescriptorController;
use OCA\OpenRegister\Service\RegisterDescriptorService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the inventory endpoint, the forced re-import, and who may call them.
 */
class RegisterDescriptorControllerTest extends TestCase {
	private RegisterDescriptorService&MockObject $descriptors;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private RegisterDescriptorController $controller;

	protected function setUp(): void {
		$this->descriptors  = $this->createMock(RegisterDescriptorService::class);
		$this->userSession  = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new RegisterDescriptorController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->descriptors,
			$this->userSession,
			$this->groupManager
		);
	}

	/**
	 * Put a caller in the session.
	 *
	 * @param string|null $uid     The uid, or null for no session.
	 * @param boolean     $isAdmin Whether they are an administrator.
	 *
	 * @return void
	 */
	private function signIn(?string $uid, bool $isAdmin = false): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);
	}

	private function row(string $state, ?string $installed): array {
		return [
			'appId'            => 'openregister',
			'slug'             => 'flows',
			'title'            => 'Flows',
			'state'            => $state,
			'installedVersion' => $installed,
			'shippedVersion'   => '1.3.0',
			'descriptor'       => 'flow_register.json',
		];
	}

	public function testTheInventoryAnswersWithRowsAndCounts(): void {
		$this->signIn('admin', isAdmin: true);
		$this->descriptors->method('inventory')->willReturn(
			[
				$this->row(RegisterDescriptorService::STATE_CURRENT, '1.3.0'),
				$this->row(RegisterDescriptorService::STATE_ABSENT, null),
				$this->row(RegisterDescriptorService::STATE_BEHIND, '1.0.0'),
			]
		);

		$response = $this->controller->index();
		$body     = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(3, $body['total']);

		// 🔴 ABSENT AND BEHIND ARE COUNTS, not something a client derives by
		// filtering. A consumer that has to compute "is anything wrong" is one
		// that renders a healthy-looking total and moves on — the exact failure
		// this endpoint exists to end.
		$this->assertSame(1, $body['absent']);
		$this->assertSame(1, $body['behind']);
	}

	/**
	 * A healthy instance must still report its counts, or a consumer cannot tell
	 * "nothing is wrong" from "the field is missing".
	 */
	public function testTheCountsArePresentEvenWhenEverythingIsCurrent(): void {
		$this->signIn('admin', isAdmin: true);
		$this->descriptors->method('inventory')
			->willReturn([$this->row(RegisterDescriptorService::STATE_CURRENT, '1.3.0')]);

		$body = $this->controller->index()->getData();

		$this->assertSame(0, $body['absent']);
		$this->assertSame(0, $body['behind']);
	}

	public function testANonAdministratorIsRefusedTheInventory(): void {
		$this->signIn('alice', isAdmin: false);
		$this->descriptors->expects($this->never())->method('inventory');

		$this->assertSame(403, $this->controller->index()->getStatus());
	}

	/**
	 * 🔴 THE REFUSAL MUST COME BEFORE THE WORK. Asserting only the status code
	 * would pass for a controller that ran the import and then returned 403.
	 */
	public function testANonAdministratorImportsNothing(): void {
		$this->signIn('alice', isAdmin: false);
		$this->descriptors->expects($this->never())->method('reimport');

		$response = $this->controller->import('openregister', 'flows');

		$this->assertSame(403, $response->getStatus());
	}

	public function testAnAnonymousCallerIsRefusedWithUnauthenticated(): void {
		$this->signIn(null);
		$this->descriptors->expects($this->never())->method('inventory');

		$this->assertSame(401, $this->controller->index()->getStatus());
	}

	public function testAnImportedDescriptorAnswersTwoHundredWithItsOutcome(): void {
		$this->signIn('admin', isAdmin: true);
		$this->descriptors->method('reimport')
			->with('openregister', 'flows')
			->willReturn(['outcome' => 'imported', 'reason' => null]);

		$response = $this->controller->import('openregister', 'flows');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('imported', $response->getData()['outcome']);
	}

	/**
	 * A failure must be a FAILURE status, not a 200 carrying bad news. A caller
	 * checking the status code is the common case, and it must not be misled.
	 */
	public function testAFailedImportAnswersFourTwentyTwoAndCarriesItsReason(): void {
		$this->signIn('admin', isAdmin: true);
		$this->descriptors->method('reimport')
			->willReturn(['outcome' => 'failed', 'reason' => 'no such register "nope"']);

		$response = $this->controller->import('openregister', 'nope');
		$body     = $response->getData();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('failed', $body['outcome']);
		$this->assertStringContainsString('nope', (string)$body['reason']);
	}
}
