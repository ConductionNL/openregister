<?php

/**
 * Golden test: who may queue a flow run, and what a refused run leaves behind.
 *
 * Pins FlowRunService::queue()'s attribution gate before its body moves behind
 * an options object: an attributed caller gets a queued run that executes as
 * them; a run nothing attributes is refused with FlowUnattributed before any
 * row is written, the refusal is recorded on the flow, a schedule is switched
 * off, and a failure to record never replaces the refusal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegated-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunStores;
use OCA\OpenRegister\Service\Flow\FlowRunWalkServices;
use OCA\OpenRegister\Service\Flow\FlowUnattributed;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\Flow\FlowRunQueueOptions;

/**
 * Allowed and refused outcomes of queue().
 */
class FlowRunQueueAccessGoldenTest extends TestCase {
	use PublishedVersionDouble;

	/**
	 * Rows the run mapper inserted.
	 *
	 * @var array<int, FlowRun>
	 */
	private array $inserted = [];

	/**
	 * Flows the flow mapper was asked to update.
	 *
	 * @var array<int, array{status: string|null, message: string|null, enabled: bool|null}>
	 */
	private array $flowUpdates = [];

	/**
	 * Warnings logged, by message.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Build the service around a flow that declares no acting identity.
	 *
	 * @param boolean $recordFails Whether writing the refusal onto the flow throws.
	 *
	 * @return FlowRunService The service.
	 */
	private function service(bool $recordFails = false): FlowRunService {
		$runs = $this->createMock(FlowRunMapper::class);
		$runs->method('insert')->willReturnCallback(
			function (FlowRun $run): FlowRun {
				$this->inserted[] = $run;
				return $run;
			}
		);

		$flow = new Flow();
		$flow->setUuid('f1');
		$flow->setEnabled(true);
		$flows = $this->createMock(FlowMapper::class);
		$flows->method('findByUuid')->willReturn($flow);
		$flows->method('update')->willReturnCallback(
			function (Flow $updated) use ($recordFails): Flow {
				if ($recordFails === true) {
					throw new RuntimeException('database gone');
				}

				$this->flowUpdates[] = ['status' => $updated->getStatus(), 'message' => $updated->getStatusMessage(), 'enabled' => $updated->getEnabled()];
				return $updated;
			}
		);

		$versions = $this->publishedVersionMapper();
		$pin = $this->pinReturning();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($versions, $pin, $flows): object {
				$known = [
					\OCA\OpenRegister\Db\FlowVersionMapperInterface::class => $versions,
					\OCA\OpenRegister\Service\Flow\FlowDefinitionPin::class => $pin,
					'OCA\OpenRegister\Db\FlowMapper' => $flows,
				];
				if (isset($known[$id]) === true) {
					return $known[$id];
				}

				throw new RuntimeException('not available: ' . $id);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		$registry = new FlowNodeRegistry($this->createMock(IEventDispatcher::class), $this->createMock(LoggerInterface::class));
		return new FlowRunService(
			stores: new FlowRunStores(mapper: $runs, stateMapper: $this->createMock(\OCA\OpenRegister\Db\FlowStateMapper::class)),
			walk: new FlowRunWalkServices(engine: new FlowEngine(new FlowDefinitionBuilder(), $logger), registry: $registry),
			logger: $logger,
			container: $container
		);
	}//end service()

	/**
	 * Queue and report the outcome as data.
	 *
	 * @param FlowRunService $service The service.
	 * @param string|null    $user    The caller.
	 * @param string         $trigger What started the run.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function outcome(FlowRunService $service, ?string $user, string $trigger): array {
		try {
			$run = $service->queueRun(flowId: 'f1', options: new FlowRunQueueOptions(subject: ['uuid' => 'o1'], trigger: $trigger, context: ['k' => 'v'], user: $user));
		} catch (FlowUnattributed $refused) {
			return [
				'refused' => true,
				'flow' => $refused->getFlowId(),
				'trigger' => $refused->getTrigger(),
				'inserted' => count($this->inserted),
				'flowUpdates' => $this->flowUpdates,
			];
		}

		return [
			'refused' => false,
			'status' => $run->getStatus(),
			'runAs' => $run->getRunAs(),
			'trigger' => $run->getTrigger(),
			'inserted' => count($this->inserted),
			'flowUpdates' => $this->flowUpdates,
		];
	}//end outcome()

	/**
	 * An attributed caller gets a queued run that executes as them; the flow is untouched.
	 *
	 * @return void
	 */
	public function testAnAttributedCallerIsQueued(): void {
		$this->assertSame(
			['refused' => false, 'status' => FlowRun::STATUS_QUEUED, 'runAs' => 'alice', 'trigger' => 'manual', 'inserted' => 1, 'flowUpdates' => []],
			$this->outcome(service: $this->service(), user: 'alice', trigger: 'manual')
		);
	}//end testAnAttributedCallerIsQueued()

	/**
	 * Nobody named: refused before a row exists, and the refusal is written onto the flow.
	 *
	 * @return void
	 */
	public function testAnUnattributedRunIsRefusedAndRecorded(): void {
		$outcome = $this->outcome(service: $this->service(), user: null, trigger: 'manual');

		$this->assertTrue($outcome['refused']);
		$this->assertSame(['f1', 'manual', 0], [$outcome['flow'], $outcome['trigger'], $outcome['inserted']]);
		$this->assertCount(1, $outcome['flowUpdates']);
		$this->assertSame(Flow::STATUS_ERROR, $outcome['flowUpdates'][0]['status']);
		$this->assertNotSame('', (string)$outcome['flowUpdates'][0]['message']);
		$this->assertTrue($outcome['flowUpdates'][0]['enabled'], 'only a schedule is switched off');
		$this->assertContains('[FlowRunService] Refused to queue an unattributed run', $this->warnings);
	}//end testAnUnattributedRunIsRefusedAndRecorded()

	/**
	 * An empty uid is nobody: refused exactly like a missing one.
	 *
	 * @return void
	 */
	public function testAnEmptyCallerIsRefused(): void {
		$outcome = $this->outcome(service: $this->service(), user: '', trigger: 'manual');

		$this->assertTrue($outcome['refused']);
		$this->assertSame(0, $outcome['inserted']);
	}//end testAnEmptyCallerIsRefused()

	/**
	 * A refused schedule is switched off, so it does not stay "on" while firing nothing.
	 *
	 * @return void
	 */
	public function testARefusedScheduleIsDisabled(): void {
		$outcome = $this->outcome(service: $this->service(), user: null, trigger: 'schedule');

		$this->assertTrue($outcome['refused']);
		$this->assertSame('schedule', $outcome['trigger']);
		$this->assertSame(Flow::STATUS_ERROR, $outcome['flowUpdates'][0]['status']);
		$this->assertFalse($outcome['flowUpdates'][0]['enabled']);
	}//end testARefusedScheduleIsDisabled()

	/**
	 * Failing to record the refusal never replaces it: the caller still gets FlowUnattributed.
	 *
	 * @return void
	 */
	public function testAFailedRecordStillRefuses(): void {
		$outcome = $this->outcome(service: $this->service(recordFails: true), user: null, trigger: 'schedule');

		$this->assertTrue($outcome['refused']);
		$this->assertSame([0, []], [$outcome['inserted'], $outcome['flowUpdates']]);
		$this->assertCount(2, $this->warnings, 'the refusal and the failed record are both logged');
	}//end testAFailedRecordStillRefuses()
}//end class
