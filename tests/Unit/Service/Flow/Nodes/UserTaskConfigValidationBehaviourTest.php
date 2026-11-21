<?php

/**
 * What a user-task step's config must say before a flow is saved: a title, a
 * resolvable performer, values from each vocabulary, a list of outcomes and a
 * numeric heartbeat, and its form; plus how the assignee is read back and how
 * templated texts that render to nothing are dropped.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Service\Flow\Nodes\UserTaskConfig;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskStep;
use OCA\OpenRegister\Service\Task\TaskForm;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * UserTaskConfig::validate(), assigneeValue() and rendered texts.
 */
class UserTaskConfigValidationBehaviourTest extends TestCase {

	private TaskFormReader&MockObject $forms;

	protected function setUp(): void {
		$this->forms = $this->createMock(TaskFormReader::class);
	}//end setUp()

	private function config(): UserTaskConfig {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return new UserTaskConfig($l10n, $this->forms);
	}//end config()

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function refusedConfigs(): array {
		$base = ['title' => 'Review', 'assignee' => 'alice'];
		return [
			'no title' => [['title' => '  ', 'assignee' => 'alice'], 'Say what is being asked, or nobody can do it.'],
			'no performer' => [['title' => 'Review', 'assignee' => ''], 'No performer can be resolved: name an assignee, candidate users, candidate groups, a candidate role or a routing fallback.'],
			'map without id' => [['title' => 'Review', 'assignee' => ['type' => 'user']], 'No performer can be resolved'],
			'unknown performer type' => [$base + ['performerType' => 'robot'], 'performerType "robot" is not one of user, group, agent, worker'],
			'unknown priority' => [$base + ['priority' => 'asap'], 'priority "asap" is not one of low, normal, high, urgent.'],
			'unknown routing' => [$base + ['routingStrategy' => 'random'], 'routingStrategy "random" is not one of single-role'],
			'empty outcomes' => [$base + ['outcomes' => []], 'Possible outcomes must be a list of names, like "approved, rejected".'],
			'non-numeric heartbeat' => [$base + ['heartbeatMinutes' => 'often'], 'Re-check every (minutes) must be a number.'],
		];
	}//end refusedConfigs()

	/**
	 * @param array<string, mixed> $config  The step config.
	 * @param string               $message The refusal it must give.
	 */
	#[DataProvider('refusedConfigs')]
	public function testAnIncompleteConfigIsRefused(array $config, string $message): void {
		$this->forms->expects($this->never())->method('validate');

		try {
			$this->config()->validate($config);
			$this->fail('An incomplete config was accepted.');
		} catch (UnexpectedValueException $e) {
			$this->assertStringContainsString($message, $e->getMessage());
		}
	}//end testAnIncompleteConfigIsRefused()

	public function testACompleteConfigHasItsFormValidated(): void {
		$form = new TaskForm(null);
		$config = [
			'title' => 'Review',
			'candidateRole' => 'clerks',
			'performerType' => 'group',
			'priority' => 'high',
			'routingStrategy' => 'single-role',
			'outcomes' => ['approved', 'rejected'],
			'heartbeatMinutes' => '15',
		];
		$this->forms->expects($this->once())->method('fromConfig')->with($config)->willReturn($form);
		$this->forms->expects($this->once())->method('validate')->with($this->identicalTo($form));

		$this->config()->validate($config);
	}//end testACompleteConfigHasItsFormValidated()

	public function testAnyOneKindOfPerformerIsEnough(): void {
		$this->forms->method('fromConfig')->willReturn(new TaskForm(null));
		$this->forms->expects($this->exactly(4))->method('validate');
		$config = $this->config();

		$config->validate(['title' => 'T', 'candidateUsers' => ['bob']]);
		$config->validate(['title' => 'T', 'candidateGroups' => 'clerks']);
		$config->validate(['title' => 'T', 'routingFallback' => 'admin']);
		$config->validate(['title' => 'T', 'assignee' => ['type' => 'agent', 'id' => 'bot']]);
	}//end testAnyOneKindOfPerformerIsEnough()

	public function testTheAssigneeValueKeepsAMapAndTrimsAString(): void {
		$config = $this->config();

		$this->assertSame(['type' => 'agent', 'id' => 'bot'], $config->assigneeValue(['assignee' => ['type' => 'agent', 'id' => 'bot']]));
		$this->assertSame('alice', $config->assigneeValue(['assignee' => '  alice ']));
		$this->assertSame('', $config->assigneeValue([]));
	}//end testTheAssigneeValueKeepsAMapAndTrimsAString()

	public function testTemplatedTextsThatRenderToNothingOrToAListAreDropped(): void {
		$data = $this->config()->taskData(
			new UserTaskStep(
				[['json' => ['id' => 'o-1', 'note' => '  ', 'tags' => ['a', 'b'], 'when' => '2030-01-01']]],
				[
					'title' => 'Review',
					'assignee' => 'alice',
					'description' => '{{ note }}',
					'dueAt' => '{{ tags }}',
					'expiresAt' => '{{ when }}',
				]
			),
			'openregister.user-task'
		);

		$this->assertNull($data['description']);
		$this->assertNull($data['dueAt']);
		$this->assertSame('2030-01-01', $data['expiresAt']);
	}//end testTemplatedTextsThatRenderToNothingOrToAListAreDropped()

	public function testTheResumeSlotRecordsTheTaskTheAskTheAssigneeAndTheRenderedTitle(): void {
		$before = time();
		$slot = $this->config()->slotValues(
			new UserTaskStep([['json' => ['id' => 'o-1', 'name' => 'Ada']]], ['title' => 'Review {{ name }}', 'assignee' => ' alice ']),
			'task-9'
		);

		$this->assertSame('task-9', $slot['taskUuid']);
		$this->assertSame('alice', $slot['assignee']);
		$this->assertSame('Review Ada', $slot['title']);
		$this->assertArrayHasKey('advance', $slot);
		$this->assertGreaterThanOrEqual($before, strtotime($slot['askedAt']));
		$this->assertLessThanOrEqual(time(), strtotime($slot['askedAt']));
	}//end testTheResumeSlotRecordsTheTaskTheAskTheAssigneeAndTheRenderedTitle()

	public function testTheHeartbeatIsTheConfiguredMinutesAhead(): void {
		$before = time();
		$at = $this->config()->heartbeatAt(['heartbeatMinutes' => 90])->getTimestamp();

		$this->assertGreaterThanOrEqual($before + 5400, $at);
		$this->assertLessThanOrEqual(time() + 5400, $at);
	}//end testTheHeartbeatIsTheConfiguredMinutesAhead()

	public function testAbsentTextsStayNullAndCandidateListsAreKept(): void {
		$data = $this->config()->taskData(
			new UserTaskStep([['id' => 'o-1']], ['title' => 'Review', 'candidateUsers' => ['bob', 'carol'], 'candidateGroups' => []]),
			'openregister.user-task'
		);

		$this->assertNull($data['description']);
		$this->assertSame(['bob', 'carol'], $data['candidateUsers']);
		$this->assertNull($data['candidateGroups']);
		$this->assertNull($data['assignee']);
	}//end testAbsentTextsStayNullAndCandidateListsAreKept()
}//end class
