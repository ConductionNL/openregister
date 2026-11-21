<?php

/**
 * OpenRegister LifecycleActionRecorder
 *
 * Records the outcome of every declared lifecycle action the executor reaches,
 * once the transition's save has resolved.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/automation-run-records/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\AutomationRecord;
use OCA\OpenRegister\Service\AutomationRecordWriter;

/**
 * Holds the records of a transition's actions until its save resolves (D-3).
 *
 * The executor runs on the pre-save event, so an action that returned is not
 * yet a transition that happened. Its record is held per object and written
 * when the save completes (`flush()`); when an action throws, the held records
 * of that transition are written as `failed` ("transition rolled back") next to
 * the thrower's own `failed` record. A save that never completes leaves no
 * `succeeded` record behind.
 *
 * @spec openspec/changes/automation-run-records/specs/object-lifecycle/spec.md
 */
class LifecycleActionRecorder {

	public const OUTCOME_SUCCEEDED = 'succeeded';

	public const OUTCOME_FAILED = 'failed';

	public const OUTCOME_SKIPPED = 'skipped';

	public const ROLLED_BACK = 'transition rolled back';

	/**
	 * The held records, per object uuid.
	 *
	 * @var array<string, array<int, AutomationRecord>>
	 */
	private array $pending = [];

	/**
	 * Constructor.
	 *
	 * @param AutomationRecordWriter $writer Stores the records.
	 *
	 * @spec openspec/changes/automation-run-records/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly AutomationRecordWriter $writer,
	) {
	}//end __construct()

	/**
	 * Note an action the executor reached and finished: succeeded, or skipped by its condition.
	 *
	 * Held until the save resolves; an action on no stored object is written at once.
	 *
	 * @param TransitionPayload    $payload  The transition and its object.
	 * @param array<string, mixed> $envelope The declared action.
	 * @param string               $outcome  succeeded or skipped.
	 * @param string               $message  Why, for a skip.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-run-records/specs/object-lifecycle/spec.md
	 */
	public function reached(TransitionPayload $payload, array $envelope, string $outcome, string $message = ''): void {
		$record = $this->record(payload: $payload, envelope: $envelope, outcome: $outcome, message: $message);
		$uuid   = (string)$record->getObjectUuid();
		if ($uuid === '') {
			$this->writer->write($record);
			return;
		}

		$this->pending[$uuid][] = $record;
	}//end reached()

	/**
	 * Record an action that threw: the transition's held records become
	 * `failed` (rolled back) and the thrower's own record is `failed` with its message.
	 *
	 * @param TransitionPayload $payload  The transition and its object.
	 * @param mixed             $envelope The declared action (possibly malformed).
	 * @param string            $message  The handler's message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-run-records/specs/object-lifecycle/spec.md
	 */
	public function failed(TransitionPayload $payload, mixed $envelope, string $message): void {
		if (is_array($envelope) === false) {
			$envelope = [];
		}

		$failed = $this->record(payload: $payload, envelope: $envelope, outcome: self::OUTCOME_FAILED, message: $message);
		foreach ($this->take(uuid: (string)$failed->getObjectUuid()) as $held) {
			$held->setOutcome(self::OUTCOME_FAILED);
			$held->setMessage(self::ROLLED_BACK);
			$this->writer->write($held);
		}

		$this->writer->write($failed);
	}//end failed()

	/**
	 * The save of an object resolved: write the records held for it as they are.
	 *
	 * @param string $uuid The object uuid.
	 *
	 * @return int The number of records written.
	 *
	 * @spec openspec/changes/automation-run-records/specs/object-lifecycle/spec.md
	 */
	public function flush(string $uuid): int {
		$held = $this->take(uuid: $uuid);
		foreach ($held as $record) {
			$this->writer->write($record);
		}

		return count($held);
	}//end flush()

	/**
	 * Drop the records held for an object without writing them (its save did not happen).
	 *
	 * @param string $uuid The object uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-run-records/specs/object-lifecycle/spec.md
	 */
	public function discard(string $uuid): void {
		unset($this->pending[$uuid]);
	}//end discard()

	/**
	 * The held records of an object, removed from the hold.
	 *
	 * @param string $uuid The object uuid.
	 *
	 * @return array<int, AutomationRecord> The records.
	 */
	private function take(string $uuid): array {
		$held = ($this->pending[$uuid] ?? []);
		unset($this->pending[$uuid]);

		return $held;
	}//end take()

	/**
	 * A lifecycle-action record for one action.
	 *
	 * @param TransitionPayload    $payload  The transition and its object.
	 * @param array<string, mixed> $envelope The declared action.
	 * @param string               $outcome  The outcome.
	 * @param string               $message  The message.
	 *
	 * @return AutomationRecord The record.
	 */
	private function record(TransitionPayload $payload, array $envelope, string $outcome, string $message): AutomationRecord {
		$name = ($envelope['action'] ?? '');
		if (is_string($name) === false) {
			$name = '';
		}

		$record = AutomationRecordWriter::draft(
			kind: AutomationRecord::KIND_LIFECYCLE_ACTION,
			key: ($envelope['key'] ?? null),
			name: $name,
			object: $payload->object
		);
		$record->setTransition($payload->transition);
		$record->setOutcome($outcome);
		$record->setMessage($message);

		return $record;
	}//end record()
}//end class
