<?php

/**
 * Golden access test: what the lock and unlock steps allow and refuse.
 *
 * Recorded on the code before the two steps shared LockStepContext. For each
 * scenario it pins the outcome (items returned, refusal class and message,
 * or suspension reason) AND every call that reached the object layer, so a
 * refusal that started to happen after a lock was taken, or a lock taken as
 * a different user, shows up as drift.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\UnlockObjectNode;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Service\Object\LockRequest;
use OCA\OpenRegister\Service\Object\UnlockRequest;

/**
 * Pins the allowed and refused outcomes of lock-object and unlock-object.
 */
final class LockNodesAccessGoldenTest extends TestCase {
	use NodeSurfaceSnapshot;

	/**
	 * Calls that reached the object layer in the current scenario.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * Whether lockObject() refuses in the current scenario.
	 *
	 * @var boolean
	 */
	private bool $lockRefuses = false;

	/**
	 * The object layer, recording every call, as the user it was entered for.
	 *
	 * @return ObjectOperationsInterface The mock.
	 */
	private function objects(): ObjectOperationsInterface {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation) {
				$this->calls[] = ['runAs', $user->getUID()];
				return $operation();
			}
		);
		$objects->method('lockObject')->willReturnCallback(
			function (LockRequest $request, bool $advisory=false): array {
				$this->calls[] = ['lockObject', [$request->identifier, $request->process, $request->duration, $advisory, $request->runUuid, $request->nodeId]];
				if ($this->lockRefuses === true) {
					throw new RuntimeException('held by another run');
				}

				return ['locked' => true];
			}
		);
		$objects->method('unlockObject')->willReturnCallback(
			function (UnlockRequest $request): bool {
				$this->calls[] = ['unlockObject', [$request->identifier, $request->advisory, $request->runUuid]];
				return true;
			}
		);

		return $objects;
	}//end objects()

	/**
	 * Users: alice is enabled, dora is disabled, everybody else does not exist.
	 *
	 * @return IUserManager The mock.
	 */
	private function users(): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if (in_array($uid, ['alice', 'dora'], true) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('isEnabled')->willReturn($uid === 'alice');
				return $user;
			}
		);

		return $users;
	}//end users()

	/**
	 * A run context.
	 *
	 * @param array $stored The node's stored resume values.
	 * @param array $extra  Keys to add or replace.
	 *
	 * @return array The context.
	 */
	private function context(array $stored = [], array $extra = []): array {
		$state = new FlowResumeState(['lock' => $stored]);
		return array_merge(
			[
				FlowNodeResumeState::CONTEXT_KEY => $state->forNode('lock'),
				FlowRunContext::CONTEXT_RUN => 'run-1',
				'runAs' => 'alice',
			],
			$extra
		);
	}//end context()

	/**
	 * The scenarios: [items, config, context, lock refuses].
	 *
	 * @return array<string, array{0: array, 1: array, 2: array, 3: bool}> The scenarios.
	 */
	private function scenarios(): array {
		$two = [['json' => ['uuid' => 'o-1', 'ref' => 'r-9']], ['json' => ['uuid' => 'o-1']], ['json' => ['uuid' => ' o-2 ']]];
		return [
			'no-items' => [[], [], $this->context(), false],
			'allowed' => [$two, [], $this->context(), false],
			'allowed-configured' => [[['json' => ['ref' => 'r-9']]], ['uuid' => '{{ ref }}', 'duration' => 60, 'process' => 'review'], $this->context(), false],
			'allowed-legacy-run-key' => [$two, [], $this->context(extra: [FlowRunContext::CONTEXT_RUN => null, 'runUuid' => 'run-legacy']), false],
			'refused-bad-uuid-config' => [$two, ['uuid' => 5], $this->context(), false],
			'refused-bad-duration' => [$two, ['duration' => 0], $this->context(), false],
			'refused-no-resume' => [$two, [], $this->context(extra: [FlowNodeResumeState::CONTEXT_KEY => null]), false],
			'refused-no-run' => [$two, [], $this->context(extra: [FlowRunContext::CONTEXT_RUN => ' ']), false],
			'refused-no-identity' => [$two, [], $this->context(extra: ['runAs' => ' ']), false],
			'refused-unknown-identity' => [$two, [], $this->context(extra: ['runAs' => 'mallory']), false],
			'refused-disabled-identity' => [$two, [], $this->context(extra: ['runAs' => 'dora']), false],
			'refused-no-target' => [[['json' => ['name' => 'x']]], [], $this->context(), false],
			'refused-non-array-json' => [[['json' => 'x']], [], $this->context(), false],
			'held-waits' => [$two, [], $this->context(), true],
			'held-gives-up' => [$two, [], $this->context(stored: [LockObjectNode::SLOT_DEADLINE => '2000-01-01T00:00:00+00:00']), true],
		];
	}//end scenarios()

	/**
	 * The outcome of one execution: what it returned or threw, and the calls made.
	 *
	 * @param object $node     The node.
	 * @param array  $scenario The scenario.
	 *
	 * @return array The outcome.
	 */
	private function runScenario(object $node, array $scenario): array {
		[$items, $config, $context, $refuses] = $scenario;
		$this->calls = [];
		$this->lockRefuses = $refuses;
		try {
			$result = ['returned' => $node->execute($items, $config, $context)];
		} catch (FlowSuspension $e) {
			$result = ['suspended' => $e->getMessage()];
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		$result['calls'] = $this->calls;
		return $result;
	}//end runScenario()

	/**
	 * Every allowed and refused outcome of both steps matches the recording.
	 *
	 * @return void
	 */
	public function testLockAndUnlockAllowAndRefuseWhatTheyDidBefore(): void {
		$l10n = $this->snapshotL10n();
		$urls = $this->snapshotUrls();
		$nodes = [
			'lock' => new LockObjectNode(objects: $this->objects(), userManager: $this->users(), presentation: new FlowNodePresentation(l10n: $l10n, urls: $urls)),
			'unlock' => new UnlockObjectNode(objects: $this->objects(), userManager: $this->users(), l10n: $l10n, urls: $urls),
		];

		$actual = [];
		foreach ($nodes as $name => $node) {
			$actual[$name]['surface'] = $this->surface(
				node: $node,
				configs: ['empty' => [], 'uuid' => ['uuid' => 'x'], 'bad-uuid' => ['uuid' => 5], 'null-uuid' => ['uuid' => null], 'bad-wait' => ['waitSeconds' => 'x']]
			);
			foreach ($this->scenarios() as $label => $scenario) {
				$actual[$name]['execute'][$label] = $this->runScenario(node: $node, scenario: $scenario);
			}
		}

		$this->assertGolden(name: 'LockNodesAccess', actual: $actual);
	}//end testLockAndUnlockAllowAndRefuseWhatTheyDidBefore()
}//end class
