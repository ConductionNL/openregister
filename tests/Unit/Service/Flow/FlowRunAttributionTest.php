<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowUnattributed;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A queued run must name who it runs as and which tenant owns it.
 *
 * Every dispatch path lands in FlowRunService::queue(): manual, event trigger,
 * cron schedule, MCP, the workflow-engine operation and a sub-flow call. Only
 * the first has a session. Before this, the rest recorded `triggeredBy = null`
 * and `organisation = null`, which makes a run unattributable — and every node
 * that requires attribution then refuses (ObjectWriteNode answers "this flow
 * run has no owner"). It had been patched at four individual call sites; these
 * tests pin the behaviour at the one place they all pass through.
 */
class FlowRunAttributionTest extends TestCase {
	/**
	 * Build the service with a flow the mapper will return.
	 *
	 * @param Flow|null   $flow         The flow to resolve, or null to make the lookup fail.
	 * @param string|null $activeOrg    The organisation a session would resolve, if any.
	 *
	 * @return FlowRunService The service under test.
	 */
	private function service(?Flow $flow, ?string $activeOrg = null): FlowRunService {
		$runMapper = $this->createMock(FlowRunMapper::class);
		$runMapper->method('insert')->willReturnArgument(0);
		$runMapper->method('update')->willReturnArgument(0);

		$flowMapper = $this->createMock(FlowMapper::class);
		if ($flow === null) {
			$flowMapper->method('findByUuid')->willThrowException(new \RuntimeException('no such flow'));
		} else {
			$flowMapper->method('findByUuid')->willReturn($flow);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($flowMapper, $activeOrg): object {
				if ($id === 'OCA\OpenRegister\Db\FlowMapper') {
					return $flowMapper;
				}

				// A session-less caller: OrganisationService is unavailable, so
				// activeOrganisation() yields null and the flow has to answer.
				if ($id === 'OCA\OpenRegister\Service\OrganisationService' && $activeOrg !== null) {
					return new class($activeOrg) {
						public function __construct(private readonly string $uuid) {
						}

						public function getActiveOrganisation(): object {
							return new class($this->uuid) {
								public function __construct(private readonly string $uuid) {
								}

								public function getUuid(): string {
									return $this->uuid;
								}
							};
						}
					};
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$registry = new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class));
		$engine = new FlowEngine(new FlowDefinitionBuilder(), $this->createMock(LoggerInterface::class));

		return new FlowRunService(
			$runMapper,
			$this->createMock(FlowStateMapper::class),
			$engine,
			$registry,
			$this->createMock(LoggerInterface::class),
			$container
		);
	}

	/**
	 * An owned flow with no dead ends.
	 *
	 * @param string|null $owner The flow's owner uid.
	 * @param string|null $org   The flow's organisation uuid.
	 *
	 * @return Flow The flow.
	 */
	private function ownedFlow(?string $owner, ?string $org): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setOwner($owner);
		$flow->setOrganisation($org);
		// A single terminal node, so refuseDeadEnd() has nothing to refuse.
		$flow->setNodes([['id' => 'stop', 'type' => 'end']]);
		$flow->setEdges([]);

		return $flow;
	}

	/**
	 * A scheduled run with no declared identity is REFUSED, not borrowed.
	 *
	 * This is the behaviour ADR-099 changes, and the reason is not stylistic:
	 * `flow.owner` answers "who may edit this definition", and reading it as an
	 * acting identity turns authoring a flow into open-ended consent to
	 * unattended execution as the author, under whatever triggers anyone later
	 * adds. Nothing records that consent, so it is not assumed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	public function testASessionlessRunWithNoDeclaredIdentityIsRefused(): void {
		$this->expectException(FlowUnattributed::class);

		$this->service($this->ownedFlow('alice', 'org-a'))
			->queue(flowId: 'flow-1', trigger: 'schedule');
	}

	/**
	 * A scheduled run acts as the identity its trigger node declares.
	 *
	 * The trigger is where a run begins, and a flow may carry several — so the
	 * identity belongs to the entry point, not to the document.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testAScheduledRunActsAsItsTriggersDeclaredIdentity(): void {
		$flow = $this->ownedFlow('alice', 'org-a');
		$flow->setNodes([
			['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => ['runAs' => 'carol']],
			['id' => 'stop', 'type' => 'end'],
		]);

		$run = $this->service($flow)->queue(flowId: 'flow-1', trigger: 'schedule');

		$this->assertSame('carol', $run->getRunAs(), 'the trigger names the acting identity');
		$this->assertNotSame('alice', $run->getRunAs(), "the flow's owner must not answer");
		$this->assertSame('org-a', $run->getOrganisation(), 'tenancy still falls back to the flow');
	}

	/**
	 * A trigger node still mid-cutover declares nothing, so the run is refused.
	 *
	 * Measured on the dev instance: all three flows carrying a schedule trigger
	 * store `config: []` and keep their cron in the legacy column. Such a node
	 * names nobody, and an empty config must not read as "no restriction".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testAnEmptyTriggerConfigDeclaresNothing(): void {
		$flow = $this->ownedFlow('alice', 'org-a');
		$flow->setNodes([
			['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => []],
			['id' => 'stop', 'type' => 'end'],
		]);

		$this->expectException(FlowUnattributed::class);

		$this->service($flow)->queue(flowId: 'flow-1', trigger: 'schedule');
	}

	/**
	 * The caller wins over anything the document declares.
	 *
	 * A manual run is the acting user's act; blaming the flow's author for
	 * somebody else's click would be a worse answer than none.
	 *
	 * @return void
	 */
	public function testAnActingUserOutranksTheDocument(): void {
		$run = $this->service($this->ownedFlow('alice', 'org-a'), 'org-b')
			->queue(flowId: 'flow-1', trigger: 'manual', user: 'bob');

		$this->assertSame('bob', $run->getTriggeredBy());
		$this->assertSame('bob', $run->getRunAs());
		$this->assertSame('org-b', $run->getOrganisation());
	}

	/**
	 * A caller with no organisation still gets the tenant from the flow.
	 *
	 * Identity and tenancy resolve independently, and this is the mixed case
	 * that proves it: the caller answers for identity while the flow answers
	 * for tenancy, in one resolution.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testTheTenantComesFromTheFlowWhenTheSessionHasNone(): void {
		$run = $this->service($this->ownedFlow('alice', 'org-a'))
			->queue(flowId: 'flow-1', trigger: 'manual', user: 'bob');

		$this->assertSame('bob', $run->getTriggeredBy());
		$this->assertSame('org-a', $run->getOrganisation());
	}

	/**
	 * An empty string is not an identity.
	 *
	 * A blank `runAs` must refuse exactly as a missing one does. Storing `''`
	 * would make the field look answered while naming nobody, which is harder to
	 * spot than a null and fails later, further from the cause.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	public function testABlankDeclaredIdentityIsNotAnIdentity(): void {
		$flow = $this->ownedFlow('alice', 'org-a');
		$flow->setNodes([
			['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => ['runAs' => '   ']],
			['id' => 'stop', 'type' => 'end'],
		]);

		$this->expectException(FlowUnattributed::class);

		$this->service($flow)->queue(flowId: 'flow-1', trigger: 'schedule');
	}

	/**
	 * A flow that cannot be loaded is refused, not silently attributed.
	 *
	 * `resolve()` still must not throw on a lookup failure — the downstream
	 * not-found handling is what should speak — but the resulting run has no
	 * identity, so the queue refuses it rather than writing an unattributed row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	public function testAnUnloadableFlowIsRefusedRatherThanUnattributed(): void {
		$this->expectException(FlowUnattributed::class);

		$this->service(null)->queue(flowId: 'ghost', trigger: 'schedule');
	}
}
