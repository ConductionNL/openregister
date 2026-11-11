<?php

/**
 * What the user-task and portal-task configs read from a step the same way.
 *
 * "Ask a person or group" and "Ask a party outside the organisation" differ in
 * who they ask and what they hand in, but read a handful of config values by
 * the same rules: the key the outcome lands under, the rendered title, when
 * the next heartbeat is due, which object the task hangs on, and how a list
 * value is split. Those rules are kept here once, so the two steps cannot
 * start disagreeing about them.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;

/**
 * Config readings shared by UserTaskConfig and PortalTaskConfig.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class TaskConfigCommon {

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
	 * Constructor.
	 *
	 * @param string $defaultOutcomeKey The item key the outcome lands under when the flow does not choose.
	 */
	public function __construct(
		private readonly string $defaultOutcomeKey,
	) {

	}//end __construct()

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
		$key = trim((string)($config['outcomeKey'] ?? ''));
		if ($key === '') {
			return $this->defaultOutcomeKey;
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
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	public function renderedTitle(array $config, array $items): string {
		return trim((string)FlowValueTemplate::render(
			value: (string)($config['title'] ?? ''),
			json: $this->representativeJson(items: $items)
		));
	}//end renderedTitle()

	/**
	 * A templated string, or null when it is absent or renders to nothing.
	 *
	 * @param mixed                $value The configured value.
	 * @param array<string, mixed> $json  The representative record.
	 *
	 * @return string|null The rendered string, or null.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	public function renderedOrNull(mixed $value, array $json): ?string {
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
		$minutes = (int)($config['heartbeatMinutes'] ?? self::DEFAULT_HEARTBEAT_MINUTES);
		if ($minutes < self::MIN_HEARTBEAT_MINUTES) {
			$minutes = self::MIN_HEARTBEAT_MINUTES;
		}

		return (new DateTime())->modify('+' . $minutes . ' minutes');
	}//end heartbeatAt()

	/**
	 * The object anchor an item carries, when it carries one.
	 *
	 * @param array<string, mixed> $json The representative record.
	 *
	 * @return array<string, mixed> objectUuid, registerId and schemaId as far as known.
	 */
	public function subjectAnchor(array $json): array {
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
	public function listOf(mixed $value): array {
		$list = [];
		foreach (self::entriesOf(value: $value) as $entry) {
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
	 * The raw entries of a configured list: a string split on commas, an array
	 * as given, nothing for anything else.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return array<mixed> The untrimmed entries.
	 */
	private static function entriesOf(mixed $value): array {
		if (is_string($value) === true) {
			return explode(',', $value);
		}

		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end entriesOf()

	/**
	 * The record of the representative item: the first array item's json.
	 *
	 * @param array $items The input items.
	 *
	 * @return array<string, mixed> The record, empty when there is none.
	 */
	public function representativeJson(array $items): array {
		foreach ($items as $item) {
			if (is_array($item) === true) {
				return (array)($item[FlowItems::JSON] ?? []);
			}
		}

		return [];
	}//end representativeJson()
}//end class
