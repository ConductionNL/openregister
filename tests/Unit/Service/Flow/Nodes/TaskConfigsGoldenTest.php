<?php

/**
 * Golden test: what UserTaskConfig and PortalTaskConfig derive from a config.
 *
 * Recorded on the code before their shared helpers moved to TaskConfigCommon:
 * the task payload, slot values, outcome key, rendered title and heartbeat
 * interval for a range of configs and items.
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

use DateTime;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskConfig;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskRound;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskConfig;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskStep;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCA\OpenRegister\Service\Task\TaskFormSources;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Pins the derived values of both task configs.
 */
final class TaskConfigsGoldenTest extends TestCase {
	use NodeSurfaceSnapshot;

	/**
	 * Config/items cases.
	 *
	 * @return array<string, array{0: array, 1: array}> Label => [config, items].
	 */
	private function cases(): array {
		$self = ['@self' => ['uuid' => 'u-1', 'register' => '4', 'schema' => 9, 'id' => 'x'], 'name' => 'Ann', 'deadline' => '2030-01-01'];
		return [
			'empty' => [[], []],
			'minimal' => [['title' => 'Approve {{ name }}', 'assignee' => 'alice'], [['json' => $self]]],
			'template-list' => [
				[
					'title' => '  {{ name }} ', 'description' => 'D {{ name }}', 'candidates' => 'group:a, user:b',
					'candidateUsers' => 'bob, , carol', 'candidateGroups' => ['staff', 3, '', ['x']], 'outcomes' => 'yes, no,',
					'outcomeKey' => ' review ', 'heartbeatMinutes' => 1, 'priority' => 'high', 'dueAt' => '{{ deadline }}',
					'partyRole' => ' applicant ', 'uploadRequired' => 'true', 'uploadMaxFiles' => '2', 'uploadAcceptedTypes' => 'pdf, image/*',
					'reasonField' => 'review.comment',
				],
				['skip-me', ['json' => $self], ['json' => ['uuid' => 'u-2']]],
			],
			'no-anchor' => [['title' => 'T', 'heartbeatMinutes' => 60, 'outcomeKey' => ''], [['json' => ['name' => 'Bo']]]],
			'id-only' => [['title' => 'T', 'heartbeatMinutes' => '30'], [['json' => ['@self' => ['id' => 'i-9', 'register' => 'x']]]]],
		];
	}//end cases()

	/**
	 * The heartbeat as minutes from now, so the recording holds whatever the clock says.
	 *
	 * @param DateTime $at The heartbeat.
	 *
	 * @return int Minutes from now.
	 */
	private function minutesFromNow(DateTime $at): int {
		return (int)round(($at->getTimestamp() - time()) / 60);
	}//end minutesFromNow()

	/**
	 * Replace every `askedAt` stamp within a minute of now with a marker, so
	 * the recording does not hold the clock.
	 *
	 * @param mixed $value The derived value.
	 *
	 * @return mixed The value with its stamps replaced.
	 */
	private function withoutClock(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $entry) {
			if ($key === 'askedAt' && is_string($entry) === true && abs(strtotime($entry) - time()) < 60) {
				$value[$key] = '<now>';
				continue;
			}

			$value[$key] = $this->withoutClock($entry);
		}

		return $value;
	}//end withoutClock()

	/**
	 * Both configs derive what they derived before.
	 *
	 * @return void
	 */
	public function testTaskConfigsMatchTheirRecording(): void {
		$l10n = $this->snapshotL10n();
		$forms = new TaskFormReader(
			new TaskFormSources($this->createMock(SchemaMapper::class), $this->createMock(TransitionEngine::class)),
			$this->createMock(IAppManager::class),
			$l10n
		);
		$user = new UserTaskConfig(l10n: $l10n, forms: $forms);
		$portal = new PortalTaskConfig(l10n: $l10n);

		$actual = [];
		foreach ($this->cases() as $label => [$config, $items]) {
			$round = (new PortalTaskRound(items: $items, config: $config))->atNode(nodeId: 'ask');
			$actual[$label] = [
				'user' => [
					'taskData' => $this->outcome(
						fn () => $user->taskData(new UserTaskStep(items: $items, config: $config, nodeId: 'ask'), 'openregister.user-task')
					),
					'slotValues' => $this->outcome(fn () => $user->slotValues(new UserTaskStep(items: $items, config: $config), 't-1')),
					'outcomeKey' => $user->outcomeKey($config),
					'renderedTitle' => $user->renderedTitle($config, $items),
					'heartbeat' => $this->minutesFromNow($user->heartbeatAt($config)),
					'performers' => $this->outcome(fn () => $user->performers($config)),
				],
				'portal' => [
					'taskData' => $this->outcome(fn () => $portal->taskData($round, 'openregister.portal-task')),
					'slotValues' => $this->outcome(fn () => $portal->slotValues($round, 't-1')),
					'outcomeKey' => $portal->outcomeKey($config),
					'renderedTitle' => $portal->renderedTitle($config, $items),
					'heartbeat' => $this->minutesFromNow($portal->heartbeatAt($config)),
					'uploadConstraints' => $this->outcome(fn () => $portal->uploadConstraints($config)),
					'subjectObjectUuid' => $this->outcome(fn () => $portal->subjectObjectUuid($items)),
					'reasonFrom' => $this->outcome(fn () => $portal->reasonFrom($config, $items)),
				],
			];
		}

		$this->assertGolden(name: 'TaskConfigs', actual: $this->withoutClock($actual));
	}//end testTaskConfigsMatchTheirRecording()
}//end class
