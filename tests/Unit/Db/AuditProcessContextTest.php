<?php
/**
 * AuditProcessContextTest: the process work in flight, as the audit trail reads it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditProcessContext;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Kernel\Contracts\Compliance\AuditProcessContextInterface;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunContextInterface;
use OCA\OpenRegister\Service\Flow\FlowRunFrame;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPassInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContextInterface;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\AuditProcessContext
 * @uses   \OCA\OpenRegister\Db\AuditFlowAttribution
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunContext
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunFrame
 * @uses   \OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext
 */
final class AuditProcessContextTest extends TestCase {

	/**
	 * The services the fake container answers, by id.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * A process context over a container that knows only the given services.
	 *
	 * @param array<string, object> $services The services by id.
	 *
	 * @return AuditProcessContext
	 */
	private function context(array $services): AuditProcessContext {
		$this->services = $services;
		$container      = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if (isset($this->services[$id]) === true) {
					return $this->services[$id];
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		return new AuditProcessContext(db: $this->createMock(IDBConnection::class), container: $container);
	}//end context()

	/**
	 * It is the audit trail's port.
	 *
	 * @return void
	 */
	public function testItAnswersTheAuditTrailsPort(): void {
		$this->assertInstanceOf(AuditProcessContextInterface::class, $this->context(services: []));
	}//end testItAnswersTheAuditTrailsPort()

	/**
	 * A row built during a run carries the run, node and step.
	 *
	 * @return void
	 */
	public function testARowInARunIsStampedWithTheFrame(): void {
		$run = new FlowRunContext();
		$run->push(frame: new FlowRunFrame(runUuid: 'run-abc', nodeId: 'retention-sweep', sequence: 7));
		$row = new AuditTrail();

		$this->context(services: [FlowRunContextInterface::class => $run])->stampFlowFrame(auditTrail: $row);

		$this->assertSame('run-abc', $row->getFlowRun());
		$this->assertSame('retention-sweep', $row->getFlowNode());
		$this->assertSame(7, $row->getFlowStep());
	}//end testARowInARunIsStampedWithTheFrame()

	/**
	 * Without a run context the row stays unattributed.
	 *
	 * @return void
	 */
	public function testARowOutsideARunIsLeftUntouched(): void {
		$row = new AuditTrail();

		$this->context(services: [])->stampFlowFrame(auditTrail: $row);

		$this->assertNull($row->getFlowRun());
		$this->assertNull($row->getFlowNode());
		$this->assertNull($row->getFlowStep());
	}//end testARowOutsideARunIsLeftUntouched()

	/**
	 * The automatic action is the one the pass is applying.
	 *
	 * @return void
	 */
	public function testTheAutomaticTransitionIsThePassesAction(): void {
		$pass = $this->createMock(AutoTransitionPassInterface::class);
		$pass->method('applyingAction')->willReturn('close');

		$this->assertSame('close', $this->context(services: [AutoTransitionPassInterface::class => $pass])->automaticTransition());
	}//end testTheAutomaticTransitionIsThePassesAction()

	/**
	 * An unwired pass means no automatic transition.
	 *
	 * @return void
	 */
	public function testAnUnwiredPassMeansNoAutomaticTransition(): void {
		$this->assertNull($this->context(services: [])->automaticTransition());
	}//end testAnUnwiredPassMeansNoAutomaticTransition()

	/**
	 * A declared reopen is named; another declared move, another object, an empty uuid and an unwired frame are not.
	 *
	 * @return void
	 */
	public function testOnlyADeclaredReopenHasAKind(): void {
		$actions = new LifecycleActionContext();
		$context = $this->context(services: [LifecycleActionContextInterface::class => $actions]);

		$actions->declare(uuid: 'u-7', action: 'reopen');
		$this->assertSame('reopen', $context->transitionKindFor(uuid: 'u-7'));
		$this->assertNull($context->transitionKindFor(uuid: 'u-8'));
		$this->assertNull($context->transitionKindFor(uuid: ''));
		$actions->release(uuid: 'u-7');

		$actions->declare(uuid: 'u-7', action: 'close');
		$this->assertNull($context->transitionKindFor(uuid: 'u-7'));
		$actions->release(uuid: 'u-7');

		$this->assertNull($this->context(services: [])->transitionKindFor(uuid: 'u-7'));
	}//end testOnlyADeclaredReopenHasAKind()
}//end class
