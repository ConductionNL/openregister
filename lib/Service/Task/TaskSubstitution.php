<?php

/**
 * Routes a task assigned to an absent person to the stand-in their Nextcloud
 * absence names, provided the stand-in holds the task's mandate.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\Task;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\User\IAvailabilityCoordinator;
use OCP\User\IOutOfOfficeData;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Substitution for an absent assignee (design D-4 and D-6).
 *
 * Reads Nextcloud's own absence: the feature must be enabled, the absence in
 * effect, and it must name a replacement who is a different, enabled user.
 * The absence message is never read: it is the user's personal text.
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
 */
class TaskSubstitution {

	/**
	 * The audit action of a task handed to a stand-in.
	 *
	 * @var string
	 */
	public const ACTION_SUBSTITUTE = 'substitute';

	/**
	 * The audit action of a stand-in that could not be used.
	 *
	 * @var string
	 */
	public const ACTION_REFUSED = 'substitute-refused';

	/**
	 * The audit action of a task given back when the absence ended.
	 *
	 * @var string
	 */
	public const ACTION_RETURN = 'substitute-return';

	/**
	 * The prefix of the actor recorded for a substitution: the absence acted.
	 *
	 * @var string
	 */
	public const ACTOR_PREFIX = 'absence:';

	/**
	 * Constructor.
	 *
	 * @param IAvailabilityCoordinator|null $availability Nextcloud's absences; absent, nobody is ever absent.
	 * @param IUserManager|null             $users        Resolves the absent person and the stand-in.
	 * @param TaskMandateGuard|null         $mandates     Whether the stand-in holds the task's mandate.
	 * @param IConfig|null                  $config       The absent person's timezone, for the period's dates.
	 * @param LoggerInterface|null          $logger       Where an absence read that broke is recorded.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
	 */
	public function __construct(
		private readonly ?IAvailabilityCoordinator $availability = null,
		private readonly ?IUserManager $users = null,
		private readonly ?TaskMandateGuard $mandates = null,
		private readonly ?IConfig $config = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Hand a task to its assignee's stand-in when the assignee is away.
	 *
	 * Mutates the task when it substitutes (assignee, onBehalfOf, mandate
	 * evidence) and returns the act to audit; leaves it untouched and returns
	 * a `substitute-refused` act when the stand-in lacks the mandate; returns
	 * null when there is nothing to do.
	 *
	 * @param Task $task The task, with the assignee just set.
	 *
	 * @return TaskAct|null The act to audit, or null.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
	 */
	public function routeIfAbsent(Task $task): ?TaskAct {
		if ((string)$task->getPerformerType() !== Task::PERFORMER_USER) {
			return null;
		}

		$absent = trim((string)$task->getAssignee());
		$absence = $this->absenceOf(uid: $absent);
		if ($absence === null || $absence['replacement'] === null) {
			return null;
		}

		$standIn = $absence['replacement'];
		$actor = self::ACTOR_PREFIX . $absence['id'];
		$guard = ($this->mandates ?? new TaskMandateGuard());
		$evidence = $guard->evidenceFor(task: $task, uid: $standIn);
		if ($evidence === null) {
			return new TaskAct(
				verb: self::ACTION_REFUSED,
				actor: $actor,
				reason: sprintf(
					"Stand-in '%s' does not hold the mandate '%s'; the task stays with '%s', absent %s to %s.",
					$standIn,
					$guard->verbFor(task: $task),
					$absent,
					$absence['start'],
					$absence['end']
				)
			);
		}

		$task->setOnBehalfOf($absent);
		$task->setAssignee($standIn);
		// An empty answer means there was nothing to check: no evidence to keep.
		$kept = null;
		if ($evidence !== []) {
			$kept = $evidence;
		}

		$task->setMandateEvidence($kept);

		return new TaskAct(
			verb: self::ACTION_SUBSTITUTE,
			actor: $actor,
			reason: sprintf("'%s' is absent %s to %s; '%s' stands in.", $absent, $absence['start'], $absence['end'], $standIn)
		);
	}//end routeIfAbsent()

	/**
	 * Whether a person's absence is in effect now.
	 *
	 * @param string $uid The person.
	 *
	 * @return bool True when away.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
	 */
	public function isAbsent(string $uid): bool {
		return $this->absenceOf(uid: $uid) !== null;
	}//end isAbsent()

	/**
	 * A person's absence in effect now: its id, its period as ISO dates in
	 * their own timezone, and the stand-in it names. Never the message.
	 *
	 * @param string $uid The person.
	 *
	 * @return array{id: string, start: string, end: string, replacement: string|null}|null The absence, or null.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
	 */
	public function absenceOf(string $uid): ?array {
		if ($uid === '' || $this->availability === null || $this->users === null) {
			return null;
		}

		try {
			$data = $this->currentAbsence(uid: $uid);
			if ($data === null) {
				return null;
			}

			return [
				'id' => $data->getId(),
				'start' => $this->dateOf(timestamp: $data->getStartDate(), uid: $uid),
				'end' => $this->dateOf(timestamp: $data->getEndDate(), uid: $uid),
				'replacement' => $this->usableStandIn(absent: $uid, replacement: $data->getReplacementUserId()),
			];
		} catch (Throwable $failure) {
			// An absence that cannot be read is treated as no absence: the task
			// stays with the person it was given to, which is what happened
			// before substitution existed.
			$this->logger?->warning(
				'[TaskSubstitution] The absence of a task assignee could not be read: ' . $failure->getMessage(),
				['exception' => $failure]
			);

			return null;
		}
	}//end absenceOf()

	/**
	 * The absence in effect for a user, or null.
	 *
	 * @param string $uid The person.
	 *
	 * @return IOutOfOfficeData|null The absence.
	 */
	private function currentAbsence(string $uid): ?IOutOfOfficeData {
		if ($this->availability?->isEnabled() !== true) {
			return null;
		}

		$user = $this->users?->get($uid);
		if ($user === null) {
			return null;
		}

		$data = $this->availability->getCurrentOutOfOfficeData($user);
		if ($data === null || $this->availability->isInEffect($data) === false) {
			return null;
		}

		return $data;
	}//end currentAbsence()

	/**
	 * The stand-in, when it is a different, enabled user.
	 *
	 * @param string      $absent      The absent person.
	 * @param string|null $replacement The replacement the absence names.
	 *
	 * @return string|null The stand-in, or null.
	 */
	private function usableStandIn(string $absent, ?string $replacement): ?string {
		$standIn = trim((string)$replacement);
		if ($standIn === '' || $standIn === $absent) {
			return null;
		}

		$user = $this->users?->get($standIn);
		if ($user === null || $user->isEnabled() === false) {
			return null;
		}

		return $standIn;
	}//end usableStandIn()

	/**
	 * An absence boundary as an ISO date in the person's own timezone.
	 *
	 * @param int    $timestamp The boundary.
	 * @param string $uid       The person.
	 *
	 * @return string The date, Y-m-d.
	 */
	private function dateOf(int $timestamp, string $uid): string {
		$zone = 'UTC';
		$configured = (string)($this->config?->getUserValue($uid, 'core', 'timezone', 'UTC') ?? 'UTC');
		if (in_array($configured, DateTimeZone::listIdentifiers(), true) === true) {
			$zone = $configured;
		}

		return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone($zone))->format('Y-m-d');
	}//end dateOf()
}//end class
