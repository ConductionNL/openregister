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
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalReference;
use OCA\OpenRegister\Service\Flow\FlowAdvanceBudget;
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
	 * The config keys whose value must come from a published vocabulary, with that vocabulary.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const VOCABULARIES = [
		'performerType' => Task::PERFORMER_TYPES,
		'priority' => Task::PRIORITIES,
		'routingStrategy' => Task::ROUTING_STRATEGIES,
	];

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
	 * The config readings this step shares with the other task step.
	 *
	 * @var TaskConfigCommon
	 */
	private readonly TaskConfigCommon $common;

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
		$this->common = new TaskConfigCommon(defaultOutcomeKey: self::DEFAULT_OUTCOME_KEY);
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
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
	 */
	public function validate(array $config): void {
		$this->refuseIncompleteAsk(config: $config);
		$this->refuseOutsideVocabularies(config: $config);
		$this->refuseUnreadableOutcomesOrHeartbeat(config: $config);

		// Throws its own message, which names the value and states the spelling.
		FlowAdvanceBudget::fromConfig(config: $config);

		// Throws naming schema, field and reason; a step with no form passes.
		$this->forms->validate(form: $this->forms->fromConfig(config: $config));

	}//end validate()

	/**
	 * Refuse a step that asks nothing, or asks nobody.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the title or every performer is missing.
	 */
	private function refuseIncompleteAsk(array $config): void {
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
	}//end refuseIncompleteAsk()

	/**
	 * Refuse a closed-vocabulary key set to a value outside its vocabulary.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a value is outside its vocabulary.
	 */
	private function refuseOutsideVocabularies(array $config): void {
		foreach (array_keys(self::VOCABULARIES) as $key) {
			$this->refuseOutsideVocabulary(config: $config, key: $key);
		}
	}//end refuseOutsideVocabularies()

	/**
	 * Refuse outcomes that read as no list, and a heartbeat that is not a number.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When either is unreadable.
	 */
	private function refuseUnreadableOutcomesOrHeartbeat(array $config): void {
		if (array_key_exists('outcomes', $config) === true && $this->common->listOf(value: $config['outcomes']) === []) {
			throw new UnexpectedValueException(
				$this->l10n->t('Possible outcomes must be a list of names, like "approved, rejected".')
			);
		}

		if (array_key_exists('heartbeatMinutes', $config) === true && is_numeric($config['heartbeatMinutes']) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('Re-check every (minutes) must be a number.')
			);
		}
	}//end refuseUnreadableOutcomesOrHeartbeat()

	/**
	 * The task fields, templated against the representative item.
	 *
	 * Everything here is passed THROUGH to the task builder, which validates
	 * it. The node adds no field of its own; the outcome vocabulary and the
	 * item key ride under `metadata`, which the task service carries and never
	 * interprets. The task is anchored to the object the item is about when
	 * the item says which one that is, so the inbox can show its subject.
	 *
	 * @param UserTaskStep $step     The step configuration, the input items (the
	 *                               first is the representative) and the node raising the task.
	 * @param string       $nodeType The node's catalogue id.
	 *
	 * @return array<string, mixed> The creation payload.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	public function taskData(UserTaskStep $step, string $nodeType): array {
		$config = $step->config;
		$items  = $step->items;
		$json   = $this->common->representativeJson(items: $items);
		$assignee = $this->assignee(config: $config);

		$state = Task::STATE_ENABLED;
		if ($assignee !== '') {
			$state = Task::STATE_ACTIVE;
		}

		$data = [
			'title' => $this->renderedTitle(config: $config, items: $items),
			'description' => $this->renderedOrNull(value: ($config['description'] ?? null), json: $json),
			'state' => $state,
			'performerType' => UserTaskPerformers::kindFor(config: $config, performers: $this->performers(config: $config)),
			'priority' => trim((string)($config['priority'] ?? 'normal')),
			'assignee' => $this->nullIfEmpty(value: $assignee),
			'candidateUsers' => $this->nullIfEmptyList(value: $this->common->listOf(value: ($config['candidateUsers'] ?? null))),
			'candidateGroups' => $this->nullIfEmptyList(value: $this->common->listOf(value: ($config['candidateGroups'] ?? null))),
			'candidateRole' => $this->nullIfEmpty(value: trim((string)($config['candidateRole'] ?? ''))),
			'routingStrategy' => $this->nullIfEmpty(value: trim((string)($config['routingStrategy'] ?? ''))),
			'routingFallback' => $this->nullIfEmpty(value: trim((string)($config['routingFallback'] ?? ''))),
			'dueAt' => $this->renderedOrNull(value: ($config['dueAt'] ?? null), json: $json),
			'expiresAt' => $this->renderedOrNull(value: ($config['expiresAt'] ?? null), json: $json),
			'onTimeout' => $this->nullIfEmpty(value: trim((string)($config['onTimeout'] ?? ''))),
			'onReject' => $this->nullIfEmpty(value: trim((string)($config['onReject'] ?? ''))),
			'metadata' => [
				'flowNodeType' => $nodeType,
				'flowNode' => $step->nodeId,
				'outcomes' => $this->common->listOf(value: ($config['outcomes'] ?? null)),
				'outcomeKey' => $this->outcomeKey(config: $config),
			],
		];

		return array_merge($data, $this->common->subjectAnchor(json: $json));
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
	 * @param UserTaskStep $step     The step configuration and input items.
	 * @param string       $taskUuid The created task.
	 *
	 * @return array<string, mixed> The slot values.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-several-user-task-nodes-in-one-flow-keep-independent-state
	 */
	public function slotValues(UserTaskStep $step, string $taskUuid): array {
		$config = $step->config;

		return [
			FlowTaskBridge::SLOT_TASK_UUID => $taskUuid,
			FlowTaskBridge::SLOT_ASKED_AT => (new DateTime())->format('c'),
			FlowTaskBridge::SLOT_ADVANCE => FlowAdvanceBudget::fromConfig(config: $config)->toStored(),
			'assignee' => $this->assigneeValue(config: $config),
			'title' => $this->renderedTitle(config: $config, items: $step->items),
		];
	}//end slotValues()

	/**
	 * The assignee as the task row stores it.
	 *
	 * Delegated to {@see UserTaskAssignee}, which owns both shapes: the two are
	 * read by different consumers for different purposes, and keeping them here
	 * put this class over its complexity budget.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return string The stored assignee, or ''.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function assignee(array $config): string {
		return PrincipalReference::storedString(value: ($config['assignee'] ?? ''));
	}//end assignee()

	/**
	 * The assignee as configured, keeping a typed reference's shape.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return string|array<mixed> The configured assignee.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function assigneeValue(array $config): string|array {
		$value = ($config['assignee'] ?? '');
		if (is_array($value) === true) {
			return $value;
		}

		return trim((string)$value);
	}//end assigneeValue()

	/**
	 * The item key the outcome is written under.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-outcome-is-written-onto-every-item-not-only-onto-the-run
	 */
	public function outcomeKey(array $config): string {
		return $this->common->outcomeKey(config: $config);
	}//end outcomeKey()

	/**
	 * The title, templated against the representative item.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param array $items The input items.
	 *
	 * @return string The rendered title.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	public function renderedTitle(array $config, array $items): string {
		return $this->common->renderedTitle(config: $config, items: $items);
	}//end renderedTitle()

	/**
	 * When to wake up and re-ask, absent a wake. Never null.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return DateTime The next heartbeat.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
	 */
	public function heartbeatAt(array $config): DateTime {
		return $this->common->heartbeatAt(config: $config);
	}//end heartbeatAt()

	/**
	 * Whether the config names anybody who could perform the task.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return boolean True when at least one performer source is set.
	 */
	private function namesAPerformer(array $config): bool {
		// Read as REFERENCES, not as strings. A `{type, id}` map casts to the
		// string "Array", which is non-empty — so a string test would call a
		// typed assignee "named" for the wrong reason today and would keep
		// doing so if the shape ever changed.
		return $this->performers(config: $config) !== [];
	}//end namesAPerformer()

	/**
	 * Everybody this step could ask, as typed references.
	 *
	 * 🔑 THE THREE LEGACY FIELDS ARE READ BY THEIR NAMES. `candidateUsers`,
	 * `candidateGroups` and `candidateRole` differ only in the kind of thing
	 * you type into them — a type system implemented as field names, which
	 * existed because the field was a text box. Each is read as candidates of
	 * its own type, so no stored flow changes meaning and none needs migrating.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return array<int, PrincipalReference> Every performer the step names.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `PrincipalReference::listFrom()` and
	 * `listOfType()` are named constructors on a value object, which is the
	 * canonical PHP idiom and indistinguishable to this rule from a static call
	 * into a service.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function performers(array $config): array {
		return array_merge(
			PrincipalReference::listFrom(value: ($config['assignee'] ?? null)),
			PrincipalReference::listFrom(value: ($config['candidates'] ?? null)),
			PrincipalReference::listFrom(value: ($config['routingFallback'] ?? null)),
			PrincipalReference::listOfType(value: ($config['candidateUsers'] ?? null), type: 'user'),
			PrincipalReference::listOfType(value: ($config['candidateGroups'] ?? null), type: 'group'),
			// `candidateRole` names a GROUP: the pre-typed guard resolved it
			// through `isInGroup()`, so reading it as anything else would move
			// who may answer on every stored flow that uses it.
			PrincipalReference::listOfType(value: ($config['candidateRole'] ?? null), type: 'group')
		);
	}//end performers()

	/**
	 * Refuse a set value outside a published vocabulary, naming both.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param string $key The config key, one of {@see self::VOCABULARIES}.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the value is set and not in the vocabulary.
	 */
	private function refuseOutsideVocabulary(array $config, string $key): void {
		$vocabulary = self::VOCABULARIES[$key];
		$value = trim((string)($config[$key] ?? ''));
		if ($value === '' || in_array($value, $vocabulary, true) === true) {
			return;
		}

		throw new UnexpectedValueException(
			$this->l10n->t('%1$s "%2$s" is not one of %3$s.', [$key, $value, implode(', ', $vocabulary)])
		);
	}//end refuseOutsideVocabulary()

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
