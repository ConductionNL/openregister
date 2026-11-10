<?php

/**
 * Characterisation of the Task entity: its registered field types and its
 * serialised shape, empty and with every field set, pinned key for key and in order.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Task;
use PHPUnit\Framework\TestCase;

/**
 * Golden field types and serialisation for Task.
 *
 * @covers \OCA\OpenRegister\Db\Task
 */
final class TaskSerialisationGoldenTest extends TestCase {

	/**
	 * The registered field types, in registration order.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'id' => 'integer',
		'uuid' => 'string',
		'taskKey' => 'string',
		'title' => 'string',
		'description' => 'string',
		'metadata' => 'json',
		'kind' => 'string',
		'runUuid' => 'string',
		'nodeId' => 'string',
		'definitionVersion' => 'integer',
		'appId' => 'string',
		'workflowStepId' => 'string',
		'organisation' => 'string',
		'state' => 'string',
		'isTerminal' => 'boolean',
		'lastAction' => 'string',
		'outcome' => 'string',
		'blockedReason' => 'string',
		'blockedBy' => 'string',
		'performerType' => 'string',
		'assignee' => 'string',
		'candidateUsers' => 'json',
		'candidateGroups' => 'json',
		'candidateRole' => 'string',
		'routingStrategy' => 'string',
		'routingFallback' => 'string',
		'onBehalfOf' => 'string',
		'mandate' => 'string',
		'requester' => 'string',
		'watchers' => 'json',
		'startAt' => 'datetime',
		'dueAt' => 'datetime',
		'expiresAt' => 'datetime',
		'onTimeout' => 'string',
		'onReject' => 'string',
		'slaValue' => 'integer',
		'slaUnit' => 'string',
		'compliancePeriodDays' => 'integer',
		'suspendedUntil' => 'datetime',
		'recurrence' => 'string',
		'priority' => 'string',
		'objectUuid' => 'string',
		'registerId' => 'integer',
		'schemaId' => 'integer',
		'templateId' => 'string',
		'templateVersion' => 'integer',
		'templateSnapshot' => 'json',
		'sequenceUuid' => 'string',
		'sequencePosition' => 'integer',
		'legacyStepId' => 'integer',
		'checklist' => 'json',
		'responses' => 'json',
		'percentComplete' => 'integer',
		'completedAt' => 'datetime',
		'completedBy' => 'string',
		'resultText' => 'string',
		'comment' => 'string',
		'evidence' => 'json',
		'overrideReason' => 'string',
		'parentTaskId' => 'integer',
		'epicTaskId' => 'integer',
		'created' => 'datetime',
		'updated' => 'datetime',
		'createdBy' => 'string',
	];

	/**
	 * The serialised shape of a fresh entity.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_JSON = [
		'id' => null,
		'uuid' => null,
		'key' => null,
		'title' => null,
		'description' => null,
		'metadata' => null,
		'kind' => null,
		'runUuid' => null,
		'nodeId' => null,
		'definitionVersion' => null,
		'appId' => null,
		'workflowStepId' => null,
		'organisation' => null,
		'state' => null,
		'isTerminal' => false,
		'lastAction' => null,
		'outcome' => null,
		'blockedReason' => null,
		'blockedBy' => null,
		'performerType' => null,
		'assignee' => null,
		'candidateUsers' => null,
		'candidateGroups' => null,
		'candidateRole' => null,
		'routingStrategy' => null,
		'routingFallback' => null,
		'onBehalfOf' => null,
		'mandate' => null,
		'requester' => null,
		'watchers' => null,
		'startAt' => null,
		'dueAt' => null,
		'expiresAt' => null,
		'onTimeout' => null,
		'onReject' => null,
		'slaValue' => null,
		'slaUnit' => null,
		'compliancePeriodDays' => null,
		'suspendedUntil' => null,
		'recurrence' => null,
		'priority' => 'normal',
		'objectUuid' => null,
		'registerId' => null,
		'schemaId' => null,
		'templateId' => null,
		'templateVersion' => null,
		'templateSnapshot' => null,
		'sequenceUuid' => null,
		'sequencePosition' => null,
		'legacyStepId' => null,
		'checklist' => null,
		'responses' => null,
		'percentComplete' => null,
		'completedAt' => null,
		'completedBy' => null,
		'resultText' => null,
		'comment' => null,
		'evidence' => null,
		'overrideReason' => null,
		'parentTaskId' => null,
		'epicTaskId' => null,
		'created' => null,
		'updated' => null,
		'createdBy' => null,
	];

	/**
	 * The serialised shape with every field set.
	 *
	 * @var array<string, mixed>
	 */
	private const POPULATED_JSON = [
		'id' => 2,
		'uuid' => 'uuid-v',
		'key' => 'taskKey-v',
		'title' => 'title-v',
		'description' => 'description-v',
		'metadata' => [
			'k' => 'metadata',
		],
		'kind' => 'kind-v',
		'runUuid' => 'runUuid-v',
		'nodeId' => 'nodeId-v',
		'definitionVersion' => 17,
		'appId' => 'appId-v',
		'workflowStepId' => 'workflowStepId-v',
		'organisation' => 'organisation-v',
		'state' => 'state-v',
		'isTerminal' => true,
		'lastAction' => 'lastAction-v',
		'outcome' => 'outcome-v',
		'blockedReason' => 'blockedReason-v',
		'blockedBy' => 'blockedBy-v',
		'performerType' => 'performerType-v',
		'assignee' => 'assignee-v',
		'candidateUsers' => [
			'k' => 'candidateUsers',
		],
		'candidateGroups' => [
			'k' => 'candidateGroups',
		],
		'candidateRole' => 'candidateRole-v',
		'routingStrategy' => 'routingStrategy-v',
		'routingFallback' => 'routingFallback-v',
		'onBehalfOf' => 'onBehalfOf-v',
		'mandate' => 'mandate-v',
		'requester' => 'requester-v',
		'watchers' => [
			'k' => 'watchers',
		],
		'startAt' => '2026-01-02T03:04:05+00:00',
		'dueAt' => '2026-01-02T03:04:05+00:00',
		'expiresAt' => '2026-01-02T03:04:05+00:00',
		'onTimeout' => 'onTimeout-v',
		'onReject' => 'onReject-v',
		'slaValue' => 8,
		'slaUnit' => 'slaUnit-v',
		'compliancePeriodDays' => 20,
		'suspendedUntil' => '2026-01-02T03:04:05+00:00',
		'recurrence' => 'recurrence-v',
		'priority' => 'priority-v',
		'objectUuid' => 'objectUuid-v',
		'registerId' => 10,
		'schemaId' => 8,
		'templateId' => 'templateId-v',
		'templateVersion' => 15,
		'templateSnapshot' => [
			'k' => 'templateSnapshot',
		],
		'sequenceUuid' => 'sequenceUuid-v',
		'sequencePosition' => 16,
		'legacyStepId' => 12,
		'checklist' => [
			'k' => 'checklist',
		],
		'responses' => [
			'k' => 'responses',
		],
		'percentComplete' => 15,
		'completedAt' => '2026-01-02T03:04:05+00:00',
		'completedBy' => 'completedBy-v',
		'resultText' => 'resultText-v',
		'comment' => 'comment-v',
		'evidence' => [
			'k' => 'evidence',
		],
		'overrideReason' => 'overrideReason-v',
		'parentTaskId' => 12,
		'epicTaskId' => 10,
		'created' => '2026-01-02T03:04:05+00:00',
		'updated' => '2026-01-02T03:04:05+00:00',
		'createdBy' => 'createdBy-v',
	];

	/**
	 * Field types are registered under the same names, types and order.
	 *
	 * @return void
	 */
	public function testFieldTypesAreRegisteredInOrder(): void {
		$this->assertSame(self::FIELD_TYPES, (new Task())->getFieldTypes());
	}//end testFieldTypesAreRegisteredInOrder()

	/**
	 * A fresh entity serialises to the pinned shape.
	 *
	 * @return void
	 */
	public function testEmptyEntitySerialises(): void {
		$this->assertSame(self::EMPTY_JSON, (new Task())->jsonSerialize());
	}//end testEmptyEntitySerialises()

	/**
	 * A fully set entity serialises to the pinned shape.
	 *
	 * @return void
	 */
	public function testPopulatedEntitySerialises(): void {
		$this->assertSame(self::POPULATED_JSON, $this->populated(entity: new Task())->jsonSerialize());
	}//end testPopulatedEntitySerialises()

	/**
	 * Set every registered field to a value derived from its name and type.
	 *
	 * @param Task $entity The entity to fill.
	 *
	 * @return Task The filled entity.
	 */
	private function populated(Task $entity): Task {
		foreach ($entity->getFieldTypes() as $field => $type) {
			$entity->{'set'.ucfirst($field)}($this->valueFor(field: $field, type: $type));
		}

		return $entity;
	}//end populated()

	/**
	 * A deterministic value for one field.
	 *
	 * @param string $field The field name.
	 * @param string $type  The registered entity type.
	 *
	 * @return mixed The value.
	 */
	private function valueFor(string $field, string $type): mixed {
		return match ($type) {
			'integer', 'int' => strlen($field),
			'boolean', 'bool' => true,
			'json' => ['k' => $field],
			'datetime' => new DateTime('2026-01-02T03:04:05+00:00'),
			'float', 'double' => 1.5,
			default => $field.'-v',
		};
	}//end valueFor()
}//end class
