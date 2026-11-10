<?php

/**
 * Golden test: who FlowService::run() and runForAction() queue a run for, and who they refuse.
 *
 * Pins, on the code as it stood before the run entry points were thinned to
 * one shared starter: the owner's run is queued as the acting user with the
 * trigger asked for, and advanced inline only when the caller waits; a
 * colleague who neither owns the flow nor may edit it is refused before
 * anything is queued; a flow of another organisation reads as absent; and the
 * declared-action path queues without the owner check, as the acting user.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Exception\FlowRunRefused;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowDefinitionStore;
use OCA\OpenRegister\Service\Flow\FlowDeletionSweep;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunAuthorization;
use OCA\OpenRegister\Service\Flow\FlowRunDispatch;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowServicePlatform;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Allowed and refused outcomes of the two run entry points of FlowService.
 */
final class FlowServiceRunAccessGoldenTest extends TestCase {

	/**
	 * Every queue() call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queued = [];

	/**
	 * Every advance() call, in order.
	 *
	 * @var array<int, array{run: FlowRun, rethrow: bool}>
	 */
	private array $advanced = [];

	/**
	 * The flow `flow-1`, owned by anja in org-a.
	 *
	 * @param string $organisation The flow's organisation.
	 *
	 * @return Flow
	 */
	private function flow(string $organisation = 'org-a'): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setOwner('anja');
		$flow->setOrganisation($organisation);
		$flow->setEnabled(true);

		return $flow;
	}//end flow()

	/**
	 * Build the service for one signed-in caller in org-a.
	 *
	 * @param string $uid          The signed-in uid.
	 * @param string $organisation The organisation the stored flow belongs to.
	 *
	 * @return FlowService
	 */
	private function service(string $uid, string $organisation = 'org-a'): FlowService {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findByUuid')->willReturn($this->flow(organisation: $organisation));

		$runner = $this->createMock(FlowRunService::class);
		$runner->method('queue')->willReturnCallback(
			function (string $flowId, array $subject = [], string $trigger = 'manual', array $context = [], ?string $user = null): FlowRun {
				$this->queued[] = ['flowId' => $flowId, 'subject' => $subject, 'trigger' => $trigger, 'context' => $context, 'user' => $user];
				$run = new FlowRun();
				$run->setUuid('run-' . count($this->queued));
				$run->setStatus(FlowRun::STATUS_QUEUED);

				return $run;
			}
		);

		$advancer = $this->createMock(FlowRunAdvancer::class);
		$advancer->method('advance')->willReturnCallback(
			function (FlowRun $run, bool $rethrow = false): FlowRun {
				$this->advanced[] = ['run' => $run, 'rethrow' => $rethrow];
				$run->setStatus(FlowRun::STATUS_COMPLETED);

				return $run;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$access = $this->createMock(FlowAccess::class);
		$access->method('currentUser')->willReturn($user);
		$access->method('callerIsAdmin')->willReturn(false);
		$access->method('may')->willReturn(false);

		return new FlowService(
			new FlowDefinitionStore($mapper, $this->createMock(FlowTriggerIndex::class)),
			new FlowRunDispatch($runner, $advancer, new FlowRunAuthorization(access: $access)),
			new FlowDeletionSweep(
				$this->createMock(FlowRunMapper::class),
				$this->createMock(FlowRunStepMapper::class),
				$this->createMock(FlowStateMapper::class)
			),
			new FlowServicePlatform($session, $this->createMock(LoggerInterface::class), $this->organisation())
		);
	}//end service()

	/**
	 * A container whose organisation service names org-a as active.
	 *
	 * @return ContainerInterface
	 */
	private function organisation(): ContainerInterface {
		$organisations = new class {

			/**
			 * The active organisation.
			 *
			 * @return object
			 */
			public function getActiveOrganisation(): object {
				return new class {

					/**
					 * The uuid.
					 *
					 * @return string
					 */
					public function getUuid(): string {
						return 'org-a';
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($organisations);

		return $container;
	}//end organisation()

	/**
	 * The owner's run is queued as the owner, with the trigger asked for, and not advanced.
	 *
	 * @return void
	 */
	public function testTheOwnersRunIsQueuedAsTheActingUser(): void {
		$run = $this->service(uid: 'anja')->run('flow-1', ['uuid' => 'o-1'], ['k' => 'v'], false, 'test');

		$this->assertSame('run-1', $run->getUuid());
		$this->assertSame(
			[['flowId' => 'flow-1', 'subject' => ['uuid' => 'o-1'], 'trigger' => 'test', 'context' => ['k' => 'v'], 'user' => 'anja']],
			$this->queued
		);
		$this->assertSame([], $this->advanced);
	}//end testTheOwnersRunIsQueuedAsTheActingUser()

	/**
	 * A caller who waits gets the queued row advanced inline, with errors rethrown.
	 *
	 * @return void
	 */
	public function testAWaitingCallerGetsTheQueuedRunAdvancedInline(): void {
		$run = $this->service(uid: 'anja')->run('flow-1', [], [], true);

		$this->assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus());
		$this->assertSame(Flow::TRIGGER_MANUAL, $this->queued[0]['trigger']);
		$this->assertCount(1, $this->advanced);
		$this->assertSame('run-1', $this->advanced[0]['run']->getUuid());
		$this->assertTrue($this->advanced[0]['rethrow']);
	}//end testAWaitingCallerGetsTheQueuedRunAdvancedInline()

	/**
	 * A colleague who neither owns the flow nor may edit it is refused before anything is queued.
	 *
	 * @return void
	 */
	public function testAColleagueIsRefusedBeforeAnythingIsQueued(): void {
		try {
			$this->service(uid: 'bram')->run('flow-1', [], [], true);
			$this->fail('a colleague ran a flow that is not theirs');
		} catch (FlowRunRefused $refused) {
			$this->assertSame(FlowRunAuthorization::NOT_YOURS, $refused->getVerdict());
		}

		$this->assertSame([], $this->queued);
		$this->assertSame([], $this->advanced);
	}//end testAColleagueIsRefusedBeforeAnythingIsQueued()

	/**
	 * Another organisation's flow reads as absent, even to its owner's uid.
	 *
	 * @return void
	 */
	public function testAnotherOrganisationsFlowReadsAsAbsent(): void {
		$this->expectException(DoesNotExistException::class);

		try {
			$this->service(uid: 'anja', organisation: 'org-b')->run('flow-1');
		} finally {
			$this->assertSame([], $this->queued);
		}
	}//end testAnotherOrganisationsFlowReadsAsAbsent()

	/**
	 * The declared-action path queues as the acting user without the owner check.
	 *
	 * @return void
	 */
	public function testADeclaredActionRunsAsTheActingUserWithoutTheOwnerCheck(): void {
		$run = $this->service(uid: 'bram')->runForAction('flow-1', new \OCA\OpenRegister\Service\Flow\FlowRunOptions(subject: ['uuid' => 'o-2'], context: ['a' => 1], sync: false, trigger: Flow::TRIGGER_MANUAL));

		$this->assertSame('run-1', $run->getUuid());
		$this->assertSame(
			[['flowId' => 'flow-1', 'subject' => ['uuid' => 'o-2'], 'trigger' => Flow::TRIGGER_MANUAL, 'context' => ['a' => 1], 'user' => 'bram']],
			$this->queued
		);
		$this->assertSame([], $this->advanced);

		$this->service(uid: 'bram')->runForAction('flow-1', new \OCA\OpenRegister\Service\Flow\FlowRunOptions(subject: [], context: [], sync: true, trigger: Flow::TRIGGER_MANUAL));
		$this->assertCount(1, $this->advanced);
		$this->assertTrue($this->advanced[0]['rethrow']);
	}//end testADeclaredActionRunsAsTheActingUserWithoutTheOwnerCheck()
}//end class
