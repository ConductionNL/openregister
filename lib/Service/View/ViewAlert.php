<?php

/**
 * A saved view that says something when its count crosses a line.
 *
 * 🔴 IT FIRES ON THE CROSSING, NOT ON THE STATE. A count above the threshold
 * fires once and stays `fired` until a sweep sees it back across the line, then
 * re-arms. Firing on the state would page a team lead every fifteen minutes for
 * as long as a backlog stands, which is how an alert becomes a thing people
 * filter out of their inbox — and the one that mattered goes with it.
 *
 * 🔑 THE THRESHOLD IS A DECLARATION, and everything about the alert is read
 * from it: nothing is inferred from the view's query, and nothing is remembered
 * except the last state and the last count. An alert that adapted to what it
 * had seen would be a different alert from the one somebody set.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\View
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\View;

use InvalidArgumentException;
use JsonSerializable;

/**
 * One view's declared count alert.
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
 */
final class ViewAlert implements JsonSerializable {

	/**
	 * Fire when the count reaches or passes the threshold.
	 *
	 * @var string
	 */
	public const GTE = 'gte';

	/**
	 * Fire when the count falls to or below the threshold.
	 *
	 * @var string
	 */
	public const LTE = 'lte';

	/**
	 * The whole operator vocabulary.
	 *
	 * @var array<int, string>
	 */
	public const OPERATORS = [self::GTE, self::LTE];

	/**
	 * Waiting for the count to cross.
	 *
	 * @var string
	 */
	public const ARMED = 'armed';

	/**
	 * Crossed, and said so. Stays here until the count comes back.
	 *
	 * @var string
	 */
	public const FIRED = 'fired';

	/**
	 * Shortest interval a view may be evaluated on, in seconds.
	 *
	 * A count is a query. One view asking every ten seconds is a load nobody
	 * notices; a thousand of them is an outage, and the person who set the
	 * first one had no way to know about the other nine hundred.
	 *
	 * @var int
	 */
	public const MIN_EVERY = 300;

	/**
	 * Constructor.
	 *
	 * @param string   $operator   One of OPERATORS.
	 * @param int      $threshold  The line.
	 * @param string[] $recipients Who hears about it.
	 * @param string[] $channels   How.
	 * @param int      $every      Seconds between evaluations.
	 */
	private function __construct(
		public readonly string $operator,
		public readonly int $threshold,
		public readonly array $recipients,
		public readonly array $channels,
		public readonly int $every,
	) {
	}//end __construct()

	/**
	 * Read a declared alert, refusing anything that is not one.
	 *
	 * 🔴 EVERY REFUSAL NAMES ITS FIELD. "The alert is invalid" sends somebody
	 * back to a form with five inputs and no idea which one; this is a
	 * validator for a thing a person typed, and a 422 that does not say what to
	 * fix is a 500 with better manners.
	 *
	 * @param mixed $raw The declared block.
	 *
	 * @return self|null The alert, or null when none is declared.
	 *
	 * @throws InvalidArgumentException When a declared alert does not read.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	public static function parse(mixed $raw): ?self {
		if ($raw === null || $raw === [] || $raw === '') {
			return null;
		}

		if (is_array($raw) === false) {
			throw new InvalidArgumentException('alert: an alert is an object with an operator and a threshold.');
		}

		// The four field checks run in the ORDER they used to, and `channels`
		// still sits between recipients and every, because each one throws and
		// reordering them would change WHICH field a malformed alert names.
		$operator = self::validOperator(raw: ($raw['operator'] ?? null));
		$threshold = self::validThreshold(raw: ($raw['threshold'] ?? null));
		$recipients = self::validRecipients(raw: ($raw['recipients'] ?? []));

		$channels = self::stringList(raw: ($raw['channels'] ?? []), field: 'alert.channels');
		if ($channels === []) {
			$channels = ['nc-notification'];
		}

		return new self(
			operator: $operator,
			threshold: $threshold,
			recipients: $recipients,
			channels: $channels,
			every: self::validEvery(raw: ($raw['every'] ?? self::MIN_EVERY))
		);
	}//end parse()

	/**
	 * The declared operator, or a refusal naming the field.
	 *
	 * @param mixed $raw The declared operator.
	 *
	 * @return string The operator.
	 *
	 * @throws InvalidArgumentException When it is not one this class knows.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	private static function validOperator(mixed $raw): string {
		if (is_string($raw) === false || in_array($raw, self::OPERATORS, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					'alert.operator: use one of %s; got %s.',
					implode(', ', self::OPERATORS),
					var_export($raw, true)
				)
			);
		}

		return $raw;
	}//end validOperator()

	/**
	 * The declared threshold, or a refusal naming the field.
	 *
	 * @param mixed $raw The declared threshold.
	 *
	 * @return integer The threshold.
	 *
	 * @throws InvalidArgumentException When it is not a whole count of rows.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	private static function validThreshold(mixed $raw): int {
		if (is_int($raw) === false || $raw < 0) {
			throw new InvalidArgumentException(
				sprintf('alert.threshold: a count threshold is a whole number of rows, zero or more; got %s.', var_export($raw, true))
			);
		}

		return $raw;
	}//end validThreshold()

	/**
	 * The declared recipients, or a refusal naming the field.
	 *
	 * An alert nobody hears is a query run on a timer forever. It is not a
	 * smaller alert; it is a cost with no reader.
	 *
	 * @param mixed $raw The declared recipients.
	 *
	 * @return array<int, string> The recipients.
	 *
	 * @throws InvalidArgumentException When the list is empty or unreadable.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	private static function validRecipients(mixed $raw): array {
		$recipients = self::stringList(raw: $raw, field: 'alert.recipients');
		if ($recipients === []) {
			throw new InvalidArgumentException('alert.recipients: name at least one recipient, or the alert has nobody to tell.');
		}

		return $recipients;
	}//end validRecipients()

	/**
	 * The declared evaluation interval, or a refusal naming the field.
	 *
	 * @param mixed $raw The declared interval in seconds.
	 *
	 * @return integer The interval.
	 *
	 * @throws InvalidArgumentException When it is under the floor.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	private static function validEvery(mixed $raw): int {
		if (is_int($raw) === false || $raw < self::MIN_EVERY) {
			throw new InvalidArgumentException(
				sprintf('alert.every: evaluate at most once every %d seconds; got %s.', self::MIN_EVERY, var_export($raw, true))
			);
		}

		return $raw;
	}//end validEvery()

	/**
	 * Whether a count is on the far side of the line.
	 *
	 * @param int $count The count.
	 *
	 * @return bool True when it has crossed.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	public function isCrossed(int $count): bool {
		if ($this->operator === self::GTE) {
			return ($count >= $this->threshold);
		}

		return ($count <= $this->threshold);
	}//end isCrossed()

	/**
	 * What this count does to the alert's state.
	 *
	 * Returns the state to store and whether anybody is told. The two are not
	 * the same question: a count that stays crossed moves nothing and tells
	 * nobody, and a count that comes back re-arms silently.
	 *
	 * @param string $state The stored state.
	 * @param int    $count The fresh count.
	 *
	 * @return array{state: string, fires: bool} The decision.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	public function decide(string $state, int $count): array {
		$crossed = $this->isCrossed(count: $count);

		if ($crossed === false) {
			// Back across the line. Re-arming is silent: nobody asked to hear
			// that a backlog cleared, and a "resolved" message they did not ask
			// for is the second half of the noise this design avoids.
			return ['state' => self::ARMED, 'fires' => false];
		}

		if ($state === self::FIRED) {
			// Still crossed, already said. This is the whole of D-3.
			return ['state' => self::FIRED, 'fires' => false];
		}

		return ['state' => self::FIRED, 'fires' => true];
	}//end decide()

	/**
	 * Whether a view is due for evaluation.
	 *
	 * A view that has never been evaluated is due: an alert somebody set five
	 * minutes ago should not wait for a full interval that it has no record of.
	 *
	 * @param int|null $lastEvaluated Unix time of the last evaluation.
	 * @param int      $now           Unix time now.
	 *
	 * @return bool True when it is due.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	public function isDue(?int $lastEvaluated, int $now): bool {
		if ($lastEvaluated === null) {
			return true;
		}

		return (($now - $lastEvaluated) >= $this->every);
	}//end isDue()

	/**
	 * The alert as stored.
	 *
	 * @return array<string, mixed> The block.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	public function jsonSerialize(): array {
		return [
			'operator' => $this->operator,
			'threshold' => $this->threshold,
			'recipients' => $this->recipients,
			'channels' => $this->channels,
			'every' => $this->every,
		];
	}//end jsonSerialize()

	/**
	 * A list of non-empty strings, or a refusal naming the field.
	 *
	 * @param mixed  $raw   The declared value.
	 * @param string $field The field name, for the refusal.
	 *
	 * @return string[] The list.
	 *
	 * @psalm-return list<string>
	 *
	 * @throws InvalidArgumentException When it is not a list of strings.
	 */
	private static function stringList(mixed $raw, string $field): array {
		if (is_string($raw) === true) {
			$raw = [$raw];
		}

		if (is_array($raw) === false) {
			throw new InvalidArgumentException(sprintf('%s: expected a list of names.', $field));
		}

		$list = [];
		foreach ($raw as $entry) {
			if (is_string($entry) === false) {
				throw new InvalidArgumentException(sprintf('%s: every entry is a name; got %s.', $field, gettype($entry)));
			}

			$name = trim($entry);
			if ($name !== '' && in_array($name, $list, true) === false) {
				$list[] = $name;
			}
		}

		return $list;
	}//end stringList()
}//end class
