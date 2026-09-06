<?php

/**
 * The user-task node's configuration intake: validation and templating.
 *
 * Split from {@see UserTaskNode} so the node reads as its lifecycle (create,
 * suspend, continue, place the outcome) and this class as its boundary: what
 * a saved configuration must say, and how it turns into a task payload for
 * one item. Every value assembled here is passed THROUGH to the task
 * builder, which owns the vocabularies; this class refuses only what would
 * otherwise bury a mistake in a suspended run (a task nobody can be found
 * for, a budget spelled wrong) and templates the rest.
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
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowAdvanceBudget;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\IL10N;
use UnexpectedValueException;

/**
 * Reads, validates and templates a user-task step's configuration.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowValueTemplate and FlowAdvanceBudget
 * are stateless helpers over values; a factory to call them would add a
 * dependency to say the same thing.
 */
final class UserTaskConfig {

	/**
	 * Minutes between heartbeats when the flow does not choose.
	 *
	 * Same reasoning as {@see AwaitSignalNode}: short enough that a lost wake
	 * is an inconvenience, long enough that a fortnight-long approval costs a
	 * few thousand no-op wakes. A completion wakes the run at once either way.
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
	 *
	 * `task`, not `signal`: a flow holding both wait nodes must not have them
	 * writing over each other's key by default.
	 *
	 * @var string
	 */
	private const DEFAULT_OUTCOME_KEY = 'task';

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations, for refusal messages an author reads.
	 * @param TaskFormReader $forms Reads and refuses the step's form declaration.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly TaskFormReader $forms,
	) {

	}//end __construct()

	/**
	 * Refuse a step that asks nothing, asks nobody, or carries an unreadable budget.
	 *
	 * The performer check is the one that must not be left to run time: a task
	 * nobody can be found for is not a task, and failing at run time would bury
	 * the mistake in a suspended run. The budget check is design D-4: `null`
	 * is refused by name so an author who asked for unlimited never silently
	 * gets zero.
	 *
	 * The form declaration is checked here too, against the LIVE subject
	 * schema: a field that is not a property, or is read-only or not visible,
	 * an action the schema does not declare, or an external form without the
	 * Forms app, is refused now, naming schema, field and reason. Left to run
	 * time it would surface as a refusal the performer cannot act on.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the config is refused.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
	 */
	public function validate(array $config): void {
		if (trim((string)($config['title'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('Say what is being asked, or nobody can do it.')
			);
		}

		if ($this->namesAPerformer(config: $config) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t(
					'No performer can be resolved: name an assignee, candidate users, candidate groups, a candidate role or a routing fallback.'
				)
			);
		}

		$this->refuseOutsideVocabulary(config: $config, key: 'performerType', vocabulary: Task::PERFORMER_TYPES);
		$this->refuseOutsideVocabulary(config: $config, key: 'priority', vocabulary: Task::PRIORITIES);
		$this->refuseOutsideVocabulary(config: $config, key: 'routingStrategy', vocabulary: Task::ROUTING_STRATEGIES);

		if (array_key_exists('outcomes', $config) === true && $this->listOf(value: $config['outcomes']) === []) {
			throw new UnexpectedValueException(
				$this->l10n->t('Possible outcomes must be a list of names, like "approved, rejected".')
			);
		}

		if (array_key_exists('heartbeatMinutes', $config) === true && is_numeric($config['heartbeatMinutes']) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('Re-check every (minutes) must be a number.')
			);
		}

		// Throws its own message, which names the value and states the spelling.
		FlowAdvanceBudget::fromConfig(config: $config);

		// Throws naming schema, field and reason; a step with no form passes.
		$this->forms->validate(form: $this->forms->fromConfig(config: $config));

	}//end validate()

	/**
	 * The task fields, templated against the representative item.
	 *
	 * Everything here is passed THROUGH to the task builder, which validates
	 * it. The node adds no field of its own; the outcome vocabulary and the
	 * item key ride under `metadata`, which the task service carries and never
	 * interprets. The task is anchored to the object the item is about when
	 * the item says which one that is, so the inbox can show its subject.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param array $items The input items; the first is the representative.
	 * @param string $nodeId The node raising the task.
	 * @param string $nodeType The node's catalogue id.
	 *
	 * @return array<string, mixed> The creation payload.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	public function taskData(array $config, array $items, string $nodeId, string $nodeType): array {
		$json = $this->representativeJson(items: $items);
		$assignee = $this->assignee(config: $config);

		$state = Task::STATE_ENABLED;
		if ($assignee !== '') {
			$state = Task::STATE_ACTIVE;
		}

		$data = [
			'title' => $this->renderedTitle(config: $config, items: $items),
			'description' => $this->renderedOrNull(value: ($config['description'] ?? null), json: $json),
			'state' => $state,
			'performerType' => trim((string)($config['performerType'] ?? Task::PERFORMER_USER)),
			'priority' => trim((string)($config['priority'] ?? 'normal')),
			'assignee' => $this->nullIfEmpty(value: $assignee),
			'candidateUsers' => $this->nullIfEmptyList(value: $this->listOf(value: ($config['candidateUsers'] ?? null))),
			'candidateGroups' => $this->nullIfEmptyList(value: $this->listOf(value: ($config['candidateGroups'] ?? null))),
			'candidateRole' => $this->nullIfEmpty(value: trim((string)($config['candidateRole'] ?? ''))),
			'routingStrategy' => $this->nullIfEmpty(value: trim((string)($config['routingStrategy'] ?? ''))),
			'routingFallback' => $this->nullIfEmpty(value: trim((string)($config['routingFallback'] ?? ''))),
			'dueAt' => $this->renderedOrNull(value: ($config['dueAt'] ?? null), json: $json),
			'expiresAt' => $this->renderedOrNull(value: ($config['expiresAt'] ?? null), json: $json),
			'onTimeout' => $this->nullIfEmpty(value: trim((string)($config['onTimeout'] ?? ''))),
			'onReject' => $this->nullIfEmpty(value: trim((string)($config['onReject'] ?? ''))),
			'metadata' => [
				'flowNodeType' => $nodeType,
				'flowNode' => $nodeId,
				'outcomes' => $this->listOf(value: ($config['outcomes'] ?? null)),
				'outcomeKey' => $this->outcomeKey(config: $config),
			],
		];

		return array_merge($data, $this->subjectAnchor(json: $json));
	}//end taskData()

	/**
	 * What the node remembers in its resume slot once the task exists.
	 *
	 * Written ONCE by the node: askedAt records when somebody was first asked,
	 * not when the run last checked; the budget is the one the node was saved
	 * with, so the completion listener spends what the author asked for; the
	 * assignee is read by FlowRunAssignee so a resume POSTed at the run by
	 * anyone else is refused at the door as well as ignored by the node.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param array $items The input items.
	 * @param string $taskUuid The created task.
	 *
	 * @return array<string, mixed> The slot values.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-several-user-task-nodes-in-one-flow-keep-independent-state
	 */
	public function slotValues(array $config, array $items, string $taskUuid): array {
		return [
			FlowTaskBridge::SLOT_TASK_UUID => $taskUuid,
			FlowTaskBridge::SLOT_ASKED_AT => (new DateTime())->format('c'),
			FlowTaskBridge::SLOT_ADVANCE => FlowAdvanceBudget::fromConfig(config: $config)->toStored(),
			'assignee' => $this->assignee(config: $config),
			'title' => $this->renderedTitle(config: $config, items: $items),
		];
	}//end slotValues()

	/**
	 * The directly configured assignee, trimmed; '' when the task is pooled.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return string The assignee uid, or ''.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	public function assignee(array $config): string {
		return trim((string)($config['assignee'] ?? ''));
	}//end assignee()

	/**
	 * The item key the outcome is written under.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-outcome-is-written-onto-every-item-not-only-onto-the-run
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
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	public function renderedTitle(array $config, array $items): string {
		return trim((string)FlowValueTemplate::render(
			value: (string)($config['title'] ?? ''),
			json: $this->representativeJson(items: $items)
		));
	}//end renderedTitle()

	/**
	 * When to wake up and re-ask, absent a wake. Never null.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return DateTime The next heartbeat.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
	 */
	public function heartbeatAt(array $config): DateTime {
		$minutes = (int)($config['heartbeatMinutes'] ?? self::DEFAULT_HEARTBEAT_MINUTES);
		if ($minutes < self::MIN_HEARTBEAT_MINUTES) {
			$minutes = self::MIN_HEARTBEAT_MINUTES;
		}

		return (new DateTime())->modify('+' . $minutes . ' minutes');
	}//end heartbeatAt()

	/**
	 * Whether the config names anybody who could perform the task.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return boolean True when at least one performer source is set.
	 */
	private function namesAPerformer(array $config): bool {
		foreach (['assignee', 'candidateRole', 'routingFallback'] as $key) {
			if (trim((string)($config[$key] ?? '')) !== '') {
				return true;
			}
		}

		foreach (['candidateUsers', 'candidateGroups'] as $key) {
			if ($this->listOf(value: ($config[$key] ?? null)) !== []) {
				return true;
			}
		}

		return false;
	}//end namesAPerformer()

	/**
	 * Refuse a set value outside a published vocabulary, naming both.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param string $key The config key.
	 * @param array<int, string> $vocabulary The accepted values.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the value is set and not in the vocabulary.
	 */
	private function refuseOutsideVocabulary(array $config, string $key, array $vocabulary): void {
		$value = trim((string)($config[$key] ?? ''));
		if ($value === '' || in_array($value, $vocabulary, true) === true) {
			return;
		}

		throw new UnexpectedValueException(
			$this->l10n->t('%1$s "%2$s" is not one of %3$s.', [$key, $value, implode(', ', $vocabulary)])
		);
	}//end refuseOutsideVocabulary()

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

		return $this->nullIfEmpty(value: trim((string)$rendered));
	}//end renderedOrNull()

	/**
	 * Null for an empty string.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The value, or null when empty.
	 */
	private function nullIfEmpty(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nullIfEmpty()

	/**
	 * Null for an empty list.
	 *
	 * @param array<int, string> $value The list.
	 *
	 * @return array<int, string>|null The list, or null when empty.
	 */
	private function nullIfEmptyList(array $value): ?array {
		if ($value === []) {
			return null;
		}

		return $value;
	}//end nullIfEmptyList()
}//end class
