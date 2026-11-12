<?php

/**
 * FlowTimerMapperInterface: what callers outside the component use of FlowTimerMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTimeInterface;
use OCA\OpenRegister\Db\FlowTimer\TimerSubject;
use OCP\AppFramework\Db\Entity;

/**
 * The flow-timer table: arming, the sweep's due queries and the subject and run lookups.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to FlowTimerMapper.
 */
interface FlowTimerMapperInterface {

	/**
	 * Insert, stamping `created`.
	 *
	 * @param Entity $entity The timer to insert.
	 *
	 * @return FlowTimer The inserted timer, with its id.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function insert(Entity $entity): FlowTimer;

	/**
	 * Update, stamping `updated`.
	 *
	 * @param Entity $entity The timer to update.
	 *
	 * @return FlowTimer The updated timer.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function update(Entity $entity): FlowTimer;

	/**
	 * Find a timer by its public uuid.
	 *
	 * @param string $uuid The timer uuid.
	 *
	 * @return FlowTimer The timer.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such timer exists.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function findByUuid(string $uuid): FlowTimer;

	/**
	 * The expiry range scan: armed ENFORCING-PURPOSE timers whose fire moment
	 * has passed, oldest first, bounded.
	 *
	 * `purpose = expiry` is part of the predicate because a `due` timer stays
	 * ARMED past its fire moment by design — that is what makes it overdue on
	 * read — and must not be re-selected every pass.
	 *
	 * @param DateTimeInterface $now The sweep instant.
	 * @param int $limit The batch limit.
	 *
	 * @return array<int, FlowTimer> The due expiry timers.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function findDueExpiries(DateTimeInterface $now, int $limit): array;

	/**
	 * The rung range scan: armed timers whose next unfired rung is due, bounded.
	 *
	 * @param DateTimeInterface $now The sweep instant.
	 * @param int $limit The batch limit.
	 *
	 * @return array<int, FlowTimer> The timers with a due rung.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function findDueRungs(DateTimeInterface $now, int $limit): array;

	/**
	 * Every timer bound to a subject, optionally restricted to a state set.
	 *
	 * @param TimerSubject       $subject The subject's type and uuid.
	 * @param array<int, string> $states  Restrict to these states; empty means all.
	 *
	 * @return array<int, FlowTimer> The subject's timers, oldest first.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function findBySubject(TimerSubject $subject, array $states = []): array;

	/**
	 * The OPEN timers a run terminality reaches: bound to the run as subject,
	 * or carrying it as provenance.
	 *
	 * @param string $runUuid The run uuid.
	 *
	 * @return array<int, FlowTimer> The open timers.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function findOpenByRun(string $runUuid): array;

	/**
	 * Every timer in a state, paged by id, for the invariant check.
	 *
	 * @param string   $state  The state.
	 * @param IdCursor $cursor The rows after this id, and the page size.
	 *
	 * @return array<int, FlowTimer> The page.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function findByStatePaged(string $state, IdCursor $cursor): array;

	/**
	 * CLAIM the terminal fire of a timer: `SET state = 'fired' WHERE uuid = ?
	 * AND state = 'armed'`. Zero affected rows means another pass owns it, so
	 * the outcome is applied at most once (design D-8).
	 *
	 * @param string $uuid The timer uuid.
	 * @param DateTimeInterface $firedAt The claim instant.
	 *
	 * @return boolean True when this caller won the claim.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function claimFired(string $uuid, DateTimeInterface $firedAt): bool;

	/**
	 * The OPEN timers that name a working calendar, oldest first.
	 *
	 * Only `armed` and `suspended` block a calendar's deletion: a fired,
	 * cancelled or superseded timer will never consult the calendar again, so
	 * refusing on its account would make a calendar undeletable forever.
	 *
	 * @param string $calendarSlug The calendar's slug.
	 * @param int $limit How many rows to return at most.
	 *
	 * @return array<int, FlowTimer> The open timers naming that calendar.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-only-an-administrator-writes-a-calendar-and-a-referenced-one-cannot-be-deleted
	 */
	public function findOpenByCalendarSlug(string $calendarSlug, int $limit): array;

	/**
	 * How many OPEN timers name a working calendar.
	 *
	 * Counted in the database rather than by measuring
	 * {@see findOpenByCalendarSlug()}, whose result is capped at the ten uuids
	 * the refusal quotes: a count read off a capped page would report ten for
	 * a calendar a thousand timers depend on.
	 *
	 * @param string $calendarSlug The calendar's slug.
	 *
	 * @return integer The number of armed or suspended timers.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-only-an-administrator-writes-a-calendar-and-a-referenced-one-cannot-be-deleted
	 */
	public function countOpenByCalendarSlug(string $calendarSlug): int;
}//end interface
