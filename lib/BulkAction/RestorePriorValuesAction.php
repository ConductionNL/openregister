<?php

/**
 * Write back what another job wrote over.
 *
 * The inverse of a bulk action is an ordinary bulk job, not a transaction
 * rollback: the members were written one by one, minutes or hours ago, and
 * other people have written since. So the reversal runs the same engine, the
 * same ceiling, the same preview and the same per-member reporting, and its
 * per-member write is the prior value the original job recorded (D-1).
 *
 * Two members are skipped rather than written. One whose recorded values no
 * longer match what the original job wrote was edited by somebody afterwards,
 * and restoring it would destroy work that somebody did deliberately, which
 * is the failure this change exists to prevent, pointed the other way (D-3).
 * One the original job never wrote has nothing to go back to.
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BulkAction;

use InvalidArgumentException;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\BulkJobMemberMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\ObjectPatch;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;

/**
 * The built-in inverse of a reversible bulk job.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) BulkActionResult's named constructors
 * are its only constructor: the class is immutable and its private
 * __construct exists so an outcome cannot be built without saying which of
 * the four it is.
 */
class RestorePriorValuesAction implements ReversibleBulkActionInterface, RestorePriorValuesActionInterface {

	/**
	 * How long a reversal itself stays undoable, in seconds.
	 *
	 * A reversal is a job like any other, so it records its own prior values
	 * and can be undone in turn. Somebody who undoes the wrong job should not
	 * then be stuck with it (D-1).
	 *
	 * @var int
	 */
	public const REVERSAL_WINDOW = 604800;

	/**
	 * The original job's member rows, memoised for the length of one request.
	 *
	 * The engine asks about each object twice, once to rehearse and once to
	 * commit, and the rows of a finished job do not change underneath it.
	 *
	 * @var array<string, BulkJobMember|null>
	 */
	private array $records = [];

	/**
	 * Constructor.
	 *
	 * @param BulkJobMemberMapperInterface $memberMapper The original job's outcomes.
	 * @param BulkObjectAccess             $access       The object write path and the log.
	 */
	public function __construct(
		private readonly BulkJobMemberMapperInterface $memberMapper,
		private readonly BulkObjectAccess $access,
	) {
	}//end __construct()

	/**
	 * The action's stable id.
	 *
	 * @return string The action id.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * The label an operator reads.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getLabel(): string {
		return 'Undo a bulk action';
	}//end getLabel()

	/**
	 * One sentence saying what the action does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getDescription(): string {
		return 'Writes back what an earlier job changed. An object somebody has edited since is left alone and reported by name.';
	}//end getDescription()

	/**
	 * Undoing a hundred cases is an act somebody has to be able to explain.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function requiresJustification(): bool {
		return true;
	}//end requiresJustification()

	/**
	 * The guards the engine enforces for this action.
	 *
	 * No homogeneity guard. The selection is whatever the original job wrote,
	 * and refusing to undo it because a schema version moved in the meantime
	 * would strand the mistake this action exists to correct.
	 *
	 * @return array<int, string> No guards.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getGuards(): array {
		return [];
	}//end getGuards()

	/**
	 * Check the parameters before a job is created.
	 *
	 * @param array<string, mixed> $parameters The parameters the caller sent.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the job being undone is not named.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function validateParameters(array $parameters): void {
		$jobId = ($parameters[self::PARAM_JOB] ?? null);

		if (is_int($jobId) === false || $jobId < 1) {
			throw new InvalidArgumentException(
				'The action '.self::ID.' needs '.self::PARAM_JOB.' naming the job it undoes.'
			);
		}
	}//end validateParameters()

	/**
	 * How long a reversal stays undoable, in seconds.
	 *
	 * @return int The window, in seconds.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getReversalWindow(): int {
		return self::REVERSAL_WINDOW;
	}//end getReversalWindow()

	/**
	 * What undoing would change on one object, and what it would change it from.
	 *
	 * @param ObjectEntity         $object     The object to act on.
	 * @param array<string, mixed> $parameters The job's parameters.
	 *
	 * @return array{prior: array<string, mixed>, applied: array<string, mixed>} The plan.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function reversalPlanFor(ObjectEntity $object, array $parameters): array {
		$restore = $this->restoreValuesFor(object: $object, parameters: $parameters);
		$current = $object->getObject();

		$prior = [];
		foreach (array_keys($restore) as $key) {
			$prior[$key] = ($current[$key] ?? null);
		}

		return [
			self::PLAN_PRIOR => $prior,
			self::PLAN_APPLIED => $restore,
		];
	}//end reversalPlanFor()

	/**
	 * Rehearse or make the restoring write.
	 *
	 * @param BulkStep $step The member, the job being undone, rehearsal or commit, and the actor.
	 *
	 * @return BulkActionResult What happened, or what would happen.
	 *
	 * rehearsal and the commit is the design property of D-1.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function apply(BulkStep $step): BulkActionResult {
		$record = $this->recordFor(object: $step->object, parameters: $step->parameters);
		if ($record === null) {
			return BulkActionResult::skipped(
				reason: 'the job being undone never wrote this object, so there is nothing to write back'
			);
		}

		$skip = $this->reasonToLeave(record: $record, current: $step->object->getObject());
		if ($skip !== null) {
			return BulkActionResult::skipped(reason: $skip);
		}

		if ($step->commit === false) {
			return BulkActionResult::applied();
		}

		return $this->write(step: $step, restore: ($record->getPriorValues() ?? []));
	}//end apply()

	/**
	 * Why the object is left as it is, or null when the prior values go back.
	 *
	 * "Already back" is checked FIRST. An object somebody restored by hand
	 * also fails the changed-since test below, and reporting that one as
	 * "somebody edited this" would send its owner looking for an edit that
	 * never happened.
	 *
	 * @param BulkJobMember        $record  What the original job wrote on this object.
	 * @param array<string, mixed> $current The object's data now.
	 *
	 * @return string|null The reason to skip, or null.
	 */
	private function reasonToLeave(BulkJobMember $record, array $current): ?string {
		if ($this->wouldChangeNothing(restore: ($record->getPriorValues() ?? []), current: $current) === true) {
			return 'the object already carries the values it had before';
		}

		$changed = $this->propertiesChangedSince(record: $record, current: $current);
		if ($changed !== []) {
			return 'not reversible: '.implode(', ', $changed).' changed after the original job wrote it, and a '
				.'later change is never overwritten';
		}

		return null;
	}//end reasonToLeave()

	/**
	 * Make the restoring write.
	 *
	 * @param BulkStep             $step    The member and the user the job runs as.
	 * @param array<string, mixed> $restore The values to write back.
	 *
	 * @return BulkActionResult What happened.
	 */
	private function write(BulkStep $step, array $restore): BulkActionResult {
		$object = $step->object;
		try {
			$this->access->objects->patchObject(
				patch: new ObjectPatch(
					objectId: (string)$object->getUuid(),
					data: $restore,
					target: new ObjectFindTarget(register: $object->getRegister(), schema: $object->getSchema()),
					currentUser: $step->actor
				)
			);
		} catch (\Throwable $exception) {
			$this->access->logger->warning(
				message: '[BulkAction] Restoring the prior value failed for object',
				context: [
					'action' => self::ID,
					'object' => $object->getUuid(),
					'error' => $exception->getMessage(),
				]
			);

			return BulkActionResult::failed(message: $exception->getMessage());
		}//end try

		return BulkActionResult::applied();
	}//end write()

	/**
	 * The properties somebody changed after the original job wrote them.
	 *
	 * Compares the object's CURRENT values against what the original job
	 * WROTE, never against the prior values: an object that was already
	 * restored by hand and one nobody touched read identically off the prior
	 * values alone (D-3).
	 *
	 * @param BulkJobMember        $record  The original job's member row.
	 * @param array<string, mixed> $current The object's current data.
	 *
	 * @return array<int, string> The property names, empty when nothing moved.
	 */
	private function propertiesChangedSince(BulkJobMember $record, array $current): array {
		$applied = ($record->getAppliedValues() ?? []);
		$changed = [];

		foreach ($applied as $key => $value) {
			if ($this->sameValue(left: ($current[$key] ?? null), right: $value) === false) {
				$changed[] = (string)$key;
			}
		}

		return $changed;
	}//end propertiesChangedSince()

	/**
	 * Whether two stored values are the same value.
	 *
	 * Strict, with one narrow exception: two NUMBERS that agree are the same
	 * number whether one of them arrived as a string. The object write path
	 * casts a numeric property to the type its schema declares, so the value
	 * the job handed it and the value the register stored can differ in type
	 * and in nothing else. Strict comparison alone would read that cast as
	 * somebody's later edit, and every member of a numeric bulk write would
	 * come back not reversible with no edit behind it.
	 *
	 * Null and the booleans are deliberately NOT folded in. `null` and `false`
	 * are different answers to "is this set", and losing that distinction here
	 * would let a real change pass as no change, which is the one direction
	 * this check must never fail in.
	 *
	 * @param mixed $left  One value.
	 * @param mixed $right The other.
	 *
	 * @return bool True when they are the same value.
	 */
	private function sameValue(mixed $left, mixed $right): bool {
		if ($left === $right) {
			return true;
		}

		if ($this->isNumber(value: $left) === false || $this->isNumber(value: $right) === false) {
			return false;
		}

		return ((float)$left === (float)$right);
	}//end sameValue()

	/**
	 * Whether a stored value is a number, however it was typed.
	 *
	 * A boolean is not. `true` is numeric to PHP's cast and is not a number to
	 * anybody reading the register.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for an int, a float or a numeric string.
	 */
	private function isNumber(mixed $value): bool {
		if (is_int($value) === true || is_float($value) === true) {
			return true;
		}

		return (is_string($value) === true && is_numeric($value) === true);
	}//end isNumber()

	/**
	 * Whether the object already carries the values the reversal would write.
	 *
	 * @param array<string, mixed> $restore The values to write back.
	 * @param array<string, mixed> $current The object's current data.
	 *
	 * @return bool True when the write would change nothing.
	 */
	private function wouldChangeNothing(array $restore, array $current): bool {
		if ($restore === []) {
			return true;
		}

		foreach ($restore as $key => $value) {
			if ($this->sameValue(left: ($current[$key] ?? null), right: $value) === false) {
				return false;
			}
		}

		return true;
	}//end wouldChangeNothing()

	/**
	 * The values this reversal would write back on one object.
	 *
	 * @param ObjectEntity         $object     The object.
	 * @param array<string, mixed> $parameters The job's parameters.
	 *
	 * @return array<string, mixed> The recorded prior values, or an empty array.
	 */
	private function restoreValuesFor(ObjectEntity $object, array $parameters): array {
		$record = $this->recordFor(object: $object, parameters: $parameters);

		if ($record === null) {
			return [];
		}

		return ($record->getPriorValues() ?? []);
	}//end restoreValuesFor()

	/**
	 * The original job's member row for one object.
	 *
	 * @param ObjectEntity         $object     The object.
	 * @param array<string, mixed> $parameters The job's parameters.
	 *
	 * @return BulkJobMember|null The member row, or null when the job never wrote it.
	 */
	private function recordFor(ObjectEntity $object, array $parameters): ?BulkJobMember {
		$jobId = (int)($parameters[self::PARAM_JOB] ?? 0);
		$uuid = (string)$object->getUuid();
		$key = $jobId.':'.$uuid;

		if (array_key_exists($key, $this->records) === true) {
			return $this->records[$key];
		}

		$member = $this->memberMapper->findByJobAndObject(jobId: $jobId, objectUuid: $uuid);

		// A member the original job never WROTE has no prior value, and a row
		// with none is the same as no row: there is nothing to go back to.
		if ($member !== null && $member->getPriorValues() === null) {
			$member = null;
		}

		$this->records[$key] = $member;

		return $member;
	}//end recordFor()
}//end class
