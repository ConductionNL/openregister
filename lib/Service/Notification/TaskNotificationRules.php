<?php

/**
 * The declarative notification rule set for engine tasks.
 *
 * Modelled on SystemSchemaRules: in-code `x-openregister-notifications`
 * rules under one synthetic slug, evaluated by the ONE dispatcher every
 * other rule in the platform goes through. Nothing in the task path calls
 * the notification API imperatively; this rule set is the whole of "who is
 * told what, when" (flow-task-inbox-projections, design D-1 and D-3).
 *
 * Rules address the NAMED TRANSITION ACTION the task row records, never the
 * resulting state, so a completion by approval and one by rejection are
 * separately addressable. The overdue rule filters a DERIVED predicate over
 * the payload the adapter builds per evaluation; no field anywhere records
 * whether a task is overdue.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\Schema;

/**
 * Task rule registry: the seed data of this change (ADR-001).
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */
class TaskNotificationRules {

	/**
	 * The synthetic schema slug tasks are evaluated under.
	 */
	public const SLUG = 'openregister_task';

	/**
	 * The transition action the write-back gate records on a refusal, so the
	 * refusal notice is a rule like every other task notification.
	 */
	public const ACTION_WRITE_BACK_REFUSED = 'write-back-refused';

	/**
	 * The route the navigation actions and the VTODO `URL` resolve to.
	 */
	private const OPEN_ROUTE = 'flow-tasks/{{taskUuid}}';

	/**
	 * The payload fields TaskObjectAdapter publishes, typed for the dialect
	 * validator so a `kind: field` recipient can only name a real field.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const PAYLOAD_PROPERTIES = [
		'taskUuid' => ['type' => 'string'],
		'title' => ['type' => 'string'],
		'description' => ['type' => 'string'],
		'state' => ['type' => 'string'],
		'isTerminal' => ['type' => 'boolean'],
		'lastAction' => ['type' => 'string'],
		'outcome' => ['type' => 'string'],
		'priority' => ['type' => 'string'],
		'performerType' => ['type' => 'string'],
		'assignee' => ['type' => 'string'],
		'previousAssignee' => ['type' => 'string'],
		'candidateUsers' => ['type' => 'array'],
		'candidateGroups' => ['type' => 'array'],
		'candidateRole' => ['type' => 'string'],
		'requester' => ['type' => 'string'],
		'watchers' => ['type' => 'array'],
		'startAt' => ['type' => 'string'],
		'dueAt' => ['type' => 'string'],
		'expiresAt' => ['type' => 'string'],
		'overdue' => ['type' => 'boolean'],
		'daysUntilDue' => ['type' => 'integer'],
		'daysOverdue' => ['type' => 'integer'],
		'objectUuid' => ['type' => 'string'],
		'registerId' => ['type' => 'integer'],
		'schemaId' => ['type' => 'integer'],
		'subjectTitle' => ['type' => 'string'],
		'appId' => ['type' => 'string'],
		'runUuid' => ['type' => 'string'],
		'completedBy' => ['type' => 'string'],
		'writeBackActor' => ['type' => 'string'],
		'writeBackReason' => ['type' => 'string'],
	];

	/**
	 * The rules. Text is per locale, sentence case, English primary.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const RULES = [
		// 1. Assigned to you: actionable, binary. The reject action routes to
		// the form because a rejecting outcome needs a comment; the rule does
		// not have to know that, the target resolver does.
		'taskAssignedToYou' => [
			'trigger' => [
				'type' => 'transition',
				'action' => ['create', 'assign', 'reassign', 'claim', 'delegate'],
			],
			'enabled' => true,
			'channels' => ['nc-notification', 'web-push'],
			'recipients' => [['kind' => 'field', 'field' => 'assignee']],
			'subject' => [
				'en' => 'Assigned to you: {{title}}',
				'nl' => 'Aan jou toegewezen: {{title}}',
			],
			'message' => [
				'en' => 'You can answer this task from here.',
				'nl' => 'Je kunt deze taak vanaf hier afhandelen.',
			],
			'actions' => [
				[
					'label' => ['en' => 'Approve', 'nl' => 'Goedkeuren'],
					'primary' => true,
					'target' => ['kind' => 'task-verb', 'verb' => 'complete', 'outcome' => 'approved'],
				],
				[
					'label' => ['en' => 'Reject', 'nl' => 'Afwijzen'],
					'target' => ['kind' => 'task-verb', 'verb' => 'complete', 'outcome' => 'rejected'],
				],
			],
		],
		// 2. Offered to your pool: no assignee to address, so the recipients
		// are the pool's members, resolved from the task's OWN candidate
		// groups and users, and nobody else.
		'taskOfferedToPool' => [
			'trigger' => ['type' => 'transition', 'action' => 'offer'],
			'enabled' => true,
			'channels' => ['nc-notification'],
			'recipients' => [['kind' => 'expression', 'resolver' => TaskPoolRecipientResolver::class]],
			'subject' => [
				'en' => 'New task for your group: {{title}}',
				'nl' => 'Nieuwe taak voor je groep: {{title}}',
			],
			'actions' => [
				[
					'label' => ['en' => 'Open', 'nl' => 'Openen'],
					'primary' => true,
					'target' => ['kind' => 'route', 'app' => 'openregister', 'route' => self::OPEN_ROUTE],
				],
			],
		],
		// 3. Reassigned away from you.
		'taskReassignedAway' => [
			'trigger' => ['type' => 'transition', 'action' => ['reassign', 'delegate']],
			'enabled' => true,
			'channels' => ['nc-notification'],
			'recipients' => [['kind' => 'field', 'field' => 'previousAssignee']],
			'subject' => [
				'en' => 'No longer yours: {{title}}',
				'nl' => 'Niet meer van jou: {{title}}',
			],
		],
		// 4. Due soon: the EVENT is computed by flow-business-timers and
		// recorded as an action; this rule only delivers it.
		'taskDueSoon' => [
			'trigger' => ['type' => 'transition', 'action' => 'due-soon'],
			'enabled' => true,
			'channels' => ['nc-notification'],
			'recipients' => [['kind' => 'field', 'field' => 'assignee']],
			'subject' => [
				'en' => 'Due soon: {{title}}',
				'nl' => 'Bijna over tijd: {{title}}',
			],
			'actions' => [
				[
					'label' => ['en' => 'Open', 'nl' => 'Openen'],
					'primary' => true,
					'target' => ['kind' => 'route', 'app' => 'openregister', 'route' => self::OPEN_ROUTE],
				],
			],
		],
		// 5. Escalated: assignee and requester both hear it.
		'taskEscalated' => [
			'trigger' => ['type' => 'transition', 'action' => 'escalate'],
			'enabled' => true,
			'channels' => ['nc-notification'],
			'recipients' => [
				['kind' => 'field', 'field' => 'assignee'],
				['kind' => 'field', 'field' => 'requester'],
			],
			'subject' => [
				'en' => 'Escalated: {{title}}',
				'nl' => 'Geescaleerd: {{title}}',
			],
		],
		// 6. Overdue: the derived predicate, in the verified operator-object
		// grammar. No `overdue` field appears anywhere in it.
		'taskOverdue' => [
			'trigger' => [
				'type' => 'scheduled',
				'intervalSec' => 86400,
				'filter' => [
					'all' => [
						['field' => 'isTerminal', 'operator' => 'notIn', 'values' => [true]],
						['field' => 'dueAt', 'operator' => 'before', 'value' => 'now'],
					],
				],
				'dedupeFields' => ['taskUuid'],
			],
			'enabled' => true,
			'channels' => ['nc-notification'],
			'recipients' => [['kind' => 'field', 'field' => 'assignee']],
			'subject' => [
				'en' => 'Task overdue: {{title}}',
				'nl' => 'Taak over tijd: {{title}}',
			],
			'actions' => [
				[
					'label' => ['en' => 'Open', 'nl' => 'Openen'],
					'primary' => true,
					'target' => ['kind' => 'route', 'app' => 'openregister', 'route' => self::OPEN_ROUTE],
				],
			],
		],
		// 7. Cancelled, by a person or by propagation from a stopped run.
		'taskCancelled' => [
			'trigger' => ['type' => 'transition', 'action' => ['cancel', 'terminate']],
			'enabled' => true,
			'channels' => ['nc-notification'],
			'recipients' => [['kind' => 'field', 'field' => 'assignee']],
			'subject' => [
				'en' => 'Task withdrawn: {{title}}',
				'nl' => 'Taak ingetrokken: {{title}}',
			],
		],
		// 8. A refused write-back, explained to the person who tried it. No
		// silent revert anywhere: this rule is how the calendar user learns
		// why their tick did not take.
		'taskWriteBackRefused' => [
			'trigger' => ['type' => 'transition', 'action' => self::ACTION_WRITE_BACK_REFUSED],
			'enabled' => true,
			'channels' => ['nc-notification'],
			'recipients' => [['kind' => 'field', 'field' => 'writeBackActor']],
			'subject' => [
				'en' => 'Change not applied: {{title}}',
				'nl' => 'Wijziging niet doorgevoerd: {{title}}',
			],
			'message' => [
				'en' => '{{writeBackReason}}',
				'nl' => '{{writeBackReason}}',
			],
			'actions' => [
				[
					'label' => ['en' => 'Open task', 'nl' => 'Taak openen'],
					'primary' => true,
					'target' => ['kind' => 'route', 'app' => 'openregister', 'route' => self::OPEN_ROUTE],
				],
			],
		],
	];

	/**
	 * The declared rules.
	 *
	 * @return array<string, array<string, mixed>> Rules keyed by name.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function getRules(): array {
		return self::RULES;
	}//end getRules()

	/**
	 * The payload fields a rule may address, as schema properties.
	 *
	 * @return array<string, array<string, string>> Property name => JSON-schema fragment.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function payloadProperties(): array {
		return self::PAYLOAD_PROPERTIES;
	}//end payloadProperties()

	/**
	 * The rule set as the schema array the dialect validator consumes.
	 *
	 * @return array<string, mixed> `properties` plus `x-openregister-notifications`.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function asSchemaArray(): array {
		return [
			'properties' => self::PAYLOAD_PROPERTIES,
			'x-openregister-notifications' => self::RULES,
		];
	}//end asSchemaArray()

	/**
	 * A synthetic Schema carrying the rules, for dispatchWithSchema().
	 *
	 * @return Schema The synthetic schema.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function buildSchema(): Schema {
		$schema = new Schema();
		$schema->setSlug(self::SLUG);
		$schema->setTitle('Task');
		$schema->setProperties(self::PAYLOAD_PROPERTIES);
		$schema->setConfiguration(['x-openregister-notifications' => self::RULES]);

		return $schema;
	}//end buildSchema()
}//end class
