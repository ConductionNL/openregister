<?php

/**
 * The history an ensure brings over from another engine, checked and grouped
 * by item, and the audit rows an ensured item is inserted with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use DateTime;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Exception\CaseValidationException;
use Throwable;

/**
 * One ensure's brought-over history: every entry names a declared item, has
 * real states and a readable moment, or the whole ensure is refused before
 * anything is written.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
 */
final class CaseEnsuredHistory {

	/**
	 * The checked entries by item key.
	 *
	 * @var array<string, array<int, array{from: string, to: string, at: DateTime, actor: string|null, reason: string|null}>>
	 */
	private readonly array $byKey;

	/**
	 * Check the history and group it by item key.
	 *
	 * @param array<int, mixed>     $history Entries `{item, from?, to, at, actor?, reason?}`.
	 * @param array<string, string> $types   The definition's key => type.
	 *
	 * @throws CaseValidationException On an entry that is not usable.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	public function __construct(array $history, array $types) {
		$byKey = [];
		foreach ($history as $index => $entry) {
			$label = sprintf('history[%d]', (int)$index);
			$key = self::historyKey(label: $label, entry: $entry);
			if (isset($types[$key]) === false) {
				throw new CaseValidationException(message: sprintf("%s names item '%s', which the definition does not declare.", $label, $key));
			}

			$byKey[$key][] = $this->historyRecord(label: $label, entry: $entry);
		}

		$this->byKey = $byKey;
	}//end __construct()

	/**
	 * The audit rows an ensured item is inserted with: its creation in the
	 * state it carries, by the importer, then its brought-over history,
	 * flagged as imported, with the original moments and actors.
	 *
	 * @param CaseItem $item  The inserted row, in the state it carries.
	 * @param string   $actor The importer, recorded as the cause reference and as the actor of an entry that names none.
	 *
	 * @return array<int, CaseItemAudit> The rows to append, in order.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	public function auditsFor(CaseItem $item, string $actor): array {
		$why = new CaseTransitionCause(cause: CaseItemAudit::CAUSE_IMPORT, causeRef: 'ensure:' . $actor, actor: $actor, reason: null);
		$creation = $why->auditFor(item: $item, fromState: '', toState: (string)$item->getState());
		$creation->setImported(false);
		$audits = [$creation];
		foreach (($this->byKey[(string)$item->getItemKey()] ?? []) as $entry) {
			$why = new CaseTransitionCause(
				cause: CaseItemAudit::CAUSE_IMPORT,
				causeRef: 'ensure:' . $actor,
				actor: ($entry['actor'] ?? $actor),
				reason: $entry['reason']
			);
			$audit = $why->auditFor(item: $item, fromState: $entry['from'], toState: $entry['to']);
			$audit->setImported(true);
			$audit->setCreated($entry['at']);
			$audits[] = $audit;
		}

		return $audits;
	}//end auditsFor()

	/**
	 * The item a history entry names, trimmed.
	 *
	 * @param string $label The entry, for messages.
	 * @param mixed  $entry The entry as submitted.
	 *
	 * @return string The trimmed item key.
	 *
	 * @throws CaseValidationException When the entry is not an object.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private static function historyKey(string $label, mixed $entry): string {
		if (is_array($entry) === false) {
			throw new CaseValidationException(message: sprintf('%s must be an object.', $label));
		}

		return trim((string)($entry['item'] ?? ''));
	}//end historyKey()

	/**
	 * One history entry as it is imported: both states real, the moment readable.
	 *
	 * @param string               $label The entry, for messages.
	 * @param array<string, mixed> $entry The entry as submitted.
	 *
	 * @return array{from: string, to: string, at: DateTime, actor: string|null, reason: string|null} The entry.
	 *
	 * @throws CaseValidationException On a state that is not one, or an unreadable moment.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function historyRecord(string $label, array $entry): array {
		$states = self::historyStates(label: $label, entry: $entry);

		return [
			'from' => $states['from'],
			'to' => $states['to'],
			'at' => $this->historyMoment(label: $label, value: ($entry['at'] ?? null)),
			'actor' => $this->stringOrNull(value: ($entry['actor'] ?? null)),
			'reason' => $this->stringOrNull(value: ($entry['reason'] ?? null)),
		];
	}//end historyRecord()

	/**
	 * A history entry's to-state, and its from-state when given, trimmed; both must be plan-item states.
	 *
	 * @param string               $label The entry, for messages.
	 * @param array<string, mixed> $entry The entry as submitted.
	 *
	 * @return array{from: string, to: string} The trimmed from-state (or '') and to-state.
	 *
	 * @throws CaseValidationException When either is not a state.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private static function historyStates(string $label, array $entry): array {
		$from = trim((string)($entry['from'] ?? ''));
		$to = trim((string)($entry['to'] ?? ''));
		if (in_array($to, CaseItem::STATES, true) === false || ($from !== '' && in_array($from, CaseItem::STATES, true) === false)) {
			throw new CaseValidationException(
				message: sprintf("%s has from-state '%s' and to-state '%s'; both must be plan-item states.", $label, $from, $to)
			);
		}

		return ['from' => $from, 'to' => $to];
	}//end historyStates()

	/**
	 * The moment of a history entry.
	 *
	 * @param string $label The entry, for messages.
	 * @param mixed  $value Its `at`.
	 *
	 * @return DateTime The moment.
	 *
	 * @throws CaseValidationException When it is not an ISO 8601 date-time.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function historyMoment(string $label, mixed $value): DateTime {
		$moment = $this->moment(value: $value);
		if ($moment === null) {
			throw new CaseValidationException(message: sprintf('%s has an unreadable moment; `at` must be an ISO 8601 date-time.', $label));
		}

		return $moment;
	}//end historyMoment()

	/**
	 * Parse an ISO 8601 moment.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTime|null The moment, or null when unreadable.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function moment(mixed $value): ?DateTime {
		if (is_string($value) === false) {
			return null;
		}

		// Only a full ISO 8601 date-time with an offset: relative phrases that
		// DateTime would happily accept ("yesterday") are not a moment. An
		// empty value does not match either.
		$pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/';
		if (preg_match($pattern, trim($value)) !== 1) {
			return null;
		}

		try {
			return new DateTime(trim($value));
		} catch (Throwable) {
			return null;
		}
	}//end moment()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end stringOrNull()
}//end class
