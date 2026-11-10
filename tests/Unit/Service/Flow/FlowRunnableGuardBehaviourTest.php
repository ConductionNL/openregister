<?php

/**
 * FlowRunnableGuard maps "no such flow", a refused run and a missing edit right
 * to the HTTP refusal a controller returns, and to null when the caller may proceed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Exception\FlowRunRefused;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowRunAuthorization;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunnableGuard
 * @uses   \OCA\OpenRegister\Exception\FlowRunRefused
 * @uses   \OCA\OpenRegister\Db\Flow
 */
class FlowRunnableGuardBehaviourTest extends TestCase {
	/**
	 * Without a flow service every flow is unknown.
	 *
	 * @return void
	 */
	public function testNoFlowServiceIsNotFound(): void {
		$response = (new FlowRunnableGuard())->refusalUnlessRunnable('f-1');
		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such flow: f-1'], $response->getData());
	}

	/**
	 * A flow that cannot be found is not found, and is never asked to run.
	 *
	 * @return void
	 */
	public function testUnknownFlowIsNotFound(): void {
		$flows = $this->createMock(FlowService::class);
		$flows->method('find')->with('f-1')->willThrowException(new DoesNotExistException('x'));
		$flows->expects($this->never())->method('assertRunnable');

		$response = (new FlowRunnableGuard($flows))->refusalUnlessRunnable('f-1');
		$this->assertSame([404, ['error' => 'No such flow: f-1']], [$response->getStatus(), $response->getData()]);
	}

	/**
	 * A runnable flow gives no refusal.
	 *
	 * @return void
	 */
	public function testRunnableFlowPasses(): void {
		$flow  = new Flow();
		$flows = $this->createMock(FlowService::class);
		$flows->method('find')->willReturn($flow);
		$flows->expects($this->once())->method('assertRunnable')->with($flow);

		$this->assertNull((new FlowRunnableGuard($flows))->refusalUnlessRunnable('f-1'));
	}

	/**
	 * A refused run is forbidden, carrying the message and verdict.
	 *
	 * @return void
	 */
	public function testRefusedRunIsForbidden(): void {
		$flows = $this->createMock(FlowService::class);
		$flows->method('find')->willReturn(new Flow());
		$flows->method('assertRunnable')->willThrowException(new FlowRunRefused(FlowRunAuthorization::NOT_YOURS, 'Not your flow'));

		$response = (new FlowRunnableGuard($flows))->refusalUnlessRunnable('f-1');
		$this->assertSame([403, ['error' => 'Not your flow', 'verdict' => 'not-yours']], [$response->getStatus(), $response->getData()]);
	}

	/**
	 * A refusal for want of a session is unauthorised instead.
	 *
	 * @return void
	 */
	public function testNoSessionIsUnauthorised(): void {
		$flows = $this->createMock(FlowService::class);
		$flows->method('find')->willReturn(new Flow());
		$flows->method('assertRunnable')->willThrowException(new FlowRunRefused(FlowRunAuthorization::NO_SESSION, 'Sign in'));

		$response = (new FlowRunnableGuard($flows))->refusalUnlessRunnable('f-1');
		$this->assertSame([401, 'no-session'], [$response->getStatus(), $response->getData()['verdict']]);
	}

	/**
	 * Without the access service editing is forbidden.
	 *
	 * @return void
	 */
	public function testEditWithoutAccessServiceIsForbidden(): void {
		$response = (new FlowRunnableGuard())->refusalUnlessMayEditFlow();
		$this->assertSame([403, ['error' => 'Flow authorization is unavailable.']], [$response->getStatus(), $response->getData()]);
	}

	/**
	 * Without a signed-in user editing is unauthorised.
	 *
	 * @return void
	 */
	public function testEditWithoutUserIsUnauthorised(): void {
		$access = $this->createMock(FlowAccess::class);
		$access->method('currentUser')->willReturn(null);
		$access->expects($this->never())->method('may');

		$response = (new FlowRunnableGuard(null, $access))->refusalUnlessMayEditFlow();
		$this->assertSame([401, ['error' => 'Not signed in.']], [$response->getStatus(), $response->getData()]);
	}

	/**
	 * A user with the flow.update right may edit; without it they are forbidden.
	 *
	 * @return void
	 */
	public function testEditRight(): void {
		$user   = $this->createMock(IUser::class);
		$access = $this->createMock(FlowAccess::class);
		$access->method('currentUser')->willReturn($user);
		$access->method('may')->willReturnCallback(static fn (IUser $u, string $action) => $u === $user && $action === 'flow.update');
		$this->assertNull((new FlowRunnableGuard(null, $access))->refusalUnlessMayEditFlow());

		$denying = $this->createMock(FlowAccess::class);
		$denying->method('currentUser')->willReturn($user);
		$denying->method('may')->willReturn(false);
		$response = (new FlowRunnableGuard(null, $denying))->refusalUnlessMayEditFlow();
		$this->assertSame([403, ['error' => 'You do not have the "flow.update" right.']], [$response->getStatus(), $response->getData()]);
	}
}
