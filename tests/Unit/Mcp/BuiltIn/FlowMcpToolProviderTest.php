<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Mcp\BuiltIn;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Mcp\BuiltIn\FlowMcpToolProvider;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class FlowMcpToolProviderTest extends TestCase {
	private FlowRunService $runner;
	private FlowRunMapper $mapper;
	private IUserSession&MockObject $userSession;
	private FlowMcpToolProvider $provider;
	// Declared, not created on the fly. Assigning an undeclared property in
	// setUp() is deprecated in PHP 8.2 and an error in PHP 9, and it also
	// costs the type: an undeclared $flows is mixed, so nothing checks that
	// the provider is handed a FlowService at all.
	private \OCA\OpenRegister\Service\Flow\FlowService&MockObject $flows;
	private \OCA\OpenRegister\Service\Flow\FlowNodePreflight&MockObject $preflight;

	protected function setUp(): void {
		$this->runner = $this->createMock(FlowRunService::class);
		$this->mapper = $this->createMock(FlowRunMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		// The ObjectService and IAppConfig mocks that used to be supplied here
		// are gone with the collaborators themselves. runFlow()'s guard resolves
		// the flow through FlowService — the store flows actually live in — so
		// an ObjectService is no longer part of the answer, and PHPStan was
		// failing `PHP Quality (phpstan)` on the two properties left injected
		// and never read.
		$this->flows = $this->createMock(\OCA\OpenRegister\Service\Flow\FlowService::class);
		$this->preflight = $this->createMock(\OCA\OpenRegister\Service\Flow\FlowNodePreflight::class);
		$this->preflight->method('inspect')->willReturn(['blocking' => [], 'warnings' => []]);

		$this->provider = new FlowMcpToolProvider(
			$this->runner,
			$this->mapper,
			$this->flows,
			$this->preflight,
			$this->userSession
		);
	}

	/**
	 * Put a signed-in user on the session mock.
	 */
	private function signedInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testTheAppIdIsOpenregister(): void {
		$this->assertSame('openregister', $this->provider->getAppId());
	}

	/**
	 * Every tool id must be namespaced with the app id, or McpToolsService
	 * silently drops it.
	 */
	public function testEveryToolIsNamespacedAndWellFormed(): void {
		foreach ($this->provider->getTools() as $tool) {
			$this->assertStringStartsWith('openregister.', $tool['id']);
			$this->assertArrayHasKey('name', $tool);
			$this->assertArrayHasKey('description', $tool);
			$this->assertSame('object', $tool['inputSchema']['type']);
		}
	}

	/**
	 * ADR-063 / #2159: both tools declare their annotation hints and scope, so
	 * a consumer never has to fall back to classifying a read-only status poll
	 * as a destructive write.
	 */
	public function testEveryToolDeclaresItsAnnotationHintsAndScope(): void {
		$byId = [];
		foreach ($this->provider->getTools() as $tool) {
			foreach (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'scope'] as $key) {
				$this->assertArrayHasKey($key, $tool, $tool['id'] . ' must declare ' . $key);
			}

			$byId[$tool['id']] = $tool;
		}

		$this->assertFalse($byId['openregister.runFlow']['readOnlyHint']);
		$this->assertTrue($byId['openregister.runFlow']['destructiveHint']);
		$this->assertFalse($byId['openregister.runFlow']['idempotentHint']);
		$this->assertSame('create', $byId['openregister.runFlow']['scope']);

		$this->assertTrue($byId['openregister.flowRunStatus']['readOnlyHint']);
		$this->assertFalse($byId['openregister.flowRunStatus']['destructiveHint']);
		$this->assertTrue($byId['openregister.flowRunStatus']['idempotentHint']);
		$this->assertSame('read', $byId['openregister.flowRunStatus']['scope']);
	}

	public function testRunFlowQueuesARunAndReturnsItsUuid(): void {
		$this->signedInAs('alice');

		$run = new FlowRun();
		$run->setUuid('run-123');
		$run->setStatus(FlowRun::STATUS_QUEUED);

		$this->runner->expects($this->once())
			->method('queue')
			->with(
				$this->equalTo('f1'),
				$this->callback(fn ($s) => $s['uuid'] === 'u1'),
				$this->equalTo('mcp')
			)
			->willReturn($run);

		$result = $this->provider->invokeTool('openregister.runFlow', [
			'flowId' => 'f1',
			'uuid' => 'u1',
			'register' => 'reg',
			'schema' => 'sch',
		]);

		$this->assertSame('run-123', $result['runUuid']);
		$this->assertTrue($result['queued']);
	}

	/**
	 * #2158: the acting session user must reach queue(), or every
	 * agent-dispatched run is recorded with a null triggeredBy and every
	 * downstream node that needs an owner degrades.
	 */
	public function testRunFlowAttributesTheRunToTheSessionUser(): void {
		$this->signedInAs('alice');

		$run = new FlowRun();
		$run->setUuid('run-42');
		$run->setStatus(FlowRun::STATUS_QUEUED);

		$seen = null;
		$this->runner->expects($this->once())
			->method('queue')
			->willReturnCallback(
				function (string $flowId, array $subject, string $trigger, array $context, ?string $user) use ($run, &$seen) {
					$seen = $user;
					return $run;
				}
			);

		$this->provider->invokeTool('openregister.runFlow', ['flowId' => 'f1']);

		$this->assertSame('alice', $seen);
	}

	/**
	 * With no session user there is no actor to invent — queue() is handed
	 * null, exactly the shape it already accepts, rather than a fabricated uid.
	 */
	public function testRunFlowPassesNullWhenThereIsNoSessionUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$run = new FlowRun();
		$run->setUuid('run-43');
		$run->setStatus(FlowRun::STATUS_QUEUED);

		$seen = 'untouched';
		$this->runner->expects($this->once())
			->method('queue')
			->willReturnCallback(
				function (string $flowId, array $subject, string $trigger, array $context, ?string $user) use ($run, &$seen) {
					$seen = $user;
					return $run;
				}
			);

		$this->provider->invokeTool('openregister.runFlow', ['flowId' => 'f1']);

		$this->assertNull($seen);
	}

	public function testRunFlowNeedsAFlowId(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->provider->invokeTool('openregister.runFlow', []);
	}

	public function testFlowRunStatusReturnsTheRun(): void {
		$run = new FlowRun();
		$run->setUuid('run-9');
		$run->setStatus(FlowRun::STATUS_COMPLETED);
		$this->mapper->method('findByUuid')->with('run-9')->willReturn($run);

		$result = $this->provider->invokeTool('openregister.flowRunStatus', ['runUuid' => 'run-9']);

		$this->assertTrue($result['found']);
		$this->assertSame('completed', $result['status']);
	}

	public function testFlowRunStatusReturnsNotFoundRatherThanThrowing(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('nope'));

		$result = $this->provider->invokeTool('openregister.flowRunStatus', ['runUuid' => 'ghost']);

		$this->assertFalse($result['found']);
	}

	public function testFlowRunStatusNeedsARunUuid(): void {
		$this->mapper->expects($this->never())->method('findByUuid');

		$this->expectException(UnexpectedValueException::class);
		$this->provider->invokeTool('openregister.flowRunStatus', ['runUuid' => '  ']);
	}

	public function testRunFlowRefusesAFlowTheCallerCannotSee(): void {
		$this->flows->method('find')->willThrowException(new DoesNotExistException('hidden'));
		$this->runner->expects($this->never())->method('queue');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('No such flow: f9');
		$this->provider->invokeTool('openregister.runFlow', ['flowId' => 'f9']);
	}

	public function testRunFlowRefusesAFlowTheCallerMayNotRun(): void {
		$this->flows->method('assertRunnable')
			->willThrowException(new \OCA\OpenRegister\Exception\FlowRunRefused(verdict: 'unadopted', message: 'Nobody adopted this flow.'));
		$this->runner->expects($this->never())->method('queue');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Nobody adopted this flow.');
		$this->provider->invokeTool('openregister.runFlow', ['flowId' => 'f1']);
	}

	public function testSaveFlowNeedsAName(): void {
		$this->flows->expects($this->never())->method('save');

		$this->expectException(UnexpectedValueException::class);
		$this->provider->invokeTool('openregister.saveFlow', ['name' => '   ']);
	}

	/**
	 * A new flow is saved through FlowService with a null uuid and the given
	 * fields, defaults filled in for the ones the caller left out.
	 */
	public function testSaveFlowCreatesAFlowThroughTheService(): void {
		$seen = [];
		$this->flows->expects($this->once())
			->method('save')
			->willReturnCallback(
				function (array $data, ?string $uuid) use (&$seen) {
					$seen = ['data' => $data, 'uuid' => $uuid];
					$flow = new \OCA\OpenRegister\Db\Flow();
					$flow->setUuid('flow-1');
					$flow->setName($data['name']);
					$flow->setEnabled($data['enabled']);
					$flow->setNodes([['id' => 'a'], ['id' => 'b']]);
					$flow->setEdges([['from' => 'a', 'to' => 'b']]);
					return $flow;
				}
			);

		$result = $this->provider->invokeTool('openregister.saveFlow', ['name' => ' Intake ']);

		$this->assertNull($seen['uuid']);
		$this->assertSame(
			[
				'name' => 'Intake',
				'description' => '',
				'app' => 'openregister',
				'enabled' => false,
				'nodes' => [],
				'edges' => [],
			],
			$seen['data']
		);
		$this->assertSame(
			[
				'uuid' => 'flow-1',
				'name' => 'Intake',
				'enabled' => false,
				'nodes' => 2,
				'edges' => 1,
				'blocking' => [],
				'warnings' => [],
			],
			$result
		);
	}

	/**
	 * A given uuid targets that flow, and the preflight runs on the saved
	 * document so its findings come back with the result.
	 */
	public function testSaveFlowUpdatesTheGivenFlowAndReturnsThePreflightFindings(): void {
		$preflight = $this->createMock(\OCA\OpenRegister\Service\Flow\FlowNodePreflight::class);
		$preflight->expects($this->once())
			->method('inspect')
			->with(['nodes' => [['id' => 'a']], 'edges' => []])
			->willReturn(['blocking' => ['dead end at a'], 'warnings' => ['no trigger']]);
		$provider = new FlowMcpToolProvider($this->runner, $this->mapper, $this->flows, $preflight, $this->userSession);

		$flow = new \OCA\OpenRegister\Db\Flow();
		$flow->setUuid('flow-7');
		$flow->setName('Intake');
		$flow->setEnabled(true);
		$flow->setNodes([['id' => 'a']]);
		$flow->setEdges([]);
		$this->flows->expects($this->once())
			->method('save')
			->with(
				$this->callback(fn (array $data) => $data['enabled'] === true && $data['app'] === 'dossiq' && $data['nodes'] === [['id' => 'a']]),
				'flow-7'
			)
			->willReturn($flow);

		$result = $provider->invokeTool('openregister.saveFlow', [
			'name' => 'Intake',
			'uuid' => ' flow-7 ',
			'app' => 'dossiq',
			'enabled' => true,
			'nodes' => [['id' => 'a']],
		]);

		$this->assertSame('flow-7', $result['uuid']);
		$this->assertTrue($result['enabled']);
		$this->assertSame(['dead end at a'], $result['blocking']);
		$this->assertSame(['no trigger'], $result['warnings']);
	}

	public function testAnUnknownToolThrows(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->provider->invokeTool('openregister.somethingElse', []);
	}
}
