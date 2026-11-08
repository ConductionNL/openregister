<?php

/**
 * Golden outcomes of TaskBuilder: the task a payload builds, field by
 * field, and every refusal by its message.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskRelation;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Pins what fromData() writes and refuses, and what relationsFor() builds.
 */
class TaskBuilderGoldenTest extends TestCase {

	private const GETTERS = [
		'getState', 'getIsTerminal', 'getLastAction', 'getOutcome', 'getPriority', 'getPerformerType',
		'getOnTimeout', 'getOnReject', 'getChecklist', 'getUuid', 'getTaskKey', 'getTitle', 'getDescription',
		'getMetadata', 'getKind', 'getRunUuid', 'getNodeId', 'getDefinitionVersion', 'getAppId', 'getWorkflowStepId',
		'getOrganisation', 'getAssignee', 'getCandidateUsers', 'getCandidateGroups', 'getCandidateRole',
		'getRoutingStrategy', 'getRoutingFallback', 'getOnBehalfOf', 'getMandate', 'getRequester', 'getWatchers',
		'getSlaValue', 'getSlaUnit', 'getCompliancePeriodDays', 'getRecurrence', 'getObjectUuid', 'getRegisterId',
		'getSchemaId', 'getParentTaskId', 'getEpicTaskId', 'getBlockedBy', 'getPercentComplete', 'getResponses',
		'getEvidence', 'getCreatedBy', 'getTemplateId', 'getTemplateVersion', 'getTemplateSnapshot', 'getSequenceUuid',
		'getSequencePosition', 'getLegacyStepId',
	];

	/**
	 * Every getter's value, dates as ISO strings.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(Task $task): array {
		$out = [];
		foreach (self::GETTERS as $getter) {
			$out[$getter] = $task->$getter();
		}

		foreach (['getDueAt', 'getExpiresAt', 'getStartAt', 'getSuspendedUntil'] as $getter) {
			$value = $task->$getter();
			$out[$getter] = ($value === null) ? null : $value->format('c');
		}

		return $out;
	}//end snapshot()

	public function testAFullPayloadLandsFieldByField(): void {
		$due = new DateTime('2030-01-02T03:04:05+00:00');
		$task = (new TaskBuilder())->fromData(
			data: [
				'state' => 'approved',
				'outcome' => 'signed',
				'priority' => 'HIGH',
				'performerType' => 'agent',
				'dueAt' => $due,
				'expiresAt' => '2030-02-01T00:00:00+00:00',
				'onTimeout' => 'skip',
				'onReject' => 'dead_letter',
				'startAt' => '2029-12-01T00:00:00+00:00',
				'suspendedUntil' => '',
				'checklist' => [['id' => 1, 'label' => ' L ', 'checked' => 1], ['id' => 'b', 'label' => 'B', 'description' => 'd']],
				'uuid' => 'u-1',
				'key' => '  k-1 ',
				'title' => 'Title',
				'description' => '   ',
				'metadata' => ['m' => 1],
				'kind' => 'review',
				'runUuid' => 'run',
				'nodeId' => 'node',
				'definitionVersion' => '3',
				'appId' => 'app',
				'workflowStepId' => 'ws',
				'organisation' => 'org',
				'assignee' => 'alice',
				'candidateUsers' => ['u1'],
				'candidateGroups' => 'not-an-array',
				'candidateRole' => 'clerk',
				'routingStrategy' => 'round-robin',
				'routingFallback' => 'pool',
				'onBehalfOf' => 'olga',
				'mandate' => 'art. 1',
				'requester' => 'rita',
				'watchers' => ['w'],
				'slaValue' => 5,
				'slaUnit' => 'days',
				'compliancePeriodDays' => '',
				'recurrence' => 'RRULE:FREQ=DAILY',
				'objectUuid' => 'obj',
				'registerId' => '2',
				'schemaId' => 3,
				'parentTaskId' => null,
				'epicTaskId' => '9',
				'blockedBy' => 't-0',
				'percentComplete' => '50',
				'responses' => ['r' => 1],
				'evidence' => [['f' => 1]],
				'templateId' => 'tpl',
				'templateVersion' => '4',
				'templateSnapshot' => ['s' => 1],
				'sequenceUuid' => 'seq',
				'sequencePosition' => '2',
				'legacyStepId' => 11,
			],
			actor: 'creator'
		);

		$this->assertSame(
			[
				'getState' => Task::STATE_COMPLETED,
				'getIsTerminal' => true,
				'getLastAction' => 'create',
				'getOutcome' => 'signed',
				'getPriority' => 'high',
				'getPerformerType' => 'agent',
				'getOnTimeout' => 'skip',
				'getOnReject' => 'dead_letter',
				'getChecklist' => [
					['id' => '1', 'label' => ' L ', 'description' => null, 'checked' => true],
					['id' => 'b', 'label' => 'B', 'description' => 'd', 'checked' => false],
				],
				'getUuid' => 'u-1',
				'getTaskKey' => 'k-1',
				'getTitle' => 'Title',
				'getDescription' => null,
				'getMetadata' => ['m' => 1],
				'getKind' => 'review',
				'getRunUuid' => 'run',
				'getNodeId' => 'node',
				'getDefinitionVersion' => 3,
				'getAppId' => 'app',
				'getWorkflowStepId' => 'ws',
				'getOrganisation' => 'org',
				'getAssignee' => 'alice',
				'getCandidateUsers' => ['u1'],
				'getCandidateGroups' => null,
				'getCandidateRole' => 'clerk',
				'getRoutingStrategy' => 'round-robin',
				'getRoutingFallback' => 'pool',
				'getOnBehalfOf' => 'olga',
				'getMandate' => 'art. 1',
				'getRequester' => 'rita',
				'getWatchers' => ['w'],
				'getSlaValue' => 5,
				'getSlaUnit' => 'days',
				'getCompliancePeriodDays' => null,
				'getRecurrence' => 'RRULE:FREQ=DAILY',
				'getObjectUuid' => 'obj',
				'getRegisterId' => 2,
				'getSchemaId' => 3,
				'getParentTaskId' => null,
				'getEpicTaskId' => 9,
				'getBlockedBy' => 't-0',
				'getPercentComplete' => 50,
				'getResponses' => ['r' => 1],
				'getEvidence' => [['f' => 1]],
				'getCreatedBy' => 'creator',
				'getTemplateId' => 'tpl',
				'getTemplateVersion' => 4,
				'getTemplateSnapshot' => ['s' => 1],
				'getSequenceUuid' => 'seq',
				'getSequencePosition' => 2,
				'getLegacyStepId' => 11,
				'getDueAt' => '2030-01-02T03:04:05+00:00',
				'getExpiresAt' => '2030-02-01T00:00:00+00:00',
				'getStartAt' => '2029-12-01T00:00:00+00:00',
				'getSuspendedUntil' => null,
			],
			$this->snapshot($task)
		);
		$this->assertSame($due, $task->getDueAt());
	}//end testAFullPayloadLandsFieldByField()

	public function testAnEmptyPayloadTakesTheDefaults(): void {
		$task = (new TaskBuilder())->fromData(data: [], actor: null);
		$snapshot = $this->snapshot($task);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $snapshot['getUuid']);
		unset($snapshot['getUuid']);
		$expected = array_fill_keys(array_keys($snapshot), null);
		$expected['getState'] = Task::STATE_AVAILABLE;
		$expected['getIsTerminal'] = false;
		$expected['getLastAction'] = 'create';
		$expected['getPriority'] = 'normal';
		$expected['getPerformerType'] = Task::PERFORMER_USER;
		$this->assertSame($expected, $snapshot);
	}//end testAnEmptyPayloadTakesTheDefaults()

	public function testALegacyStateCarriesItsOutcomeUnlessOneIsGiven(): void {
		$builder = new TaskBuilder();

		$this->assertSame('approved', $builder->fromData(data: ['state' => 'approved'], actor: null)->getOutcome());
		$this->assertSame('approved', $builder->fromData(data: ['state' => 'approved', 'outcome' => ''], actor: null)->getOutcome());
	}//end testALegacyStateCarriesItsOutcomeUnlessOneIsGiven()

	/**
	 * Payloads fromData() refuses, with the message.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refused(): array {
		$item = 'Every checklist item requires an id and a label.';

		return [
			'performer type' => [['performerType' => 'robot'], "Performer type 'robot' is not in the known vocabulary (" . implode('|', Task::PERFORMER_TYPES) . ').'],
			'expiry before due' => [
				['dueAt' => '2030-02-01T00:00:00+00:00', 'expiresAt' => '2030-01-01T00:00:00+00:00'],
				"expiresAt '2030-01-01T00:00:00+00:00' lies before dueAt '2030-02-01T00:00:00+00:00': a task that dies before it is due is a configuration error.",
			],
			'unparsable due' => [['dueAt' => 'not a date at all'], "Field 'dueAt' does not parse as a date."],
			'integer expiry' => [['expiresAt' => 12345], "Field 'expiresAt' does not parse as a date."],
			'array start' => [['startAt' => ['x']], "Field 'startAt' does not parse as a date."],
			'unparsable suspension' => [['suspendedUntil' => 'nope nope'], "Field 'suspendedUntil' does not parse as a date."],
			'unknown timeout' => [['onTimeout' => 'retry', 'expiresAt' => '2030-01-01'], "Field 'onTimeout' value 'retry' is not in the behaviour vocabulary (skip|error|dead_letter)."],
			'timeout without deadline' => [['onTimeout' => 'error'], "onTimeout 'error' without an expiresAt is a configuration error: there is no deadline to time out on."],
			'unknown reject' => [['onReject' => 'ignore'], "Field 'onReject' value 'ignore' is not in the behaviour vocabulary (skip|error|dead_letter)."],
			'string checklist' => [['checklist' => '[]'], 'The checklist must be a typed array of {id, label, description, checked} items, not a string containing JSON.'],
			'scalar checklist' => [['checklist' => 5], 'The checklist must be a typed array of {id, label, description, checked} items.'],
			'item not an array' => [['checklist' => ['x']], $item],
			'item without id' => [['checklist' => [['label' => 'L']]], $item],
			'item with blank label' => [['checklist' => [['id' => 'a', 'label' => ' ']]], $item],
			'due refused before expiry' => [['dueAt' => 'bad', 'expiresAt' => 'bad'], "Field 'dueAt' does not parse as a date."],
			'performer refused before dates' => [['performerType' => 'x', 'dueAt' => 'bad'], "Performer type 'x' is not in the known vocabulary (" . implode('|', Task::PERFORMER_TYPES) . ').'],
			'timeout refused before checklist' => [['onTimeout' => 'skip', 'checklist' => 'x'], "onTimeout 'skip' without an expiresAt is a configuration error: there is no deadline to time out on."],
		];
	}//end refused()

	/**
	 * @dataProvider refused
	 *
	 * @param array<string, mixed> $data
	 */
	public function testRefusals(array $data, string $message): void {
		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage($message);

		(new TaskBuilder())->fromData(data: $data, actor: null);
	}//end testRefusals()

	public function testTheEmptyStringAndNullDatesAreAbsent(): void {
		$task = (new TaskBuilder())->fromData(data: ['dueAt' => '', 'expiresAt' => null, 'onTimeout' => ''], actor: null);

		$this->assertNull($task->getDueAt());
		$this->assertNull($task->getOnTimeout());
	}//end testTheEmptyStringAndNullDatesAreAbsent()

	public function testRelationsSkipNonArraysAndCarryIds(): void {
		$task = new Task();
		$task->setId(8);
		$builder = new TaskBuilder();

		$this->assertSame([], $builder->relationsFor(task: $task, data: []));
		$this->assertSame([], $builder->relationsFor(task: $task, data: ['relations' => 'x']));

		$rows = $builder->relationsFor(
			task: $task,
			data: ['relations' => ['skip', ['role' => ' subject ', 'objectUuid' => ' o-1 ', 'registerId' => '4', 'schemaId' => ''], ['role' => 'r', 'objectUuid' => 'o-2']]]
		);

		$this->assertCount(2, $rows);
		$this->assertInstanceOf(TaskRelation::class, $rows[0]);
		$this->assertSame(
			[[8, 'subject', 'o-1', 4, null], [8, 'r', 'o-2', null, null]],
			array_map(static fn (TaskRelation $row): array => [$row->getTaskId(), $row->getRole(), $row->getObjectUuid(), $row->getRegisterId(), $row->getSchemaId()], $rows)
		);
	}//end testRelationsSkipNonArraysAndCarryIds()

	public function testARelationWithoutRoleOrObjectIsRefused(): void {
		$task = new Task();
		$task->setId(8);

		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage('A task relation requires both a role and an objectUuid.');

		(new TaskBuilder())->relationsFor(task: $task, data: ['relations' => [['role' => 'r', 'objectUuid' => 'o'], ['role' => ' ', 'objectUuid' => 'o']]]);
	}//end testARelationWithoutRoleOrObjectIsRefused()
}//end class
