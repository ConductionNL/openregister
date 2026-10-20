<?php

/**
 * A step's own report into the run log, carried through the node context.
 *
 * The run log records what a node RECEIVED and RETURNED; a node whose work is
 * a side effect — a send, a call — has more to say than its pass-through items
 * can carry. This handle travels in the context like the token, the resume
 * state and the guard do (`IFlowNode::execute()` takes `$context` by value, so
 * only an object survives the copy), and the engine drains it onto the step's
 * own log entry after each hop.
 *
 * The report is per HOP: the engine takes it after each dispatch, so an entry
 * a node wrote can never leak onto a later step's log line.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Collects a node's own log detail for the current hop.
 */
class FlowStepReport {

	/**
	 * The context key this handle travels under.
	 */
	public const CONTEXT_KEY = '_stepReport';

	/**
	 * The most lines one step keeps in its report.
	 */
	public const MAX_LINES = 50;

	/**
	 * The most characters one line's message keeps.
	 *
	 * The count cap alone does not bound the log: fifty lines of a megabyte each
	 * are fifty megabytes per step, kept for months.
	 */
	public const MAX_MESSAGE_LENGTH = 500;

	/**
	 * What replaces the end of a message cut at {@see self::MAX_MESSAGE_LENGTH}.
	 */
	public const TRUNCATION_MARKER = '… [truncated]';

	/**
	 * The levels a line may carry.
	 */
	public const LEVELS = ['info', 'success', 'warning', 'error'];

	/**
	 * The detail written by the current hop's node.
	 *
	 * @var array<string, mixed>
	 */
	private array $detail = [];

	/**
	 * Lines left out of the current hop past {@see self::MAX_LINES}.
	 *
	 * @var integer
	 */
	private int $dropped = 0;

	/**
	 * Record one piece of detail for the current step's log entry.
	 *
	 * A node MUST keep its report bounded itself — the run log is kept for
	 * months, and the engine does not re-sample what a node chose to write.
	 *
	 * @param string $key The detail's name within the entry's `report` map.
	 * @param mixed $value The detail; must be JSON-serialisable.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	public function put(string $key, mixed $value): void {
		$this->detail[$key] = $value;
	}//end put()

	/**
	 * Record one timestamped line for the current step, under `report.lines`.
	 *
	 * What a person reads in a run view: "looked up the account", "no
	 * account, posted nothing". `level` is `info`, `success`, `warning` or
	 * `error`; anything else is recorded as `info`. At most
	 * {@see self::MAX_LINES} lines are kept per step, and the last one then
	 * says how many were left out, so a node writing in a loop cannot grow
	 * the log without bound. A message longer than
	 * {@see self::MAX_MESSAGE_LENGTH} characters is cut and ends in
	 * {@see self::TRUNCATION_MARKER}, so one large string cannot grow it either.
	 *
	 * @param string $level   The line's level.
	 * @param string $message What happened, in plain words.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-step-log-lines/specs/flow-step-log-lines/spec.md
	 */
	public function line(string $level, string $message): void {
		$lines = ($this->detail['lines'] ?? []);
		if (is_array($lines) === false) {
			$lines = [];
		}

		if (count($lines) >= self::MAX_LINES) {
			$this->dropped++;
			$lines[self::MAX_LINES - 1] = [
				'at' => $this->now(),
				'level' => 'info',
				'message' => sprintf('%d more lines left out.', $this->dropped + 1),
			];
			$this->detail['lines'] = $lines;
			return;
		}

		if (in_array($level, self::LEVELS, true) === false) {
			$level = 'info';
		}

		$lines[] = [
			'at' => $this->now(),
			'level' => $level,
			'message' => $this->bounded(message: $message),
		];
		$this->detail['lines'] = $lines;
	}//end line()

	/**
	 * The message, cut to {@see self::MAX_MESSAGE_LENGTH} characters with a marker when it is longer.
	 *
	 * The result, marker included, is at most MAX_MESSAGE_LENGTH characters.
	 *
	 * @param string $message The message as the node wrote it.
	 *
	 * @return string The bounded message.
	 */
	private function bounded(string $message): string {
		if (mb_strlen($message) <= self::MAX_MESSAGE_LENGTH) {
			return $message;
		}

		$keep = (self::MAX_MESSAGE_LENGTH - mb_strlen(self::TRUNCATION_MARKER));

		return mb_substr($message, 0, $keep) . self::TRUNCATION_MARKER;
	}//end bounded()

	/**
	 * The current moment as ISO 8601 with milliseconds, UTC.
	 *
	 * @return string The moment.
	 *
	 * @spec openspec/changes/flow-step-log-lines/specs/flow-step-log-lines/spec.md
	 */
	private function now(): string {
		$moment = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)), new DateTimeZone('UTC'));
		if ($moment === false) {
			return gmdate('Y-m-d\TH:i:s+00:00');
		}

		return $moment->format('Y-m-d\TH:i:s.vP');
	}//end now()

	/**
	 * Return and clear the current hop's detail.
	 *
	 * Clearing on read is what scopes the report to one hop: the engine takes
	 * it while writing the step's log entry, and the next node starts blank.
	 *
	 * @return array<string, mixed> The detail written since the last take.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	public function take(): array {
		$detail = $this->detail;
		$this->detail = [];
		$this->dropped = 0;

		return $detail;
	}//end take()
}//end class
