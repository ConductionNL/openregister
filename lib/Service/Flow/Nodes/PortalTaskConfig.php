<?php

/**
 * The portal-task node's configuration intake: validation and templating.
 *
 * Split from {@see PortalTaskNode} the way {@see UserTaskConfig} is split
 * from its node: the node reads as its lifecycle (match, create, deliver,
 * suspend, continue, re-ask) and this class as its boundary. It refuses only
 * what would otherwise bury a mistake in a suspended run (no title, a blank
 * party role, an upload limit that is not a number, a budget spelled wrong)
 * and templates the rest. The mandatory re-ask reason is deliberately NOT
 * validated here: whether a firing is a re-entry is run-time knowledge.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowAdvanceBudget;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCP\IL10N;
use UnexpectedValueException;

/**
 * Reads, validates and templates a portal-task step's configuration.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowValueTemplate and FlowAdvanceBudget
 * are stateless helpers over values; a factory to call them would add a
 * dependency to say the same thing.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The sum of many small
 * readers, each a default-or-refuse over one key; the same shape and the same
 * justification as UserTaskConfig, which sits just under the threshold only
 * because it has no upload vocabulary.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One reader per config key
 * family the node consumes (role, upload, reason, outcome, heartbeat, slot);
 * folding them into fewer readers would put the vocabulary back into the
 * node, which is the split this class exists to keep.
 */
final class PortalTaskConfig {

	/**
	 * Minutes between heartbeats when the flow does not choose. Same value and
	 * same reasoning as the other two waiters.
	 *
	 * @var int
	 */
	private const DEFAULT_HEARTBEAT_MINUTES = 15;

	/**
	 * The floor a configured heartbeat is clamped to: the stock cron period.
	 *
	 * @var int
	 */
	private const MIN_HEARTBEAT_MINUTES = 5;

	/**
	 * The item key the outcome lands under when the flow does not choose.
	 * Distinct from `task` and `signal` so a flow holding all three waiters
	 * never has them writing over each other by default.
	 *
	 * @var string
	 */
	private const DEFAULT_OUTCOME_KEY = 'portalTask';

	/**
	 * The item field the re-ask reason is read from when the flow does not choose.
	 *
	 * @var string
	 */
	private const DEFAULT_REASON_FIELD = 'reason';

	/**
	 * Slot key: the cycle number of the task currently held.
	 *
	 * @var string
	 */
	public const SLOT_CYCLE = 'cycle';

	/**
	 * Slot key: the uuid of the task the previous cycle held.
	 *
	 * @var string
	 */
	public const SLOT_PREVIOUS_TASK_UUID = 'previousTaskUuid';

	/**
	 * Slot key: when the node last CONTINUED past its terminal task. Set once
	 * per cycle; its presence is what tells a re-entry from a first pass.
	 *
	 * @var string
	 */
	public const SLOT_PASSED_AT = 'passedAt';

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations, for refusal messages an author reads.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Refuse a step that asks nothing, names a blank party role, or carries
	 * an unreadable limit or budget.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the config is refused.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
	 */
	public function validate(array $config): void {
		if (trim((string)($config['title'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('Say what is being asked, or nobody can do it.')
			);
		}

		if (array_key_exists('partyRole', $config) === true && trim((string)$config['partyRole']) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('Name the party role on the case (for example "initiator"), or leave it out to use the default.')
			);
		}

		$this->refuseNonNumericLimits(config: $config);

		// Throws its own message, which names the value and states the spelling.
		FlowAdvanceBudget::fromConfig(config: $config);

	}//end validate()

	/**
	 * Refuse a limit that is not a number, and a file count below one.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a limit is unreadable.
	 */
	private function refuseNonNumericLimits(array $config): void {
		foreach (['uploadMaxFiles', 'uploadMaxSizeMb', 'heartbeatMinutes'] as $numeric) {
			$value = ($config[$numeric] ?? null);
			if ($value !== null && $value !== '' && is_numeric($value) === false) {
				throw new UnexpectedValueException(
					$this->l10n->t('%1$s must be a number.', [$numeric])
				);
			}
		}

		if (isset($config['uploadMaxFiles']) === true && is_numeric($config['uploadMaxFiles']) === true && (int)$config['uploadMaxFiles'] < 1) {
			throw new UnexpectedValueException(
				$this->l10n->t('uploadMaxFiles must be at least 1; turn "Upload required" off to allow a completion without a file.')
			);
		}
	}//end refuseNonNumericLimits()

	/**
	 * The party role the node matches on the case.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return string The role; `initiator` by default.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
	 */
	public function partyRole(array $config): string {
		$role = trim((string)($config['partyRole'] ?? ''));
		if ($role === '') {
			return PortalPartyResolver::DEFAULT_ROLE;
		}

		return $role;
	}//end partyRole()

	/**
	 * The upload constraints, normalised for the task's metadata and the
	 * completion validator.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return array{required: bool, maxFiles: int, acceptedTypes: array<int, string>, maxSizeBytes: int|null} The constraints.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
	 */
	public function uploadConstraints(array $config): array {
		$maxFiles = 1;
		if (is_numeric($config['uploadMaxFiles'] ?? null) === true) {
			$maxFiles = max(1, (int)$config['uploadMaxFiles']);
		}

		$maxBytes = null;
		if (is_numeric($config['uploadMaxSizeMb'] ?? null) === true && (float)$config['uploadMaxSizeMb'] > 0) {
			$maxBytes = (int)round((float)$config['uploadMaxSizeMb'] * 1024 * 1024);
		}

		return [
			'required' => $this->truthy(value: ($config['uploadRequired'] ?? false)),
			'maxFiles' => $maxFiles,
			'acceptedTypes' => $this->listOf(value: ($config['uploadAcceptedTypes'] ?? null)),
			'maxSizeBytes' => $maxBytes,
		];
	}//end uploadConstraints()

	/**
	 * The task fields for one ask, templated against the representative item.
	 *
	 * Everything here is passed THROUGH to the task builder. The party
	 * reference is the resolved, frozen match; the state is `active` because
	 * an external task is always assigned at creation (never pooled); the
	 * cycle, previous task and reason ride under `metadata` so "asked three
	 * times" is a read, not a reconstruction.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param array $items The input items; the first is the representative.
	 * @param string $nodeId The node raising the task.
	 * @param string $nodeType The node's catalogue id.
	 * @param string $partyReference The frozen party reference (`party:<ref>`).
	 * @param int $cycle This ask's cycle number, 1 for the first.
	 * @param string|null $previousTaskUuid The previous cycle's task, on a re-ask.
	 * @param string|null $reason The re-ask reason, on a re-ask.
	 *
	 * @return array<string, mixed> The creation payload.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function taskData(
		array $config,
		array $items,
		string $nodeId,
		string $nodeType,
		string $partyReference,
		int $cycle = 1,
		?string $previousTaskUuid = null,
		?string $reason = null,
	): array {
		$json = $this->representativeJson(items: $items);

		$data = [
			'title' => $this->renderedTitle(config: $config, items: $items),
			'description' => $this->renderedOrNull(value: ($config['description'] ?? null), json: $json),
			'state' => Task::STATE_ACTIVE,
			'performerType' => Task::PERFORMER_EXTERNAL,
			'priority' => 'normal',
			'assignee' => $partyReference,
			'dueAt' => $this->renderedOrNull(value: ($config['dueAt'] ?? null), json: $json),
			'expiresAt' => $this->renderedOrNull(value: ($config['expiresAt'] ?? null), json: $json),
			'onTimeout' => $this->behaviourOrNull(value: ($config['onTimeout'] ?? '')),
			'onReject' => $this->behaviourOrNull(value: ($config['onReject'] ?? '')),
			'metadata' => [
				'flowNodeType' => $nodeType,
				'flowNode' => $nodeId,
				'outcomeKey' => $this->outcomeKey(config: $config),
				'partyRole' => $this->partyRole(config: $config),
				'partyReference' => $partyReference,
				'cycle' => $cycle,
				'previousTaskUuid' => $previousTaskUuid,
				'reaskReason' => $reason,
				'upload' => $this->uploadConstraints(config: $config),
			],
		];

		return array_merge($data, $this->subjectAnchor(json: $json));
	}//end taskData()

	/**
	 * What the node remembers in its resume slot once a task exists.
	 *
	 * The assignee stored is the PARTY reference: `FlowRunAssignee` reads it
	 * at the resume door, and no Nextcloud identity can equal a party
	 * reference, so a resume POSTed at the run is refused for everyone, which
	 * is the contract (the resume endpoint cannot answer for a resident).
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param array $items The input items.
	 * @param string $taskUuid The created task.
	 * @param string $partyReference The frozen party reference.
	 * @param int $cycle The cycle number.
	 * @param string|null $previousTaskUuid The previous cycle's task, on a re-ask.
	 *
	 * @return array<string, mixed> The slot values.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-re-ask-creates-a-new-task-carrying-a-mandatory-reason
	 */
	public function slotValues(array $config, array $items, string $taskUuid, string $partyReference, int $cycle, ?string $previousTaskUuid): array {
		return [
			FlowTaskBridge::SLOT_TASK_UUID => $taskUuid,
			FlowTaskBridge::SLOT_ASKED_AT => (new DateTime())->format('c'),
			FlowTaskBridge::SLOT_ADVANCE => FlowAdvanceBudget::fromConfig(config: $config)->toStored(),
			'assignee' => $partyReference,
			'title' => $this->renderedTitle(config: $config, items: $items),
			self::SLOT_CYCLE => $cycle,
			self::SLOT_PREVIOUS_TASK_UUID => $previousTaskUuid,
			self::SLOT_PASSED_AT => null,
		];
	}//end slotValues()

	/**
	 * The re-ask reason the items carry, read from the configured field.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param array $items The input items.
	 *
	 * @return string The reason, trimmed; '' when the field is absent or empty.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-re-ask-creates-a-new-task-carrying-a-mandatory-reason
	 */
	public function reasonFrom(array $config, array $items): string {
		$rendered = FlowValueTemplate::render(
			value: '{{ ' . $this->reasonField(config: $config) . ' }}',
			json: $this->representativeJson(items: $items)
		);
		if (is_scalar($rendered) === false) {
			return '';
		}

		return trim((string)$rendered);
	}//end reasonFrom()

	/**
	 * The item field the re-ask reason is read from.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return string The dotted field path; `reason` by default.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-re-ask-creates-a-new-task-carrying-a-mandatory-reason
	 */
	public function reasonField(array $config): string {
		$field = trim((string)($config['reasonField'] ?? ''));
		if ($field === '') {
			return self::DEFAULT_REASON_FIELD;
		}

		return $field;
	}//end reasonField()

	/**
	 * The item key the outcome is written under.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return string The key; `portalTask` by default.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-suspension-is-heartbeat-safe-and-continues-on-task-terminality
	 */
	public function outcomeKey(array $config): string {
		$key = trim((string)($config['outcomeKey'] ?? ''));
		if ($key === '') {
			return self::DEFAULT_OUTCOME_KEY;
		}

		return $key;
	}//end outcomeKey()

	/**
	 * The title, templated against the representative item.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param array $items The input items.
	 *
	 * @return string The rendered title.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function renderedTitle(array $config, array $items): string {
		return trim((string)FlowValueTemplate::render(
			value: (string)($config['title'] ?? ''),
			json: $this->representativeJson(items: $items)
		));
	}//end renderedTitle()

	/**
	 * When to wake up and re-check, absent a wake. Never null.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return DateTime The next heartbeat.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-suspension-is-heartbeat-safe-and-continues-on-task-terminality
	 */
	public function heartbeatAt(array $config): DateTime {
		$minutes = (int)($config['heartbeatMinutes'] ?? self::DEFAULT_HEARTBEAT_MINUTES);
		if ($minutes < self::MIN_HEARTBEAT_MINUTES) {
			$minutes = self::MIN_HEARTBEAT_MINUTES;
		}

		return (new DateTime())->modify('+' . $minutes . ' minutes');
	}//end heartbeatAt()

	/**
	 * The uuid of the subject case object the representative item is about.
	 *
	 * @param array $items The input items.
	 *
	 * @return string The object uuid, or '' when the item names none.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
	 */
	public function subjectObjectUuid(array $items): string {
		return (string)($this->subjectAnchor(json: $this->representativeJson(items: $items))['objectUuid'] ?? '');
	}//end subjectObjectUuid()

	/**
	 * The object anchor an item carries, when it carries one.
	 *
	 * @param array<string, mixed> $json The representative record.
	 *
	 * @return array<string, mixed> objectUuid, registerId and schemaId as far as known.
	 */
	private function subjectAnchor(array $json): array {
		$self = (array)($json['@self'] ?? []);
		$objectUuid = trim((string)($self['uuid'] ?? ($json['uuid'] ?? ($self['id'] ?? ''))));
		if ($objectUuid === '') {
			return [];
		}

		$anchor = ['objectUuid' => $objectUuid];
		if (is_numeric($self['register'] ?? null) === true) {
			$anchor['registerId'] = (int)$self['register'];
		}

		if (is_numeric($self['schema'] ?? null) === true) {
			$anchor['schemaId'] = (int)$self['schema'];
		}

		return $anchor;
	}//end subjectAnchor()

	/**
	 * A list from a list or a comma-separated string; empty for anything else.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return array<int, string> Trimmed, non-empty entries.
	 */
	private function listOf(mixed $value): array {
		if (is_string($value) === true) {
			$value = explode(',', $value);
		}

		if (is_array($value) === false) {
			return [];
		}

		$list = [];
		foreach ($value as $entry) {
			if (is_scalar($entry) === false) {
				continue;
			}

			$entry = trim((string)$entry);
			if ($entry !== '') {
				$list[] = $entry;
			}
		}

		return $list;
	}//end listOf()

	/**
	 * A boolean read the way a form or a JSON body may spell it.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return bool The boolean.
	 */
	private function truthy(mixed $value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
	}//end truthy()

	/**
	 * The record of the representative item: the first array item's json.
	 *
	 * @param array $items The input items.
	 *
	 * @return array<string, mixed> The record, empty when there is none.
	 */
	private function representativeJson(array $items): array {
		foreach ($items as $item) {
			if (is_array($item) === true) {
				return (array)($item[FlowItems::JSON] ?? []);
			}
		}

		return [];
	}//end representativeJson()

	/**
	 * A declared behaviour value, trimmed, or null for absent. Vocabulary
	 * validation is TaskBuilder's boundary, not this node's.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return string|null The behaviour, or null.
	 */
	private function behaviourOrNull(mixed $value): ?string {
		$behaviour = trim((string)$value);
		if ($behaviour === '') {
			return null;
		}

		return $behaviour;
	}//end behaviourOrNull()

	/**
	 * A templated string, or null when it renders to nothing.
	 *
	 * @param mixed $value The configured value.
	 * @param array<string, mixed> $json The representative record.
	 *
	 * @return string|null The rendered string, or null.
	 */
	private function renderedOrNull(mixed $value, array $json): ?string {
		if ($value === null) {
			return null;
		}

		$rendered = FlowValueTemplate::render(value: $value, json: $json);
		if (is_scalar($rendered) === false) {
			return null;
		}

		$rendered = trim((string)$rendered);
		if ($rendered === '') {
			return null;
		}

		return $rendered;
	}//end renderedOrNull()
}//end class
